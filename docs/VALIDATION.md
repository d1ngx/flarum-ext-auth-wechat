# Validation record — 2026-10-22

## Completed

- PHP 8.5.12 with Flarum 2.0.2 / FoF OAuth 2.0.0 installed classes: **26 tests, 0 failures**.
- Independent clean Composer installation of this extension's dependencies: **26 tests, 0 failures**. No forum configuration or production database loaded.
- `composer validate --strict`: passed.
- HTTP exchanges mocked; identity continuity and binding conflict tests use real framework models with in-memory SQLite.
- Legacy provider identifier and `/auth/wechat` route remain compatible by design. The resource owner returns UnionID, not OpenID.

## Not yet accepted

- Real WeChat QR authorization, current application permissions and callback domain.
- Browser-level login/registration and account security UI acceptance.
- Production enablement: not performed.
- PHP 8.3 / 8.4 matrix execution: not confirmed. Workflow is provided for those versions.

## GitHub Actions

The initial push produced `startup_failure` before any job or log was created. Actions is enabled and the Tests workflow is registered. The API refused rerunning that startup failure. This is not a passing CI result, and the available diagnostics do not identify the underlying cause.

An independent PHP 8.5 clean-install run provides executable validation while Actions startup remains unresolved. The workflow supports manual dispatch for future reruns. Do not describe the PHP matrix or real WeChat callback as verified until those checks actually run.
