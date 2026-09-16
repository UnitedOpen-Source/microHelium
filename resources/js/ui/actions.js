// Native dialogs provide focus containment, Escape, and background inertness.
// No inline handlers: the production Content Security Policy stays strict.
export function initializeActions() {
    document.querySelectorAll('[data-canned-answer]').forEach(button => {
        button.addEventListener('click', () => {
            const answer = button.form?.elements.namedItem('answer');
            if (!answer) return;
            answer.value = button.dataset.cannedAnswer;
            answer.dispatchEvent(new Event('input', { bubbles: true }));
            answer.focus();
        });
    });

    const forms = [...document.querySelectorAll('form[data-confirm]')];
    if (!forms.length) return;
    const dialog = document.createElement('dialog');
    dialog.className = 'confirm-dialog';
    dialog.setAttribute('aria-labelledby', 'action-confirm-title');
    dialog.setAttribute('aria-describedby', 'action-confirm-description');
    dialog.innerHTML = '<h2 id="action-confirm-title">Confirmar ação</h2><p id="action-confirm-description"></p><div class="feature-actions"><button type="button" class="button-secondary" autofocus>Cancelar</button><button type="button" class="button-danger">Confirmar</button></div>';
    document.body.append(dialog);
    const [cancel, confirm] = dialog.querySelectorAll('button');
    let pending;
    const allowed = new WeakSet();
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { const trigger = pending?.submitter; pending = null; trigger?.focus(); });
    confirm.addEventListener('click', () => {
        const action = pending;
        if (!action) return;
        dialog.close();
        allowed.add(action.form);
        action.form.requestSubmit(action.submitter || undefined);
    });
    forms.forEach(form => form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        if (allowed.delete(form)) return;
        event.preventDefault();
        pending = { form, submitter: event.submitter };
        dialog.querySelector('p').textContent = form.dataset.confirm;
        dialog.showModal();
    }));
}
