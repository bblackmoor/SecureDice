# Changelog

## 2.0.58

- Added a manual Actions run for verifying the MySQL integration tests when commits are published through an API connection.

## 2.0.57

- Moved Secure Dice 2 results, consent, and email queues to `sd2_` tables in the existing MySQL database.
- Added a one-time, resumable legacy roll import that verifies every copied row before renaming old Secure Dice tables with `sd1_` prefixes. Old email addresses are not imported.
- Updated DreamHost configuration, deployment steps, and MySQL integration tests.

## 2.0.51

- Removed the one-time release-history cleanup after retaining `v2.0.49` as the sole permanent Release and preserving all historical Git tags.

## 2.0.50

- Added 90-day development ZIP artifacts for every commit to `main`, identified by version and short commit SHA.
- Reserved permanent, cleanly named GitHub Releases for matching version tags.
- Added tag-to-`VERSION` validation before a permanent release is published.
