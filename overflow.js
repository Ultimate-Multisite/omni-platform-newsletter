/* Never shrink or hide excess copy: explicitly flag a sheet that exceeds its budget. */
(() => {
    const text = window.omniNewsletterPrint || {
        fits: 'Fits on two pages. Ready to print.',
        tooLong: 'Too long to fit on page %s. Shorten the text or use smaller photos, then refresh this page.',
        sheetWarning: 'Page %d is too long to fit. Nothing has been hidden: shorten the text or use smaller photos before printing.',
    };
    const body = document.body;
    const previewMode = body.classList.contains('opn-print-mode');
    // Fractional grid sizes can differ from scroll sizes by a pixel; real overflow is larger.
    const tolerance = 2;

    const check = () => {
        document.querySelectorAll('[data-opn-overflow-warning]').forEach(node => node.remove());
        if (!body.classList.contains('opn-print-mode')) return;
        const overflowing = [];
        document.querySelectorAll('.opn-sheet').forEach((sheet, index) => {
            const areas = [sheet, sheet.querySelector('.opn-sidebar'), sheet.querySelector('.opn-main')];
            const overflow = areas.some(area => area && (area.scrollHeight > area.clientHeight + tolerance || area.scrollWidth > area.clientWidth + tolerance));
            sheet.dataset.printOverflow = overflow ? 'true' : 'false';
            if (overflow) {
                overflowing.push(index + 1);
                const warning = document.createElement('p');
                warning.className = 'opn-layout-warning';
                warning.dataset.opnOverflowWarning = 'true';
                warning.setAttribute('role', 'alert');
                warning.textContent = text.sheetWarning.replace('%d', String(index + 1));
                sheet.before(warning);
            }
        });
        const status = document.querySelector('[data-opn-fit-status]');
        if (status) {
            status.dataset.state = overflowing.length ? 'overflow' : 'fits';
            status.textContent = overflowing.length ? text.tooLong.replace('%s', overflowing.join(', ')) : text.fits;
        }
    };

    // Ordinary web view: switch to the two-sheet layout only while printing.
    window.addEventListener('beforeprint', () => {
        if (!previewMode) body.classList.add('opn-print-mode');
        check();
    });
    window.addEventListener('afterprint', () => {
        if (!previewMode) {
            body.classList.remove('opn-print-mode');
            check();
        }
    });
    window.addEventListener('load', check);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(check);
    document.querySelectorAll('.opn-browser-print').forEach(button => {
        button.addEventListener('click', () => window.print());
    });
})();
