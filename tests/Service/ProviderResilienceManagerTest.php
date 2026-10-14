<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\CircuitBreakerState;
use App\Service\ProviderResilienceManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ProviderResilienceManagerTest extends TestCase
{
    private ProviderResilienceManager $manager;

    #[\Override]
    protected function setUp(): void
    {
        $this->manager = new ProviderResilienceManager();
    }

    public function testInitialStateIsClosedAndAvailable(): void
    {
        $this->assertTrue($this->manager->isAvailable('BrickPicker'));
        $this->assertSame(CircuitBreakerState::CLOSED, $this->manager->getCircuitState('BrickPicker'));
        $this->assertSame(500, $this->manager->getAdaptiveDelayMs('BrickPicker'));
    }

    public function testConsecutive403TripsCircuitBreaker(): void
    {
        $this->manager->setForbiddenThreshold(3);
        $this->manager->setForbiddenCooldownSeconds(100);

        $this->manager->recordForbidden('BrickPicker');
        $this->assertSame(CircuitBreakerState::CLOSED, $this->manager->getCircuitState('BrickPicker'));
        $this->assertTrue($this->manager->isAvailable('BrickPicker'));

        $this->manager->recordForbidden('BrickPicker');
        $this->assertSame(CircuitBreakerState::CLOSED, $this->manager->getCircuitState('BrickPicker'));

        // 3rd consecutive 403 -> trips circuit
        $this->manager->recordForbidden('BrickPicker', 'https://example.com');
        $this->assertSame(CircuitBreakerState::OPEN, $this->manager->getCircuitState('BrickPicker'));
        $this->assertFalse($this->manager->isAvailable('BrickPicker'));
        $this->assertStringContainsString('3 consecutive 403', $this->manager->getTrippedReason('BrickPicker') ?? '');
    }

    public function testOpenCircuitSkipsExecutionWithoutCallingCallback(): void
    {
        $this->manager->tripCircuit('BrickPicker', 'Test trip', 60);

        $called = false;
        $result = $this->manager->execute(
            'BrickPicker',
            'https://example.com',
            function () use (&$called): \PHPUnit\Framework\MockObject\MockObject {
                $called = true;
                return $this->createMock(ResponseInterface::class);
            },
            new NullLogger()
        );

        $this->assertNull($result);
        $this->assertFalse($called);
    }

    public function testHalfOpenStateRecoversOnSuccess(): void
    {
        $this->manager->tripCircuit('BrickPicker', 'Test trip', 5);

        // Manually simulate cooldown elapsed by moving circuitOpenUntil into the past
        $ref = new \ReflectionClass($this->manager);
        $prop = $ref->getProperty('providers');
        $providers = $prop->getValue($this->manager);
        $providers['brickpicker']['circuitOpenUntil'] = microtime(true) - 1.0;
        $prop->setValue($this->manager, $providers);

        // Should now transition to HALF_OPEN
        $this->assertTrue($this->manager->isAvailable('BrickPicker'));
        $this->assertSame(CircuitBreakerState::HALF_OPEN, $this->manager->getCircuitState('BrickPicker'));

        // Successful trial probe recovers to CLOSED
        $this->manager->recordSuccess('BrickPicker');
        $this->assertSame(CircuitBreakerState::CLOSED, $this->manager->getCircuitState('BrickPicker'));
        $this->assertTrue($this->manager->isAvailable('BrickPicker'));
    }

    public function testAdaptivePacingIncreasesOn429AndDecreasesOnSuccess(): void
    {
        $this->manager->setInitialPacingDelayMs(500);
        $this->manager->setMinPacingDelayMs(200);
        $this->manager->setMaxPacingDelayMs(30000);
        $this->manager->setDecreaseStepMs(100);
        $this->manager->setIncreaseFactor(2.0);
        $this->manager->setSuccessThresholdForDecrease(3);

        $this->assertSame(500, $this->manager->getAdaptiveDelayMs('Brickset'));

        // 429 received with Retry-After: 3 seconds -> multiplicative jump to max(500*2, 3000) = 3000ms
        $this->manager->recordRateLimited('Brickset', 3);
        $this->assertSame(3000, $this->manager->getAdaptiveDelayMs('Brickset'));

        // Another 429 without Retry-After -> 3000 * 2 = 6000ms
        $this->manager->recordRateLimited('Brickset', null);
        $this->assertSame(6000, $this->manager->getAdaptiveDelayMs('Brickset'));

        // 3 consecutive successes trigger additive decrease: 6000 - 100 = 5900ms
        $this->manager->recordSuccess('Brickset');
        $this->assertSame(6000, $this->manager->getAdaptiveDelayMs('Brickset'));
        $this->manager->recordSuccess('Brickset');
        $this->assertSame(6000, $this->manager->getAdaptiveDelayMs('Brickset'));
        $this->manager->recordSuccess('Brickset');
        $this->assertSame(5900, $this->manager->getAdaptiveDelayMs('Brickset'));
    }

    public function testProvidersAreIsolatedFromEachOther(): void
    {
        $this->manager->tripCircuit('BrickPicker', 'Blocked', 3600);
        $this->manager->recordRateLimited('Brickset', 10);

        // BrickPicker is tripped
        $this->assertFalse($this->manager->isAvailable('BrickPicker'));
        $this->assertSame(CircuitBreakerState::OPEN, $this->manager->getCircuitState('BrickPicker'));

        // Brickset is healthy, only pacing is increased
        $this->assertTrue($this->manager->isAvailable('Brickset'));
        $this->assertSame(CircuitBreakerState::CLOSED, $this->manager->getCircuitState('Brickset'));
        $this->assertGreaterThanOrEqual(10000, $this->manager->getAdaptiveDelayMs('Brickset'));
    }

    public function testWaitPacingEnforcesDelay(): void
    {
        $slept = [];
        $this->manager->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $this->manager->setAdaptiveDelayMs('Brickset', 1000);

        // First wait establishes baseline lastRequestTime
        $this->manager->waitPacing('Brickset');

        // Immediate second wait must sleep remaining fraction of 1000ms
        $this->manager->waitPacing('Brickset');

        $this->assertCount(1, $slept);
        $this->assertGreaterThan(0.8, $slept[0]);
        $this->assertLessThanOrEqual(1.1, $slept[0]);
    }

    public function testExecuteHandles429RetrySuccessfully(): void
    {
        $slept = [];
        $this->manager->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $response429 = $this->createMock(ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn(['retry-after' => ['2']]);

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('getStatusCode')->willReturn(200);

        $calls = 0;
        $result = $this->manager->execute(
            'Brickset',
            'https://brickset.com/sets/10131-1',
            function () use (&$calls, $response429, $response200): \PHPUnit\Framework\MockObject\MockObject {
                ++$calls;
                return $calls === 1 ? $response429 : $response200;
            },
            new NullLogger()
        );

        $this->assertSame($response200, $result);
        $this->assertSame(2, $calls);
        $this->assertContains(2, $slept);
        $this->assertTrue($this->manager->isAvailable('Brickset'));
        $this->assertGreaterThanOrEqual(2000, $this->manager->getAdaptiveDelayMs('Brickset'));
    }
}
