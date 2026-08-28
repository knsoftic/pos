import './bootstrap';

/**
 * Shared chart defaults so every chart in the app looks like one system.
 * Colors follow the brand palette in resources/css/app.css.
 */
window.chartDefaults = (dark = document.documentElement.classList.contains('dark')) => ({
    chart: {
        fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
        toolbar: { show: false },
        background: 'transparent',
        animations: { easing: 'easeinout', speed: 300 },
    },
    theme: { mode: dark ? 'dark' : 'light' },
    colors: ['#1f4ded', '#10b981', '#8b5cf6', '#f59e0b'],
    grid: {
        borderColor: dark ? 'rgba(148,163,184,0.15)' : 'rgba(100,116,139,0.15)',
        strokeDashArray: 4,
    },
    dataLabels: { enabled: false },
    tooltip: { theme: dark ? 'dark' : 'light' },
});

/**
 * ApexCharts is ~600 kB — far too much to ship on every request when only
 * dashboards and reports draw charts, and the POS screen must stay fast (#122).
 * So it is code-split into its own chunk and fetched on demand:
 *
 *     loadCharts().then((ApexCharts) => new ApexCharts(el, options).render());
 *
 * Repeat calls reuse the same promise, so the chunk is only downloaded once
 * even when a page renders several charts.
 */
let chartsPromise;

window.loadCharts = () => {
    chartsPromise ??= import('apexcharts').then((module) => {
        window.ApexCharts = module.default;

        return module.default;
    });

    return chartsPromise;
};
