# Changelog

All notable changes to the **PeeStream for WordPress & DooPlay** plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- AutoPublish V2 webhook support with raw-body HMAC-SHA256 validation, `site_id` checking, `site.test` handling, unknown-event acknowledgement, and `event_id` deduplication/72-hour expiry.
- Admin options for PeeStream `site_id` and default post status (`draft` or `publish`).
- Standalone webhook mock coverage for V2 event and site validation paths.

### Security
- Removed secret-in-query-string webhook authentication; requests use `X-PeeStream-Signature`.

---

## [1.0.4-beta.1] - 2026-11-12

### Important Notice
> **BETA RELEASE - NOT PRODUCTION TESTED**
> This pre-release build incorporates critical architectural bug fixes and unit test harnesses. Live CMS end-to-end testing on an active WordPress/DooPlay instance has not been run. Staging deployment and complete database backup prior to migration are mandatory.

### Added
- **Binary-Safe Case-Sensitive URL Deduplication:** Enforced SHA-256 identity (`url_hash`) and `BINARY original_url` comparison in database migration deduplication and queue reservation, preventing case-sensitive video URLs from colliding on case-insensitive collations.
- **Fail-Safe Deduplication Archiving:** Upgrades safely archive duplicate queue items to `wp_peestream_migration_queue_archive` before adding unique index `uq_post_urlhash`; any SQL error halts migration with zero data loss.
- **Lock Lease Hardening:** Loss of global processing lock immediately stops post metadata modifications.
- **Webhook Transaction Protection:** Wrapped post insertion and metadata attachment in explicit SQL transactions with automatic rollback on failure, eliminating orphan draft posts.
- **Truthful Admin Batch Progress:** Distinct counters for completed, pending, failed, and skipped items; pending items show "Queued / Pending encoding".
- **Comprehensive Unit & Harness Test Suite:** 174 automated tests covering syntax linting, DooPlay formatters, concurrency locks, rollback safety, and webhook handlers.

---

## [1.0.3] - 2026-11-11

### Added
- **Unified Manual Migration Locking:** Integrated `acquire_lock(120)` with periodic heartbeat lease extension and safe `finally` unlock, ensuring AJAX Start Migration, AJAX Check Queue, and WP-Cron run mutually exclusively without racing or overwriting post metadata.
- **Multi-Server Post Atomicity:** Posts containing multiple competitor links process each server link individually and atomically. Fresh post metadata is reloaded before each modification, distinct backup snapshots are stored in `wp_peestream_migration_backups`, and each server can be rolled back individually.
- **Idempotent Queue Pre-Reservation:** Added `UNIQUE KEY uq_post_orig (post_id, original_url(191))` to the migration queue table. Tasks are atomically pre-reserved in the database before calling PeeStream's `/api/upload/url`.
- **Strict DB Schema Compliance Verification:** `create_tables()` verifies the presence of all mandatory columns across all three tables and validates indexes.
- **Consistent UTC Timestamps:** Switched all timestamp writes and comparison cutoffs to consistent UTC, eliminating bugs caused by WordPress local timezone offsets.

---

## [1.0.2] - 2026-11-10

### Added
- **Queue Lock Lease Extension & Time Budgeting:** Replaced fixed queue lock timeout with per-task heartbeat lease extension (`extend_lock`) and execution time budgeting (`max_execution_time`).
- **Atomic Lock Release by Owner Token:** Implemented atomic SQL conditional deletion ensuring a worker can only release its own lock token.
- **Verified Post Meta Persistence:** In both manual AJAX and background WP-Cron queue processing, DooPlay `repeatable_fields` updates are verified (`fresh_meta === target_meta`) before marking queue items as completed.
- **Partial Failure Recovery:** Backups are written with status `in_progress` prior to post modification and promoted to `completed` only after post meta is confirmed saved.

---

## [1.0.1] - 2026-11-09

### Added
- **Server Integrity & Strict Matching:** `replace` migration strictly matches identical original server URLs, eliminating loose hostname matching.
- **Editorial Conflict Detection:** Automatic conflict detection flags queue items if the target server was modified or deleted by an editor during encoding.
- **Differentiated Surgical Rollback:** Rollback engine distinguishes `primary` (removes only the prepended PeeStream server) from `replace` (restores complete original server entry).
- **Atomic Webhook Idempotency:** Webhook receiver atomically pre-reserves incoming `(file_code, event)` records with status `processing`.
- **Security & Webhook Disambiguation:** Hardened REST API webhook with POST-only restriction, fail-closed secret authentication (>= 16 chars), IP rate-limiting, and atomic replay protection with `UNIQUE KEY uq_fc_event`.

---

## [1.0.0] - 2026-11-08

### Added
- Initial release with DooPlay metabox, shortcode, competitor migrator, and webhook receiver.
