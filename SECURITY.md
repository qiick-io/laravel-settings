# Security policy

## Supported versions

Security fixes land on `develop` and ship in the next `0.1.0-beta.*`; older betas are not patched, so upgrade to the latest. From `1.0.0` the supported lines are listed here.

## Reporting a vulnerability

Use GitHub private vulnerability reporting on the repository's Security tab. Include the affected version or commit, the impact and a minimal reproduction with synthetic data. Expect acknowledgement within 72 hours. [Report a vulnerability privately](https://github.com/qiick-io/laravel-settings/security/advisories/new)

## Critical issues

Plaintext disclosure of a setting declared secret, unauthorized access across scopes and bypasses of secret encryption are critical. Security fixes ship with a changelog entry and a GitHub security advisory.
