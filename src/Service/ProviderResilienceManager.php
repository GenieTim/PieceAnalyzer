<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\CircuitBreakerState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Central resilience manager for external providers.
 *
 * Implements:
 * 1. Circuit Breaker: skips providers after repeated fatal failures (e.g., 403 Forbidden).
 * 2. Adaptive Inter-Request Pacing (AIMD): automatically converges on the provider's rate limit sweet spot.
 * 3. Rate Limit Retry & Backoff: handles 429 with Retry-After header and exponential backoff.
 */
class ProviderResilienceManager implements ProviderResilienceManagerInterface
{
    /**
     * @var array<string, array{
     *     circuitState: CircuitBreakerState,
     *     circuitOpenUntil: ?float,
     *     trippedReason: ?string,
     *     consecutive403Count: int,
     *     consecutiveServerErrors: int,
     *     consecutiveSuccesses: int,
     *     pacingDelayMs: int,
     *     lastRequestTime: ?float,
     *     rateLimitedUntil: ?float
     * }>
     */
    private array $providers = [];

    private int $initialPacingDelayMs = 500;
    private int $minPacingDelayMs = 200;
    private int $maxPacingDelayMs = 30000;
    private int $decreaseStepMs = 100;
    private float $increaseFactor = 1.5;
    private int $successThresholdForDecrease = 3;

    private int $forbiddenThreshold = 3;
    private int $forbiddenCooldownSeconds = 3600;
    private int $serverErrorThreshold = 5;
    private int $serverErrorCooldownSeconds = 300;

    private int $maxRetries = 3;
    private int $baseRetryDelaySeconds = 2;
    private int $maxRetryDelaySeconds = 60;
    private int $maxCooldownWaitSeconds = 60;
    private int $defaultCooldownSeconds = 60;

    /**
     * Optional custom sleeper callback for testing.
     *
     * @var (callable(float|int): void)|null
     */
    private $sleeper;

    /**
     * @return array{
     *     circuitState: CircuitBreakerState,
     *     circuitOpenUntil: ?float,
     *     trippedReason: ?string,
     *     consecutive403Count: int,
     *     consecutiveServerErrors: int,
     *     consecutiveSuccesses: int,
     *     pacingDelayMs: int,
     *     lastRequestTime: ?float,
     *     rateLimitedUntil: ?float
     * }
     */
    private function &getProviderState(string $provider): array
    {
        $key = strtolower(trim($provider));
        if (!isset($this->providers[$key])) {
            $defaultRateLimitedUntil = $this->providers['default']['rateLimitedUntil'] ?? null;
            $this->providers[$key] = [
                'circuitState' => CircuitBreakerState::CLOSED,
                'circuitOpenUntil' => null,
                'trippedReason' => null,
                'consecutive403Count' => 0,
                'consecutiveServerErrors' => 0,
                'consecutiveSuccesses' => 0,
                'pacingDelayMs' => $this->initialPacingDelayMs,
                'lastRequestTime' => null,
                'rateLimitedUntil' => $defaultRateLimitedUntil,
            ];
        }

        return $this->providers[$key];
    }

    #[\Override]
    public function isAvailable(string $provider): bool
    {
        $state = &$this->getProviderState($provider);

        if ($state['circuitState'] === CircuitBreakerState::OPEN) {
            $now = microtime(true);
            if ($state['circuitOpenUntil'] !== null && $now >= $state['circuitOpenUntil']) {
                $state['circuitState'] = CircuitBreakerState::HALF_OPEN;
                return true;
            }

            return false;
        }

        return true;
    }

    #[\Override]
    public function isRateLimited(string $provider = 'default'): bool
    {
        $state = $this->getProviderState($provider);
        if ($state['rateLimitedUntil'] !== null && microtime(true) < $state['rateLimitedUntil']) {
            return true;
        }

        if (strtolower(trim($provider)) === 'default') {
            foreach ($this->providers as $pState) {
                if ($pState['rateLimitedUntil'] !== null && microtime(true) < $pState['rateLimitedUntil']) {
                    return true;
                }
            }
        }

        return false;
    }

    #[\Override]
    public function getRateLimitedUntil(string $provider = 'default'): ?float
    {
        $state = $this->getProviderState($provider);
        if ($state['rateLimitedUntil'] !== null) {
            return $state['rateLimitedUntil'];
        }

        if (strtolower(trim($provider)) === 'default') {
            foreach ($this->providers as $pState) {
                if ($pState['rateLimitedUntil'] !== null && microtime(true) < $pState['rateLimitedUntil']) {
                    return $pState['rateLimitedUntil'];
                }
            }
        }

        return null;
    }

    #[\Override]
    public function setRateLimitedUntil(string $provider = 'default', ?float $timestamp = null): static
    {
        $state = &$this->getProviderState($provider);
        $state['rateLimitedUntil'] = $timestamp;

        if (strtolower(trim($provider)) === 'default') {
            foreach ($this->providers as &$pState) {
                $pState['rateLimitedUntil'] = $timestamp;
            }
        }

        return $this;
    }

    public function getMaxCooldownWaitSeconds(): int
    {
        return $this->maxCooldownWaitSeconds;
    }

    public function setMaxCooldownWaitSeconds(int $seconds): static
    {
        $this->maxCooldownWaitSeconds = max(0, $seconds);

        return $this;
    }

    public function getDefaultCooldownSeconds(): int
    {
        return $this->defaultCooldownSeconds;
    }

    public function setDefaultCooldownSeconds(int $seconds): static
    {
        $this->defaultCooldownSeconds = max(1, $seconds);

        return $this;
    }

    #[\Override]
    public function checkAndHandleCooldown(string $provider, LoggerInterface $logger): bool
    {
        if (!$this->isRateLimited($provider)) {
            return true;
        }

        $state = &$this->getProviderState($provider);
        $remaining = (int) ceil(($state['rateLimitedUntil'] ?? microtime(true)) - microtime(true));
        if ($remaining <= 0) {
            $state['rateLimitedUntil'] = null;
            return true;
        }

        if ($remaining <= $this->maxCooldownWaitSeconds) {
            $logger->warning(sprintf(
                'Rate limit cooldown active for %s. Waiting %d seconds for rate limit to reset...',
                $provider,
                $remaining
            ), [
                'provider' => $provider,
                'remaining_seconds' => $remaining,
            ]);

            $this->sleep($remaining);
            $state['rateLimitedUntil'] = null;
            if (isset($this->providers['default'])) {
                $this->providers['default']['rateLimitedUntil'] = null;
            }
            return true;
        }

        $logger->warning(sprintf(
            'Skipping request to %s: rate limit cooldown active (remaining: %d seconds > max wait: %d seconds).',
            $provider,
            $remaining,
            $this->maxCooldownWaitSeconds
        ), [
            'provider' => $provider,
            'remaining_seconds' => $remaining,
        ]);

        return false;
    }

    #[\Override]
    public function getCircuitState(string $provider): CircuitBreakerState
    {
        $state = &$this->getProviderState($provider);
        if ($state['circuitState'] === CircuitBreakerState::OPEN && ($state['circuitOpenUntil'] !== null && microtime(true) >= $state['circuitOpenUntil'])) {
            $state['circuitState'] = CircuitBreakerState::HALF_OPEN;
        }

        return $state['circuitState'];
    }

    public function getTrippedReason(string $provider): ?string
    {
        return $this->getProviderState($provider)['trippedReason'];
    }

    public function getCircuitOpenUntil(string $provider): ?float
    {
        return $this->getProviderState($provider)['circuitOpenUntil'];
    }

    #[\Override]
    public function getAdaptiveDelayMs(string $provider): int
    {
        return $this->getProviderState($provider)['pacingDelayMs'];
    }

    public function setAdaptiveDelayMs(string $provider, int $delayMs): static
    {
        $state = &$this->getProviderState($provider);
        $state['pacingDelayMs'] = max($this->minPacingDelayMs, min($this->maxPacingDelayMs, $delayMs));

        return $this;
    }

    #[\Override]
    public function tripCircuit(string $provider, string $reason, ?int $cooldownSeconds = null): void
    {
        $cooldown = $cooldownSeconds ?? $this->forbiddenCooldownSeconds;
        $state = &$this->getProviderState($provider);
        $state['circuitState'] = CircuitBreakerState::OPEN;
        $state['circuitOpenUntil'] = microtime(true) + $cooldown;
        $state['trippedReason'] = $reason;
    }

    #[\Override]
    public function resetCircuit(string $provider): void
    {
        $state = &$this->getProviderState($provider);
        $state['circuitState'] = CircuitBreakerState::CLOSED;
        $state['circuitOpenUntil'] = null;
        $state['trippedReason'] = null;
        $state['consecutive403Count'] = 0;
        $state['consecutiveServerErrors'] = 0;
        $state['rateLimitedUntil'] = null;
    }

    #[\Override]
    public function recordSuccess(string $provider): void
    {
        $state = &$this->getProviderState($provider);

        if ($state['circuitState'] === CircuitBreakerState::HALF_OPEN) {
            $state['circuitState'] = CircuitBreakerState::CLOSED;
            $state['trippedReason'] = null;
        }

        $state['consecutive403Count'] = 0;
        $state['consecutiveServerErrors'] = 0;
        $state['rateLimitedUntil'] = null;
        ++$state['consecutiveSuccesses'];

        // AIMD Additive Decrease: cautiously lower pacing delay towards the floor
        if ($state['consecutiveSuccesses'] >= $this->successThresholdForDecrease) {
            $state['consecutiveSuccesses'] = 0;
            if ($state['pacingDelayMs'] > $this->minPacingDelayMs) {
                $state['pacingDelayMs'] = max(
                    $this->minPacingDelayMs,
                    $state['pacingDelayMs'] - $this->decreaseStepMs
                );
            }
        }
    }

    #[\Override]
    public function recordRateLimited(string $provider, ?int $retryAfterSeconds = null): void
    {
        $state = &$this->getProviderState($provider);
        $state['consecutiveSuccesses'] = 0;

        // AIMD Multiplicative Increase: quickly back off pacing delay
        $newDelayMs = (int) ceil($state['pacingDelayMs'] * $this->increaseFactor);
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $newDelayMs = max($newDelayMs, $retryAfterSeconds * 1000);
        }

        $state['pacingDelayMs'] = max(
            $this->minPacingDelayMs,
            min($this->maxPacingDelayMs, $newDelayMs)
        );

        $cooldown = $retryAfterSeconds ?? $this->baseRetryDelaySeconds;
        $state['rateLimitedUntil'] = microtime(true) + $cooldown;
    }

    #[\Override]
    public function recordForbidden(string $provider, ?string $url = null): void
    {
        $state = &$this->getProviderState($provider);
        $state['consecutiveSuccesses'] = 0;
        ++$state['consecutive403Count'];

        if ($state['consecutive403Count'] >= $this->forbiddenThreshold) {
            $reason = sprintf(
                '%d consecutive 403 Forbidden responses (last url: %s)',
                $state['consecutive403Count'],
                $url ?? 'unknown'
            );
            $this->tripCircuit($provider, $reason, $this->forbiddenCooldownSeconds);
        }
    }

    #[\Override]
    public function recordServerError(string $provider, int $statusCode, ?string $url = null): void
    {
        $state = &$this->getProviderState($provider);
        $state['consecutiveSuccesses'] = 0;
        ++$state['consecutiveServerErrors'];

        if ($state['consecutiveServerErrors'] >= $this->serverErrorThreshold) {
            $reason = sprintf(
                '%d consecutive server errors (last status: %d, url: %s)',
                $state['consecutiveServerErrors'],
                $statusCode,
                $url ?? 'unknown'
            );
            $this->tripCircuit($provider, $reason, $this->serverErrorCooldownSeconds);
        }
    }

    #[\Override]
    public function waitPacing(string $provider, ?LoggerInterface $logger = null): void
    {
        $state = &$this->getProviderState($provider);
        $now = microtime(true);

        // 1. Enforce adaptive inter-request delay
        if ($state['lastRequestTime'] !== null) {
            $elapsedMs = ($now - $state['lastRequestTime']) * 1000;
            $neededMs = (float) $state['pacingDelayMs'];
            if ($elapsedMs < $neededMs) {
                $sleepSeconds = ($neededMs - $elapsedMs) / 1000;
                $this->sleep($sleepSeconds);
            }
        }

        // 2. Enforce active 429 rate limit cooldown if within max cooldown wait
        if ($state['rateLimitedUntil'] !== null) {
            $remaining = $state['rateLimitedUntil'] - microtime(true);
            if ($remaining > 0) {
                if ($remaining <= $this->maxCooldownWaitSeconds) {
                    if ($logger instanceof LoggerInterface) {
                        $logger->info(sprintf(
                            'Provider %s rate-limited; waiting %d seconds cooldown...',
                            $provider,
                            (int) ceil($remaining)
                        ));
                    }
                    $this->sleep($remaining);
                    $state['rateLimitedUntil'] = null;
                }
            } else {
                $state['rateLimitedUntil'] = null;
            }
        }

        $state['lastRequestTime'] = microtime(true);
    }

    #[\Override]
    public function execute(
        string $provider,
        string $url,
        callable $requestCallback,
        LoggerInterface $logger
    ): ?ResponseInterface {
        if (!$this->isAvailable($provider)) {
            $state = $this->getProviderState($provider);
            $logger->warning(sprintf(
                'Skipping request to %s (%s): Circuit breaker is %s (%s).',
                $provider,
                $url,
                $state['circuitState']->value,
                $state['trippedReason'] ?? 'Threshold exceeded'
            ));
            return null;
        }

        $this->waitPacing($provider, $logger);

        for ($attempt = 0; $attempt <= $this->maxRetries; ++$attempt) {
            try {
                /** @var ResponseInterface $response */
                $response = $requestCallback();
                $statusCode = $response->getStatusCode();
            } catch (\Throwable $e) {
                $logger->warning(sprintf('Exception during request to %s: %s', $provider, $e->getMessage()), [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                $this->recordServerError($provider, 0, $url);
                return null;
            }

            if ($statusCode === 200) {
                $this->recordSuccess($provider);
                return $response;
            }

            if ($statusCode === 403) {
                $this->recordForbidden($provider, $url);
                $state = $this->getProviderState($provider);
                if ($state['circuitState'] === CircuitBreakerState::OPEN) {
                    $logger->error(sprintf(
                        'Circuit breaker TRIPPED for %s after %d 403s. Cooldown: %ds.',
                        $provider,
                        $state['consecutive403Count'],
                        $this->forbiddenCooldownSeconds
                    ), ['url' => $url]);
                }
                return $response;
            }

            if ($statusCode >= 500) {
                $this->recordServerError($provider, $statusCode, $url);
                return $response;
            }

            if ($statusCode !== 429) {
                return $response;
            }

            // 429 Rate Limit encountered
            $headers = $response->getHeaders(false);
            $retryAfterHeader = $headers['retry-after'][0] ?? null;
            $retryAfterSec = $this->parseRetryAfter($retryAfterHeader);
            $this->recordRateLimited($provider, $retryAfterSec);

            $delay = $this->calculateBackoffDelay($attempt, $retryAfterHeader);

            if ($attempt < $this->maxRetries) {
                $logger->warning(sprintf(
                    '%s returned 429 for %s. Adaptive delay: %dms. Retrying in %ds (attempt %d/%d)...',
                    $provider,
                    $url,
                    $this->getAdaptiveDelayMs($provider),
                    $delay,
                    $attempt + 1,
                    $this->maxRetries
                ), [
                    'provider' => $provider,
                    'url' => $url,
                    'status' => 429,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries,
                    'retry_after' => $delay,
                ]);

                $this->sleep($delay);
            } else {
                $cooldown = max($delay, $this->defaultCooldownSeconds);
                $state = &$this->getProviderState($provider);
                $state['rateLimitedUntil'] = microtime(true) + $cooldown;

                $logger->warning(sprintf(
                    '%s rate limit (429) persists for %s after %d retries. Entering cooldown of %d seconds.',
                    $provider,
                    $url,
                    $this->maxRetries,
                    $cooldown
                ), [
                    'provider' => $provider,
                    'url' => $url,
                    'status' => 429,
                    'retries' => $this->maxRetries,
                    'cooldown_seconds' => $cooldown,
                ]);

                return $response;
            }
        }

        return null;
    }

    #[\Override]
    public function fetchUrl(
        string $provider,
        string $url,
        array $httpOptions,
        LoggerInterface $logger
    ): ?string {
        if (!$this->isAvailable($provider)) {
            $state = $this->getProviderState($provider);
            $logger->warning(sprintf(
                'Skipping request to %s (%s): Circuit breaker is %s (%s).',
                $provider,
                $url,
                $state['circuitState']->value,
                $state['trippedReason'] ?? 'Threshold exceeded'
            ));
            return null;
        }

        $this->waitPacing($provider, $logger);

        for ($attempt = 0; $attempt <= $this->maxRetries; ++$attempt) {
            $context = stream_context_create(['http' => $httpOptions]);
            $http_response_header = [];
            $content = @file_get_contents($url, false, $context);

            $statusCode = 200;
            $retryAfterHeader = null;
            if (
                isset($http_response_header[0])
                && preg_match('{HTTP/\S*\s+(\d{3})}', $http_response_header[0], $match)
            ) {
                $statusCode = (int) $match[1];
            }
            foreach ($http_response_header as $headerLine) {
                if (stripos($headerLine, 'Retry-After:') === 0) {
                    $retryAfterHeader = trim(substr($headerLine, 12));
                }
            }

            if ($statusCode === 200 && $content !== false) {
                $this->recordSuccess($provider);
                return $content;
            }

            if ($statusCode === 403) {
                $this->recordForbidden($provider, $url);
                $state = $this->getProviderState($provider);
                if ($state['circuitState'] === CircuitBreakerState::OPEN) {
                    $logger->error(sprintf(
                        'Circuit breaker TRIPPED for %s after %d 403s. Cooldown: %ds.',
                        $provider,
                        $state['consecutive403Count'],
                        $this->forbiddenCooldownSeconds
                    ), ['url' => $url]);
                }
                return null;
            }

            if ($statusCode >= 500) {
                $this->recordServerError($provider, $statusCode, $url);
                return null;
            }

            if ($statusCode !== 429) {
                return ($content !== false && $statusCode === 200) ? $content : null;
            }

            $retryAfterSec = $this->parseRetryAfter($retryAfterHeader);
            $this->recordRateLimited($provider, $retryAfterSec);

            $delay = $this->calculateBackoffDelay($attempt, $retryAfterHeader);

            if ($attempt < $this->maxRetries) {
                $logger->warning(sprintf(
                    '%s returned 429 for %s. Adaptive delay: %dms. Retrying in %ds (attempt %d/%d)...',
                    $provider,
                    $url,
                    $this->getAdaptiveDelayMs($provider),
                    $delay,
                    $attempt + 1,
                    $this->maxRetries
                ), [
                    'provider' => $provider,
                    'url' => $url,
                    'status' => 429,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries,
                    'retry_after' => $delay,
                ]);

                $this->sleep($delay);
            } else {
                $cooldown = max($delay, $this->defaultCooldownSeconds);
                $state = &$this->getProviderState($provider);
                $state['rateLimitedUntil'] = microtime(true) + $cooldown;

                $logger->warning(sprintf(
                    '%s rate limit (429) persists for %s after %d retries. Entering cooldown of %d seconds.',
                    $provider,
                    $url,
                    $this->maxRetries,
                    $cooldown
                ), [
                    'provider' => $provider,
                    'url' => $url,
                    'status' => 429,
                    'retries' => $this->maxRetries,
                    'cooldown_seconds' => $cooldown,
                ]);

                return null;
            }
        }

        return null;
    }

    public function parseRetryAfter(?string $headerValue, int $defaultDelay = 5): int
    {
        if ($headerValue === null || trim($headerValue) === '') {
            return $defaultDelay;
        }

        $headerValue = trim($headerValue);
        if (is_numeric($headerValue)) {
            return max(1, (int) ceil((float) $headerValue));
        }

        $timestamp = strtotime($headerValue);
        if ($timestamp !== false) {
            $diff = $timestamp - time();
            return max(1, $diff);
        }

        return $defaultDelay;
    }

    public function calculateBackoffDelay(int $attempt, ?string $retryAfterHeader = null): int
    {
        if ($retryAfterHeader !== null && trim($retryAfterHeader) !== '') {
            $delay = $this->parseRetryAfter($retryAfterHeader, $this->baseRetryDelaySeconds);
        } else {
            $delay = (int) ($this->baseRetryDelaySeconds * (2 ** $attempt));
        }

        return max(1, min($delay, $this->maxRetryDelaySeconds));
    }

    protected function sleep(float|int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }

    public function setSleeper(?callable $sleeper): static
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function setMaxRetries(int $retries): static
    {
        $this->maxRetries = max(0, $retries);

        return $this;
    }

    public function getBaseRetryDelaySeconds(): int
    {
        return $this->baseRetryDelaySeconds;
    }

    public function setBaseRetryDelaySeconds(int $delay): static
    {
        $this->baseRetryDelaySeconds = max(1, $delay);

        return $this;
    }

    public function getMaxRetryDelaySeconds(): int
    {
        return $this->maxRetryDelaySeconds;
    }

    public function setMaxRetryDelaySeconds(int $maxDelay): static
    {
        $this->maxRetryDelaySeconds = max(1, $maxDelay);

        return $this;
    }

    public function getForbiddenThreshold(): int
    {
        return $this->forbiddenThreshold;
    }

    public function setForbiddenThreshold(int $threshold): static
    {
        $this->forbiddenThreshold = max(1, $threshold);

        return $this;
    }

    public function getForbiddenCooldownSeconds(): int
    {
        return $this->forbiddenCooldownSeconds;
    }

    public function setForbiddenCooldownSeconds(int $seconds): static
    {
        $this->forbiddenCooldownSeconds = max(1, $seconds);

        return $this;
    }

    public function getInitialPacingDelayMs(): int
    {
        return $this->initialPacingDelayMs;
    }

    public function setInitialPacingDelayMs(int $ms): static
    {
        $this->initialPacingDelayMs = max(0, $ms);

        return $this;
    }

    public function getMinPacingDelayMs(): int
    {
        return $this->minPacingDelayMs;
    }

    public function setMinPacingDelayMs(int $ms): static
    {
        $this->minPacingDelayMs = max(0, $ms);

        return $this;
    }

    public function getMaxPacingDelayMs(): int
    {
        return $this->maxPacingDelayMs;
    }

    public function setMaxPacingDelayMs(int $ms): static
    {
        $this->maxPacingDelayMs = max(1, $ms);

        return $this;
    }

    public function getDecreaseStepMs(): int
    {
        return $this->decreaseStepMs;
    }

    public function setDecreaseStepMs(int $ms): static
    {
        $this->decreaseStepMs = max(1, $ms);

        return $this;
    }

    public function getIncreaseFactor(): float
    {
        return $this->increaseFactor;
    }

    public function setIncreaseFactor(float $factor): static
    {
        $this->increaseFactor = max(1.1, $factor);

        return $this;
    }

    public function getSuccessThresholdForDecrease(): int
    {
        return $this->successThresholdForDecrease;
    }

    public function setSuccessThresholdForDecrease(int $count): static
    {
        $this->successThresholdForDecrease = max(1, $count);

        return $this;
    }
}
