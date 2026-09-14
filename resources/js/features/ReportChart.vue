<script setup>
/*
 * Issue #144 -- the chart, drawn in the browser.
 *
 * BOCA bundles libchart and renders a PNG on the server. We do not: the data
 * is already aggregated by App\Services\ContestReportBuilder and the frontend
 * already has Vue + Vite, so the only thing added here is a client-side draw.
 *
 * chart.js was already a declared dependency in package.json and nothing
 * imported it, so this adds no dependency at all.
 *
 * WHY chart.js survives the CSP that issue #143 tightened (script-src 'self'
 * plus a per-request nonce, style-src with no 'unsafe-inline', no
 * 'unsafe-eval'):
 *  - it is imported through Vite, so it is same-origin 'self' script, not a
 *    CDN tag;
 *  - it never compiles a string into code, so the missing 'unsafe-eval'
 *    costs nothing. (resources/js/app.js used to, through Vue's runtime
 *    template compiler, and that threw an EvalError which took the whole
 *    page's JavaScript -- this page included -- with it. Fixed there; see the
 *    comment on the island loop.)
 *  - it sizes the canvas through CSSOM property assignment
 *    (canvas.style.width = ...), which CSP does not police, and it injects no
 *    <style> element and no style attribute.
 * Only the registrations actually used are imported, so the chunk carries a
 * bar/line/doughnut controller and nothing else.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    ArcElement, BarController, BarElement, CategoryScale, Chart, DoughnutController,
    Filler, LineController, LineElement, LinearScale, PointElement, Tooltip,
} from 'chart.js';

Chart.register(ArcElement, BarController, BarElement, CategoryScale, DoughnutController, Filler, LineController, LineElement, LinearScale, PointElement, Tooltip);

const props = defineProps({
    title: { type: String, required: true },
    // What the chart is FOR, in words. A chart nobody can read is a
    // decoration; this is also the canvas's accessible name, so a screen
    // reader gets the same sentence a sighted reader gets.
    summary: { type: String, default: '' },
    type: { type: String, default: 'bar' },
    labels: { type: Array, default: () => [] },
    // [{ label, data, token }] -- token names a CSS custom property so the
    // chart follows the light/dark theme instead of hardcoding hex.
    datasets: { type: Array, default: () => [] },
    unitLabel: { type: String, default: 'Valor' },
});

const canvas = ref(null);
let chart = null;
let themeObserver = null;

const token = name => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '#0f766e';

// The palette is deliberately small and pulled from the design tokens: the
// same four colours the rest of the application already uses for
// primary/success/destructive/warning states, so a red slice on a chart
// means what a red badge means three rows above it.
const SERIES_TOKENS = ['--color-primary', '--color-success', '--color-destructive', '--color-warning', '--color-info'];

function palette() {
    return props.datasets.map((dataset, index) => token(dataset.token || SERIES_TOKENS[index % SERIES_TOKENS.length]));
}

function sliceColours() {
    return props.labels.map((_, index) => token(SERIES_TOKENS[index % SERIES_TOKENS.length]));
}

function config() {
    const colours = palette();
    const grid = token('--color-border');
    const ink = token('--color-muted-foreground');

    return {
        type: props.type,
        data: {
            labels: props.labels,
            datasets: props.datasets.map((dataset, index) => ({
                label: dataset.label,
                data: dataset.data,
                borderColor: props.type === 'doughnut' ? token('--color-card') : colours[index],
                backgroundColor: props.type === 'doughnut' ? sliceColours() : colours[index],
                fill: props.type === 'line' ? false : undefined,
                tension: props.type === 'line' ? 0.25 : undefined,
                borderWidth: props.type === 'line' ? 2 : 1,
                pointRadius: props.type === 'line' ? 2 : undefined,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            // The legend is off and the <table> below carries the series
            // names instead: chart.js draws its legend as canvas pixels, and
            // pixels are not reachable by a screen reader or by a keyboard.
            plugins: { legend: { display: false }, tooltip: { enabled: true } },
            animation: false,
            scales: props.type === 'doughnut' ? undefined : {
                x: { grid: { color: grid }, ticks: { color: ink } },
                y: { beginAtZero: true, grid: { color: grid }, ticks: { color: ink, precision: 0 } },
            },
        },
    };
}

function draw() {
    if (!canvas.value) return;
    chart?.destroy();
    chart = null;
    // A chart that cannot be drawn must not take the report down with it: a
    // 2d context is not guaranteed (an old browser, a headless renderer, a
    // canvas-blocking extension), and the numbers are also printed as a
    // table right below. Losing the picture is a degraded page; throwing here
    // would unmount the whole screen.
    try {
        chart = new Chart(canvas.value, config());
    } catch {
        chart = null;
    }
}

onMounted(() => {
    draw();
    // ThemeToggle flips a class on <html>; the chart's colours came from CSS
    // custom properties resolved once, so without this a chart drawn in light
    // mode keeps light-mode gridlines after the switch.
    themeObserver = new MutationObserver(draw);
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

onBeforeUnmount(() => {
    themeObserver?.disconnect();
    chart?.destroy();
    chart = null;
});

watch(() => [props.labels, props.datasets, props.type], draw, { deep: true });
</script>

<template>
    <figure class="report-chart">
        <figcaption>
            <h3>{{ title }}</h3>
            <p v-if="summary" class="feature-help">{{ summary }}</p>
        </figcaption>
        <div class="report-chart-canvas">
            <canvas ref="canvas" role="img" :aria-label="summary || title"></canvas>
        </div>
        <!--
            The numbers, in text. Not a nicety: the specs require that nothing
            depend on colour alone and that every screen be usable by
            keyboard, and a <canvas> satisfies neither. It also means the page
            still says something useful if the chart fails to draw for any
            reason at all.
        -->
        <details class="report-chart-data">
            <summary>Ver dados em tabela</summary>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ unitLabel }}</th>
                            <th v-for="dataset in datasets" :key="dataset.label" scope="col">{{ dataset.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(label, index) in labels" :key="label">
                            <th scope="row">{{ label }}</th>
                            <td v-for="dataset in datasets" :key="dataset.label">{{ dataset.data[index] ?? 0 }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </figure>
</template>
