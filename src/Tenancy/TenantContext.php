<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Tenancy;

/**
 * Holds the current tenant id for automatic query scoping (FR-MT-01/02).
 *
 * Registered as a *scoped* binding (reset per request/job lifecycle) so that a
 * tenant set in one request never bleeds into the next under a long-lived worker
 * (Octane/Horizon/RoadRunner) — see NFR-SC-05. Within a unit of work the tenant
 * can be switched explicitly via {@see runAs()}.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    public function __construct(private readonly string $default = 'default')
    {
        $this->tenantId = $default;
    }

    public function id(): string
    {
        return $this->tenantId ?? $this->default;
    }

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function forget(): void
    {
        $this->tenantId = $this->default;
    }

    /**
     * Run a callback scoped to a tenant, restoring the previous tenant after.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(string $tenantId, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
