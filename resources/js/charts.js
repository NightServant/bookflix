/**
 * Chart.js for the admin model comparison only: imported on demand by version-chart.js, so no other page downloads it. Only the parts a line
 * chart uses are registered. One series on one axis in the accent colour; a filled marker is a version that was promoted, a ring one that was
 * not, and a null value (a run with no metrics) leaves a gap. The table on the page carries the same numbers and is the accessible alternative.
 */
import { CategoryScale, Chart, LinearScale, LineController, LineElement, PointElement, Tooltip } from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip);

const token = (name, fallback) => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

export function drawVersionChart(canvas, { labels, values, promoted, label, format }) {
    const accent = token('--color-accent-electric', '#4061e7');
    const card = token('--color-surface-card', '#232323');
    const grid = token('--color-hairline', '#2d2d2d');
    const muted = token('--color-ink-subtle', '#b3b3b3');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    return new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label,
                data: values,
                spanGaps: false,
                tension: 0,
                borderColor: accent,
                borderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 6,
                pointBorderWidth: 2,
                pointBorderColor: accent,
                pointBackgroundColor: promoted.map((wasPromoted) => (wasPromoted ? accent : card)),
                pointHoverBackgroundColor: promoted.map((wasPromoted) => (wasPromoted ? accent : card)),
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: reduced ? false : { duration: 250 },
            interaction: { mode: 'nearest', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    displayColors: false,
                    callbacks: {
                        label: (item) => `${label} ${format(item.parsed.y)} · ${promoted[item.dataIndex] ? 'promoted' : 'not promoted'}`,
                    },
                },
            },
            scales: {
                x: { grid: { display: false }, border: { color: grid }, ticks: { color: muted, maxRotation: 0, autoSkip: true } },
                y: { grid: { color: grid }, border: { display: false }, ticks: { color: muted, maxTicksLimit: 5, callback: (value) => format(value) } },
            },
        },
    });
}
