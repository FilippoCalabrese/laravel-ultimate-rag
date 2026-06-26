---
title: "Multi-tenancy"
description: "Serve many customers from one installation while keeping each tenant's data strictly isolated — automatically."
---

# Multi-tenancy

If your app serves multiple customers (or workspaces, or teams) from one
installation, you need **multi-tenancy**: each tenant's data must be invisible to
every other tenant. The engine builds this in — every document, chunk, vector and
query carries a `tenant_id`, and isolation is enforced automatically.

::: callout info "In plain words"
A *tenant* is one isolated customer. Multi-tenancy means tenant A can never see,
search, or leak into tenant B's data — even though they share the same database
and code. You don't have to remember to filter by tenant on every query; the
engine does it for you, and refuses to let you turn it off.
:::

## How isolation works

Isolation is **namespace-per-tenant** by default: each tenant's vectors live in
their own named bucket in the vector store, so a search physically can't reach
another tenant's vectors. Schema-per-tenant and database-per-tenant are available
as stricter enterprise options (`rag-engine.tenancy.isolation`).

## The tenant context

`TenantContext` holds "who is the current tenant" for the current unit of work.
It's registered as a **scoped** binding, which matters under long-lived workers
(Octane, Horizon, RoadRunner): the tenant set in one request or job **never bleeds
into the next**.

```php
use Sellinnate\RagEngine\Facades\Rag;

Rag::tenant()->id();              // 'default'
Rag::tenant()->set('tenant-7');   // change the current tenant

// Run a closure as a specific tenant; the previous tenant is restored afterwards:
$answer = Rag::forTenant('tenant-9', function () {
    Rag::tenant()->id();          // 'tenant-9'
    return Rag::search('q')->get();
});
// back to whatever the tenant was before
```

::: callout tip "Where to set the tenant"
Set the tenant once, early in the request — typically in middleware, from the
authenticated user's account/organisation. After that, every `Rag::` call is
automatically scoped. For background jobs, pass the `tenant_id` into the job (as
`ProcessDocumentJob` does) and set it at the start of `handle()`.
:::

### Example: tenant middleware

```php
namespace App\Http\Middleware;

use Closure;
use Sellinnate\RagEngine\Facades\Rag;

class SetRagTenant
{
    public function handle($request, Closure $next)
    {
        if ($user = $request->user()) {
            Rag::tenant()->set((string) $user->organization_id);
        }

        return $next($request);
    }
}
```

## Isolation is fail-closed at the retrieval layer

The vector store is a low-level primitive — like a raw SQL table, it applies only
the filters it's handed. Mandatory tenant scoping is enforced one level up, in the
**retrieval layer**, which always injects the current tenant from `TenantContext`.

Tenant identity is compared **strictly** (as exact strings), so numeric-looking
ids such as `'100'` and `'1e2'` can never be coerced into matching and leaking
across tenants.

::: callout warning "You cannot widen scope from a query"
A `tenant_id` you put in `->where(...)` does **not** override the ambient tenant —
the engine overwrites it with the current one. There is deliberately no way to
search across tenants from the query builder. Cross-tenant access requires an
explicit, server-side `Rag::forTenant(...)`.
:::

::: callout info "Isolation is a tested invariant"
This isn't just design intent. The test suite actively *attempts* to leak data
across tenants — including via numeric-string id coercion — and asserts that it
cannot happen.
:::

## Quotas

Per-tenant quotas (max documents, corpus size, embedding-token budget) live in
config and are enforced during ingestion, with soft warnings and hard limits.
They cap cost and prevent one tenant from exhausting shared resources. See
**[Orchestration → Quotas](/concepts/orchestration#quotas)** and
**[Configuration](/getting-started/configuration#multi-tenancy)**.

## Best practices

- **Set the tenant in middleware** from the authenticated user — once, not per
  call.
- **Always pass `tenant_id` into queued jobs** and set it at the top of
  `handle()`.
- **Never expose a tenant selector to end users** — derive the tenant server-side
  from auth.
- **Use `forTenant()` only in trusted, server-side admin paths** for legitimate
  cross-tenant work.
- **Set quotas per tenant** to bound cost and abuse.

## Next

- **[Security & BYOK](/concepts/security)** — per-tenant encryption and erasure.
- **[Orchestration & jobs](/concepts/orchestration)** — tenant-scoped background
  work.
