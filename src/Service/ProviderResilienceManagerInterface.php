<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\CircuitBreakerState;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

interface ProviderResilienceManagerInterface
{
    /**
     * Check whether requests to this provider are currently allowed (circuit not OPEN).
     */
    public function isAvailable(string $provider): bool;

    /**
     * Check whether a rate-limit cooldown is currently active for this provider.
     */
    public function isRateLimited(string $provider = 'default'): bool;

    /**
     * Get timestamp until which the provider is rate limited.
     */
    public function getRateLimitedUntil(string $provider = 'default'): ?float;

    /**
     * Set timestamp until which the provider is rate limited.
     */
    public function setRateLimitedUntil(string $provider, ?float $timestamp): static;

    /**
     * Check and handle active rate limit cooldown.
     */
    public function checkAndHandleCooldown(string $provider, LoggerInterface $logger): bool;

    /**
     * Retrieve current circuit breaker state for a provider (CLOSED, OPEN, HALF_OPEN).
     */
    public function getCircuitState(string $provider): CircuitBreakerState;

    /**
     * Get the current adaptive inter-request delay in milliseconds.
     */
    public function getAdaptiveDelayMs(string $provider): int;

    /**
     * Manually trip the circuit breaker for a provider.
     */
    public function tripCircuit(string $provider, string $reason, ?int $cooldownSeconds = null): void;

    /**
     * Reset circuit breaker and errors for a provider.
     */
    public function resetCircuit(string $provider): void;

    /**
     * Record a successful response (HTTP 200) to probe for additive decrease in delay.
     */
    public function recordSuccess(string $provider): void;

    /**
     * Record a rate-limited response (HTTP 429) to trigger multiplicative increase in delay.
     */
    public function recordRateLimited(string $provider, ?int $retryAfterSeconds = null): void;

    /**
     * Record an authorization or bot-protection block (HTTP 403) and trip circuit if threshold reached.
     */
    public function recordForbidden(string $provider, ?string $url = null): void;

    /**
     * Record a server error (5xx) or connection failure.
     */
    public function recordServerError(string $provider, int $statusCode, ?string $url = null): void;

    /**
     * Enforce adaptive inter-request pacing delay prior to request execution.
     */
    public function waitPacing(string $provider, ?LoggerInterface $logger = null): void;

    /**
     * Execute an HTTP request with circuit-breaker checking, adaptive pacing, and retry on 429.
     */
    public function execute(
        string $provider,
        string $url,
        callable $requestCallback,
        LoggerInterface $logger
    ): ?ResponseInterface;

    /**
     * Stream context fallback execution with circuit-breaker checking, adaptive pacing, and retry on 429.
     *
     * @param array<string, mixed> $httpOptions
     */
    public function fetchUrl(
        string $provider,
        string $url,
        array $httpOptions,
        LoggerInterface $logger
    ): ?string;
}
