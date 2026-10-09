# Contributing

Thanks for your interest in GIM Pay for WooCommerce. Bug reports, fixes and translations
are welcome.

## Reporting a bug

Open an [issue](https://github.com/okemamy/gimpay-woocommerce/issues) with:

- WordPress, WooCommerce and PHP versions, and whether the block or classic checkout is used;
- the plugin version and the environment (UAT or production);
- the steps to reproduce, the expected and the actual result;
- relevant lines from **WooCommerce › Status › Logs** (source `gimpay`) and order notes.

**Never post your secret key, full card numbers or customer data.** Redact merchant and
terminal IDs if you prefer. Security issues must be reported privately, see
[SECURITY.md](SECURITY.md).

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Keep changes focused; one topic per pull request.
3. Follow the [WordPress coding standards](https://developer.wordpress.org/coding-standards/)
   (`WordPress-Extra`): tabs, Yoda conditions, sanitized input, escaped output, `gimpay_`
   prefixes.
4. Write code, comments, commit messages and source strings in English. Wrap user-facing
   strings in translation functions with the `gimpay-woocommerce` text domain.
5. Run the checks:

   ```sh
   php tests/test-hash.php
   find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
   ```

6. Add an entry under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md).

Commit messages use the imperative mood, with a short summary line (about 50 characters),
a blank line, then the reason for the change if it is not obvious:

```
Add configurable Data Service URL

Some acquirers expose the Data Service on a dedicated host.
```

## Translations

1. Regenerate the template: `wp i18n make-pot . languages/gimpay-woocommerce.pot --exclude=tests`.
2. Create or update `languages/gimpay-woocommerce-<locale>.po`.
3. Compile it: `wp i18n make-mo languages` and `wp i18n make-php languages`.

## License

By contributing, you agree that your contributions are licensed under the
[GPL-2.0-or-later](LICENSE).
