# Changelog

All notable changes to `laravel-zoom-meeting` will be documented in this file.

## 1.2.0 - Unreleased

- Add PHP 8.4 and Laravel 11, 12, and 13 compatibility coverage.
- Require the stable `nncodes/laravel-meta-attributes:^2.2` metadata package.
- Resolve the personal Meta fork through its VCS repository; consumers must register that repository in their root Composer configuration because dependency repository declarations do not propagate. CHIP root wiring is tracked separately as T10, not in this package change.
- Restore cancelled participant pivots on re-registration and exclude cancelled pivots from active relations.
- Preserve historical migrations, configuration, models, relations, events, and Zoom metadata behavior.
- Characterize Zoom SDK payloads and metadata transfer without network access; lifecycle hook methods remain intentional no-ops and introduce no new events.
- Make default PHPUnit runs clean across PHPUnit 10 and 11; coverage reports remain opt-in through `composer test-coverage`.
- Psalm uses Laravel 13.31.0 and Testbench 11.2.0 because Psalm 6.17.2 crashes on annotations introduced in Laravel 13.32; PHPUnit continues to test the current Laravel 13 compatibility range, and Psalm's legacy findings remain informational.

## 1.0.0 - 202X-XX-XX

- initial release
