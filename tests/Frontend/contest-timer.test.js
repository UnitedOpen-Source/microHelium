import './vue-loader.js';
import test from 'node:test';
import assert from 'node:assert/strict';
const { default: Timer } = await import('../../resources/js/components/ContestTimer.vue');

function state(data, now) {
    const context = { contestData: data, now, freezeTime: 60 };
    for (const [key, compute] of Object.entries(Timer.computed)) Object.defineProperty(context, key, { get: () => compute.call(context) });
    return context;
}

test('server release overrides local freeze arithmetic, including after the contest ends', () => {
    const data = { start_time: '2026-09-15T12:00:00Z', duration: 60, is_running: false, is_frozen: true };
    const timer = state(data, Date.parse('2026-09-15T13:30:00Z'));
    assert.equal(timer.ended, true); assert.equal(timer.frozen, true); assert.equal(timer.formattedTime, '00:00:00');
    data.is_frozen = false; assert.equal(timer.frozen, false);
});
test('explicit server finalization and stopped state override the local remaining time', () => {
    const data = { start_time: '2026-09-15T12:00:00Z', duration: 60, is_running: false, is_finalized: true };
    const timer = state(data, Date.parse('2026-09-15T12:30:00Z'));
    assert.equal(timer.statusLabel, 'Competição finalizada'); assert.equal(timer.formattedTime, '00:00:00');
});

test('effective end from the server includes global time adjustments', () => {
    const data = { start_time: '2026-09-15T12:00:00Z', end_time: '2026-09-15T13:30:00Z', duration: 60, is_running: true, is_frozen: false };
    const timer = state(data, Date.parse('2026-09-15T13:10:00Z'));
    assert.equal(timer.ended, false); assert.equal(timer.formattedTime, '00:20:00');
});
test('a pre-start snapshot does not label the next minute as ended', () => {
    const data = { start_time: '2026-09-15T12:00:00Z', server_time: '2026-09-15T11:59:50Z', duration: 60, is_running: false, is_frozen: false };
    const timer = state(data, Date.parse('2026-09-15T12:00:10Z'));
    assert.equal(timer.ended, false); assert.equal(timer.statusLabel, 'Tempo restante');
});
