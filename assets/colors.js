/*
 * Omni Platform Newsletter: per-issue colours, with a live preview in the editor.
 * No build step. Mirrors resolveColors() in includes/colors.php so the editor
 * shows what web, print and email will use.
 */
(function (wp, data) {
    'use strict';

    if (!wp || !data || !wp.plugins) {
        return;
    }

    const el = wp.element.createElement;
    const { Fragment, useState, useEffect } = wp.element;
    const { __, sprintf } = wp.i18n;
    const { useSelect, useDispatch } = wp.data;
    const { Button, ColorPalette, Dropdown, ToggleControl } = wp.components;
    const editorPkg = wp.editor && wp.editor.PluginDocumentSettingPanel ? wp.editor : wp.editPost;
    const { PluginDocumentSettingPanel } = editorPkg;
    const td = 'omni-platform-newsletter';
    const SIDES = ['front', 'back'];
    const SIDE_LABELS = { front: __('Front page', td), back: __('Back page', td) };
    const SLOT_LABELS = { background: __('Background', td), text: __('Text', td), heading: __('Headings', td), title: __('Title', td) };

    /* Paragraph styles for issue labels: a full-width bar, or a tag tucked over the photo above. */
    if (wp.blocks && wp.blocks.registerBlockStyle) {
        wp.blocks.registerBlockStyle('core/paragraph', { name: 'opn-label', label: __('Issue label', td) });
        wp.blocks.registerBlockStyle('core/paragraph', { name: 'opn-tag', label: __('Tag over photo', td) });
    }

    /* Colour maths (same rules as includes/palette.php). */
    function hexToRgb(hex) {
        let h = String(hex || '').trim().replace(/^#/, '');
        if (h.length === 3) {
            h = h.split('').map((c) => c + c).join('');
        }
        return /^[0-9a-f]{6}$/i.test(h) ? [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) : null;
    }
    function normalize(hex) {
        const rgb = hexToRgb(hex);
        return rgb ? '#' + rgb.map((c) => c.toString(16).padStart(2, '0')).join('') : '';
    }
    function luminance(hex) {
        const c = (hexToRgb(hex) || [0, 0, 0]).map((v) => {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }
    function contrast(a, b) {
        const la = luminance(a);
        const lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }
    const readableOn = (bg, text) => (contrast(bg, '#ffffff') >= contrast(bg, text) ? '#ffffff' : text);

    const roles = [].concat(...Object.values(data.regions).map((r) => Object.values(r.slots)));

    /** Stored meta → {linked, front, back} with known roles and valid colours only. */
    function parse(raw) {
        let value = {};
        try {
            value = raw ? JSON.parse(raw) : {};
        } catch (e) {
            value = {};
        }
        const out = { linked: value.linked === undefined ? true : !!value.linked, front: {}, back: {} };
        SIDES.forEach((side) => {
            Object.entries(value[side] || {}).forEach(([role, hex]) => {
                if (roles.includes(role) && normalize(hex)) {
                    out[side][role] = normalize(hex);
                }
            });
        });
        return out;
    }

    function serialize(stored) {
        const front = stored.front || {};
        const back = stored.linked ? {} : (stored.back || {});
        if (stored.linked && !Object.keys(front).length) {
            return '';
        }
        return JSON.stringify({ linked: !!stored.linked, front, back });
    }

    /** Every role filled from the author's choices and the site palette (see resolveColors()). */
    function resolve(overrides) {
        const d = data.defaults;
        const out = {};
        Object.values(data.regions).forEach(({ slots }) => {
            const bg = overrides[slots.background] || d[slots.background];
            out[slots.background] = bg;
            const text = overrides[slots.text]
                || (overrides[slots.background] ? readableOn(bg, data.baseText) : d[slots.text]);
            out[slots.text] = text;
            ['heading', 'title'].forEach((slot) => {
                if (slots[slot]) {
                    const role = slots[slot];
                    out[role] = overrides[role] || (contrast(d[role], bg) >= 3 ? d[role] : text);
                }
            });
        });
        return out;
    }

    const resolveIssue = (stored) => ({
        front: resolve(stored.front),
        back: resolve(stored.linked ? stored.front : stored.back),
    });

    /** Editor-canvas CSS so the sections look like the printed pages while you edit. */
    function canvasCss(c) {
        const w = '.editor-styles-wrapper ';
        const headings = ':where(h1,h2,h3,h4,h5,h6)';
        const page = (sel, x) => [
            `${w}${sel}{background:${x.page}!important;color:${x.pageText};}`,
            `${w}${sel} ${headings},${w}${sel} .opn-kicker{color:${x.heading};}`,
            `${w}${sel} .opn-title{color:${x.title};}`,
            `${w}${sel} :is(.opn-issue-label,.is-style-opn-label,.is-style-opn-tag){background:${x.label};color:${x.labelText};padding:6px 14px;font-weight:700;text-align:right;}`,
            `${w}${sel} .is-style-opn-tag{width:fit-content;max-width:90%;margin-left:auto!important;margin-top:-1.8em!important;position:relative;z-index:1;}`,
            `${w}${sel} .opn-callout{background:${x.callout};color:${x.calloutText};padding:14px 18px;}`,
            `${w}${sel} .opn-callout ${headings}{color:${x.calloutHeading};}`,
            `${w}${sel} a{color:inherit;}`,
        ].join('');
        const s = c.front;
        return page('.opn-source-front', c.front) + page('.opn-source-back', c.back)
            + `${w}.opn-source-sidebar{background:${s.sidebar}!important;color:${s.sidebarText};}`
            + `${w}.opn-source-sidebar ${headings}{color:${s.sidebarHeading};}`
            + `${w}.opn-source-sidebar a{color:inherit;}`
            + `${w}.opn-source-sidebar .opn-motto{background:${s.motto};color:${s.mottoText};padding:16px;text-align:center;}`
            + `${w}.opn-source-sidebar .opn-contact-card{background:${s.card};color:${s.cardText};padding:16px;}`
            + `${w}.opn-source-sidebar .opn-contact-card ${headings}{color:${s.cardHeading};}`
            + `${w}.opn-source-footer{background:${s.footer}!important;color:${s.footerText};}`
            + `${w}.opn-source-footer a{color:inherit;}`;
    }

    /** Write the preview CSS into the editor canvas (an iframe in current WordPress). */
    let lastCss = '';
    function applyCanvasCss() {
        const docs = [document];
        document.querySelectorAll('iframe[name="editor-canvas"]').forEach((frame) => {
            if (frame.contentDocument) {
                docs.push(frame.contentDocument);
            }
        });
        docs.forEach((doc) => {
            if (!doc.head) {
                return;
            }
            let style = doc.getElementById('opn-live-colors');
            if (!style) {
                style = doc.createElement('style');
                style.id = 'opn-live-colors';
                doc.head.appendChild(style);
            }
            if (style.textContent !== lastCss) {
                style.textContent = lastCss;
            }
        });
    }
    // The canvas iframe can be recreated (device preview, mode switches): keep the style in place.
    setInterval(applyCanvasCss, 1000);

    function useStoredColors() {
        const raw = useSelect((s) => (s('core/editor').getEditedPostAttribute('meta') || {})[data.metaKey] || '', []);
        const { editPost } = useDispatch('core/editor');
        const stored = parse(raw);
        const write = (next) => editPost({ meta: { [data.metaKey]: serialize(next) } });
        return { stored, write };
    }

    /** Always mounted: keeps the canvas preview in step with the colours. */
    function LiveColors() {
        const { stored } = useStoredColors();
        const css = canvasCss(resolveIssue(stored));
        useEffect(() => {
            lastCss = css;
            applyCanvasCss();
        }, [css]);
        return null;
    }

    const line = (color, kind) => el('span', { className: 'opn-mini__line' + (kind ? ' opn-mini__line--' + kind : ''), style: { background: color } });

    /** A small picture of one printed page in its colours. */
    function MiniSheet({ colors: c, side, active, onPick }) {
        return el('button', {
            type: 'button',
            className: 'opn-mini' + (active ? ' is-active' : ''),
            onClick: () => onPick(side),
            'aria-pressed': active,
            title: sprintf(__('Edit the %s colours', td), SIDE_LABELS[side].toLowerCase()),
        },
        el('span', { className: 'opn-mini__sheet', 'aria-hidden': true },
            el('span', { className: 'opn-mini__side', style: { background: c.sidebar } },
                el('span', { className: 'opn-mini__motto', style: { background: c.motto } }, line(c.mottoText), line(c.mottoText)),
                el('span', { className: 'opn-mini__card', style: { background: c.card } }, line(c.cardHeading, 'h'), line(c.cardText)),
                line(c.sidebarHeading, 'h'), line(c.sidebarText), line(c.sidebarText), line(c.sidebarText)),
            el('span', { className: 'opn-mini__main', style: { background: c.page } },
                el('span', { className: 'opn-mini__photo' }, el('span', { className: 'opn-mini__tag', style: { background: c.label } })),
                el('span', { className: 'opn-mini__title', style: { background: c.title } }),
                line(c.heading, 'h'), line(c.pageText), line(c.pageText), line(c.pageText),
                el('span', { className: 'opn-mini__callout', style: { background: c.callout } }, line(c.calloutHeading, 'h'), line(c.calloutText))),
            el('span', { className: 'opn-mini__footer', style: { background: c.footer } }, line(c.footerText))),
        el('span', { className: 'opn-mini__label' }, SIDE_LABELS[side]));
    }

    /** One colour of one area, with a palette and a reset. */
    function Swatch({ slot, role, value, isSet, options, onChange }) {
        const name = SLOT_LABELS[slot];
        const resetLabel = slot === 'background' ? __('Use the site colour', td) : __('Automatic', td);
        return el(Dropdown, {
            className: 'opn-swatch',
            popoverProps: { placement: 'left-start', offset: 12 },
            renderToggle: ({ isOpen, onToggle }) => el(Button, {
                className: 'opn-swatch__toggle',
                onClick: onToggle,
                'aria-expanded': isOpen,
                'data-opn-role': role,
                label: sprintf('%1$s: %2$s', name, isSet ? value : resetLabel),
                showTooltip: true,
            },
            el('span', { className: 'opn-swatch__chip' + (isSet ? '' : ' is-auto'), style: { background: value } }),
            el('span', { className: 'opn-swatch__name' }, name)),
            renderContent: () => el('div', { className: 'opn-swatch__popover' },
                el(ColorPalette, {
                    colors: options,
                    value: isSet ? value : undefined,
                    onChange: (next) => onChange(next ? normalize(next) : null),
                    clearable: false,
                    enableAlpha: false,
                }),
                el(Button, { variant: 'secondary', onClick: () => onChange(null), disabled: !isSet, className: 'opn-swatch__reset' }, resetLabel)),
        });
    }

    function SchemeButton({ scheme, active, onPick }) {
        const p = scheme.preview;
        return el(Button, {
            className: 'opn-scheme' + (active ? ' is-active' : ''),
            onClick: () => onPick(scheme),
            'aria-pressed': active,
            'data-opn-scheme': scheme.slug,
        },
        el('span', { className: 'opn-scheme__chips', 'aria-hidden': true },
            [p.sidebar, p.motto, p.page, p.title, p.label, p.callout].map((color, i) => el('span', { key: i, style: { background: color } }))),
        el('span', { className: 'opn-scheme__name' }, scheme.label));
    }

    const sameColors = (a, b) => {
        const ka = Object.keys(a);
        return ka.length === Object.keys(b).length && ka.every((k) => normalize(a[k]) === normalize(b[k]));
    };

    function ColorsPanel() {
        const { stored, write } = useStoredColors();
        const [picked, setPicked] = useState('front');
        const side = stored.linked ? 'front' : picked;
        const resolved = resolveIssue(stored);
        const current = resolved[side];
        const overrides = stored[side] || {};

        // Palette choices: the site's colours, then the colours already used in this issue.
        const seen = new Set();
        const options = [];
        const add = (name, color) => {
            const hex = normalize(color);
            if (hex && !seen.has(hex)) {
                seen.add(hex);
                options.push({ name, color: hex });
            }
        };
        data.siteColors.forEach((c) => add(c.name, c.color));
        SIDES.forEach((s) => Object.values(resolved[s]).forEach((hex) => add(hex, hex)));
        add(__('White', td), '#ffffff');
        add(__('Black', td), '#000000');

        const update = (changes) => {
            const next = { linked: stored.linked, front: { ...stored.front }, back: { ...stored.back } };
            changes(next);
            write(next);
        };
        const setRole = (role, value) => update((next) => {
            if (value) {
                next[side][role] = value;
            } else {
                delete next[side][role];
            }
        });
        const applyScheme = (scheme) => update((next) => {
            next[side] = { ...scheme.colors };
        });
        const setLinked = (on) => update((next) => {
            next.linked = on;
            // Linking uses the front page's colours on both; unlinking starts the back as a copy.
            next.back = on ? {} : { ...next.front };
            if (!on) {
                setPicked('back');
            }
        });

        return el(PluginDocumentSettingPanel, { name: 'omni-newsletter-colors', title: __('Colours', td), className: 'opn-colors', initialOpen: true },
            el('p', { className: 'opn-panel__hint opn-colors__intro' }, __('Pick a ready-made scheme, then fine-tune any part. The editor shows your colours as you choose them; print and email use them too.', td)),
            el('div', { className: 'opn-colors__minis' },
                SIDES.map((s) => el(MiniSheet, {
                    key: s,
                    side: s,
                    colors: resolved[s],
                    active: !stored.linked && side === s,
                    onPick: (which) => (stored.linked ? null : setPicked(which)),
                }))),
            el(ToggleControl, {
                label: __('Same colours on front and back', td),
                checked: stored.linked,
                onChange: setLinked,
                help: stored.linked
                    ? __('Turn off to give the back page its own colours.', td)
                    : __('Choose which page to change below, or click a page picture.', td),
            }),
            !stored.linked && el('div', { className: 'opn-colors__sides', role: 'group', 'aria-label': __('Page to change', td) },
                SIDES.map((s) => el(Button, { key: s, variant: side === s ? 'primary' : 'secondary', onClick: () => setPicked(s), isPressed: side === s }, SIDE_LABELS[s])),
                el(Button, {
                    variant: 'link',
                    className: 'opn-colors__copy',
                    onClick: () => update((next) => { next.back = { ...next.front }; }),
                }, __('Copy front colours to the back', td))),

            el('h3', { className: 'opn-panel__heading' }, stored.linked ? __('Colour scheme', td) : sprintf(__('Colour scheme: %s', td), SIDE_LABELS[side])),
            el('div', { className: 'opn-colors__schemes' },
                data.schemes.map((scheme) => el(SchemeButton, {
                    key: scheme.slug,
                    scheme,
                    active: sameColors(overrides, scheme.colors),
                    onPick: applyScheme,
                }))),

            el('h3', { className: 'opn-panel__heading' }, stored.linked ? __('Fine-tune', td) : sprintf(__('Fine-tune: %s', td), SIDE_LABELS[side])),
            Object.entries(data.regions).map(([key, region]) => el('div', { key, className: 'opn-colors__region', 'data-opn-region': key },
                el('div', { className: 'opn-colors__region-name' }, region.label,
                    el('span', { className: 'opn-colors__region-help' }, region.help)),
                el('div', { className: 'opn-colors__swatches' },
                    Object.entries(region.slots).map(([slot, role]) => el(Swatch, {
                        key: role,
                        slot,
                        role,
                        value: current[role],
                        isSet: !!overrides[role],
                        options,
                        onChange: (value) => setRole(role, value),
                    }))))),
            el(Button, {
                variant: 'tertiary',
                isDestructive: true,
                className: 'opn-colors__reset',
                disabled: !Object.keys(stored.front).length && !Object.keys(stored.back).length && stored.linked,
                onClick: () => write({ linked: true, front: {}, back: {} }),
            }, __('Reset all colours to the site colours', td)));
    }

    wp.plugins.registerPlugin('omni-platform-newsletter-colors', {
        render: () => el(Fragment, null, el(LiveColors), el(ColorsPanel)),
    });
}(window.wp, window.omniNewsletterColors));
