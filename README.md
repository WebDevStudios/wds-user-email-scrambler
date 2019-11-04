# WDS User Email Scrambler

Adds a WP-CLI command that scrambles user email addresses. Useful for preventing accidentally emailing real customers/users when testing mass or transactional email.

## Installation

- Download and install the file like any other WordPress plugin on your site.
- Activate as a normal WordPress plugin or drop the `wds-user-email-scrambler.php` file into `/mu-plugins`

## Arguments

- `--ignored-domains=<domains>` - Comma-separated list of domains to exclude from the scrambling process.
- `--table=<table>` - Table to target for scrambling. Defaults to `users`.
- `--field=<field>` - Field to target for scramlbing. Defaults to `user_email`.
- `--where-field=<field>`, `--where-value=<value>` - Field and field value used to target specific records via a where clause: `WHERE <field> = <value>`.

## Usage

- Use `wp db export before-scramble.sql` to backup your database before scrambling.
- Run the `wp scramble-user-emails` command. **It is highly recommended** to specify some ignored domains.
- By default, the command applies to the `user_email` field in the `users` table. Use the `--table` and `--field` arguments to target a field in another table.
- Use the `--where-field` and `--where-value` to target specific records for scrambling.

**Specifying Ignored Domains**

- If you run `wp scramble-user-emails --ignored-domains="webdevstudios.com, wdslab.com"` for example, then any user email that ends with `@webdevstudios.com` or `@wdslab.com` will be ignored.
- This is useful for preserving, for example, admin email addresses or author email addresses.

**Specifying custom tables**

- If you run `wp scramble-user-emails --table=postmeta --field=meta_value --where-field=meta_key --where-value="_billing_email"`, the `meta_value` for all records in the `postmeta` table with `meta_key = "_billing_email"` will be scrambled.

## Example

**Before:**
![before-screenshot](https://i.imgur.com/e7cuvKP.png)

**Command**
```
wp scramble-user-emails --ignored-domains="webdevstudios.com, okeeffemuseum.org, anagr.am"
```

**After:**
![after-screenshot](https://i.imgur.com/nFR98ku.png)

## Example with specified target table and `WHERE` clause arguments

**Before:**
![before-screenshot](https://i.imgur.com/qmPNnbf.png)

**Command**
```
wp scramble-user-emails --ignored-domains="webdevstudios.com" --table=postmeta --field=meta_value --where-field=meta_key --where-value="_billing_email"
```

**After:**
![after-screenshot](https://i.imgur.com/fT1dk2L.png)


