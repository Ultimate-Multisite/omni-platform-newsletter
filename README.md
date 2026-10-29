# omni-platform-newsletter

Write one newsletter. Publish it on your website, email it to subscribers, and print it as a two-page, front-and-back US Letter.

Built for nonprofits on WordPress. See [`readme.txt`](readme.txt) for features, capabilities and filters.

## How it fits together

| Piece | Role |
|---|---|
| Omni (this plugin) | The only newsletter screens users see; layout contract; web and print views; send workflow |
| Newspack Newsletters | Block editor for the newsletter post type; email-safe HTML (generated on save) |
| The Newsletter Plugin | Subscribers, consent, lists, unsubscribe links, batching, delivery via `wp_mail()` |
| Bit Social (optional) | Shares published newsletters to connected social accounts |

## Code map

- `omni-platform-newsletter.php` — section contract, web/print rendering, email block order and flattening.
- `includes/admin.php` — menu, list/subscribers/settings pages, new-issue defaults, editor setup.
- `includes/delivery.php` — Newsletter plugin adapter: audience, test send, campaign send, status, sender fallback, inbox preview text.
- `includes/rest.php` — editor panel endpoints (`omni-platform-newsletter/v1/issues/{id}`, `/test`, `/send`).
- `includes/social.php` — Bit Social auto-post rule for newsletters.
- `includes/colors.php` — per-issue colour schemes (front and back pages), presets, resolution against the site palette (`palette()`).
- `assets/editor.js` — "Publish, print & email" panel (no build step).
- `assets/colors.js` — "Colours" panel, live editor-canvas preview, and the "Issue label" / "Tag over photo" paragraph styles (no build step).
- `docs/newsletter-colours.md` — step-by-step tutorial for editors.
- `layout.css`, `overflow.js`, `web-template.php` — website, print preview and print.

## Development

```bash
composer install
composer test
```
