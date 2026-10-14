<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\CircuitBreakerState;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Trait providing robust HTTP rate-limiting, adaptive AIMD delay pacing,
 * circuit-breaker protection (skipping failing providers), and retry backoff.
 *
 * Delegates to ProviderResilienceManager for centralized resilience state.
 */
trait RateLimitRetryTrait
{
    private ?ProviderResilienceManagerInterface $resilienceManager = null;
    private string $defaultServiceName = 'default';
    protected int $requestDelayMs = 0;

    /**
     * Optional custom sleeper callback for testing (receives float/int seconds).
     *
     * @var (callable(float|int): void)|null
     */
    private $sleeper;

    protected function sleep(float|int $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
        } else {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    public function getResilienceManager(): ProviderResilienceManagerInterface
    {
        if ($this->resilienceManager === null) {
            $manager = new ProviderResilienceManager();
            if ($this->sleeper !== null) {
                $manager->setSleeper($this->sleeper);
            }
            $this->resilienceManager = $manager;
        }

        return $this->resilienceManager;
    }

    public function setResilienceManager(ProviderResilienceManagerInterface $resilienceManager): static
    {
        $this->resilienceManager = $resilienceManager;
        if ($this->sleeper !== null && $resilienceManager instanceof ProviderResilienceManager) {
            $resilienceManager->setSleeper($this->sleeper);
        }

        return $this;
    }

    public function isAvailable(?string $serviceName = null): bool
    {
        return $this->getResilienceManager()->isAvailable($serviceName ?? $this->defaultServiceName);
    }

    public function getCircuitState(?string $serviceName = null): CircuitBreakerState
    {
        return $this->getResilienceManager()->getCircuitState($serviceName ?? $this->defaultServiceName);
    }

    public function getAdaptiveDelayMs(?string $serviceName = null): int
    {
        return $this->getResilienceManager()->getAdaptiveDelayMs($serviceName ?? $this->defaultServiceName);
    }

    public function resetCircuit(?string $serviceName = null): void
    {
        $this->getResilienceManager()->resetCircuit($serviceName ?? $this->defaultServiceName);
    }

    public function isRateLimited(): bool
    {
        return $this->getResilienceManager()->isRateLimited($this->defaultServiceName);
    }

    public function getRateLimitedUntil(): ?float
    {
        return $this->getResilienceManager()->getRateLimitedUntil($this->defaultServiceName);
    }

    public function setRateLimitedUntil(?float $timestamp): static
    {
        $this->getResilienceManager()->setRateLimitedUntil($this->defaultServiceName, $timestamp);

        return $this;
    }

    public function resetRateLimit(): static
    {
        $this->getResilienceManager()->setRateLimitedUntil($this->defaultServiceName, null);

        return $this;
    }

    public function getMaxRetries(): int
    {
        $manager = $this->getResilienceManager();
        return $manager instanceof ProviderResilienceManager ? $manager->getMaxRetries() : 3;
    }

    public function setMaxRetries(int $retries): static
    {
        $manager = $this->getResilienceManager();
        if ($manager instanceof ProviderResilienceManager) {
            $manager->setMaxRetries($retries);
        }

        return $this;
    }

    public function getBaseDelaySeconds(): int
    {
        $manager = $this->getResilienceManager();
        return $manager instanceof ProviderResilienceManager ? $manager->getBaseRetryDelaySeconds() : 2;
    }

    public function setBaseDelaySeconds(int $delay): static
    {
        $manager = $this->getResilienceManager();
        if ($manager instanceof ProviderResilienceManager) {
            $manager->setBaseRetryDelaySeconds($delay);
        }

        return $this;
    }

    public function getMaxDelaySeconds(): int
    {
        $manager = $this->getResilienceManager();
        return $manager instanceof ProviderResilienceManager ? $manager->getMaxRetryDelaySeconds() : 60;
    }

    public function setMaxDelaySeconds(int $maxDelay): static
    {
        $manager = $this->getResilienceManager();
        if ($manager instanceof ProviderResilienceManager) {
            $manager->setMaxRetryDelaySeconds($maxDelay);
        }

        return $this;
    }

    public function getMaxCooldownWaitSeconds(): int
    {
        $manager = $this->getResilienceManager();
        return $manager instanceof ProviderResilienceManager ? $manager->getMaxCooldownWaitSeconds() : 60;
    }

    public function setMaxCooldownWaitSeconds(int $seconds): static
    {
        $manager = $this->getResilienceManager();
        if ($manager instanceof ProviderResilienceManager) {
            $manager->setMaxCooldownWaitSeconds($seconds);
        }

        return $this;
    }

    public function getDefaultCooldownSeconds(): int
    {
        $manager = $this->getResilienceManager();
        return $manager instanceof ProviderResilienceManager ? $manager->getDefaultCooldownSeconds() : 60;
    }

    public function setDefaultCooldownSeconds(int $seconds): static
    {
        $manager = $this->getResilienceManager();
        if ($manager instanceof ProviderResilienceManager) {
            $manager->setDefaultCooldownSeconds($seconds);
        }

        return $this;
    }

    public function getRequestDelayMs(): int
    {
        return $this->getAdaptiveDelayMs($this->defaultServiceName);
    }

    public function setRequestDelayMs(int $ms): static
    {
        $this->requestDelayMs = max(0, $ms);
        $manager = $this->getResilienceManager();
        if ($manager instanceof ProviderResilienceManager) {
            $manager->setAdaptiveDelayMs($this->defaultServiceName, $ms);
        }

        return $this;
    }

    public function setSleeper(?callable $sleeper): static
    {
        $this->sleeper = $sleeper;
        $manager = $this->getResilienceManager();
        if ($manager instanceof ProviderResilienceManager) {
            $manager->setSleeper($sleeper);
        }

        return $this;
    }

    public function parseRetryAfter(?string $headerValue, int $defaultDelay = 5): int
    {
        $manager = $this->getResilienceManager();
        return $manager instanceof ProviderResilienceManager
            ? $manager->parseRetryAfter($headerValue, $defaultDelay)
            : $defaultDelay;
    }

    public function calculateBackoffDelay(int $attempt, ?string $retryAfterHeader = null): int
    {
        $manager = $this->getResilienceManager();
        return $manager instanceof ProviderResilienceManager
            ? $manager->calculateBackoffDelay($attempt, $retryAfterHeader)
            : 2;
    }

    protected function checkAndHandleCooldown(string $serviceName, LoggerInterface $logger): bool
    {
        return $this->getResilienceManager()->checkAndHandleCooldown($serviceName, $logger);
    }

    protected function executeWithRateLimitRetry(
        callable $requestCallback,
        string $serviceName,
        string $url,
        LoggerInterface $logger
    ): ?ResponseInterface {
        return $this->getResilienceManager()->execute($serviceName, $url, $requestCallback, $logger);
    }

    /**
     * @param array<string, mixed> $httpOptions
     */
    protected function fetchUrlWithRateLimitRetry(
        string $url,
        array $httpOptions,
        string $serviceName,
        LoggerInterface $logger
    ): ?string {
        return $this->getResilienceManager()->fetchUrl($serviceName, $url, $httpOptions, $logger);
    }
}
