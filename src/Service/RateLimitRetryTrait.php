<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Trait providing robust HTTP 429 (Too Many Requests) rate-limiting, retry with backoff,
 * Retry-After header parsing, and circuit-breaker cooldown protection for price loader services.
 */
trait RateLimitRetryTrait
{
    private ?float $rateLimitedUntil = null;
    private int $maxRetries = 3;
    private int $baseDelaySeconds = 2;
    private int $maxDelaySeconds = 60;
    private int $maxCooldownWaitSeconds = 60;
    private int $defaultCooldownSeconds = 60;
    private int $requestDelayMs = 0;

    /**
     * Optional custom sleeper callback for testing (receives float/int seconds).
     *
     * @var (callable(float|int): void)|null
     */
    private $sleeper;

    public function isRateLimited(): bool
    {
        return $this->rateLimitedUntil !== null && microtime(true) < $this->rateLimitedUntil;
    }

    public function getRateLimitedUntil(): ?float
    {
        return $this->rateLimitedUntil;
    }

    public function setRateLimitedUntil(?float $timestamp): static
    {
        $this->rateLimitedUntil = $timestamp;

        return $this;
    }

    public function resetRateLimit(): static
    {
        $this->rateLimitedUntil = null;

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

    public function getBaseDelaySeconds(): int
    {
        return $this->baseDelaySeconds;
    }

    public function setBaseDelaySeconds(int $delay): static
    {
        $this->baseDelaySeconds = max(1, $delay);

        return $this;
    }

    public function getMaxDelaySeconds(): int
    {
        return $this->maxDelaySeconds;
    }

    public function setMaxDelaySeconds(int $maxDelay): static
    {
        $this->maxDelaySeconds = max(1, $maxDelay);

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

    public function getRequestDelayMs(): int
    {
        return $this->requestDelayMs;
    }

    public function setRequestDelayMs(int $ms): static
    {
        $this->requestDelayMs = max(0, $ms);

        return $this;
    }

    public function setSleeper(?callable $sleeper): static
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    /**
     * Parse Retry-After HTTP header value (seconds integer or HTTP-date).
     */
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

    /**
     * Calculate backoff delay in seconds using Retry-After header or exponential backoff.
     */
    public function calculateBackoffDelay(int $attempt, ?string $retryAfterHeader = null): int
    {
        if ($retryAfterHeader !== null && trim($retryAfterHeader) !== '') {
            $delay = $this->parseRetryAfter($retryAfterHeader, $this->baseDelaySeconds);
        } else {
            // Exponential backoff: baseDelay * 2^attempt (e.g. 2s, 4s, 8s...)
            $delay = (int) ($this->baseDelaySeconds * (2 ** $attempt));
        }

        return max(1, min($delay, $this->maxDelaySeconds));
    }

    /**
     * Sleep for given seconds using sleeper callable if set, or usleep.
     */
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

    /**
     * Check if rate-limit cooldown is currently active.
     * If remaining cooldown is within maxCooldownWaitSeconds, waits it out and resets.
     * Otherwise returns false to skip request.
     */
    protected function checkAndHandleCooldown(string $serviceName, LoggerInterface $logger): bool
    {
        if (!$this->isRateLimited()) {
            return true;
        }

        $remaining = (int) ceil($this->rateLimitedUntil - microtime(true));
        if ($remaining <= 0) {
            $this->rateLimitedUntil = null;
            return true;
        }

        if ($remaining <= $this->maxCooldownWaitSeconds) {
            $logger->warning(sprintf(
                'Rate limit cooldown active for %s. Waiting %d seconds for rate limit to reset...',
                $serviceName,
                $remaining
            ), [
                'service' => $serviceName,
                'remaining_seconds' => $remaining,
            ]);

            $this->sleep($remaining);
            $this->rateLimitedUntil = null;
            return true;
        }

        $logger->warning(sprintf(
            'Skipping request to %s: rate limit cooldown active (remaining: %d seconds > max wait: %d seconds).',
            $serviceName,
            $remaining,
            $this->maxCooldownWaitSeconds
        ), [
            'service' => $serviceName,
            'remaining_seconds' => $remaining,
        ]);

        return false;
    }

    /**
     * Execute an HTTP request with automatic 429 rate limit detection, retry with backoff,
     * Retry-After header parsing, and cooldown state tracking.
     */
    protected function executeWithRateLimitRetry(
        callable $requestCallback,
        string $serviceName,
        string $url,
        LoggerInterface $logger
    ): ?ResponseInterface {
        if (!$this->checkAndHandleCooldown($serviceName, $logger)) {
            return null;
        }

        for ($attempt = 0; $attempt <= $this->maxRetries; ++$attempt) {
            try {
                /** @var ResponseInterface $response */
                $response = $requestCallback();
                $statusCode = $response->getStatusCode();
            } catch (\Throwable $e) {
                $logger->warning(sprintf('Exception during request to %s: %s', $serviceName, $e->getMessage()), [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }

            if ($statusCode !== 429) {
                return $response;
            }

            // HTTP 429 encountered
            $headers = $response->getHeaders(false);
            $retryAfterHeader = $headers['retry-after'][0] ?? null;
            $delay = $this->calculateBackoffDelay($attempt, $retryAfterHeader);

            if ($attempt < $this->maxRetries) {
                $logger->warning(sprintf(
                    '%s returned 429 Too Many Requests for %s. Retrying in %d seconds (attempt %d of %d)...',
                    $serviceName,
                    $url,
                    $delay,
                    $attempt + 1,
                    $this->maxRetries
                ), [
                    'service' => $serviceName,
                    'url' => $url,
                    'status' => 429,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries,
                    'retry_after' => $delay,
                ]);

                $this->sleep($delay);
            } else {
                // All retries exhausted -> enter cooldown
                $cooldown = max($delay, $this->defaultCooldownSeconds);
                $this->rateLimitedUntil = microtime(true) + $cooldown;

                $logger->warning(sprintf(
                    '%s rate limit (429) persists for %s after %d retries. Entering cooldown of %d seconds.',
                    $serviceName,
                    $url,
                    $this->maxRetries,
                    $cooldown
                ), [
                    'service' => $serviceName,
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

    /**
     * Fallback HTTP fetch using stream context (file_get_contents) with 429 detection and retry.
     *
     * @param array<string, mixed> $httpOptions
     */
    protected function fetchUrlWithRateLimitRetry(
        string $url,
        array $httpOptions,
        string $serviceName,
        LoggerInterface $logger
    ): ?string {
        if (!$this->checkAndHandleCooldown($serviceName, $logger)) {
            return null;
        }

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

            if ($statusCode !== 429) {
                return ($content !== false && $statusCode === 200) ? $content : null;
            }

            $delay = $this->calculateBackoffDelay($attempt, $retryAfterHeader);

            if ($attempt < $this->maxRetries) {
                $logger->warning(sprintf(
                    '%s returned 429 Too Many Requests for %s. Retrying in %d seconds (attempt %d of %d)...',
                    $serviceName,
                    $url,
                    $delay,
                    $attempt + 1,
                    $this->maxRetries
                ), [
                    'service' => $serviceName,
                    'url' => $url,
                    'status' => 429,
                    'attempt' => $attempt + 1,
                    'max_retries' => $this->maxRetries,
                    'retry_after' => $delay,
                ]);

                $this->sleep($delay);
            } else {
                $cooldown = max($delay, $this->defaultCooldownSeconds);
                $this->rateLimitedUntil = microtime(true) + $cooldown;

                $logger->warning(sprintf(
                    '%s rate limit (429) persists for %s after %d retries. Entering cooldown of %d seconds.',
                    $serviceName,
                    $url,
                    $this->maxRetries,
                    $cooldown
                ), [
                    'service' => $serviceName,
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
}
