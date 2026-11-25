# Security checklist

| # | Property | Covering test | Status |
| --- | --- | --- | --- |
| S1 | Instance settings have a non-null scope ID and a unique natural key. | `SettingsTableTest` | Covered |
| S2 | Secrets are redacted in public payloads, logs and exceptions. The cache stores only ciphertext for secrets. | `SecretTest`, `SettingsRuntimeTest`, `SettingsGroupCacheTest` | Covered |
| S3 | Declared secrets are encrypted at rest and verification rejects plaintext rows. | `SettingsRuntimeTest`; commands step | Partial |
| S4 | Writes reject undeclared keys and keys outside the target scope. | `SettingsRuntimeTest` | Covered |
| S5 | Scope references reject unknown scopes and non-empty IDs for instance-like scopes. | `ScopeRefTest` | Covered |
| S6 | Workers see committed changes made by another process. | `SettingsCacheRaceTest` | Covered |
| S7 | Rollback preserves the last committed cached value. | `SettingsGroupCacheTest` | Covered |
| S8 | An outdated revision prevents a lost update. | `SettingsRuntimeTest`, `SettingsWriteRaceTest` | Covered |
| S9 | Previous keys keep secrets readable during rotation until re-encryption completes. | `SettingsRuntimeTest`; commands step | Partial |
| S10 | Undecryptable secrets fail closed. | `SecretTest`, `SettingsRuntimeTest` | Covered |
| F1 | Older published config receives defaults and invalid options fail at boot with the key name. | `SettingsCheckTest` | Covered |
