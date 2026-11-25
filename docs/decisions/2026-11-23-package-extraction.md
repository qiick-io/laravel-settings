# 2026-11-23: Extract shared settings before 1.0

The maintainer approved extracting scoped settings, encrypted secrets, typed access and caching into `qiick/laravel-settings`. Centralizing the storage and encryption behavior gives products one place to verify settings invariants. Products define their own keys, defaults and authorization.
