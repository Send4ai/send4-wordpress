# Send4 for WordPress

A WordPress plugin that forwards form submissions to [Send4](https://send4.ai), so an AI flow can
follow up with the lead over SMS within seconds. It works with Contact Form 7, WPForms, Gravity
Forms and Elementor Pro.

Install it from the WordPress plugin directory: <https://wordpress.org/plugins/send4/>. What it does
for your site: <https://send4.ai/integrations/wordpress>.

The plugin is a thin client: it holds no business logic beyond mapping fields and not losing
submissions.

```
form plugin hooks ──► Normalizer ──► Delivery (sync POST, 3s) ──► Send4
                          ▲                     │
             per-form field mapping             └─► retry queue (WP-Cron, ~1.5 days)

     adapters ──► Form descriptors ──► Forms sync (PUT, on change + daily) ──► Send4
```

Two things leave the site. **Submissions**, which are the product. And a **description of the
forms themselves** — name, key, whether they are enabled, and each field's label and type, never a
submitted value. Send4 only ever sees a form key on a submission, which is too late to offer that
form in its flow builder, so the plugin tells it up front and the customer picks their forms from
a list instead of retyping a key.

⚠️ A field's declared key must be the key a submission will actually carry, so
`Send4_Form_Descriptors` asks `Send4_Normalizer::data_keys_for()` rather than slugging the label
again. Slugging it independently is right most of the time and quietly wrong for a renamed
collision or a duplicate label — and Send4 would then show the customer a variable that is never
populated, with nothing erroring anywhere. `tests/Unit/FormDescriptorsTest.php` pins the two
together; do not let them drift.

## Repository layout

| Path | What it is |
|---|---|
| `send4.php`, `includes/`, `admin/` | the plugin |
| `includes/adapters/` | one class per form plugin; adding another is one file |
| `dev/` | local-only seeding and simulation scripts (excluded from the zip) |
| `tests/` | PHPUnit suite, run against a real WordPress |
| `bin/build-zip.sh` | builds the distributable zip using `.distignore` |
| `bin/check-version.sh` | checks the three version strings (and a tag) agree; `make release-check` |
| `.wordpress-org/` | the directory listing's icon, banner and screenshots (excluded from the zip) |

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

WordPress runs on <http://localhost:8088> (`admin` / `admin`). Two Contact Form 7 forms are
seeded: a plain one at `/contact/`, and at `/contact-choices/` one the way a real site builds it —
theme markup around the tags, a checkbox with five choices and three `[acceptance]` tags — which
is what the form descriptors have to survive.

Gravity Forms and Elementor Pro are paid, so they are not installed locally. Their adapters are
covered by fixtures instead, and `make simulate ADAPTER=gravityforms` fires their hooks with
fixture data through the real adapter code.

### Minting a key

Create the API client in the Send4 dashboard (**Settings → API keys**) with the
**WordPress plugin** ability (`integrations.wordpress`). Use one key per site: Send4 binds a
credential to the `site_url` it was minted for and answers `403` when a submission arrives from
anywhere else.

## Tests

```bash
make test            # PHPUnit against a real WordPress, backed by the MariaDB container
make test-php74      # the same suite on PHP 7.4 — the version the plugin header promises
make test-multisite  # the same suite as a multisite network
make lint            # PHPCS (WordPress Coding Standards, PHP 7.4 compatibility) + PHPStan
```

Those three test targets are the three legs CI runs, so a push should not be the first time
any of them executes.

Both install `vendor/` on the host first: the tests container bind-mounts this directory, so
dependencies installed inside it would not persist. PHPStan needs more than the default 128 MB,
which is why `make lint` passes `-d memory_limit=1G`; CI does not need the flag because
`setup-php` sets `memory_limit=-1`.

## Releasing

`bin/build-zip.sh` produces `build/send4.zip`.

A pushed `v*` tag is a release (`.github/workflows/release.yml`).

1. Bump the version in all three places: the `Version` header and `SEND4_VERSION` in
   `send4.php`, and `Stable tag` in `readme.txt`. Write the changelog entry and the upgrade
   notice, run `make pot`, then `make release-check` and `make zip`. The readme keeps two
   changelog entries, as WordPress.org asks: move the older one to the top of `changelog.txt`.
   Replace the upgrade notice rather than adding one; WordPress only shows the new version's.
2. Merge to `main`, then run `git tag vX.Y.Z && git push origin vX.Y.Z`. Tag a commit that is
   already on `main`: the workflow refuses any other.
3. The workflow runs all of CI on the tag, checks the tag against the version (`make
   release-check TAG=vX.Y.Z` is the same check), and creates the GitHub release with
   `send4.zip` attached and the changelog entry as notes. That zip is what a hand install and
   the WordPress.org reviewer get.
4. The `wordpress-org` job commits the same zip to the WordPress.org SVN (`trunk` and
   `tags/X.Y.Z`), and `.wordpress-org/` to `assets/`. It is **skipped** until the repository
   variable `WPORG_PUBLISH` is `true`, which is set once the plugin is approved. Once it runs,
   approve the job, then click the Release Confirmation email WordPress.org sends.

To publish a tag cut before approval: Actions → *Release* → *Run workflow*, `tag: vX.Y.Z`. It is
a dry run unless you untick `dry_run`. A dry run still needs the SVN credentials.

⚠️ A tag cannot be moved once WordPress.org has it, so a fix is always a new patch version.
⚠️ Never move a site onto a new version by deleting and reinstalling the plugin: `uninstall.php`
drops the retry queue and the connection.

## License

GPL v2 or later. See [LICENSE](LICENSE).
