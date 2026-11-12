# Changelog

## [1.0.1] - 2026-11-12

### Removed

- Korean translation file

### Changed

- Use wpdb() and safe SQL calls
- Use conditional to not allow direct file access to plugin files


## [1.0.0] - 2026-11-07

### Added

- `GET /wp-json/hanja/v1/lookup` looks up one character in the external `hanja` table with a prepared statement. Database credentials come from `wp-config.php`.
- Post content wraps each Han ideograph in `span.hanja-word`, leaving link labels and script, style, and textarea blocks unchanged.
- A click or finger tap opens a tooltip with that character's definition.
- `maintenance/loadHanja.php` loads the libhangul hanja list into MySQL.

### Changed

- Dictionary connection failures are recorded through `$wpdb`. Query failures were already logged there.
- `maintenance/loadHanja.php` refuses direct web access and reads the dictionary file with `file()`.
- `maintenance/loadHanja.php` loads the dictionary through `$wpdb`. Inserts use `$wpdb->insert()`, which prepares the character and definition.
- Direct access to `maintenance/loadHanja.php` exits. The dictionary loads with `bash maintenance/loadHanja`.
- `readme.txt` supplies the WordPress.org headers: tested up to 7.1, GPLv2 or later, and stable tag 1.0.0.
