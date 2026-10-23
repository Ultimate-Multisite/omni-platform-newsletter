# Omni Platform Newsletter instructions

- This repository contains the standalone `ultimate-multisite/omni-platform-newsletter` WordPress plugin.
- Preserve the four-section content contract: `opn-source-sidebar`, `opn-source-front`, `opn-source-back`, and `opn-source-footer`.
- Unsupported or duplicate top-level content must fail visibly; never silently discard newsletter copy.
- Print output remains exactly two portrait US Letter pages. Flag overflow instead of hiding or shrinking content.
- Omni is the only newsletter interface users see. Newspack Newsletters (block editor + email HTML), the Newsletter plugin (subscribers, consent, unsubscribe, batching, delivery) and Print My Blog are engines; their screens stay reserved for `opn_manage_newsletter_engines`.
- Sending goes through the Newsletter plugin (`includes/delivery.php`). Never bypass its unsubscribe tags, consent statuses or batching; never report a send as successful without checking for `wp_mail_failed`.
- Email layout cannot nest groups: keep `emailBlocks()` flattening story/callout groups into top-level email sections.
- Newspack strips `enqueue_block_assets` callbacks from the newsletter canvas unless they are named in `newspack_newsletters_allowed_editor_actions`; canvas assets must use named functions on that allowlist.
- Colors come from the theme palette (`includes/palette.php`); never hard-code organization colors. The website view renders inside the theme's post template; only `?opn_view=print` uses `web-template.php`.
- Keep organization-specific wording, assets, colors, and destinations out of the default pattern. Organizations use the `omni_platform_newsletter_starter_content` and `omni_platform_newsletter_email_colors` filters.
- Run `composer test` before delivery.
