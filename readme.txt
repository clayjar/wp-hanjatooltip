=== Hanja Tooltip ===
Contributors: clayjarhan
Tags: dictionary, korean, language
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows a dictionary entry when a reader clicks or taps a Han character in a post.

== Description ==

Hanja Tooltip wraps each Han ideograph in post content and shows a dictionary entry when a reader clicks or taps the character.

The lookup reads a `hanja` table. The page script calls `/wp-json/hanja/v1/lookup`.

Hangul, kana, Latin, digits, and punctuation stay plain text. Text inside links, `script`, `style`, and `textarea` is left unchanged. A character that is already tagged is left as it is.

A mouse click or finger tap requests that one character. Hover and keyboard focus do not. The tooltip shows the character, then the definition. A second click or tap on the same character, Escape, or a click outside the popup closes it.

A missing character shows the character and "Not found". Successful lookups, including misses, are cached for one hour. A database failure returns HTTP 503 with a generic message.

== Installation ==

1. Upload the plugin directory to `/wp-content/plugins/hanja-tooltip`, or install the zip from Plugins → Add New → Upload Plugin.
2. Activate Hanja Tooltip from Plugins → Installed Plugins.

Existing posts pick up the character spans when they are displayed. A full-page cache needs its pages rebuilt once after activation.

Add the dictionary connection to `wp-config.php` above the "That's all, stop editing!" line. The account should have `SELECT` on `hanja` only.

`define( 'HANJA_DB_HOST', '127.0.0.1' );`

`define( 'HANJA_DB_PORT', 3306 );`

`define( 'HANJA_DB_NAME', 'hanja' );`

`define( 'HANJA_DB_USER', 'hanja_webuser' );`

`define( 'HANJA_DB_PASSWORD', '...' );`

`HANJA_DB_HOST` defaults to `127.0.0.1` and `HANJA_DB_PORT` defaults to `3306`. If `HANJA_DB_NAME` or `HANJA_DB_USER` is omitted, the plugin uses `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_HOST`. In that fallback, `DB_HOST` is left unchanged when `HANJA_DB_PORT` is also omitted, which keeps a `localhost` socket connection intact.

== Dictionary ==

Skip this section if the `hanja` table is already loaded. Running the loader again truncates the table and inserts the dictionary from scratch.

The dictionary file is the hanja list by Choe Hwanjin, published in [libhangul](https://github.com/libhangul/libhangul). Save it as `data/hanja.txt` inside the plugin directory. The database must already exist. The account that loads it needs `CREATE`, `INSERT`, and `TRUNCATE`.

Set the connection in the environment, or in a `.env` file in the plugin root. `.env` fills only variables that are not already set.

`bash maintenance/loadHanja data/hanja.txt`

Run that from the plugin directory inside WordPress. The command loads WordPress, then `maintenance/loadHanja.php` writes the dictionary through `$wpdb`. Opening that PHP file in a browser exits immediately. Set `WP_LOAD_PATH` to `wp-load.php` when that file lives somewhere else.

Each data line is `entry:hanja:definition`. Empty lines and comments are skipped. Only a hanja field of one character is stored. An empty definition falls back to the entry. Another reading of the same character is appended with ` / `.

The script creates `hanja` when it is missing. `hanja` is a `CHAR(1)` primary key. `definition` is `TEXT`. A later run truncates the table and inserts the dictionary again.

== Changelog ==

= 1.0.0 =

* Look up one character from the hanja table.
* Wrap each Han ideograph in post content.
* Open a tooltip on click or tap.
* Load the dictionary with `bash maintenance/loadHanja`. Direct access to the PHP file exits.
