# Security Policy

RAG Engine handles sensitive content (documents, embeddings, encryption keys,
PII), so we take security seriously and welcome responsible disclosure.

## Supported versions

Security fixes are provided for the latest `1.x` release line.

| Version | Supported |
|---------|-----------|
| 1.x     | ✅        |
| < 1.0   | ❌        |

## Reporting a vulnerability

**Please do not open public GitHub issues for security vulnerabilities.**

Report privately to **security@selli.io** (or `filippo@selli.io`), or use GitHub's
[private vulnerability reporting](https://github.com/Sellinnate/laravel-ultimate-rag/security/advisories/new).

Please include:

- a description of the issue and its impact;
- steps to reproduce (a minimal proof of concept if possible);
- affected version(s) and environment.

We aim to acknowledge reports within **3 business days** and to ship a fix or
mitigation as quickly as the severity warrants. We'll credit you in the release
notes unless you prefer to remain anonymous.

## Scope & built-in protections

The engine ships with several security measures (see the
[Security docs](https://laravel-rag-engine.selli.io/concepts/security)):

- **BYOK envelope encryption** of content at rest, with **crypto-shredding** for
  the right to erasure.
- **PII redaction** before content reaches an embedding provider or the index.
- **Fail-closed multi-tenancy** — every query is scoped to the current tenant.
- **Tamper-evident WORM audit log**.
- **SSRF-guarded** URL ingestion and hardened parsers (XXE, zip-bomb).

Issues that undermine any of these guarantees — cross-tenant data leakage, key
recovery after crypto-shredding, PII reaching providers/index, SSRF/XXE bypasses,
audit-log tampering — are in scope and high priority.

## Hardening reminders for operators

- Set provider **API keys via `.env`**, never in committed config.
- Use a **real KMS** in production (cloud KMS), not the `local` dev driver.
- Enable **at-rest encryption on your vector store** (vectors are not BYOK-encrypted).
- Keep **PII redaction** and **encryption** enabled unless you have a reviewed reason.
