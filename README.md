# WDS User Email Scrambler

Adds a WP-CLI command that scrambles user email addresses. Useful for preventing accidentally emailing real customers/users when testing mass or transactional email.

## Installation

- Download and install the file like any other WordPress plugin on your site.
- Activate as a normal WordPress plugin or drop the `wds-user-email-scrambler.php` file into `/mu-plugins`

## Usage

- Use `wp db export before-scramble.sql` to backup your database before scrambling.
- Run the `wp scramble-user-emails` command. **It is highly recommended** to specify some ignored domains.

**Specifying Ignored Domains**

- If you run `wp scramble-user-emails --ignored-domains="webdevstudios.com, wdslab.com"` for example, then any user email that ends with `@webdevstudios.com` or `@wdslab.com` will be ignored.
- This is useful for preserving, for example, admin email addresses or author email addresses.