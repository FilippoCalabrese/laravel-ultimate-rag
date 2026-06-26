---
title: "Multi-tenancy"
description: "Tenant isolation and automatic query scoping."
---

# Multi-tenancy

Every document, chunk, vector and operation carries a `tenant_id`. Isolation is
**namespace-per-tenant** by default, with schema- or database-per-tenant
available as enterprise options.

## The tenant context

`TenantContext` holds the current tenant for the unit of work. It is registered
as a **scoped** binding, so under long-lived workers (Octane, Horizon,
RoadRunner) the tenant set in one request or job never bleeds into the next.

```php
use Sellinnate\RagEngine\Facades\Rag;

Rag::tenant()->id();            // 'default'
Rag::tenant()->set('tenant-7');

// Run a closure scoped to a tenant; the previous tenant is restored after.
$result = Rag::forTenant('tenant-9', function () {
    return Rag::tenant()->id(); // 'tenant-9'
});
```

## Scoping is fail-closed at the retrieval layer

The vector store is a low-level primitive — like a raw SQL table, it applies
only the filters it is handed. Mandatory tenant scoping is enforced one level up,
in the retrieval layer, which always injects the current tenant from
`TenantContext`. Tenant identity is compared **strictly**, so numeric-string ids
such as `'100'` and `'1e2'` can never collide and leak across tenants.

::: callout info "Isolation as a tested invariant"
Cross-tenant isolation is not just a design intent — the suite includes tests
that attempt to leak data across tenants (including via numeric-string id
coercion) and assert that it does not happen.
:::

## Quotas

Per-tenant quotas (max documents, corpus size, embedding-token budget) are part
of the configuration and are enforced during ingestion, with soft warnings and
hard limits.
