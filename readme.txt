=== Send4 – AI SMS Lead Follow-up for Contact Forms ===
Contributors: send4
Tags: sms, lead follow-up, lead generation, contact form 7, wpforms
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SMS lead follow-up for Contact Form 7, WPForms, Gravity Forms and Elementor Pro: an AI assistant texts each new lead in about a minute.

== Description ==

Send4 is SMS lead follow-up for WordPress forms. When someone submits a Contact Form 7, WPForms, Gravity Forms or Elementor Pro form, this plugin hands the submission to your Send4 account, and an AI assistant texts that person in about a minute: it answers their questions, asks your qualifying questions, and books a call.

Most leads that come through a contact form go cold before anybody answers them. The name for that gap is speed to lead, or lead response time: how long a new lead waits for the first reply. Send4 brings it down to about a minute, without anyone on your team watching the inbox.

[What it does for your site](https://send4.ai/integrations/wordpress) | [Send4](https://send4.ai) | [Source code on GitHub](https://github.com/Send4ai/send4-wordpress)

= What Send4 does with a lead =

* **SMS lead follow-up.** A flow in Send4 texts each new form lead, and it is a two-way SMS conversation, not a one-way notification.
* **Lead qualification.** The AI assistant asks the questions you would ask yourself, before a salesperson spends time on the lead.
* **Appointment booking.** It offers a time for a call, and the booking goes on your Send4 calendar.
* **Round-robin lead routing.** Each qualified lead goes to the next salesperson in turn.
* **A person can take over.** Anyone on your team can step into a conversation whenever they want to.

= What the plugin does =

* **No code, no webhooks.** Connect with one button, pick the field that holds the phone number, and turn the form on.
* **Works with the forms you have.** Contact Form 7, WPForms (including Lite), Gravity Forms and Elementor Pro.
* **Context included.** The other fields a visitor fills in are passed along, so the AI assistant knows what the person asked for. Password and card-payment fields are left out.
* **Outages covered.** If Send4 cannot be reached, the submission waits in a queue on your site and is retried for about a day and a half.
* **Numbers that can be texted.** Optionally adds a country picker to your phone field, and asks the visitor for a country code while they are still on the form.
* **Nothing hidden.** A dashboard in wp-admin shows what was sent for each form, what is waiting to be retried, and which Send4 flows are listening.
* **Site Health and WP-CLI.** Tools → Site Health reports on the connection and the retry queue, and `wp send4 status` prints both from a shell.

= How it works =

1. A visitor submits one of your forms.
2. The plugin sends the submission to Send4. Your visitor sees their usual confirmation and is not kept waiting.
3. In Send4, a flow, the workflow you build for that form, takes over: the AI assistant texts the lead, asks your qualifying questions, books a call on your Send4 calendar and hands the lead to your team.

= What you need =

**A Send4 account and a number to text from.** Send4 is a paid service; this plugin is the WordPress half of it. Send4 texts your leads through your own Twilio or Vonage account, which you connect in Send4. Until everything is in place, the plugin's dashboard lists what is still missing, with a link to fix each step in Send4.

= Bugs and security =

* Found a bug? [Open an issue on GitHub](https://github.com/Send4ai/send4-wordpress/issues).
* Found a security problem? Please report it privately, as the [security policy](https://github.com/Send4ai/send4-wordpress/security/policy) describes.

= Trademarks =

Contact Form 7, WPForms, Gravity Forms, Elementor, Twilio and Vonage are trademarks of their respective owners. Send4 is not affiliated with or endorsed by them.

== External services ==

This plugin sends data to Send4 (https://send4.ai), the service it integrates with. Nothing is transmitted until you start connecting the site to Send4, and no visitor's submission is sent until you also enable a form.

* **Connecting.** Pressing **Connect with Send4** sends your site's address and the address of this plugin's Connection screen in wp-admin, so that Send4 can create an API key bound to this site and send you back here to finish; the one-time code it returns is then exchanged for that key. If you paste an API key instead, the key is sent to Send4 to obtain an access token and to confirm which account it belongs to.
* **What is sent:** for each submission on an enabled form — the phone number, name and email address you mapped, the remaining form fields, the form's name and key, your site URL, and a random submission identifier used to avoid sending the same lead twice.
* **When:** at the moment the form is submitted, and again on retry if the first attempt failed.
* **Where:** to `https://app.send4.ai` by default, over HTTPS.
* **A list of your forms.** So that Send4 can offer your forms by name when you build a flow, the plugin also sends a description of every form it detects: the form's name and key, whether you have enabled it here, and each field's label and type. **No submitted values are included** — this describes the shape of your forms, not anything a visitor typed. It is sent when you connect the site (before any form is enabled), when you change a form's settings, once a day, and when you press **Sync forms to Send4**.
* **Which versions this site runs.** With the list of forms, the plugin sends the version of this plugin, of WordPress, of PHP and, when it is installed, of WooCommerce, the site's language, and whether the site is part of a multisite network. Send4 uses this to know which versions are still in use before it changes anything they depend on.
* **Checking the account.** When you open the plugin's screens, and once a day, the plugin asks Send4 which flows listen for this site's forms, whether your account is ready to text leads, and which version of this plugin Send4 needs. That request sends nothing but the connection's credentials and the User-Agent described below.
* **With every request** the plugin identifies itself and its version, and includes your site's address, in the HTTP User-Agent header.
* Send4's [terms of service](https://send4.ai/terms) and [privacy policy](https://send4.ai/privacy)

Because the data you forward includes personal information about your visitors, tell them so. Your form needs a clear consent line explaining that submitting it means Send4 may text them, and your privacy policy should name Send4 as a processor. In the United States, SMS follow-up is also regulated by the TCPA and carrier 10DLC rules; get consent language right before you switch a form on.

== Installation ==

1. Install and activate the plugin.
2. In WordPress, go to **Send4 → Connection** and press **Connect with Send4**. Approve the connection in Send4; you are brought back to WordPress and should see "Connected to *your account*".
3. Prefer to paste a key? In your Send4 dashboard, create an API key with the **WordPress plugin** permission, copy the client ID and secret (the secret is shown only once), and paste both on the same screen instead.
4. Go to **Send4 → Forms & Triggers**, choose a form, pick the field that holds the phone number, and enable it.
5. In Send4, create a flow whose trigger is **WordPress form**, and tick the forms it should listen for — they are listed by name, because the plugin reports them. Choose "All forms" to accept every form on this site.

Use one API key per website. Send4 binds a key to the site it was created for and refuses submissions that arrive from anywhere else.

== Frequently Asked Questions ==

= How do I text a lead as soon as they submit a contact form? =

Connect the plugin to Send4, then go to **Send4 → Forms & Triggers**, pick the form, choose the field that holds the phone number and turn the form on. In Send4, a flow with a **WordPress form** trigger texts each new lead in about a minute. The steps are the same for Contact Form 7, WPForms, Gravity Forms and Elementor Pro.

= What is speed to lead? =

Speed to lead, or lead response time, is how long a new lead waits for the first reply after sending a form. The longer it is, the more leads go cold. With this plugin and a flow in Send4, the first reply is a text in about a minute.

= Does it qualify leads and book appointments? =

Yes, in Send4. The flow you build there decides what the AI assistant does with each lead: lead qualification with your own questions, appointment booking on your Send4 calendar, and round-robin lead routing to the next salesperson. The plugin's part is getting every form submission there.

= Is this two-way SMS? =

Yes. The lead can answer the text and the AI assistant replies, for as long as the conversation takes. A person on your team can take over at any point, and anyone who texts STOP is opted out.

= Do I need a phone number field? =

Yes. Send4 follows up by SMS, so a submission with no phone number has nobody to reach. The plugin refuses to enable a form until you tell it which field holds the number.

= A form sends, but nobody is texted. Why? =

Open **Send4 → Dashboard**. When your Send4 account cannot text anyone yet, a card at the top, "Before Send4 can text your leads", lists each missing step: an active plan, a flow listening for your forms, the phone number Send4 texts from, and the account that number sends through. Each step links to the page in Send4 that fixes it. The submissions themselves are not lost: Send4 has them.

= Which number are my leads texted from? =

One you connect in Send4. Send4 sends through your own Twilio or Vonage account: add the account's keys under **Settings → Integrations → Provider credentials** in Send4, add the phone number under **Settings → Phones**, and put the reply addresses Send4 shows you into your Twilio or Vonage console so that answers come back. The account, the number and what they cost are yours, and so are the rules your country sets for business texting, such as 10DLC registration in the United States.

= What happens to fields I do not map? =

They are sent as context, keyed by their label, and Send4 gives them to the AI assistant so it can answer questions about what the person actually asked for. A field left empty is skipped, and password and card-payment fields are never sent.

= Does the visitor wait for Send4? =

Not for long. Each request the plugin makes to Send4 has a three-second limit, and a submission normally takes one request. If Send4 has not answered in time, the submission goes into a retry queue on your site, and the visitor sees their normal confirmation as usual.

= What if Send4 is down? =

The submission is stored in a table in your database and retried on a backoff — after a minute, five minutes, fifteen, an hour, then every six hours for about a day and a half. Retries reuse the same submission identifier, which is how Send4 recognises a submission it already has.

= Which form plugins does it work with? =

Contact Form 7, WPForms (including Lite), Gravity Forms and Elementor Pro are supported today. Each one is a small adapter class; ask us and we will add yours.

= Why would a form refuse a phone number? =

Only if you turned on **Refuse a submission whose phone number has no country code** for that form (it is off by default). Send4 texts the lead, and a text needs the full international number: `+44 20 7946 0958`, not `020 7946 0958`. The plugin never guesses a country code for a number that has none — it asks the visitor to add one instead, with the message "Please enter your phone number with its country code, starting with + — otherwise we cannot text you back."

The check is deliberately loose: a leading `+` (or `00`) and a real country code, 7 to 15 digits. It does not second-guess the length of a number inside a country. An empty, optional phone field is never refused.

Every number a form refuses when it is submitted is written to the activity log on the Send4 dashboard with the form, the reason and the shape of what was typed — the first two or three digits and how many there were, as in `86••••••• (9 digits)` — but never the number itself. That is enough to see that visitors are typing the national format, and not enough to call one of them. The dashboard also counts refusals per form. With the country picker on, most mistyped numbers are stopped in the visitor's browser before the form is sent, and those are not logged. To see which forms check, run `wp send4 forms` and read the `validates` column.

Developers can change the message with the `send4_phone_error_message` filter, and add a country calling code with `send4_phone_calling_codes`.

= What does the country picker do? =

With **Add a country picker to the phone field** on, the visitor chooses a country from a searchable list, the number is formatted as they type, and it is sent to your form plugin as `+37060012345`. It is added to the field you mapped as the phone number, whether that is a phone field or a plain text field — you do not need to rebuild your form.

It loads only on pages that show a form with the picker on. If you use a page cache, clear it after changing the setting.

On Gravity Forms, set the phone field's format to **International**. The "Standard" format only accepts North American numbers written `(###) ###-####` and would refuse the number the picker sends, so a field in that format gets no picker and no country-code check. The form's Send4 settings say so when that is the case.

= What about visitors with JavaScript turned off? =

They see your form exactly as before, with no picker. If the form refuses numbers without a country code, the check still runs when they submit, so they will be asked to type the `+` and country code themselves. Nothing is lost: they can fix it and send the form again.

= The picker does not appear in my Elementor popup =

A popup Elementor loads *after* the page has loaded, over AJAX, is the one case the plugin cannot see — Elementor decides what that response contains. The check on submission still works; only the picker is missing. Declare the form yourself, with the popup's form widget id (the `data-id` on the widget) and the phone field's ID:

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

The country picker bundles [intl-tel-input](https://github.com/jackocnr/intl-tel-input) 29.2.3 by Jack O'Connor, under the MIT licence. It is shipped unmodified in `public/lib/intl-tel-input/`, with its licence, and the unminified source sits next to the minified build. One image is renamed, `flags@2x.webp` to `flags-2x.webp`, because the plugin directory does not accept an "@" in a file name. Everything is served from your own site; nothing is loaded from a third-party host.

The file `utils.js`, which formats and checks the number, is a compiled build. It is Google's [libphonenumber](https://github.com/google/libphonenumber) (Apache License 2.0) with intl-tel-input's wrapper, compiled by Google Closure Compiler. The sources and the build script, at the version shipped:

* [Wrapper source](https://github.com/jackocnr/intl-tel-input/blob/v29.2.3/packages/core/src/js/utils.js)
* [Build script](https://github.com/jackocnr/intl-tel-input/blob/v29.2.3/scripts/build-utils.js) (`npm run build:utils` in that repository, which pulls libphonenumber in as a git submodule)

== Screenshots ==

1. SMS lead follow-up: an AI assistant texts a new form lead in about a minute, asks your qualifying questions and books a call.
2. Setting up a form: pick the field that holds the phone number, turn the form on, save.
3. The country picker on a visitor's form: they choose a country, and the number is sent with its country code.
4. Send4 → Dashboard: what happened to every submission, and what was sent for each form.
5. Send4 → Connection: the account this site is connected to, and the flows listening for its forms.
6. Tools → Site Health → Info: the connection, the retry queue and the form plugins found, ready to copy into a support request.

== Changelog ==

= 0.2.3 =
* Tools → Site Health now reports on Send4. Two tests say whether this site is connected, and whether form submissions are stuck on their way to Send4: waiting with nothing sending them, which usually means WP-Cron is not running, or never delivered. Nothing is sent to Send4 to produce them.
* Site Health's Info tab has a Send4 section with what a support request needs: the plugin version, the connection, the retry queue and which form plugins are active. Copying the site info leaves out your account name and client ID.
* The Send4 screens are tidier: checkboxes line up with their labels, cards are evenly spaced, and the Flows listening card on the dashboard no longer runs to its border.
* Fixed: when the phone field sits inside its label, the way Contact Form 7's default form writes its fields, choosing a country in the picker opened the list of countries again, and a click on the label opened the list instead of the field.
* The plugin directory has a new description and banner, an animated icon, and new screenshots, three of them animated: the text conversation, setting up a form, and the country picker.

= 0.2.2 =
* Listing assets for WordPress.org: the plugin directory now has Send4's icon, banner and screenshots.
* The plugin is now called "Send4 – AI SMS Lead Follow-up for Contact Forms" in the directory and on the Plugins screen. Nothing else changes.

Older releases are listed in `changelog.txt` in the plugin folder.

== Upgrade Notice ==

= 0.2.3 =
Adds Send4 to Tools → Site Health: whether this site is connected, and whether any form submission is stuck on its way to Send4. Fixes the country picker opening again after a country is chosen.
