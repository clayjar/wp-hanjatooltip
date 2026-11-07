# Changelog

## [1.0.0] - 2026-11-07

### Added

- `GET /wp-json/hanja/v1/lookup` looks up one character in the external `hanja` table with a prepared statement. Database credentials come from `wp-config.php`.
- Post content wraps each Han ideograph in `span.hanja-word`, leaving link labels and script, style, and textarea blocks unchanged.
- A click or finger tap opens a tooltip with that character's definition.
- `maintenance/loadHanja.php` loads the libhangul hanja list into MySQL.
