// Blade owns the document; Vue owns only its component islands.
export function initializeUI() {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    const backdrop = document.getElementById('sidebar-backdrop');
    const content = document.getElementById('app-content');
    const desktop = matchMedia('(min-width: 1024px)');
    const focusable = (root) => [...root.querySelectorAll('a[href], button, input, select, textarea, [tabindex="0"]')].filter(el => !el.disabled && el.getClientRects().length);
    const setSidebar = (open) => {
        if (!sidebar) return;
        open = open && !desktop.matches;
        sidebar.classList.toggle('is-open', open);
        backdrop?.classList.toggle('hidden', !open);
        toggle?.setAttribute('aria-expanded', String(open));
        sidebar.inert = !desktop.matches && !open;
        if (content) content.inert = open;
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) focusable(sidebar)[0]?.focus();
        else if (!desktop.matches) toggle?.focus();
    };
    const toggleSidebar = () => setSidebar(!sidebar?.classList.contains('is-open'));
    toggle?.removeAttribute('onclick');
    backdrop?.removeAttribute('onclick');
    toggle?.addEventListener('click', toggleSidebar);
    backdrop?.addEventListener('click', () => setSidebar(false));
    document.querySelector('[data-sidebar-close]')?.addEventListener('click', () => setSidebar(false));
    desktop.addEventListener('change', () => setSidebar(false));
    if (sidebar) sidebar.inert = !desktop.matches;
    sidebar?.querySelectorAll('nav a').forEach(link => {
        if (link.classList.contains('bg-primary')) link.setAttribute('aria-current', 'page');
    });

    const menu = document.getElementById('user-menu');
    const menuToggle = document.getElementById('user-menu-toggle');
    const closeMenu = () => { menu?.classList.add('hidden'); menuToggle?.setAttribute('aria-expanded', 'false'); };
    menuToggle?.removeAttribute('onclick');
    menuToggle?.addEventListener('click', () => {
        const open = menu?.classList.contains('hidden');
        menu?.classList.toggle('hidden', !open);
        menuToggle?.setAttribute('aria-expanded', String(open));
        if (open) focusable(menu)[0]?.focus();
    });
    document.addEventListener('click', event => {
        if (!menu?.contains(event.target) && !menuToggle?.contains(event.target)) closeMenu();
    });

    // Existing admin dialogs retain their actions and gain keyboard focus management.
    const dialogs = [...document.querySelectorAll('[id$="Modal"]')];
    document.querySelectorAll('[onclick]').forEach(trigger => {
        const match = trigger.getAttribute('onclick').match(/^(openModal|closeModal)\('([\w-]+)'\)$/);
        if (!match) return;
        const dialog = document.getElementById(match[2]);
        if (!dialog) return;
        trigger.removeAttribute('onclick');
        trigger.addEventListener('click', () => { dialog.classList.toggle('hidden', match[1] === 'closeModal'); });
    });
    let activeDialog = null;
    let returnFocus = null;
    dialogs.forEach(dialog => {
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.tabIndex = -1;
        const title = dialog.querySelector('h2, h3');
        if (title) {
            title.id ||= `${dialog.id}-title`;
            dialog.setAttribute('aria-labelledby', title.id);
        }
        dialog.querySelectorAll('button').forEach(button => {
            if (!button.textContent.trim() && !button.getAttribute('aria-label')) button.setAttribute('aria-label', 'Fechar janela');
        });
        new MutationObserver(() => {
            const open = !dialog.classList.contains('hidden');
            if (open && activeDialog !== dialog) {
                returnFocus = document.activeElement;
                activeDialog = dialog;
                document.body.style.overflow = 'hidden';
                (focusable(dialog)[0] || dialog).focus();
            } else if (!open && activeDialog === dialog) {
                activeDialog = null;
                document.body.style.overflow = '';
                returnFocus?.focus();
            }
        }).observe(dialog, { attributes: true, attributeFilter: ['class'] });
    });
    document.addEventListener('keydown', event => {
        const root = activeDialog || (sidebar?.classList.contains('is-open') ? sidebar : null);
        if (event.key === 'Escape') {
            if (activeDialog) { activeDialog.classList.add('hidden'); activeDialog.classList.remove('flex'); }
            else if (root === sidebar) setSidebar(false);
            else if (menu && !menu.classList.contains('hidden')) { closeMenu(); menuToggle?.focus(); }
        }
        if (event.key === 'Tab' && root) {
            const items = focusable(root), first = items[0], last = items.at(-1);
            if (!first) { event.preventDefault(); root.focus(); }
            else if (event.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
        }
    });

    document.querySelectorAll('[data-table-filter]').forEach(toolbar => {
        const table = document.getElementById(toolbar.dataset.tableFilter);
        if (!table) return;
        const rows = [...table.querySelectorAll('tbody tr')].filter(row => !row.querySelector('[colspan]'));
        const search = toolbar.querySelector('input[type=search]');
        const select = toolbar.querySelector('select');
        const status = toolbar.querySelector('[role=status]');
        const empty = document.getElementById(`${table.id}-empty`);
        const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const apply = () => {
            const query = normalize(search?.value || '');
            let count = 0;
            rows.forEach(row => {
                const match = normalize(row.textContent).includes(query) && (!select?.value || row.dataset.difficulty === select.value);
                row.hidden = !match;
                if (match) count++;
            });
            if (status) status.textContent = `${count} de ${rows.length} registros`;
            if (empty) empty.hidden = count > 0 || rows.length === 0;
        };
        search?.addEventListener('input', apply);
        select?.addEventListener('change', apply);
        toolbar.querySelector('[data-filter-clear]')?.addEventListener('click', () => { if (search) search.value = ''; if (select) select.value = ''; apply(); search?.focus(); });
        apply();
    });
    // Supply accessible names for existing icon actions and table overflow regions.
    document.querySelectorAll('button[title], a[title]').forEach(el => { if (!el.textContent.trim() && !el.hasAttribute('aria-label')) el.setAttribute('aria-label', el.title); });
    document.querySelectorAll('.overflow-x-auto:has(table)').forEach(el => { el.tabIndex = 0; el.setAttribute('role', 'region'); el.setAttribute('aria-label', 'Tabela com rolagem horizontal'); });
    document.querySelectorAll('th:not([scope])').forEach(el => el.setAttribute('scope', 'col'));
}
