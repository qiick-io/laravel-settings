# Security checklist

| # | Property | Covering test | Status |
| --- | --- | --- | --- |
| S1 | Instance settings have a non-null scope ID and a unique natural key. | `SettingsTableTest` | Covered |
| S2 | Secrets are redacted in public payloads, logs and exceptions. The request memo holds only ciphertext for secrets. | `SecretTest`, `SettingsRuntimeTest`, `SettingsRequestMemoTest` | Covered |
| S3 | Declared secrets are encrypted at rest and verification rejects plaintext rows. | `SettingsRuntimeTest`, `SettingsVerifyTest`, `SettingsArchiveTest` | Covered |
| S4 | Writes reject undeclared keys and keys outside the target scope. | `SettingsRuntimeTest` | Covered |
| S5 | Scope references reject unknown scopes and non-empty IDs for instance-like scopes. | `ScopeRefTest` | Covered |
| S6 | A new request or job sees changes committed by another process. | `SettingsMemoProcessTest` | Covered |
| S7 | Rollback preserves the last committed memoized value. | `SettingsRequestMemoTest` | Covered |
| S8 | An outdated revision prevents a lost update. | `SettingsRuntimeTest`, `SettingsWriteRaceTest` | Covered |
| S9 | Previous keys keep secrets readable during rotation until re-encryption completes. | `SettingsRuntimeTest`, `SettingsReencryptTest`, `SettingsArchiveTest` | Covered |
| S10 | Undecryptable secrets fail closed. | `SecretTest`, `SettingsRuntimeTest` | Covered |
| F1 | Older published config receives defaults and invalid options fail at boot with the key name. | `SettingsCheckTest` | Covered |
