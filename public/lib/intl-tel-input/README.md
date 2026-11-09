# intl-tel-input, vendored

The country picker behind the phone field (#0688). Copied **byte for byte** from the npm package and
never patched, so an upgrade is a re-copy and a checksum, not a merge.

| | |
|---|---|
| Package | `intl-tel-input` |
| Version | **29.2.3** (keep `Send4_Phone_Field::ITI_VERSION` in step) |
| Licence | MIT — `LICENSE`, shipped because the licence requires it |
| Upstream | https://github.com/jackocnr/intl-tel-input |
| Tarball | https://registry.npmjs.org/intl-tel-input/-/intl-tel-input-29.2.3.tgz |
| Tarball SHA-256 | `20743badf9002c04968e50cc40f8d69d2b1d439425fbe1b125e78153996904b9` |

## Files

| Here | From the package | SHA-256 |
|---|---|---|
| `LICENSE` | `LICENSE` | `cf73c26f3430cdb778415da427dfec99303332d0ab691f1e43e024b9cb81e1be` |
| `js/intlTelInput.js` | `dist/js/intlTelInput.js` | `046d54da6b8032fc95b36dca8119ff0e2b121a1cf4b84f9848f21383d53d268a` |
| `js/intlTelInput.min.js` | `dist/js/intlTelInput.min.js` | `bef7e2898f92b7fe2cb386a03b9bda889ab99935de50c0c90098e036c3072a18` |
| `js/utils.js` | `dist/js/utils.js` | `12d617d05c3bb1b11179f2e4aa0936cbfae5971aa33ed0228d6a2c5e6f7a4b67` |
| `css/intlTelInput.css` | `dist/css/intlTelInput.css` | `cebede07ea01da1cdc178fb35a83fac98a504fc2cfeb16d547227cc3bee25229` |
| `css/intlTelInput.min.css` | `dist/css/intlTelInput.min.css` | `d01af9670a4d358d7c84980cc17df2c5f35fdcc6ae40b5a2082ab3763480622b` |
| `img/flags.webp` | `dist/img/flags.webp` | `ed164ab32687531059bf7fd23cca87b8ad1fe5fa7ab9b0dd974ad362716c086a` |
| `img/flags-2x.webp` | `dist/img/flags@2x.webp` | `c8b020b385c0e5fb025ce404f4d3ff4e94d002f18fc5c684fc2aaed7ac6ba00f` |

`css/` and `img/` stay siblings because the stylesheet points at `../img/flags.webp`; moving either would
mean patching upstream CSS.

⚠️ **`flags@2x.webp` ships as `flags-2x.webp`** (#1531): the same bytes under another name, because
WordPress.org's Plugin Check refuses any file name with an `@` in it (`badly_named_files`, an error that
blocks a submission). The upstream stylesheets still name `flags@2x.webp`, and that is harmless because they
are never loaded (see below). The two generated stylesheets point at the new name, and
`bin/build-scoped-picker-css.php` throws if upstream stops naming the old one.

The page never loads `css/intlTelInput*.css` directly. Its selectors are bare (`.iti__flag`), so it restyles
any other copy of the picker on the page. `public/css/intl-tel-input-scoped.css` is generated from it with
every rule limited to `.send4-iti`, and that is what `Send4_Phone_Field` registers.

The unminified `js/intlTelInput.js` is loaded under `SCRIPT_DEBUG` and is also the human-readable source
wordpress.org guideline 4 asks for.

`js/utils.js` has no unminified twin in the package: upstream compiles it with Google Closure Compiler from
`packages/core/src/js/utils.js` plus libphonenumber (a git submodule at `third_party/libphonenumber`), using
`scripts/build-utils.js` (`npm run build:utils`). Guideline 4 accepts a link to the source and the build tools
in place of shipping them, so `readme.txt` (*Third-party libraries*) links both at the `v29.2.3` tag. On an
upgrade, change the tag in those two links as well.

## Not copied, and why

- `dist/js/intlTelInputWithUtils*.js` — bundles libphonenumber into first paint (316 KB minified).
  `utils.js` is loaded lazily on its own instead.
- `dist/js/*.mjs`, `dist/js/data*.js`, `dist/js/*.d.ts` — ESM builds, a data-only build and type
  declarations; nothing here imports them.
- `dist/js/locale/` — the plugin passes its own translated strings through `uiTranslations`, so they
  go through the `send4` text domain like every other string.
- `dist/css/intlTelInput-no-assets*.css`, `dist/css/styles.d.ts` — the variant without flag sprites.
- `dist/img/*.png` (196 KB) — the stylesheet only references the two `.webp` sprites.
- `README.md`, `package.json` — upstream documentation and metadata.

## ⚠️ Never put this under a directory called `vendor`

`bin/build-zip.sh` excludes paths with `.distignore`, whose `vendor` entry matches that name at **any**
depth. `public/vendor/intl-tel-input/` builds green and ships a plugin with no picker. CI asserts
`send4/public/lib/intl-tel-input/js/utils.js` is in the zip for exactly this reason.

## Reproduce or upgrade

```sh
npm pack intl-tel-input@29.2.3
shasum -a 256 intl-tel-input-29.2.3.tgz
tar xzf intl-tel-input-29.2.3.tgz
D=public/lib/intl-tel-input
cp package/LICENSE "$D/"
cp package/dist/js/intlTelInput.js package/dist/js/intlTelInput.min.js package/dist/js/utils.js "$D/js/"
cp package/dist/css/intlTelInput.css package/dist/css/intlTelInput.min.css "$D/css/"
cp package/dist/img/flags.webp "$D/img/"
cp package/dist/img/flags@2x.webp "$D/img/flags-2x.webp"
(cd "$D" && shasum -a 256 LICENSE js/* css/* img/*)
```

Then update the version and checksums above, bump `ITI_VERSION`, regenerate the two derived stylesheets with
`php bin/build-scoped-picker-css.php` and `php bin/build-flag-css.php` (`PhoneScopedCssTest` and
`PhoneFlagCssTest` fail until you do), and run the suite:
`PhoneValidatorTest::test_every_dial_code_the_picker_offers_is_accepted` fails if the new release can
select a calling code the server would refuse. `public/js/phone-field.js` refuses to run against any
major other than 29, so a major upgrade also means reading its changelog for `separateDialCode`,
`strictMode` and `uiTranslations`.
