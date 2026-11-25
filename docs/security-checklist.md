# Security checklist

| # | Property | Covering test | Status |
| --- | --- | --- | --- |
| S1 | Instance settings have a non-null scope ID and a unique natural key. | Schema step | Planned |
| S2 | Secrets are redacted in public payloads, logs and exceptions. The cache stores only ciphertext for secrets. | Settings and cache steps | Planned |
| S3 | Declared secrets are encrypted at rest and verification rejects plaintext rows. | Settings and commands steps | Planned |
| S4 | Writes reject undeclared keys and keys outside the target scope. | Settings step | Planned |
| S5 | Scope references reject unknown scopes and non-empty IDs for instance-like scopes. | Scope step | Planned |
| S6 | Workers see committed changes made by another process. | Cache step | Planned |
| S7 | Rollback preserves the last committed cached value. | Cache step | Planned |
| S8 | An outdated revision prevents a lost update. | Settings step | Planned |
| S9 | Previous keys keep secrets readable during rotation until re-encryption completes. | Commands step | Planned |
| S10 | Undecryptable secrets fail closed. | Settings step | Planned |
| F1 | Older published config receives defaults and invalid options fail at boot with the key name. | `SettingsCheckTest` | Covered |
