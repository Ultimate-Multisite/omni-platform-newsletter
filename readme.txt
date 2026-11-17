=== Omni Platform Newsletter ===
Contributors: superdav42
Tags: newsletter, nonprofit, email, print, pdf, block editor
Requires at least: 6.9
Requires PHP: 8.2
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Write one newsletter. Publish it on your website, email it to subscribers, and print it as a two-page letter.

== Description ==

Omni Platform Newsletter gives nonprofits one simple Newsletters area:

* **Write** each issue once in the block editor, in four labeled sections:
  Sidebar, Front page, Back page and Footer. The first issue is pre-filled from
  the site (name, tagline, this month, the writer's contact details, website and
  giving page), so only the stories need writing. The sidebar and footer carry
  over to the next issue automatically.
* **Publish** it on the website, with a newsletter archive of cards (each
  issue's first photo and lead-story summary) and a sign-up box. Block themes
  get the archive template automatically; `[omni_newsletter_signup]` adds the
  sign-up box anywhere.
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
administrators) and `opn_manage_newsletter_engines`, which shows the engine
plugins' own screens: site administrators on single-site installs, network
administrators on multisite, or anyone granted it through the
`omni_platform_newsletter_user_manages_engines` filter.

Colors come from the active theme's palette (link color, a darker shade, its brightest
strong color, text and background), so web, print and email match the website.

Filters: `omni_platform_newsletter_palette`, `omni_platform_newsletter_starter_parts`
(pre-filled first-issue text and photos), `omni_platform_newsletter_starter_content`,
`omni_platform_newsletter_email_colors`, `omni_platform_newsletter_email_html`,
`omni_platform_newsletter_post_types`,
`omni_platform_newsletter_user_manages_engines`.

== Installation ==

1. Install and activate Newspack Newsletters and The Newsletter Plugin.
2. Install and activate Omni Platform Newsletter.
3. Go to Newsletters → Write a newsletter.

== Changelog ==

= 0.4.0 =
* Colours panel in the editor: pick a colour scheme for each newsletter, give the front and back pages different colours, and fine-tune the page, title, labels, highlight boxes, sidebar, motto, contact box and footer. The editor shows the colours as you choose them; web, print and email use them too. Schemes can be changed with `omni_platform_newsletter_color_schemes`; resolved colours with `omni_platform_newsletter_issue_colors`.
* Paragraph styles "Issue label" and "Tag over photo".
* Print keeps editor column widths (25 / 50 / 25 and similar), centred or right-aligned text, and scales editor font sizes to the page.
* A right-aligned date directly above the kicker prints on the kicker's line.
* Email: headings inside columns, the motto and contact box follow the issue colours; colours chosen on a block in the editor are kept.

= 0.3.1 =
* The send dialog says "1 subscriber" instead of "1 subscribers".

= 0.3.0 =
* The first newsletter is pre-filled from the site: name, tagline, month, the writer's name and email, website and giving page. Organizations can change any part (text or photos) with `omni_platform_newsletter_starter_parts`.
* Newsletter archive for block themes: issue cards with the first photo, month and lead-story summary, plus a sign-up box (`[omni_newsletter_signup]`).
* Each issue's first photo becomes its featured image until the writer picks one, and its excerpt is the lead story instead of the page masthead.
* Links in callouts and the printed footer use the panel's text color so they stay readable.

= 0.2.1 =
* A blank Newsletter sender name or address now defaults to the site's own name and WordPress From address (computed per site, never stored), so sites copied from a template send as themselves.

= 0.2.0 =
* Website view: site header and footer, breadcrumb (Home › Newsletters › issue), no duplicate post title, one continuous article with the sidebar as a sticky card and no newsletter footer, theme heading font.
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
