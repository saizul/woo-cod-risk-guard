# Contributing

Thanks for taking the time to help. Bug reports, rule ideas backed by real
store experience, and pull requests are all welcome.

## Reporting a problem

Open an issue at <https://github.com/saizul/woo-cod-risk-guard/issues> with:

- WordPress, WooCommerce and PHP versions;
- checkout type (classic or block) and order storage (HPOS or legacy);
- what you expected and what happened;
- the reasons shown in the "COD risk" meta box, if the report is about a score.

Never paste real customer data. Replace phone numbers with something like
`01700000000` and emails with `customer@example.com`.

For security problems, please do not open a public issue. Use the contact
form on <https://saizul.com> instead.

## Development setup

```bash
git clone https://github.com/saizul/woo-cod-risk-guard.git
cd woo-cod-risk-guard
composer install
composer test
composer lint
```

PHP 8.1 or newer is required. The code must keep working on PHP 8.1, so do
not use syntax from later versions (readonly classes, typed class constants,
standalone `true` / `false` / `null` types and so on).

## Project layout

- `src/` is the scoring engine. It must stay free of WordPress and
  WooCommerce calls so it can be unit tested on its own.
- `includes/` is the WordPress integration layer.
- `tests/Unit/` holds the PHPUnit tests.

## Guidelines

- Every change to `src/` needs tests. Add cases for the edge conditions, not
  only the happy path.
- A new rule needs: a class in `src/Rules/`, registration in
  `RuleRegistry::bundled()`, translatable reason templates in
  `includes/Labels.php`, tests, and a row in the README rules table.
- In `includes/`: escape all output, use `$wpdb->prepare()` for every query,
  check capabilities and nonces on every state-changing request, and use the
  order CRUD methods (`get_meta()` / `update_meta_data()`) so the code works
  with HPOS.
- All user-facing strings go through the translation functions with the
  `woo-cod-risk-guard` text domain.
- Keep pull requests focused. One topic per pull request.

## Commit messages

Use a short imperative subject line ("Add courier status mapping"), and
explain the why in the body when it is not obvious.

## License

By contributing you agree that your contribution is licensed under the
GPL-2.0-or-later, like the rest of the project.
