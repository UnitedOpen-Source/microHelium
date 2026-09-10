import './bootstrap';
import { createApp } from 'vue';
import { initializeUI } from './ui';

// Import components
import Scoreboard from './components/Scoreboard.vue';
import RunList from './components/RunList.vue';
import SubmitForm from './components/SubmitForm.vue';
import ClarificationList from './components/ClarificationList.vue';
import ContestTimer from './components/ContestTimer.vue';
import ThemeToggle from './components/ThemeToggle.vue';

const components = { Scoreboard, RunList, SubmitForm, ClarificationList, ContestTimer, ThemeToggle };
const selectors = ['scoreboard', 'run-list', 'submit-form', 'clarification-list', 'contest-timer', 'theme-toggle'];

document.querySelectorAll(selectors.join(',')).forEach(element => {
    const host = document.createElement('div');
    host.className = 'vue-island';
    const template = element.outerHTML;
    element.replaceWith(host);
    createApp({ components, template }).mount(host);
});

initializeUI();
