# Omni Platform Newsletter

Write a newsletter once in the WordPress block editor and publish it as:

- a responsive webpage;
- responsive, email-safe HTML through Newspack Newsletters; and
- a two-sided US Letter print/PDF layout through Print My Blog Pro Print.

The plugin is intended for nonprofits and other organizations that need one
reviewed source of truth for web, email, and physical mail. It does not import
contacts, configure a sending provider, or send campaigns.

## Requirements

- WordPress 6.9+
- PHP 8.2+
- [Newspack Newsletters](https://wordpress.org/plugins/newspack-newsletters/)
- [Print My Blog](https://wordpress.org/plugins/print-my-blog/)

## Authoring contract

Insert the **Omni duplex newsletter** block pattern into a Newspack newsletter.
It creates exactly four top-level groups:

- `opn-source-sidebar`
- `opn-source-front`
- `opn-source-back`
- `opn-source-footer`

Sidebar and footer content are stored once. They repeat on both print sides;
email output places the stories first and contact/support information once at
the end. Unexpected or duplicate top-level content falls back visibly rather
than being silently discarded.

The default print palette uses slate, navy, yellow, and white. Themes can
override the `--opn-*` custom properties under `.opn-issue` without changing
the plugin. The fixed two-page layout has a deliberate content budget; excess
copy is flagged and is never hidden or automatically shrunk.

## Development

```bash
composer install
composer test
```

`composer test` validates PHP syntax, PSR-12 style, the JavaScript asset, and
the section/fallback contract.

## Status

Version 0.1 provides the proven Newspack + Print My Blog adapter. Additional
authoring, email, and print adapters can be introduced without changing the
four-section content contract.
