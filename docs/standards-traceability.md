# Standards traceability

1.0 checklist item 7a: every MUST of the standards the design names
(`laravel-settings-design.md` §7) is implemented, every SHOULD is implemented or
moved to a dated decision, and every LATER item is additive after `1.0.0`.
The security properties S1–S10 are in [security-checklist.md](security-checklist.md).

## MUST: implemented

| Source | Requirement | Where | Test |
| --- | --- | --- | --- |
| OWASP Secrets Management CS, Cryptographic Storage CS; ASVS 5.0 V11, V13 | secrets encrypted at rest with the application key, never stored in plaintext | `SettingsWriter`, `encryption = laravel-v1` | S3: `SettingsRuntimeTest`, `SettingsVerifyTest`, `SettingsArchiveTest` |
| OWASP Secrets Management CS; ASVS V16 | no plaintext secret in pages, logs, exceptions, caches or memos | `Secret` redaction, ciphertext-only memo, `forEdit()` flags | S2: `SecretTest`, `SettingsRuntimeTest`, `SettingsRequestMemoTest` |
| OWASP Cryptographic Storage CS, key rotation | old keys stay readable during rotation; rows move to the new key under the group write lock, without changing setting or scope | `SettingsKeyRotation`, `settings:reencrypt` | S9: `SettingsReencryptTest` (dry run, invalid ciphertext untouched, unavailable lock fails without writing, memo refreshed) |
| OWASP Secrets Management CS, fail closed | an undecryptable secret never enables a default | strict mode, `UndecryptableSetting` | S10: `SecretTest`, `SettingsRuntimeTest` |
| ASVS V13, mass assignment | only declared keys in their declared scopes can be written | `DefinitionRegistry` | S4: `SettingsRuntimeTest` |
| Fleet core schema | non-null natural key `(scope, scope_id, group, key)`, case-sensitive on every engine | `settings` create migration, `StorageIdentifier` | S1: `SettingsTableTest` (`distinguishes every case-sensitive part of the natural key`) |
| Fleet core schema, string scope IDs | `scope_id` is a string of at most 36 characters: organisation UUIDs lowercased, user keys in decimal, empty for instance-like scopes, no trailing spaces | `ScopeRef`, `ScopeRegistry` | S5: `ScopeRefTest` |
| RFC 9110 §13.1.1 | optimistic concurrency on a monotonic group revision | `revision()`, `expectedRevision`, `StaleSetting` | S8: `SettingsRuntimeTest` |
| Lost-update safety under real concurrency | writers serialise on 64 lock buckets (`crc32(scope, id, group) & 63`), taken in ascending order, so a group is locked even before its first row; a missing bucket fails closed | `SettingsWriteLock` | `SettingsWriteRaceTest` (two connections), `SettingsWriteLockTest`, `settings:check` (`SettingsCheckTest`) |
| Failure atomicity | multi-group `set()`, archive import and erasure commit or roll back as one transaction; import validates the whole file before writing; memo and events follow the outer commit | `DatabaseSettings`, `SettingsArchive`, `SettingsEraser` | S7: `SettingsRequestMemoTest`; `SettingsRuntimeTest` (`rolls back values and revisions with an enclosing transaction`), `SettingsArchiveTest` (`rolls back all inserted rows if a later chunk fails`) |
| Cross-process freshness | a new request or job sees committed changes | request / job memo | S6: `SettingsMemoProcessTest` (separate processes) |
| GDPR Art. 17 | the fleet eraser deletes a user's settings and memo after commit | `SettingsEraser` | `SettingsEraserTest`, `tests/Erasure` (E1–E14) |
| Fleet conventions F1, F2, F5, F12 | nested config defaults, deploy check, `*Event` names, the standard package files | `SettingsConfig`, `settings:check`, `Events\*Event`, `docs/` | `SettingsCheckTest`, `SettingsStorageConfigTest`, architecture tests |

## SHOULD: implemented or decided

| Item | Status |
| --- | --- |
| `public_id` on every row | not added, dated exception: the natural key is the public identity and the bigint never leaves the database (design §3, Team Leader 2026-11-23); a nullable `public_id` is additive |
| Organisation-scoped settings | supported by `ScopeRef::organisation()` and tested; no package key needs it before 1.0 (design §9) |
| Erasure of organisation-scoped rows | not part of user erasure: `SettingsEraser` refuses an organisation ID (`OrganisationScopeUnsupportedException`); organisation removal is the product's |
| Externa `scope_ref`, dedupe archive and additive lock-table release | product work on externa's plan (design §5.2); `settings.columns` covers the column names |
| `metadata` json, `encryption` and `revision` version columns | implemented in the schema |

## LATER: additive after 1.0.0

- A nullable `public_id`.
- Organisation-scoped package keys.
- A second `encryption` format next to `laravel-v1` (the column already versions it).
