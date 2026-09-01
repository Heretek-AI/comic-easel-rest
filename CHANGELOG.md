# Changelog

All notable changes to `comic-easel-rest` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-31

### Added

- **CPT-args shim** flipping `show_in_rest` on the `comic` CPT and the three
  taxonomies (`chapters`, `characters`, `locations`) via
  `register_{$post_type}_post_type_args` and `register_{$tax}_taxonomy_args`.
  Idempotent — preserves `rest_base` and `rest_controller_class` set by other
  plugins.
- **REST controller** under the `comic-easel/v1` namespace with six endpoints:
  - `POST /comics/with-thumbnail` — create a comic post with a featured image
    (base64 payload, data URL, or remote URL) in one request.
  - `POST /chapters` — create a chapter term.
  - `POST /comics/schedule` — schedule a comic for future publish.
  - `GET /settings` / `POST /settings` — read or update the whitelisted plugin
    options.
  - `POST /comics/bulk-import` — import a batch of comics under a chapter.
- **Application Passwords detection** (`cer_app_passwords_available()`) and
  admin notice when the parent plugin is missing.
- **PHPUnit test suite** with `WP_UnitTestCase` (shim filter) and
  `WP_Test_REST_TestCase` (controller + endpoints).
- **GitHub Actions CI** mirroring comic-easel's three-job pipeline: `lint-php`
  on PHP 7.4–8.4, `test` on PHP × WP matrix with SQLite, advisory `phpcs` on
  changed lines.
- **`bin/install-wp-tests.sh`** to fetch WP core + test library + SQLite drop-in
  + parent plugin into the WP plugins directory for CI.

[0.1.0]: https://github.com/Heretek-AI/comic-easel-rest/releases/tag/v0.1.0
