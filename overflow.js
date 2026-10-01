/* Never shrink or hide excess copy: explicitly flag a sheet that exceeds budget. */
(() => {
    const check = () => {
        document.querySelectorAll('[data-opn-overflow-warning]').forEach(node => node.remove());
        if (!window.matchMedia('print').matches) return;
        document.querySelectorAll('.opn-sheet').forEach((sheet, index) => {
            const areas = [sheet, sheet.querySelector('.opn-sidebar'), sheet.querySelector('.opn-main')];
            const overflow = areas.some(area => area && (area.scrollHeight > area.clientHeight + 2 || area.scrollWidth > area.clientWidth + 2));
            sheet.dataset.printOverflow = overflow ? 'true' : 'false';
            if (overflow) {
                const warning = document.createElement('p');
                warning.className = 'opn-layout-warning';
                warning.dataset.opnOverflowWarning = 'true';
                warning.setAttribute('role', 'alert');
                warning.textContent = `Print side ${index + 1} exceeds its space budget. Shorten copy or reduce images before printing. No content has been hidden.`;
                sheet.before(warning);
            }
        });
    };
    window.addEventListener('load', check);
    window.addEventListener('beforeprint', check);
    window.matchMedia('print').addEventListener('change', check);
    document.querySelectorAll('.opn-browser-print').forEach(button => {
        button.addEventListener('click', () => window.print());
    });
})();
