export function initializeStatusFilters() {
    document.querySelectorAll('[data-status-filter]').forEach(toolbar => {
        const list = document.getElementById(toolbar.dataset.statusFilter);
        if (!list) return;
        const items = [...list.querySelectorAll('[data-status]')];
        const buttons = [...toolbar.querySelectorAll('[data-status]')];
        const key = `ui-${list.id}`;
        const update = (value, save = false) => {
            const status = buttons.some(button => button.dataset.status === value) ? value : 'all';
            let count = 0;
            items.forEach(item => { item.hidden = status !== 'all' && item.dataset.status !== status; if (!item.hidden) count++; });
            buttons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.status === status)));
            toolbar.querySelector('[role=status]').textContent = `${count} de ${items.length} perguntas`;
            const empty = list.querySelector('[data-filter-empty]');
            if (empty) empty.hidden = count > 0 || items.length === 0;
            if (save) {
                const url = new URL(location.href);
                if (status === 'all') url.searchParams.delete(key); else url.searchParams.set(key, status);
                history.replaceState(history.state, '', url);
            }
        };
        const restore = () => update(new URL(location.href).searchParams.get(key));
        buttons.forEach(button => button.addEventListener('click', () => update(button.dataset.status, true)));
        window.addEventListener('popstate', restore);
        window.addEventListener('pageshow', restore);
        restore();
    });
}
