/*
 * Omni Platform Newsletter: layout, issue details and design tools for the block editor.
 * No build step.
 *
 * - Layout panel: sidebar beside each page, or a masthead across the top, with a
 *   ready-made masthead; the issue month, volume and number.
 * - "Issue detail" toolbar button: inserts the month, volume, number or page
 *   number into any text. They are filled in when the issue is shown, printed or emailed.
 * - QR code block: type a web address, get a QR code image.
 * - Block styles: playful, handwritten, stamp and nameplate headings; pill
 *   paragraphs; rounded boxes.
 */
(function (wp, data) {
    'use strict';

    if (!wp || !data || !wp.plugins) {
        return;
    }

    const el = wp.element.createElement;
    const { Fragment, useState, useEffect } = wp.element;
    const { __, sprintf } = wp.i18n;
    const { useSelect, useDispatch, select, dispatch } = wp.data;
    const { Button, Notice, RadioControl, SelectControl, TextControl, Placeholder, ToolbarDropdownMenu } = wp.components;
    const { BlockControls, useBlockProps } = wp.blockEditor;
    const editorPkg = wp.editor && wp.editor.PluginDocumentSettingPanel ? wp.editor : wp.editPost;
    const { PluginDocumentSettingPanel } = editorPkg;
    const td = 'omni-platform-newsletter';
    const keys = data.keys;
    const FORMAT = 'omni-platform-newsletter/issue-detail';

    /* Block styles. */
    if (wp.blocks && wp.blocks.registerBlockStyle) {
        [
            ['core/heading', 'opn-playful', __('Playful', td)],
            ['core/heading', 'opn-handwritten', __('Handwritten', td)],
            ['core/heading', 'opn-stamp', __('Stamp', td)],
            ['core/heading', 'opn-nameplate', __('Nameplate', td)],
            ['core/paragraph', 'opn-pill', __('Pill', td)],
            ['core/group', 'opn-box', __('Rounded box', td)],
        ].forEach(([block, name, label]) => wp.blocks.registerBlockStyle(block, { name, label }));
    }

    /*
     * Photos: one toolbar menu for size and text wrap, instead of alignment plus
     * width fields. Sizes are a share of the column, so they fit on paper too.
     */
    const PHOTO_CLASSES = ['opn-photo-small', 'opn-photo-medium', 'opn-photo-large'];
    const PHOTO_CHOICES = [
        { title: __('Full width', td), icon: 'align-full-width', align: undefined, size: '' },
        { title: __('Small, on the left — text wraps', td), icon: 'align-pull-left', align: 'left', size: 'small' },
        { title: __('Medium, on the left — text wraps', td), icon: 'align-pull-left', align: 'left', size: 'medium' },
        { title: __('Large, on the left — text wraps', td), icon: 'align-pull-left', align: 'left', size: 'large' },
        { title: __('Small, on the right — text wraps', td), icon: 'align-pull-right', align: 'right', size: 'small' },
        { title: __('Medium, on the right — text wraps', td), icon: 'align-pull-right', align: 'right', size: 'medium' },
        { title: __('Large, on the right — text wraps', td), icon: 'align-pull-right', align: 'right', size: 'large' },
        { title: __('Small, centred', td), icon: 'align-center', align: 'center', size: 'small' },
        { title: __('Medium, centred', td), icon: 'align-center', align: 'center', size: 'medium' },
        { title: __('Large, centred', td), icon: 'align-center', align: 'center', size: 'large' },
    ];
    if (wp.hooks && wp.compose) {
        wp.hooks.addFilter('editor.BlockEdit', 'omni-platform-newsletter/photo-layout', wp.compose.createHigherOrderComponent((BlockEdit) => function PhotoLayout(props) {
            if (props.name !== 'core/image' || !props.isSelected) {
                return el(BlockEdit, props);
            }
            const all = (props.attributes.className || '').split(/\s+/);
            const classes = all.filter((c) => c && !PHOTO_CLASSES.includes(c));
            const size = (PHOTO_CLASSES.find((c) => all.includes(c)) || '').replace('opn-photo-', '');
            const isCurrent = (choice) => choice.size === size && (choice.size ? choice.align === props.attributes.align : !props.attributes.align);
            const pick = (choice) => props.setAttributes({
                align: choice.align,
                className: [...classes, choice.size ? 'opn-photo-' + choice.size : ''].filter(Boolean).join(' ') || undefined,
                width: undefined,
                height: undefined,
            });
            return el(Fragment, null,
                el(BlockEdit, props),
                el(BlockControls, { group: 'block' },
                    el(ToolbarDropdownMenu, {
                        icon: 'format-image',
                        label: __('Photo size and text wrap', td),
                        controls: PHOTO_CHOICES.map((choice) => ({ title: choice.title, icon: choice.icon, isActive: isCurrent(choice), onClick: () => pick(choice) })),
                    })));
        }, 'withPhotoLayout'));
    }

    /* Issue details. */
    const DETAILS = [
        { key: 'issue_date', label: __('Issue month', td) },
        { key: 'volume', label: __('Volume', td) },
        { key: 'issue_number', label: __('Issue number', td) },
        { key: 'page', label: __('Page number (printed pages only)', td) },
    ];

    function monthLabel(month) {
        const m = /^(\d{4})-(\d{2})$/.exec(month || '');
        if (!m) {
            return '';
        }
        try {
            return new Date(Number(m[1]), Number(m[2]) - 1, 15).toLocaleDateString(document.documentElement.lang || undefined, { month: 'long', year: 'numeric' });
        } catch (e) {
            return month;
        }
    }

    /** What each detail shows in the editor (the server fills in the same values). */
    function previews(meta) {
        return {
            issue_date: monthLabel(meta[keys.month] || data.postMonth),
            volume: meta[keys.volume] || '[' + __('Volume', td) + ']',
            issue_number: meta[keys.number] || '[' + __('Issue number', td) + ']',
            page: '1',
        };
    }

    const escapeHtml = (text) => String(text).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const DETAIL_RE = /(<span\b(?=[^>]*\bdata-opn-detail="([a-z_]+)")[^>]*>)([\s\S]*?)(<\/span>)/g;

    /** Bring the text of inserted details up to date in every block. */
    function syncDetailText(values) {
        const update = dispatch('core/block-editor').updateBlockAttributes;
        const walk = (blocks) => blocks.forEach((block) => {
            const changes = {};
            Object.entries(block.attributes).forEach(([name, value]) => {
                const html = typeof value === 'string' ? value : (value && typeof value.toHTMLString === 'function' ? value.toHTMLString() : null);
                if (!html || html.indexOf('data-opn-detail') === -1) {
                    return;
                }
                const next = html.replace(DETAIL_RE, (all, open, key, inner, close) => (key in values ? open + escapeHtml(values[key]) + close : all));
                if (next !== html) {
                    changes[name] = next;
                }
            });
            if (Object.keys(changes).length) {
                update(block.clientId, changes);
            }
            walk(block.innerBlocks || []);
        });
        walk(select('core/block-editor').getBlocks());
    }

    if (wp.richText && wp.richText.registerFormatType) {
        const { applyFormat, create, insert } = wp.richText;
        wp.richText.registerFormatType(FORMAT, {
            title: __('Issue detail', td),
            tagName: 'span',
            className: 'opn-detail',
            attributes: { detail: 'data-opn-detail' },
            edit: function DetailButton({ value, onChange }) {
                const meta = useSelect((s) => s('core/editor').getEditedPostAttribute('meta') || {}, []);
                const shown = previews(meta);
                const add = (key) => {
                    const text = shown[key];
                    const piece = applyFormat(create({ text }), { type: FORMAT, attributes: { detail: key } }, 0, text.length);
                    onChange(insert(value, piece));
                };
                return el(BlockControls, { group: 'other' },
                    el(ToolbarDropdownMenu, {
                        icon: el('svg', { width: 24, height: 24, viewBox: '0 0 24 24', 'aria-hidden': true },
                            el('path', { d: 'M5 5h14v3h-2V7H7v10h4v2H5zM13 11h7v2h-7zm0 4h7v2h-7zm-4-6h2v2H9z' })),
                        label: __('Insert issue detail (month, volume, issue or page number)', td),
                        controls: DETAILS.map((d) => ({
                            title: sprintf('%1$s — %2$s', d.label, shown[d.key]),
                            onClick: () => add(d.key),
                        })),
                    }));
            },
        });
    }

    /* Live canvas: label the shared section by layout. */
    let canvasCss = '';
    function applyCanvasCss() {
        const docs = [document];
        document.querySelectorAll('iframe[name="editor-canvas"]').forEach((frame) => frame.contentDocument && docs.push(frame.contentDocument));
        docs.forEach((doc) => {
            if (!doc.head) {
                return;
            }
            let style = doc.getElementById('opn-live-layout');
            if (!style) {
                style = doc.createElement('style');
                style.id = 'opn-live-layout';
                doc.head.appendChild(style);
            }
            if (style.textContent !== canvasCss) {
                style.textContent = canvasCss;
            }
        });
    }
    setInterval(applyCanvasCss, 1000);

    function LiveLayout() {
        const meta = useSelect((s) => s('core/editor').getEditedPostAttribute('meta') || {}, []);
        const blockCount = useSelect((s) => s('core/block-editor').getGlobalBlockCount(), []);
        const top = meta[keys.layout] === 'top';
        const label = top
            ? __('Masthead · printed across the top of both pages, and first in the email', td)
            : __('Sidebar · printed beside both pages, and once at the end of the email', td);
        const shown = previews(meta);
        const shownKey = JSON.stringify(shown);

        useEffect(() => {
            canvasCss = '.editor-styles-wrapper .opn-source-sidebar::before{content:' + JSON.stringify(label) + ';}';
            applyCanvasCss();
        }, [label]);

        // Keep inserted details showing this issue's month, volume and number.
        useEffect(() => {
            if (blockCount) {
                syncDetailText(shown);
            }
        }, [shownKey, blockCount > 0]);
        return null;
    }

    function sharedSection() {
        return select('core/block-editor').getBlocks().find((b) => /(^|\s)opn-source-sidebar(\s|$)/.test(b.attributes.className || ''));
    }

    function LayoutPanel() {
        const meta = useSelect((s) => s('core/editor').getEditedPostAttribute('meta') || {}, []);
        const { editPost } = useDispatch('core/editor');
        const { createNotice } = useDispatch('core/notices');
        const top = meta[keys.layout] === 'top';
        const set = (key, value) => editPost({ meta: { [key]: value } });

        const fillMasthead = () => {
            const section = sharedSection();
            if (!section) {
                return;
            }
            dispatch('core/block-editor').replaceInnerBlocks(section.clientId, wp.blocks.parse(data.mastheadPattern), false);
            syncDetailText(previews(meta));
            createNotice('info', __('Masthead added. Change the name and contact details; use Undo to go back.', td), { type: 'snackbar' });
        };

        return el(PluginDocumentSettingPanel, { name: 'omni-newsletter-layout', title: __('Layout & issue details', td), className: 'opn-layout', initialOpen: true },
            el(RadioControl, {
                label: __('Shared section (entered once, printed on both pages)', td),
                selected: top ? 'top' : '',
                options: [
                    { label: __('Sidebar beside each page', td), value: '' },
                    { label: __('Masthead across the top of each page', td), value: 'top' },
                ],
                onChange: (value) => set(keys.layout, value),
            }),
            top && el(Fragment, null,
                el(Button, { variant: 'secondary', className: 'opn-wide', onClick: fillMasthead }, __('Use the ready-made masthead', td)),
                el('p', { className: 'opn-panel__hint' }, __('Replaces what is in the shared section with a name, contact box and issue details bar. Use Undo to go back.', td))),

            el(SelectControl, {
                label: __('Printed text size', td),
                value: meta[keys.textSize] || '',
                options: [
                    { label: __('Regular', td), value: '' },
                    { label: __('Compact (fits more on a page)', td), value: 'compact' },
                    { label: __('Small (fits the most)', td), value: 'small' },
                ],
                help: __('For the printed pages only. Use it when the print preview says a page is too long.', td),
                onChange: (value) => set(keys.textSize, value),
            }),

            el('h3', { className: 'opn-panel__heading' }, __('Issue details', td)),
            el(TextControl, {
                label: __('Issue month', td),
                type: 'month',
                value: meta[keys.month] || data.postMonth,
                onChange: (value) => set(keys.month, value),
            }),
            el('div', { className: 'opn-layout__pair' },
                el(TextControl, { label: __('Volume', td), value: meta[keys.volume] || '', onChange: (value) => set(keys.volume, value) }),
                el(TextControl, { label: __('Issue number', td), value: meta[keys.number] || '', onChange: (value) => set(keys.number, value) })),
            el('p', { className: 'opn-panel__hint' }, __('Put these into any text with the “Insert issue detail” button in the block toolbar. A copy of this issue moves on to the next month and number by itself.', td)));
    }

    wp.plugins.registerPlugin('omni-platform-newsletter-layout', {
        render: () => el(Fragment, null, el(LiveLayout), el(LayoutPanel)),
    });

    /* Start with the panel open: its choices shape the whole issue. */
    wp.domReady(() => {
        const panel = 'omni-platform-newsletter-layout/omni-newsletter-layout';
        const editor = select('core/editor');
        if (editor && editor.isEditorPanelOpened && !editor.isEditorPanelOpened(panel)) {
            dispatch('core/editor').toggleEditorPanelOpened(panel);
        }
    });

    /* QR code block: becomes an ordinary image, which works on the web, on paper and in email. */
    if (wp.blocks && wp.blocks.registerBlockType) {
        const qrIcon = el('svg', { width: 24, height: 24, viewBox: '0 0 24 24', 'aria-hidden': true },
            el('path', { d: 'M3 3h8v8H3zm2 2v4h4V5zm8-2h8v8h-8zm2 2v4h4V5zM3 13h8v8H3zm2 2v4h4v-4zm8-2h2v2h-2zm2 2h2v2h-2zm2-2h2v2h-2zm2 2h2v2h-2zm-6 2h2v2h-2zm4 0h2v2h-2zm-2 2h2v2h-2zm4 0h2v2h-2z' }));

        wp.blocks.registerBlockType('omni-platform-newsletter/qr-code', {
            apiVersion: 3,
            title: __('QR code', td),
            description: __('A QR code people can scan with a phone to open a web page, such as a sign-up form.', td),
            category: 'media',
            icon: qrIcon,
            keywords: [__('qr', td), __('scan', td), __('code', td), __('register', td)],
            attributes: { url: { type: 'string', default: '' } },
            supports: { html: false, reusable: false },
            edit: function QrEdit({ attributes, setAttributes, clientId }) {
                const blockProps = useBlockProps();
                const [busy, setBusy] = useState(false);
                const [error, setError] = useState('');
                const { replaceBlock } = useDispatch('core/block-editor');

                const create = async () => {
                    setBusy(true);
                    setError('');
                    try {
                        const qr = await wp.apiFetch({ path: data.qrPath, method: 'POST', data: { url: attributes.url } });
                        replaceBlock(clientId, wp.blocks.createBlock('core/image', {
                            id: qr.id,
                            url: qr.url,
                            alt: qr.alt,
                            sizeSlug: 'full',
                            width: '84px',
                            linkDestination: 'custom',
                            href: qr.target,
                            className: 'opn-qr',
                        }));
                    } catch (e) {
                        setError((e && e.message) || __('The QR code could not be created.', td));
                        setBusy(false);
                    }
                };

                return el('div', blockProps,
                    el(Placeholder, {
                        icon: qrIcon,
                        label: __('QR code', td),
                        instructions: __('Type the web address people should open when they scan it with a phone. It becomes an image you can resize like any photo.', td),
                    },
                    !data.canUpload && el(Notice, { status: 'warning', isDismissible: false }, __('You need permission to upload images to create a QR code.', td)),
                    error && el(Notice, { status: 'error', isDismissible: false }, error),
                    el('form', {
                        className: 'opn-qr-form',
                        onSubmit: (event) => {
                            event.preventDefault();
                            if (attributes.url && !busy) {
                                create();
                            }
                        },
                    },
                    el(TextControl, {
                        label: __('Web address', td),
                        type: 'url',
                        placeholder: 'https://example.org/register',
                        value: attributes.url,
                        onChange: (url) => setAttributes({ url }),
                        __nextHasNoMarginBottom: true,
                    }),
                    el(Button, { variant: 'primary', type: 'submit', isBusy: busy, disabled: busy || !attributes.url || !data.canUpload }, __('Create QR code', td)))));
            },
            save: () => null,
        });
    }
}(window.wp, window.omniNewsletterLayout));
