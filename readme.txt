=== Omni Platform Newsletter ===
Contributors: superdav42
Tags: newsletter, nonprofit, email, print, pdf, block editor
Requires at least: 6.9
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write one newsletter. Publish it on your website, email it to subscribers, and print it as a two-page letter.

== Description ==

Omni Platform Newsletter gives nonprofits one simple Newsletters area:

* **Write** each issue once in the block editor, in four labeled sections:
  Sidebar, Front page, Back page and Footer. The sidebar and footer carry over
  to the next issue automatically.
* **Publish** it on the website (with a public newsletter archive).
* **Email** it: send yourself a test, then send to all subscribers or chosen
  lists, with the recipient count confirmed first. Each email has a personal
  unsubscribe link and, once published, a link to the website version.
* **Print / save as PDF**: an on-screen preview of the two US Letter pages that
  says whether everything fits, then prints front-and-back.
* **Subscribers**: add people (with consent), paste a list from a spreadsheet,
  search, unsubscribe, and download a CSV.
* **Social media** (optional, with Bit Social): share each newsletter on
  connected accounts when it is published.

Under the hood it uses Newspack Newsletters for the editor and email-safe HTML,
and The Newsletter Plugin for subscribers, consent, unsubscribe handling and
delivery. Their own admin screens are hidden from everyone except network
administrators.

Capabilities: `opn_write_newsletters`, `opn_send_newsletters`,
`opn_manage_subscribers`, `opn_manage_newsletter_settings` (all granted to site
administrators) and `opn_manage_newsletter_engines` (network administrators).

Colors come from the active theme's palette (link color, a darker shade, its brightest
strong color, text and background), so web, print and email match the website.

Filters: `omni_platform_newsletter_palette`, `omni_platform_newsletter_starter_content`,
`omni_platform_newsletter_email_colors`, `omni_platform_newsletter_email_html`,
`omni_platform_newsletter_post_types`,
`omni_platform_newsletter_user_manages_engines`.

== Installation ==

1. Install and activate Newspack Newsletters and The Newsletter Plugin.
2. Install and activate Omni Platform Newsletter.
3. Go to Newsletters → Write a newsletter.

== Changelog ==

= 0.2.0 =
* Website view uses the theme's post template (site header and footer), one continuous page, sidebar once, no newsletter footer.
* Web, print and email colors come from the theme palette, with readable text chosen by contrast.
* One Newsletters menu: list with website and email status, Write a newsletter, Subscribers, Settings.
* Send tests and campaigns through The Newsletter Plugin, with duplicate-send protection and delivery status.
* Fall back to WordPress's From address when the Newsletter plugin has no sender (cloned sites previously failed silently).
* Two-page print preview with a fit check; printing no longer needs Print My Blog.
* Editor: no welcome tour or layout picker, labeled and locked sections, a "Publish, print & email" panel, inbox preview text.
* Email: story and callout groups become separate styled sections with the print palette and typeface.
* Hide Newspack, Newsletter plugin and Print My Blog screens from non-network administrators.
* Optional Bit Social sharing of published newsletters.

= 0.1.3 =
* Add print-layout headroom for browser font and rounding differences.

= 0.1.2 =
* Preserve full overflow detection while fitting the starter front-page media budget.

= 0.1.1 =
* Avoid false print overflow warnings from fractional grid-child measurements.

= 0.1.0 =
* Initial Newspack authoring/email adapter and Print My Blog duplex layout.
