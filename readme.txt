=== Send4 – AI SMS Lead Follow-up for Contact Forms ===
Contributors: send4
Tags: sms, leads, lead generation, contact form 7, wpforms
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Text every new form lead within seconds. Send4's AI follows up over SMS for Contact Form 7, WPForms, Gravity Forms and Elementor Pro.

== Description ==

Send4 texts every new lead from your WordPress contact forms within seconds. When someone submits a Contact Form 7,
WPForms, Gravity Forms or Elementor Pro form on your site, this plugin hands the submission to your Send4 account, and
the AI flow you set up there starts an SMS conversation with that person: it answers their questions, asks what you
need to know, and can book a call.

Most leads that come through a contact form go cold before anybody answers them. Send4 is the follow-up that
answers first.

You do not have to build a payload, sign a webhook, or write a line of code. Install the plugin, connect it to
your Send4 account with one button, pick which form field holds the phone number, and turn the form on.

**What it does**

* Works with Contact Form 7, WPForms, Gravity Forms and Elementor Pro.
* Maps the phone number, name and email once per form, in wp-admin.
* Passes every other field through as context, so the AI knows what the person asked for.
* Retries for about a day and a half if Send4 is briefly unreachable, so a lead is never lost.
* Tells you, per form, whether the last submission arrived and whether a Send4 flow is listening for it.
* Optionally adds a country picker to your phone field, and refuses a number with no country code while the
  visitor is still on the form — so the number Send4 gets is one it can text.

**You need a Send4 account and a number to text from.** Send4 is a paid service; this plugin is the WordPress half
of it. Send4 texts your leads through your own Twilio or Vonage account, which you connect in Send4. If your leads are
in Lithuania, Send4 can provide the sending number instead. Until everything is in place, the plugin's dashboard lists
what is still missing, with a link to fix each step in Send4.

== External services ==

This plugin sends data to Send4 (https://send4.ai), the service it integrates with. Nothing is transmitted
until you start connecting the site to Send4, and no visitor's submission is sent until you also enable a form.

* **Connecting.** Pressing **Connect with Send4** sends your site's address and the address of this plugin's
  Connection screen in wp-admin, so that Send4 can create an API key bound to this site and send you back here
  to finish; the one-time code it returns is then exchanged for that key. If you paste an API key instead, the
  key is sent to Send4 to obtain an access token and to confirm which account it belongs to.
* **What is sent:** for each submission on an enabled form — the phone number, name and email address you
  mapped, the remaining form fields, the form's name and key, your site URL, and a random submission
  identifier used to avoid sending the same lead twice.
* **When:** at the moment the form is submitted, and again on retry if the first attempt failed.
* **Where:** to `https://app.send4.ai` by default, over HTTPS.
* **A list of your forms.** So that Send4 can offer your forms by name when you build a flow, the plugin
  also sends a description of every form it detects: the form's name and key, whether you have enabled it
  here, and each field's label and type. **No submitted values are included** — this describes the shape of
  your forms, not anything a visitor typed. It is sent when you connect the site (before any form is
  enabled), when you change a form's settings, once a day, and when you press **Sync forms to Send4**.
* **Which versions this site runs.** With the list of forms, the plugin sends the version of this plugin, of
  WordPress, of PHP and, when it is installed, of WooCommerce, the site's language, and whether the site is part of
  a multisite network. Send4 uses this to know which versions are still in use before it changes anything they
  depend on.
* **Checking the account.** When you open the plugin's screens, and once a day, the plugin asks Send4 which flows
  listen for this site's forms, whether your account is ready to text leads, and which version of this plugin Send4
  needs. That request sends nothing but the connection's credentials and the User-Agent described below.
* **With every request** the plugin identifies itself and its version, and includes your site's address, in the
  HTTP User-Agent header.
* Send4's terms of service: https://send4.ai/terms — privacy policy: https://send4.ai/privacy

Because the data you forward includes personal information about your visitors, tell them so. Your form
needs a clear consent line explaining that submitting it means Send4 may text them, and your privacy policy
should name Send4 as a processor. In the United States, SMS follow-up is also regulated by the TCPA and
carrier 10DLC rules; get consent language right before you switch a form on.

== Installation ==

1. Install and activate the plugin.
2. In WordPress, go to **Send4 → Connection** and press **Connect with Send4**. Approve the connection in
   Send4; you are brought back to WordPress and should see "Connected to *your account*".
3. Prefer to paste a key? In your Send4 dashboard, create an API key with the **WordPress plugin**
   permission, copy the client ID and secret (the secret is shown only once), and paste both on the same
   screen instead.
4. Go to **Send4 → Forms & Triggers**, choose a form, pick the field that holds the phone number, and enable it.
5. In Send4, create a flow whose trigger is **WordPress form**, and tick the forms it should listen for —
   they are listed by name, because the plugin reports them. Choose "All forms" to accept every form on
   this site.

Use one API key per website. Send4 binds a key to the site it was created for and refuses
submissions that arrive from anywhere else.

== Frequently Asked Questions ==

= How do I text a lead as soon as they submit a contact form? =

Connect the plugin to Send4, then go to **Send4 → Forms & Triggers**, pick the form, choose the field that holds the
phone number and turn the form on. In Send4, a flow with a **WordPress form** trigger texts each new lead within
seconds. The steps are the same for Contact Form 7, WPForms, Gravity Forms and Elementor Pro.

= Do I need a phone number field? =

Yes. Send4 follows up by SMS, so a submission with no phone number has nobody to reach. The plugin refuses
to enable a form until you tell it which field holds the number.

= A form sends, but nobody is texted. Why? =

Open **Send4 → Dashboard**. When your Send4 account cannot text anyone yet, a card at the top, "Before Send4 can text
your leads", lists each missing step: an active plan, a flow listening for your forms, the phone number Send4 texts
from, and the account that number sends through. Each step links to the page in Send4 that fixes it. The submissions
themselves are not lost: Send4 has them.

= Which number are my leads texted from? =

One you connect in Send4. Send4 sends through your own Twilio or Vonage account: add the account's keys under
**Settings → Integrations → Provider credentials** in Send4, add the phone number under **Settings → Phones**, and
put the reply addresses Send4 shows you into your Twilio or Vonage console so that answers come back. The account, the
number and what they cost are yours, and so are the rules your country sets for business texting, such as 10DLC
registration in the United States.

If your leads are in Lithuania, Send4 can provide the number instead: ask us to add one to your account. It texts
Lithuanian numbers only.

= What happens to fields I do not map? =

They are all sent as context, keyed by their label, and Send4 gives them to the AI so it can answer
questions about what the person actually asked for. Nothing is dropped.

= Does the visitor wait for Send4? =

No longer than three seconds. If Send4 does not answer within that, the submission goes into a local retry
queue and the visitor sees their normal confirmation immediately.

= What if Send4 is down? =

The submission is stored in a table in your database and retried on a backoff — after a minute, five
minutes, fifteen, an hour, then every six hours for about a day and a half. Retries reuse the same
submission identifier, so a lead cannot be delivered twice.

= Which form plugins does it work with? =

Contact Form 7, WPForms (including Lite), Gravity Forms and Elementor Pro are supported today. Each one is
a small adapter class; ask us and we will add yours.

= Why would a form refuse a phone number? =

Only if you turned on **Refuse a submission whose phone number has no country code** for that form (it is off
by default). Send4 texts the lead, and a text needs the full international number: `+44 20 7946 0958`, not
`020 7946 0958`. The plugin never guesses a country code for a number that has none — it asks the visitor to
add one instead, with the message "Please enter your phone number with its country code, starting with + —
otherwise we cannot text you back."

The check is deliberately loose: a leading `+` (or `00`) and a real country code, 7 to 15 digits. It does not
second-guess the length of a number inside a country. An empty, optional phone field is never refused.

Every refusal is written to the activity log on the Send4 dashboard with the form, the reason and the shape of
what was typed — the first two or three digits and how many there were, as in `86••••••• (9 digits)` — but never
the number itself. That is enough to see that visitors are typing the national format, and not enough to call
one of them. The dashboard also counts refusals per form. To see which forms check, run `wp send4 forms` and read the `validates` column.

Developers can change the message with the `send4_phone_error_message` filter, and add a country calling code
with `send4_phone_calling_codes`.

= What does the country picker do? =

With **Add a country picker to the phone field** on, the visitor chooses a country from a searchable list, the
number is formatted as they type, and it is sent to your form plugin as `+37060012345`. It is added to the field
you mapped as the phone number, whatever kind of field that is — you do not need to rebuild your form.

It loads only on pages that show a form with the picker on. If you use a page cache, clear it after changing
the setting.

On Gravity Forms, set the phone field's format to **International**. The "Standard" format only accepts
North American numbers written `(###) ###-####` and would refuse the number the picker sends, so a field in
that format gets no picker and no country-code check. The form's Send4 settings say so when that is the case.

= What about visitors with JavaScript turned off? =

They see your form exactly as before, with no picker. If the form refuses numbers without a country code, the
check still runs when they submit, so they will be asked to type the `+` and country code themselves. Nothing
is lost: they can fix it and send the form again.

= The picker does not appear in my Elementor popup =

A popup Elementor loads *after* the page has loaded, over AJAX, is the one case the plugin cannot see — Elementor
decides what that response contains. The check on submission still works; only the picker is missing. Declare
the form yourself, with the popup's form widget id (the `data-id` on the widget) and the phone field's ID:

`add_filter( 'send4_phone_targets', function ( $targets ) {
	$targets[] = array(
		'scope'    => '[data-id="a1b2c3d"]',
		'input'    => '[name="form_fields[phone]"]',
		'validate' => true,
	);
	return $targets;
} );`

= I use WPForms Pro's Smart phone field. Will I get two pickers? =

No. If a field already has a picker, Send4 leaves it alone, and the check on submission still applies.

= Does it phone home if I do not configure it? =

No. Until you start connecting the site, the plugin makes no outbound requests at all.

== Third-party libraries ==

The country picker bundles **intl-tel-input** 29.2.3 by Jack O'Connor, under the MIT licence
(https://github.com/jackocnr/intl-tel-input). It is shipped unmodified in `public/lib/intl-tel-input/`, with its
licence, and the unminified source sits next to the minified build. One image is renamed, `flags@2x.webp` to
`flags-2x.webp`, because the plugin directory does not accept an "@" in a file name. Everything is served from your
own site; nothing is loaded from a third-party host.

`utils.js`, which formats and checks the number, is a compiled build. It is Google's libphonenumber (Apache License
2.0, https://github.com/google/libphonenumber) with intl-tel-input's wrapper, compiled by Google Closure Compiler.
The sources and the build script, at the version shipped:

* Wrapper source: https://github.com/jackocnr/intl-tel-input/blob/v29.2.3/packages/core/src/js/utils.js
* Build script: https://github.com/jackocnr/intl-tel-input/blob/v29.2.3/scripts/build-utils.js (`npm run build:utils`
  in that repository, which pulls libphonenumber in as a git submodule)

== Screenshots ==

1. Send4 → Dashboard: what has been sent, the retry queue, and recent activity.
2. Choosing which field holds the phone number.
3. The forms list, showing delivery status per form.

== Changelog ==

= 0.2.2 =
* Listing assets for WordPress.org: the plugin directory now has Send4's icon, banner and screenshots.
* The plugin is now called "Send4 – AI SMS Lead Follow-up for Contact Forms" in the directory and on the Plugins screen. Nothing else changes.

= 0.2.1 =
* Needs WordPress 6.2 or later. The retry queue now names its database table through WordPress's own
  placeholder for table names, which arrived in 6.2.
* A client secret pasted on the Connection screen is checked before it is tried. One that cannot be a Send4
  secret, for example with a space inside it, is refused with a message saying so.
* Every Send4 screen action checks its security token in the action itself. Nothing changes on screen.

Older releases are listed in `changelog.txt` in the plugin folder.

== Upgrade Notice ==

= 0.2.2 =
Needs WordPress 6.2 or later. Includes 0.2.0 and 0.2.1: the dashboard shows what your Send4 account still needs before it can text your leads, and you are told when Send4 needs a newer version of this plugin.
