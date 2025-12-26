import './bootstrap';
import { createApp } from 'vue';

// Import components
import Scoreboard from './components/Scoreboard.vue';
import RunList from './components/RunList.vue';
import SubmitForm from './components/SubmitForm.vue';
import ClarificationList from './components/ClarificationList.vue';
import ContestTimer from './components/ContestTimer.vue';
import ThemeToggle from './components/ThemeToggle.vue';

const app = createApp({});

// Register components
app.component('scoreboard', Scoreboard);
app.component('run-list', RunList);
app.component('submit-form', SubmitForm);
app.component('clarification-list', ClarificationList);
app.component('contest-timer', ContestTimer);
app.component('theme-toggle', ThemeToggle);

app.mount('#app');
