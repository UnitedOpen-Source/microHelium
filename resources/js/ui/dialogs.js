const focusable = root => [...root.querySelectorAll('a[href], button, input, select, textarea, [tabindex="0"]')]
    .filter(el => !el.disabled && !el.closest('[inert]') && el.getClientRects().length);

// Inert only sibling branches: the modal can be nested inside main content.
export function isolateDialog(dialog) {
    const previous = new Map();
    let branch = dialog;
    while (branch.parentElement) {
        for (const sibling of branch.parentElement.children) {
            if (sibling !== branch && !['SCRIPT', 'STYLE', 'LINK'].includes(sibling.tagName)) {
                previous.set(sibling, sibling.inert);
                sibling.inert = true;
            }
        }
        branch = branch.parentElement;
        if (branch === document.body) break;
    }
    return () => previous.forEach((inert, element) => { element.inert = inert; });
}

export function initializeDialogs() {
    let active = null;
    let restoreBackground = () => {};
    let returnFocus = null;
    let previousOverflow = '';
    const dialogs = [...document.querySelectorAll('[id$="Modal"]')];
    const sync = dialog => {
        const open = !dialog.classList.contains('hidden');
        if (open && active !== dialog) {
            if (active) active.classList.add('hidden');
            else {
                returnFocus = document.activeElement;
                previousOverflow = document.body.style.overflow;
            }
            restoreBackground();
            active = dialog;
            restoreBackground = isolateDialog(dialog);
            document.body.style.overflow = 'hidden';
            const initial = dialog.querySelector('[aria-invalid="true"]') || dialog.querySelector('[data-dialog-initial-focus]') || focusable(dialog)[0] || dialog;
            initial.focus();
        } else if (!open && active === dialog) {
            active = null;
            restoreBackground();
            document.body.style.overflow = previousOverflow;
            if (returnFocus?.isConnected && !returnFocus.closest('[inert]')) returnFocus.focus();
        }
    };
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
        new MutationObserver(() => sync(dialog)).observe(dialog, { attributes: true, attributeFilter: ['class'] });
        sync(dialog);
    });
    document.querySelectorAll('[onclick]').forEach(trigger => {
        const match = trigger.getAttribute('onclick').match(/^(openModal|closeModal)\('([\w-]+)'\)$/);
        if (!match) return;
        const dialog = document.getElementById(match[2]);
        if (!dialog) return;
        trigger.removeAttribute('onclick');
        trigger.addEventListener('click', () => {
            dialog.classList.toggle('hidden', match[1] === 'closeModal');
            sync(dialog);
        });
    });
    document.addEventListener('keydown', event => {
        if (!active) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            active.classList.add('hidden');
            active.classList.remove('flex');
            sync(active);
        } else if (event.key === 'Tab') {
            const items = focusable(active), first = items[0], last = items.at(-1);
            if (!first) { event.preventDefault(); active.focus(); }
            else if (event.shiftKey && (document.activeElement === first || !active.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || !active.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
        }
    }, true);
    document.addEventListener('focusin', event => {
        if (active && !active.contains(event.target)) (focusable(active)[0] || active).focus();
    });
    return { open(dialog) { dialog.classList.remove('hidden'); sync(dialog); } };
}
