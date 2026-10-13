<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RateLimitRetryTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\ResponseInterface;

class RateLimitRetryTraitTest extends TestCase
{
    private function createService(): object
    {
        return new class {
            use RateLimitRetryTrait;

            public function testExecute(callable $callback, string $serviceName, string $url, NullLogger $logger): ?ResponseInterface
            {
                return $this->executeWithRateLimitRetry($callback, $serviceName, $url, $logger);
            }

            public function testCooldown(string $serviceName, NullLogger $logger): bool
            {
                return $this->checkAndHandleCooldown($serviceName, $logger);
            }
        };
    }

    public function testParseRetryAfterNumericSeconds(): void
    {
        $service = $this->createService();

        $this->assertSame(30, $service->parseRetryAfter('30'));
        $this->assertSame(120, $service->parseRetryAfter('120'));
        $this->assertSame(1, $service->parseRetryAfter('0'));
        $this->assertSame(1, $service->parseRetryAfter('-5'));
    }

    public function testParseRetryAfterHttpDate(): void
    {
        $service = $this->createService();

        $futureTime = time() + 45;
        $httpDate = gmdate('D, d M Y H:i:s \G\M\T', $futureTime);

        $delay = $service->parseRetryAfter($httpDate);
        $this->assertGreaterThanOrEqual(40, $delay);
        $this->assertLessThanOrEqual(50, $delay);
    }

    public function testParseRetryAfterDefaultFallback(): void
    {
        $service = $this->createService();

        $this->assertSame(5, $service->parseRetryAfter(null));
        $this->assertSame(5, $service->parseRetryAfter(''));
        $this->assertSame(5, $service->parseRetryAfter('   '));
        $this->assertSame(5, $service->parseRetryAfter('invalid-date-string'));
        $this->assertSame(10, $service->parseRetryAfter(null, 10));
    }

    public function testCalculateBackoffDelay(): void
    {
        $service = $this->createService();
        $service->setBaseDelaySeconds(2);
        $service->setMaxDelaySeconds(30);

        // Exponential: 2 * 2^attempt
        $this->assertSame(2, $service->calculateBackoffDelay(0));
        $this->assertSame(4, $service->calculateBackoffDelay(1));
        $this->assertSame(8, $service->calculateBackoffDelay(2));
        $this->assertSame(16, $service->calculateBackoffDelay(3));
        $this->assertSame(30, $service->calculateBackoffDelay(5)); // Clamped to maxDelay (30)

        // Retry-After header takes precedence
        $this->assertSame(15, $service->calculateBackoffDelay(0, '15'));
        $this->assertSame(30, $service->calculateBackoffDelay(0, '60')); // Clamped to maxDelay (30)
    }

    public function testExecuteWithRateLimitRetryImmediateSuccess(): void
    {
        $service = $this->createService();
        $slept = [];
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $calls = 0;
        $result = $service->testExecute(function () use (&$calls, $response): \PHPUnit\Framework\MockObject\MockObject {
            ++$calls;
            return $response;
        }, 'TestService', 'https://example.com', new NullLogger());

        $this->assertSame($response, $result);
        $this->assertSame(1, $calls);
        $this->assertEmpty($slept);
        $this->assertFalse($service->isRateLimited());
    }

    public function testExecuteWithRateLimitRetryRecoversAfter429(): void
    {
        $service = $this->createService();
        $slept = [];
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $response429 = $this->createMock(ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn(['retry-after' => ['5']]);

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('getStatusCode')->willReturn(200);

        $calls = 0;
        $result = $service->testExecute(function () use (&$calls, $response429, $response200): \PHPUnit\Framework\MockObject\MockObject {
            ++$calls;
            return $calls === 1 ? $response429 : $response200;
        }, 'TestService', 'https://example.com', new NullLogger());

        $this->assertSame($response200, $result);
        $this->assertSame(2, $calls);
        $this->assertSame([5], $slept);
        $this->assertFalse($service->isRateLimited());
    }

    public function testExecuteWithRateLimitRetryExhaustsRetriesAndSetsCooldown(): void
    {
        $service = $this->createService();
        $service->setMaxRetries(2);
        $service->setBaseDelaySeconds(1);
        $service->setDefaultCooldownSeconds(45);

        $slept = [];
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $response429 = $this->createMock(ResponseInterface::class);
        $response429->method('getStatusCode')->willReturn(429);
        $response429->method('getHeaders')->willReturn([]);

        $calls = 0;
        $result = $service->testExecute(function () use (&$calls, $response429): \PHPUnit\Framework\MockObject\MockObject {
            ++$calls;
            return $response429;
        }, 'TestService', 'https://example.com', new NullLogger());

        $this->assertSame($response429, $result);
        // Attempt 0 (sleep 1s), Attempt 1 (sleep 2s), Attempt 2 (exhausted, enters cooldown) -> 3 calls
        $this->assertSame(3, $calls);
        $this->assertSame([1, 2], $slept);
        $this->assertTrue($service->isRateLimited());
        $this->assertNotNull($service->getRateLimitedUntil());
    }

    public function testCooldownWaitsWhenRemainingIsSmall(): void
    {
        $service = $this->createService();
        $service->setMaxCooldownWaitSeconds(30);

        // Set cooldown 5 seconds in future
        $service->setRateLimitedUntil(microtime(true) + 5.0);

        $slept = [];
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $canProceed = $service->testCooldown('TestService', new NullLogger());

        $this->assertTrue($canProceed);
        $this->assertCount(1, $slept);
        $this->assertGreaterThanOrEqual(4, $slept[0]);
        $this->assertFalse($service->isRateLimited());
    }

    public function testCooldownSkipsWhenRemainingExceedsMaxWait(): void
    {
        $service = $this->createService();
        $service->setMaxCooldownWaitSeconds(10);

        // Set cooldown 60 seconds in future
        $service->setRateLimitedUntil(microtime(true) + 60.0);

        $slept = [];
        $service->setSleeper(function ($seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $canProceed = $service->testCooldown('TestService', new NullLogger());

        $this->assertFalse($canProceed);
        $this->assertEmpty($slept);
        $this->assertTrue($service->isRateLimited());
    }
}
