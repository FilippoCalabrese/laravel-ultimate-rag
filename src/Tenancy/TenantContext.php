<?php

declare(strict_types=1);

namespace Sellinnate\RagEngine\Tenancy;

use Sellinnate\RagEngine\Exceptions\RagException;

/**
 * Holds the current tenant id for automatic query scoping (FR-MT-01/02).
 *
 * Registered as a *scoped* binding (reset per request/job lifecycle) so that a
 * tenant set in one request never bleeds into the next under a long-lived worker
 * (Octane/Horizon/RoadRunner) — see NFR-SC-05. Within a unit of work the tenant
 * can be switched explicitly via {@see runAs()}.
 *
 * In **strict mode** (config `rag-engine.tenancy.strict`) reading the tenant
 * before one has been explicitly set throws — preventing silent fallback to the
 * shared `default` tenant, which would co-mingle real tenants on misconfiguration.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private bool $explicit = false;

    public function __construct(
        private readonly string $default = 'default',
        private readonly bool $strict = false,
    ) {
        $this->tenantId = $default;
    }

    public function id(): string
    {
        if ($this->strict && ! $this->explicit) {
            throw new RagException('No tenant has been explicitly set (strict tenancy mode is enabled).');
        }

        return $this->tenantId ?? $this->default;
    }

    public function hasExplicitTenant(): bool
    {
        return $this->explicit;
    }

    public function set(string $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->explicit = true;
    }

    public function forget(): void
    {
        $this->tenantId = $this->default;
        $this->explicit = false;
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
        $previousExplicit = $this->explicit;

        $this->tenantId = $tenantId;
        $this->explicit = true;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
            $this->explicit = $previousExplicit;
        }
    }
}
