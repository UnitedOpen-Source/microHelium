import { validationMessage } from './forms.js';

export function initializeWizard() {
    const form = document.getElementById('wizardForm');
    if (!form) return;
    let currentStep = 1;
    const totalSteps = 5;

    function showStep(step) {
        document.getElementById('wizard-status').textContent = `Etapa ${step} de ${totalSteps}`;
        document.querySelectorAll('.wizard-step').forEach(el => el.classList.add('hidden'));
        document.getElementById('step' + step).classList.remove('hidden');

        for (let i = 1; i <= totalSteps; i++) {
            const indicator = document.getElementById('step' + i + '-indicator');
            const text = indicator.nextElementSibling;
            indicator.setAttribute('aria-label', `Etapa ${i}${i < step ? ': concluída' : ''}`);
            if (i === step) indicator.setAttribute('aria-current', 'step'); else indicator.removeAttribute('aria-current');
            if (i < step) {
                indicator.className = 'flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 bg-success text-success-foreground rounded-full font-bold text-sm';
                indicator.innerHTML = '<svg aria-hidden="true" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
                if (text) text.className = 'ml-1 sm:ml-2 font-medium text-success hidden md:inline';
            } else if (i === step) {
                indicator.className = 'flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 bg-primary text-primary-foreground rounded-full font-bold text-sm';
                indicator.innerHTML = i;
                if (text) text.className = 'ml-1 sm:ml-2 font-medium text-foreground hidden md:inline';
            } else {
                indicator.className = 'flex items-center justify-center w-8 h-8 sm:w-10 sm:h-10 bg-muted text-muted-foreground rounded-full font-bold text-sm';
                indicator.innerHTML = i;
                if (text) text.className = 'ml-1 sm:ml-2 font-medium text-muted-foreground hidden md:inline';
            }
        }

        for (let i = 1; i < totalSteps; i++) {
            const bar = document.getElementById('progress-' + i + '-' + (i+1));
            if (bar) bar.style.width = (step > i) ? '100%' : '0%';
        }

        document.getElementById('prevBtn').classList.toggle('hidden', step === 1);
        document.getElementById('nextBtn').classList.toggle('hidden', step === totalSteps);
        document.getElementById('submitBtn').classList.toggle('hidden', step !== totalSteps);

        const heading = document.querySelector('#step' + step + ' h2');
        if (heading) { heading.tabIndex = -1; heading.focus(); }
        if (step === 5) updateSummary();
    }

    function nextStep() {
        if (!validateStep(currentStep)) return;
        if (currentStep < totalSteps) { currentStep++; showStep(currentStep); }
    }

    function prevStep() {
        if (currentStep > 1) { currentStep--; showStep(currentStep); }
    }

    function validateStep(step) {
        const section = document.getElementById('step' + step);
        const fields = [...section.querySelectorAll('input, select, textarea')];
        const invalid = fields.find(field => field.willValidate && !field.validity.valid);
        const languageMissing = step === 3 && !section.querySelector('.language-card input:checked');
        let message = section.querySelector('[data-step-error]');
        if (!message) {
            message = document.createElement('p'); message.className = 'error-summary';
            message.dataset.stepError = ''; message.setAttribute('role', 'alert');
            message.id = `step${step}-error`; section.prepend(message);
        }
        message.hidden = !invalid && !languageMissing;
        fields.forEach(field => {
            field.removeAttribute('aria-invalid');
            const descriptions = (field.getAttribute('aria-describedby') || '').split(' ').filter(id => id && id !== message.id);
            if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
            else field.removeAttribute('aria-describedby');
        });
        if (invalid || languageMissing) {
            message.textContent = invalid ? validationMessage(invalid) : 'Selecione pelo menos uma linguagem para continuar.';
            const field = invalid || section.querySelector('.language-card input');
            field?.setAttribute('aria-invalid', 'true');
            if (field) field.setAttribute('aria-describedby', [...new Set([...(field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean), message.id])].join(' '));
            field?.focus();
            return false;
        }
        return true;
    }

    document.getElementById('wizardForm').addEventListener('reveal-field', event => {
        const section = event.target.closest('.wizard-step');
        if (section) { currentStep = Number(section.id.replace('step', '')); showStep(currentStep); }
    });
    document.getElementById('wizardForm').addEventListener('submit', event => {
        for (let step = 1; step <= totalSteps; step++) {
            const section = document.getElementById('step' + step);
            const invalid = [...section.querySelectorAll('input,select,textarea')].some(field => field.willValidate && !field.validity.valid);
            if (invalid || (step === 3 && !section.querySelector('.language-card input:checked'))) {
                event.preventDefault(); currentStep = step; showStep(step); validateStep(step); return;
            }
        }
    });

    function updateSummary() {
        document.getElementById('summaryName').textContent = document.getElementById('contestName').value || '--';
        const start = document.getElementById('contestStart').value;
        if (start) document.getElementById('summaryStart').textContent = new Date(start).toLocaleString('pt-BR');
        const duration = parseInt(document.getElementById('contestDuration').value) || 0;
        const h = Math.floor(duration / 60), m = duration % 60;
        document.getElementById('summaryDuration').textContent = h > 0 ? h + 'h ' + m + 'min' : m + 'min';
        document.getElementById('summaryPenalty').textContent = (document.getElementById('contestPenalty').value || 20) + 'min / ' + (document.getElementById('contestFreeze').value || 0) + 'min';
        const langs = Array.from(document.querySelectorAll('.language-card input:checked')).map(cb => cb.value.toUpperCase()).join(', ');
        document.getElementById('summaryLanguages').textContent = langs || 'Nenhuma';
        const probs = document.querySelectorAll('.problem-card input:checked').length;
        document.getElementById('summaryProblems').textContent = probs + ' problema(s) selecionado(s)';
    }

    function setDuration(mins) {
        document.getElementById('contestDuration').value = mins;
        updateEndTime();
    }

    function updateEndTime() {
        const start = document.getElementById('contestStart').value;
        const duration = parseInt(document.getElementById('contestDuration').value) || 0;
        if (start && duration) {
            const endDate = new Date(new Date(start).getTime() + duration * 60 * 1000);
            document.getElementById('calculatedEnd').textContent = endDate.toLocaleString('pt-BR');
        } else {
            document.getElementById('calculatedEnd').textContent = '--';
        }
    }

    function selectAllLanguages() {
        document.querySelectorAll('.language-card').forEach(card => {
            card.querySelector('input').checked = true;
            card.classList.add('selected');
        });
        updateLangCount();
    }

    function deselectAllLanguages() {
        document.querySelectorAll('.language-card').forEach(card => {
            card.querySelector('input').checked = false;
            card.classList.remove('selected');
        });
        updateLangCount();
    }

    function updateLangCount() {
        document.getElementById('selectedLangCount').textContent = document.querySelectorAll('.language-card input:checked').length;
    }

    function selectAllProblems() {
        document.querySelectorAll('.problem-card:not([style*="display: none"])').forEach(card => {
            card.querySelector('input').checked = true;
            card.classList.add('selected');
        });
        updateProblemCount();
    }

    function deselectAllProblems() {
        document.querySelectorAll('.problem-card').forEach(card => {
            card.querySelector('input').checked = false;
            card.classList.remove('selected');
        });
        updateProblemCount();
    }

    function updateProblemCount() {
        document.getElementById('selectedProblemCount').textContent = document.querySelectorAll('.problem-card input:checked').length;
    }

    function filterProblems(difficulty) {
        document.querySelectorAll('.filter-btn').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.filter === difficulty)));
        document.querySelectorAll('.problem-card').forEach(card => {
            if (difficulty === 'all' || card.dataset.difficulty === difficulty) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }

    function initializeValues() {
        const now = new Date();
        now.setHours(now.getHours() + 1);
        now.setMinutes(0);
        const startInput = document.getElementById('contestStart');
        if (!startInput.value) {
            const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
            startInput.value = local.toISOString().slice(0, 16);
        }
        updateEndTime();
        updateLangCount();

        document.getElementById('contestStart').addEventListener('change', updateEndTime);
        document.getElementById('contestDuration').addEventListener('input', updateEndTime);

        for (const [selector, update] of [['.language-card', updateLangCount], ['.problem-card', updateProblemCount]]) {
            document.querySelectorAll(selector).forEach(card => {
                const checkbox = card.querySelector('input');
                const sync = () => { card.classList.toggle('selected', checkbox.checked); update(); };
                checkbox.addEventListener('change', sync);
                sync();
            });
        }
        document.getElementById('step1-indicator').setAttribute('aria-current', 'step');

    }
    initializeValues();
    const actions = { nextStep, prevStep, setDuration, selectAllLanguages, deselectAllLanguages, selectAllProblems, deselectAllProblems, filterProblems };
    form.querySelectorAll('[data-wizard-action]').forEach(button => {
        button.addEventListener('click', () => actions[button.dataset.wizardAction]?.(button.dataset.value));
    });
    filterProblems('all');
}
