const controls = form => [...form.elements].filter(field => field.willValidate);
const fieldLabel = field => field.labels?.[0]?.textContent.trim().replace(/\s*\*$/, '') || field.name;

export function validationMessage(field) {
    const validity = field.validity;
    if (validity.valueMissing) return field.type === 'checkbox' ? 'Selecione esta opção para continuar.' : `Preencha o campo ${fieldLabel(field)}.`;
    if (validity.typeMismatch && field.type === 'email') return 'Informe um e-mail válido, como nome@exemplo.com.';
    if (validity.rangeUnderflow) return `Informe um valor maior ou igual a ${field.min}.`;
    if (validity.rangeOverflow) return `Informe um valor menor ou igual a ${field.max}.`;
    if (validity.tooShort) return `Use pelo menos ${field.minLength} caracteres.`;
    return field.validationMessage;
}

export function markFieldError(field, message) {
    if (!field.id) {
        const base = `field-${field.name.replace(/[^a-z0-9_-]/gi, '-') || 'input'}`;
        let id = base, suffix = 1;
        while (document.getElementById(id)) id = `${base}-${suffix++}`;
        field.id = id;
    }
    const errorId = `${field.id}-error`;
    let error = document.getElementById(errorId);
    if (!error) {
        error = document.createElement('p');
        error.id = errorId;
        error.className = 'field-error';
        field.insertAdjacentElement('afterend', error);
    }
    error.textContent = message;
    field.setAttribute('aria-invalid', 'true');
    field.setAttribute('aria-describedby', [...new Set([...(field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean), errorId])].join(' '));
}

function clearFieldError(field) {
    const errorId = `${field.id}-error`;
    document.getElementById(errorId)?.remove();
    field.removeAttribute('aria-invalid');
    const describedBy = (field.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== errorId);
    if (describedBy.length) field.setAttribute('aria-describedby', describedBy.join(' '));
    else field.removeAttribute('aria-describedby');
}

export function initializeForms(dialogs) {
    // Server messages remain readable without JavaScript, then become linked inline errors.
    document.querySelectorAll('[data-error-summary]').forEach(summary => {
        let first;
        summary.querySelectorAll('[data-error-field]').forEach(item => {
            const field = [...document.querySelectorAll('input[name],select[name],textarea[name]')].find(field => field.name === item.dataset.errorField || field.name === `${item.dataset.errorField}[]`);
            if (!field) return;
            markFieldError(field, item.textContent.trim());
            const link = document.createElement('a');
            link.href = `#${field.id}`;
            link.textContent = item.textContent;
            link.addEventListener('click', event => {
                event.preventDefault();
                const dialog = field.closest('[role=dialog]');
                if (dialog) dialogs.open(dialog);
                field.dispatchEvent(new CustomEvent('reveal-field', { bubbles: true }));
                field.focus();
            });
            item.replaceChildren(link);
            first ||= field;
        });
        if (first) {
            const dialog = first.closest('[role=dialog]');
            if (dialog) dialogs.open(dialog);
            first.dispatchEvent(new CustomEvent('reveal-field', { bubbles: true }));
            first.focus();
        } else summary.focus();
    });

    const pending = new Map();
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(form => {
        if (form.id !== 'wizardForm') form.noValidate = true;
        form.addEventListener('input', event => {
            if (event.target.getAttribute('aria-invalid') === 'true') clearFieldError(event.target);
        });
        form.addEventListener('submit', event => {
            if (pending.has(form)) { event.preventDefault(); return; }
            if (event.defaultPrevented) return;
            const invalid = controls(form).filter(field => !field.validity.valid);
            if (invalid.length) {
                event.preventDefault();
                invalid.forEach(field => markFieldError(field, validationMessage(field)));
                invalid[0].dispatchEvent(new CustomEvent('reveal-field', { bubbles: true }));
                invalid[0].focus();
                return;
            }
            // Let native confirmations and page-specific validators cancel the action first.
            queueMicrotask(() => {
                if (event.defaultPrevented) return;
                const button = event.submitter;
                const previous = button?.innerHTML;
                const status = document.createElement('p');
                status.className = 'form-progress';
                status.setAttribute('role', 'status');
                status.textContent = 'Enviando… Aguarde a confirmação.';
                form.insertAdjacentElement('afterend', status);
                form.setAttribute('aria-busy', 'true');
                if (button) {
                    button.setAttribute('aria-disabled', 'true');
                    button.textContent = 'Enviando…';
                }
                // aria-disabled keeps the submitter's name/value in native form serialization.
                pending.set(form, () => {
                    form.removeAttribute('aria-busy');
                    status.remove();
                    if (button) { button.innerHTML = previous; button.removeAttribute('aria-disabled'); }
                });
            });
        });
    });
    window.addEventListener('pageshow', () => { pending.forEach(restore => restore()); pending.clear(); });
}
