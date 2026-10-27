/* Omni Platform Newsletter: "Publish & send" panel for the block editor. No build step. */
(function (wp, data) {
    'use strict';

    if (!wp || !data) {
        return;
    }

    const el = wp.element.createElement;
    const { Fragment, useState, useEffect, useCallback } = wp.element;
    const { __, sprintf, _n } = wp.i18n;
    const { useSelect, useDispatch, select, dispatch } = wp.data;
    const { Button, CheckboxControl, ToggleControl, TextControl, Modal, Notice, Spinner, ExternalLink } = wp.components;
    const editorPkg = wp.editor && wp.editor.PluginDocumentSettingPanel ? wp.editor : wp.editPost;
    const { PluginDocumentSettingPanel, PluginPrePublishPanel, PluginPostPublishPanel } = editorPkg;
    const td = 'omni-platform-newsletter';
    const SECTION_CLASSES = ['opn-source-sidebar', 'opn-source-front', 'opn-source-back', 'opn-source-footer'];

    /* Quiet first-run interruptions: the welcome tour and Newspack's layout picker. */
    try {
        const prefs = dispatch('core/preferences');
        if (prefs) {
            prefs.set('core/edit-post', 'welcomeGuide', false);
            prefs.set('core', 'welcomeGuide', false);
        }
    } catch (e) { /* older editors */ }

    /* Panels that are not useful for a newsletter (engine users keep them). */
    if (!data.engines) {
        wp.domReady(() => {
            // Newspack's layout/visibility sidebar, ads, and debug tools. Its MJML plugin
            // (which builds the email version on save) and editor buttons stay registered.
            ['newspack-newsletters-sidebar', 'newspack-newsletters-ads-settings', 'newspack-newsletters-debug-send', 'block-directory', 'post-type-switcher']
                .forEach((name) => {
                    if (wp.plugins.getPlugin(name)) {
                        wp.plugins.unregisterPlugin(name);
                    }
                });
            const editor = dispatch('core/editor');
            if (editor && editor.removeEditorPanel) {
                ['taxonomy-panel-category', 'taxonomy-panel-post_tag', 'taxonomy-panel-newspack_nl_advertiser', 'post-excerpt', 'discussion-panel', 'page-attributes', 'template']
                    .forEach((panel) => editor.removeEditorPanel(panel));
            }
            document.body.classList.add('opn-simple-editor');
        });
    }

    const api = (path, options) => wp.apiFetch(Object.assign({ path: data.restPath + path }, options || {}));
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    /** Save (if needed) and wait until the editor has finished saving. */
    async function saveIfNeeded() {
        const editor = select('core/editor');
        if (editor.isEditedPostDirty() || editor.isEditedPostNew()) {
            await dispatch('core/editor').savePost();
        }
        for (let i = 0; i < 60 && (select('core/editor').isSavingPost() || select('core/editor').isAutosavingPost()); i++) {
            await sleep(250);
        }
    }

    /** Run an email request, waiting briefly while the email version is regenerated after a save. */
    async function withFreshEmail(run) {
        let lastError;
        for (let attempt = 0; attempt < 8; attempt++) {
            try {
                return await run();
            } catch (error) {
                lastError = error;
                if (!error || !['opn_email_stale', 'opn_email_missing'].includes(error.code)) {
                    throw error;
                }
                await sleep(1500);
            }
        }
        throw lastError;
    }

    /** Client-side check of the four-section layout, before anything is saved. */
    function layoutProblem(blocks) {
        const found = blocks.map((block) => (block.attributes.className || '').split(/\s+/).find((c) => SECTION_CLASSES.includes(c)) || null);
        if (found.some((c) => c === null)) {
            return __('Some content is outside the Sidebar, Front, Back and Footer sections. Move it into one of them so it appears in the email and print versions.', td);
        }
        const missing = SECTION_CLASSES.filter((c) => !found.includes(c));
        if (missing.length || found.length !== SECTION_CLASSES.length) {
            return __('This newsletter needs exactly one Sidebar, Front, Back and Footer section. Use “Undo” or copy a previous newsletter to restore them.', td);
        }
        return '';
    }

    function useIssue() {
        const postId = useSelect((s) => s('core/editor').getCurrentPostId(), []);
        const [summary, setSummary] = useState(null);
        const refresh = useCallback(() => {
            if (!postId) {
                return Promise.resolve();
            }
            return api(String(postId)).then(setSummary).catch(() => setSummary(null));
        }, [postId]);
        const saved = useSelect((s) => !s('core/editor').isSavingPost() && s('core/editor').getCurrentPostAttribute('modified'), []);
        useEffect(() => { refresh(); }, [refresh, saved]);
        useEffect(() => {
            if (!summary || !['sending', 'queued'].includes(summary.delivery.state)) {
                return undefined;
            }
            const timer = setInterval(refresh, 10000);
            return () => clearInterval(timer);
        }, [summary, refresh]);
        return { postId, summary, setSummary, refresh };
    }

    function SendModal({ postId, summary, onClose, onSent }) {
        const [lists, setLists] = useState(data.defaultLists || []);
        const [again, setAgain] = useState(false);
        const [busy, setBusy] = useState(false);
        const [error, setError] = useState('');
        const alreadySent = summary && summary.delivery.state === 'sent';
        // wp_localize_script() turns top-level numbers into strings; _n() needs a number for "1 subscriber".
        const count = lists.length === 0
            ? Number(data.allCount)
            : data.lists.filter((l) => lists.includes(l.id)).reduce((sum, l) => sum + Number(l.count), 0);

        const send = async () => {
            setBusy(true);
            setError('');
            try {
                await saveIfNeeded();
                const result = await withFreshEmail(() => api(postId + '/send', { method: 'POST', data: { lists, again } }));
                onSent(result);
            } catch (e) {
                setError((e && e.message) || __('The newsletter could not be sent.', td));
            }
            setBusy(false);
        };

        return el(Modal, { title: __('Send to subscribers', td), onRequestClose: onClose, className: 'opn-send-modal' },
            data.lists.length > 0 && el('fieldset', null,
                el('legend', null, __('Who should receive it?', td)),
                el(CheckboxControl, {
                    label: sprintf(__('Everyone (%s)', td), data.allCount),
                    checked: lists.length === 0,
                    onChange: (on) => on && setLists([]),
                }),
                data.lists.map((list) => el(CheckboxControl, {
                    key: list.id,
                    label: sprintf('%s (%s)', list.name, list.count),
                    checked: lists.includes(list.id),
                    onChange: (on) => setLists(on ? [...lists, list.id] : lists.filter((id) => id !== list.id)),
                }))
            ),
            el('p', { className: 'opn-send-modal__count' }, count > 0
                ? sprintf(_n('This will email %s subscriber.', 'This will email %s subscribers.', count, td), count)
                : __('There are no confirmed subscribers to send to yet.', td)),
            el('p', null, __('Emails cannot be recalled once sent. Send yourself a test first if you have not already.', td)),
            alreadySent && el(CheckboxControl, {
                label: __('This newsletter was already emailed. Yes, send it again.', td),
                checked: again,
                onChange: setAgain,
            }),
            error && el(Notice, { status: 'error', isDismissible: false }, error),
            el('div', { className: 'opn-send-modal__actions' },
                el(Button, { variant: 'tertiary', onClick: onClose, disabled: busy }, __('Cancel', td)),
                el(Button, {
                    variant: 'primary',
                    onClick: send,
                    isBusy: busy,
                    disabled: busy || count < 1 || (alreadySent && !again),
                }, busy ? __('Sending…', td) : sprintf(_n('Send to %s subscriber', 'Send to %s subscribers', count, td), count))
            )
        );
    }

    function SendButton({ issue, variant }) {
        const [open, setOpen] = useState(false);
        const { createNotice } = useDispatch('core/notices');
        if (!data.canSend || !data.delivery) {
            return null;
        }
        const state = issue.summary ? issue.summary.delivery.state : 'not_sent';
        if (['sending', 'queued'].includes(state)) {
            return null;
        }
        return el(Fragment, null,
            el(Button, { variant: variant || 'primary', onClick: () => setOpen(true), className: 'opn-wide' },
                state === 'sent' ? __('Send again…', td) : __('Send to subscribers…', td)),
            open && el(SendModal, {
                postId: issue.postId,
                summary: issue.summary,
                onClose: () => setOpen(false),
                onSent: (result) => {
                    setOpen(false);
                    issue.setSummary(result);
                    createNotice('success', __('Your newsletter is on its way. Larger lists are delivered in batches over the next few minutes.', td), { type: 'snackbar' });
                },
            })
        );
    }

    function Panel() {
        const issue = useIssue();
        const { summary } = issue;
        const { editPost } = useDispatch('core/editor');
        const { createNotice } = useDispatch('core/notices');
        const meta = useSelect((s) => s('core/editor').getEditedPostAttribute('meta') || {}, []);
        const title = useSelect((s) => s('core/editor').getEditedPostAttribute('title'), []);
        const status = useSelect((s) => s('core/editor').getEditedPostAttribute('status'), []);
        const blocks = useSelect((s) => s('core/block-editor').getBlocks(), []);
        const canPublish = useSelect((s) => !!s('core/editor').getCurrentPost()._links?.['wp:action-publish'], []);
        const [testing, setTesting] = useState(false);
        const [testTo, setTestTo] = useState(data.userEmail);
        const problem = layoutProblem(blocks);

        const openPrint = async () => {
            const win = window.open('', '_blank');
            await saveIfNeeded();
            const fresh = await api(String(issue.postId)).catch(() => summary);
            if (win && fresh) {
                win.location = fresh.printUrl;
            }
        };

        const sendTest = async () => {
            setTesting(true);
            try {
                await saveIfNeeded();
                const result = await withFreshEmail(() => api(issue.postId + '/test', { method: 'POST', data: { email: testTo } }));
                issue.setSummary(result);
                createNotice('success', sprintf(__('Test email sent to %s.', td), testTo), { type: 'snackbar' });
            } catch (e) {
                createNotice('error', (e && e.message) || __('The test email could not be sent.', td));
            }
            setTesting(false);
        };

        return el(PluginDocumentSettingPanel, { name: 'omni-newsletter', title: __('Publish, print & email', td), className: 'opn-panel', initialOpen: true },
            problem && el(Notice, { status: 'warning', isDismissible: false }, problem),

            el('h3', { className: 'opn-panel__heading' }, __('Website', td)),
            'is_public' in meta && canPublish && el(ToggleControl, {
                label: __('Show on our website when published', td),
                checked: !!meta.is_public,
                onChange: (on) => editPost({ meta: { is_public: on } }),
            }),
            el('p', { className: 'opn-panel__status' },
                summary && summary.webUrl
                    ? el(ExternalLink, { href: summary.webUrl }, __('View on your website', td))
                    : (status === 'publish' ? __('Published privately (not on your website).', td) : __('Not on your website yet. Click “Publish” when it is ready.', td))),
            status !== 'publish' && el('p', { className: 'opn-panel__hint' },
                __('Publishing puts it on your website only. It does not email anyone.', td),
                data.socialEnabled ? ' ' + __('It is also shared on your connected social media accounts.', td) : ''),

            el('h3', { className: 'opn-panel__heading' }, __('Print', td)),
            el(Button, { variant: 'secondary', onClick: openPrint, className: 'opn-wide' }, __('Print / Save as PDF', td)),
            el('p', { className: 'opn-panel__hint' }, __('Opens the two-page, front-and-back version. It warns you if anything is too long to fit.', td)),

            el('h3', { className: 'opn-panel__heading' }, __('Email', td)),
            !data.delivery && el('p', null, __('Email sending is not set up on this site yet. Please contact support.', td)),
            data.delivery && el(Fragment, null,
                el(TextControl, {
                    label: __('Email subject', td),
                    value: meta._omni_platform_newsletter_subject || '',
                    placeholder: title,
                    help: __('Leave blank to use the newsletter title.', td),
                    onChange: (value) => editPost({ meta: { _omni_platform_newsletter_subject: value } }),
                }),
                'preview_text' in meta && el(TextControl, {
                    label: __('Inbox preview text', td),
                    value: meta.preview_text || '',
                    help: __('Shown after the subject in most inboxes. Filled in from your front page until you change it.', td),
                    onChange: (value) => editPost({ meta: { preview_text: value } }),
                }),
                summary && el('p', { className: 'opn-panel__status opn-panel__status--' + summary.delivery.state },
                    ['sending', 'queued'].includes(summary.delivery.state) && el(Spinner, null),
                    summary.deliveryLabel),
                data.canSend && el(Fragment, null,
                    el(TextControl, { label: __('Send a test to', td), type: 'email', value: testTo, onChange: setTestTo }),
                    el(Button, { variant: 'secondary', onClick: sendTest, isBusy: testing, disabled: testing || !testTo || !!problem, className: 'opn-wide' },
                        testing ? __('Sending test…', td) : __('Send me a test email', td)),
                    el('div', { className: 'opn-panel__spacer' }),
                    !problem && el(SendButton, { issue }),
                    el('p', { className: 'opn-panel__hint' },
                        sprintf(_n('%s person subscribes to your newsletter.', '%s people subscribe to your newsletter.', data.allCount, td), data.allCount), ' ',
                        el('a', { href: data.subscribersUrl }, __('Manage subscribers', td)))
                )
            )
        );
    }

    function PrePublish() {
        return el(PluginPrePublishPanel, { title: __('What publishing does', td), initialOpen: true },
            el('p', null, __('Publishing puts this newsletter on your website. It does not email anyone.', td)),
            data.socialEnabled && el('p', null, __('It will also be shared on your connected social media accounts.', td)),
            el('p', null, __('To email it, use “Send to subscribers” in the newsletter panel.', td)));
    }

    function PostPublish() {
        const issue = useIssue();
        if (!data.delivery || !data.canSend) {
            return null;
        }
        return el(PluginPostPublishPanel, { title: __('Next: email your subscribers', td), initialOpen: true },
            el('p', null, __('Your newsletter is on the website. Send it to your subscribers when you are ready.', td)),
            el(SendButton, { issue }));
    }

    wp.plugins.registerPlugin('omni-platform-newsletter', {
        render: () => el(Fragment, null, el(Panel), el(PrePublish), el(PostPublish)),
    });

    /* The newsletter panel is the main way to publish, print and email: always start with it open. */
    wp.domReady(() => {
        const panel = 'omni-platform-newsletter/omni-newsletter';
        const editor = select('core/editor');
        if (editor && editor.isEditorPanelOpened && !editor.isEditorPanelOpened(panel)) {
            dispatch('core/editor').toggleEditorPanelOpened(panel);
        }
    });

    if (data.copied) {
        // Wait until the editor has loaded the post; notices created earlier are cleared on load.
        const unsubscribe = wp.data.subscribe(() => {
            if (select('core/editor').getCurrentPostId() && select('core/block-editor').getBlocks().length) {
                unsubscribe();
                setTimeout(() => dispatch('core/notices').createNotice('success', __('This is a copy. Update the front and back pages, then publish or send it.', td), { isDismissible: true, id: 'opn-copied' }), 2000);
            }
        });
    }
}(window.wp, window.omniNewsletter));
