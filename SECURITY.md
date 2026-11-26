# Security policy

## Supported versions

Security fixes go into the latest release only. WordPress.org serves one version of the plugin and
a fix always ships as a new patch version, so the way to get it is to update.

## Reporting a vulnerability

Please do not open a public issue for a security problem.

Report it privately through GitHub:
**[Report a vulnerability](https://github.com/Send4ai/send4-wordpress/security/advisories/new)**
(the repository's **Security** tab → **Report a vulnerability**). Only the maintainers can read
the report.

It helps to include:

- the plugin version, the WordPress and PHP versions, and the form plugin involved;
- what an attacker can do, and what they need to do it: a logged-out visitor, a subscriber, an
  administrator;
- the steps to reproduce it, or a proof of concept.

Never put real lead data (names, phone numbers, email addresses) or live API credentials in a
report. If a Send4 credential has been exposed, revoke it in Send4 first, under
**Settings → API keys**.

We will confirm that we have the report, tell you what we find, and credit you in the advisory if
you would like that. Please give us the chance to release a fix before you publish the details.

## Scope

This policy covers the code in this repository, which is what the zip on WordPress.org contains.
Reports we particularly want to hear about are those that let someone:

- read, alter or replay the submissions waiting in the retry queue;
- read the stored Send4 credentials, or make the plugin send submissions somewhere other than
  Send4;
- act in the plugin's wp-admin screens without the capability or the nonce they check;
- inject markup or script through a form field, a form's name or anything Send4 returns.

A problem in the Send4 service itself, rather than in this plugin, can be reported the same way;
say so in the report. A problem in the bundled
[intl-tel-input](https://github.com/jackocnr/intl-tel-input) library belongs upstream, but tell us
too if it can be reached through the plugin.
