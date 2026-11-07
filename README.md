# Hanja Tooltip

WordPress plugin that wraps each Han ideograph in post content and shows a dictionary entry when a reader clicks or taps the character.

The lookup reads the `hanja` table. The page script calls `/wp-json/hanja/v1/lookup?char=韓`.

## Install

The repository root is the plugin. WordPress reads `hanja-tooltip.php`.

Clone it into the plugins directory. Use the HTTPS or SSH address shown under **Code** on this repository:

```bash
git clone <repository-url> /path/to/wordpress/wp-content/plugins/hanja-tooltip
```

Or download the ZIP from that **Code** menu, then in WordPress open **Plugins → Add New → Upload Plugin** and install that file. GitHub names the extracted folder after the repository and branch. That name is fine.

Activate **Hanja Tooltip** under **Plugins → Installed Plugins**.

`the_content` runs when a post is displayed, so existing posts pick up the spans without a separate cache purge. A full-page cache still needs its pages rebuilt once after activation.

## Database

Add the dictionary connection to `wp-config.php` above the “That's all, stop editing!” line. The account should have `SELECT` on `hanja` only.

```php
define( 'HANJA_DB_HOST', '127.0.0.1' );
define( 'HANJA_DB_PORT', 3306 );
define( 'HANJA_DB_NAME', 'hanja1' );
define( 'HANJA_DB_USER', 'hanja_webuser' );
define( 'HANJA_DB_PASSWORD', '…' );
```

`HANJA_DB_HOST` defaults to `127.0.0.1` and `HANJA_DB_PORT` defaults to `3306`. If `HANJA_DB_NAME` or `HANJA_DB_USER` is omitted, the plugin uses `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_HOST`, so a `hanja` table in the WordPress database still works. In that fallback, `DB_HOST` is left unchanged when `HANJA_DB_PORT` is also omitted, which keeps a `localhost` socket connection intact.

## Load the dictionary

The dictionary file is the hanja list by Choe Hwanjin (2005, 2006), published in [libhangul](https://github.com/libhangul/libhangul) as [`data/hanja/hanja.txt`](https://github.com/libhangul/libhangul/blob/main/data/hanja/hanja.txt). Its header states the copyright and license. This repository does not include that file.

Save it as `data/hanja.txt` inside the plugin directory. The database must already exist. The account that loads it needs `CREATE`, `INSERT`, and `TRUNCATE` on that database. That can be a different account from the `SELECT`-only user configured above.

Set the connection in the environment, or in a `.env` file in the plugin root. `.env` fills only variables that are not already set.

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hanja1
DB_USERNAME=
DB_PASSWORD=
```

```bash
php maintenance/loadHanja.php data/hanja.txt
```

Each data line is `entry:hanja:definition`. Empty lines and comments are skipped. Only a hanja field of one character is stored. An empty definition falls back to the Korean `entry`. Another reading of the same character is appended with ` / `, in file order.

The script creates `hanja` when it is missing:

| Column | Type | Notes |
| --- | --- | --- |
| `hanja` | `CHAR(1)` | Primary key, `utf8mb4_bin` |
| `definition` | `TEXT` | Reading and gloss, with extra readings joined by ` / ` |

A later run truncates `hanja` and inserts the dictionary again. The current libhangul list yields 27,552 rows. The script prints how many rows it inserted.

## Behavior

`the_content` wraps each Unicode Han ideograph (`\p{Han}`) in `<span class="hanja-word" data-char="韓">`. Hangul, kana, Latin, digits, and punctuation stay plain text. Text inside links, `script`, `style`, and `textarea` is left unchanged. A span that is already tagged is left as it is.

A mouse click or finger tap on a span requests that one character. Hover and keyboard focus do not. The tooltip shows the character immediately, then the definition. A second click or tap on the same character, Escape, or a click outside the popup closes it.

A hit looks like:

```json
{
  "char": "韓",
  "definition": "…",
  "found": true
}
```

A missing character returns `found: false` and an empty `definition`. The tooltip then shows the character and “Not found”. Successful lookups, including misses, are cached for one hour. A database failure returns HTTP 503 with a generic message.
