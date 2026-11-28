# Contributing

Bug reports, fixes and adapters for more form plugins are welcome. The [README](README.md) says
what the plugin does; this file is how the repository works.

- **A bug or an idea:** [open an issue](https://github.com/Send4ai/send4-wordpress/issues/new/choose).
- **A question about using the plugin:** the [support forum](https://wordpress.org/support/plugin/send4/).
- **A security problem:** see [SECURITY.md](SECURITY.md). Please do not open a public issue.

## Pull requests

`main` only changes through pull requests. Branch from `main`, and say in the pull request why the
change exists and what a site owner would notice: the maintainers write the changelog entry from
that when the release is cut.

CI runs PHPCS, PHPStan and ESLint, PHPUnit on the five legs below, builds the distributable zip and runs
WordPress.org's Plugin Check on it. `All checks` sums them up in one status. A pull request that
only touches documentation (Markdown at the root, the issue and pull request templates,
`.wordpress-org/`) skips everything but the zip.

| PHP | WordPress | Contact Form 7 | Why |
|---|---|---|---|
| 7.4 | 6.2 | 5.8 | the floor the plugin header promises |
| 7.4 | latest | 6.1 | Contact Form 7 needs PHP 8.3 from 6.2 onwards |
| 8.3 | latest | latest | |
| 8.3 | latest, multisite | latest | the installer's network branch |
| 8.5 | latest | latest | the newest PHP hosts offer |

"Latest" is whatever `composer.lock` holds, which Dependabot moves once a month. The lint and the
legs on current versions have a `make` target, so a push should not be the first time they run:

```bash
make lint            # PHPCS (WordPress Coding Standards, PHP 7.4 compatibility) + PHPStan + ESLint
make test            # PHPUnit against a real WordPress, backed by the MariaDB container
make test-php74      # the same suite on PHP 7.4, the version the plugin header promises
make test-multisite  # the same suite as a multisite network
```

The two PHP 7.4 legs swap packages before they run. To reproduce one, make the same swap, run the
suite, and put the lock file back:

```bash
composer require --dev 'wpackagist-plugin/contact-form-7:6.1.*'
# the floor instead: 'roots/wordpress:6.2.*' 'wp-phpunit/wp-phpunit:6.2.*' 'wpackagist-plugin/contact-form-7:5.8.*'
make test-php74
git checkout composer.json composer.lock && composer install
```

What the code has to hold to:

- **PHP 7.4 syntax.** The plugin header promises 7.4, and PHPCompatibility checks it.
- **Native types where they can be proven.** Return values and private properties carry a native
  type when every value they can hold is known. Parameters of anything WordPress or another plugin
  can call keep their type in the docblock only: a hook hands a callback whatever the last plugin
  returned, and a native type there turns a surprise into a fatal error on someone's site. Nothing
  newer than PHP 7.4 allows: no union types, no `mixed`, no `static` return type.
- **WordPress Coding Standards**, with every global prefixed `send4_` or `Send4_` and every string
  in the `send4` text domain. `languages/send4.pot` is regenerated at release, not in each pull
  request.
- **JavaScript with no build step.** `public/js/phone-field.js` and `admin/js/phone-country.js`
  are shipped as written, and the first runs on pages the plugin does not control, next to
  whatever else a site loads. ESLint (`eslint.config.mjs`) holds them to `'use strict'` inside one
  function, no globals, no `console`, and syntax no newer than ES2020.
- **The WordPress HTTP API only**, with `sslverify` left `true`. No curl. CI checks both as text.
- **No Composer at runtime.** `Send4_Autoloader` maps `Send4_Foo_Bar` onto
  `includes/class-send4-foo-bar.php`; `vendor/` is development tooling and never ships.
- **New behaviour comes with a test.**
- **If a change alters what leaves the site**, update `== External services ==` in `readme.txt` in
  the same pull request. WordPress.org requires that section to match what the plugin does.
- **No API keys, client secrets or real lead data** in code, tests, fixtures or screenshots.

Contributions are licensed under GPL v2 or later, like the rest of the plugin.

## Repository layout

| Path | What it is |
|---|---|
| `send4.php`, `includes/`, `admin/`, `public/` | the plugin |
| `includes/adapters/` | one class per form plugin; adding another is one file |
| `public/lib/intl-tel-input/` | the bundled country picker, shipped unmodified |
| `dev/` | local-only seeding and simulation scripts (excluded from the zip) |
| `tests/` | PHPUnit suite, run against a real WordPress |
| `bin/build-zip.sh` | builds the distributable zip using `.distignore` |
| `bin/check-version.sh` | checks the three version strings (and a tag) agree; `make release-check` |
| `.wordpress-org/` | the directory listing's icon, banner and screenshots (excluded from the zip) |

Two things leave a site. **Submissions**, which are the product. And a **description of the forms
themselves**: name, key, whether they are enabled, and each field's label and type, never a
submitted value. Send4 only ever sees a form key on a submission, which is too late to offer that
form in its flow builder, so the plugin tells it up front and the customer picks their forms from
a list instead of retyping a key.

⚠️ A field's declared key must be the key a submission will actually carry, so
`Send4_Form_Descriptors` asks `Send4_Normalizer::data_keys_for()` rather than slugging the label
again. Slugging it independently is right most of the time and quietly wrong for a renamed
collision or a duplicate label, and Send4 would then show the customer a variable that is never
populated, with nothing erroring anywhere. `tests/Unit/FormDescriptorsTest.php` pins the two
together; do not let them drift.

## Local development

Requires Docker and a Send4 account. The plugin points at `http://host.docker.internal:8080` by
default; set `SEND4_API_BASE_URL` in `.env` to use another server.

```bash
cp .env.example .env          # add SEND4_CLIENT_ID / SEND4_CLIENT_SECRET
make install                  # WordPress + CF7 + WPForms Lite + the seeded test forms
make connect                  # verify the credentials against Send4
make e2e                      # submit the seeded form through CF7's real pipeline
make queue                    # what is waiting to be retried
```

`make help` lists every target.

WordPress runs on <http://localhost:8088> (`admin` / `admin`). Two Contact Form 7 forms are
seeded: a plain one at `/contact/`, and at `/contact-choices/` one the way a real site builds it,
with theme markup around the tags, a checkbox with five choices and three `[acceptance]` tags,
which is what the form descriptors have to survive.

Gravity Forms and Elementor Pro are paid, so they are not installed locally. Their adapters are
covered by fixtures instead, and `make simulate ADAPTER=gravityforms` fires their hooks with
fixture data through the real adapter code.

### Minting a key

Create the API client in the Send4 dashboard (**Settings → API keys**) with the
**WordPress plugin** ability (`integrations.wordpress`). Use one key per site: Send4 binds a
credential to the `site_url` it was minted for and answers `403` when a submission arrives from
anywhere else.

## Tests

The test and lint targets install `vendor/` on the host first: the tests container bind-mounts
this directory, so dependencies installed inside it would not persist. `make lint` also installs
`node_modules/` for ESLint, so it needs Node 20.19 or later. PHPStan needs more than the
default 128 MB, which is why `make lint` passes `-d memory_limit=1G`; CI does not need the flag
because `setup-php` sets `memory_limit=-1`.

`tests/Unit/` covers what needs no database: the normalizer, the phone validator, the form
descriptors. `tests/Integration/` runs each form plugin's real submission path, the queue, the
installer and the admin screens against WordPress.

## Adding a form plugin

1. Add `includes/adapters/class-send4-adapter-<name>.php`, implementing `Send4_Form_Adapter`, and
   add it to the list in `Send4_Adapters::all()`.
2. Implement the optional interfaces it can support: `Send4_Validating_Adapter` for the phone
   check, `Send4_Findable_Adapter` to look one form up by id, `Send4_Truncating_Adapter` if its
   listing can stop early.
3. Add fixtures under `tests/fixtures/<name>/`, an envelope test under `tests/Unit/Normalizer/`,
   and submission and phone-validation tests under `tests/Integration/`. The four existing
   adapters each have all three.
4. For a paid form plugin that cannot be installed in CI, add a stub under `tests/stubs/` and name
   it in `scanFiles` in `phpstan.neon.dist`, as Gravity Forms and Elementor Pro do.

⚠️ Do not add a method to `Send4_Form_Adapter`. It is public through the `send4_form_adapters`
filter, so growing it would fatal every third-party adapter on upgrade. A new capability is a new
optional interface, probed with `instanceof`.

## Releasing

For maintainers. `bin/build-zip.sh` produces `build/send4.zip`, and a pushed `v*` tag is a release
(`.github/workflows/release.yml`).

1. Bump the version in all three places: the `Version` header and `SEND4_VERSION` in
   `send4.php`, and `Stable tag` in `readme.txt`. Write the changelog entry and the upgrade
   notice, run `make pot`, then `make release-check` and `make zip`. The readme keeps two
   changelog entries, as WordPress.org asks: move the older one to the top of `changelog.txt`.
   Replace the upgrade notice rather than adding one; WordPress only shows the new version's.
2. Merge to `main`, then run `git tag vX.Y.Z && git push origin vX.Y.Z`. Tag a commit that is
   already on `main`: the workflow refuses any other.
3. The workflow runs all of CI on the tag, checks the tag against the version (`make
   release-check TAG=vX.Y.Z` is the same check), and creates the GitHub release with
   `send4.zip` attached and the changelog entry as notes. That zip is what a hand install gets.
4. The `wordpress-org` job commits the same zip to the WordPress.org SVN (`trunk` and
   `tags/X.Y.Z`), and `.wordpress-org/` to `assets/`. It runs only while the repository variable
   `WPORG_PUBLISH` is `true`. Approve the job, then click the Release Confirmation email
   WordPress.org sends.

To publish an existing tag by hand: Actions → *Release* → *Run workflow*, `tag: vX.Y.Z`. It is
a dry run unless you untick `dry_run`. A dry run still needs the SVN credentials.

⚠️ A tag cannot be moved once WordPress.org has it, so a fix is always a new patch version.

⚠️ Never move a site onto a new version by deleting and reinstalling the plugin: `uninstall.php`
drops the retry queue and the connection.
