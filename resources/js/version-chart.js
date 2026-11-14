/**
 * `versionChart`: the line chart on the admin model comparison page. Chart.js arrives as its own chunk (./charts.js) when the page first draws.
 * `series` holds every metric's values oldest first, so switching the metric redraws without a request. A metric with fewer than two values
 * draws nothing (`plottable`), and the page says why; the table below always holds the numbers.
 */
export default function versionChart(Alpine) {
    Alpine.data('versionChart', ({ series, metrics, initial }) => {
        let chart = null;

        return {
            metric: initial,

            get plottable() {
                return (series.values[this.metric] ?? []).filter((value) => value !== null).length >= 2;
            },

            get label() {
                return metrics[this.metric]?.label ?? this.metric;
            },

            async init() {
                const { drawVersionChart } = await import('./charts.js');
                this.drawChart = drawVersionChart;
                this.render();
                // Render after Alpine has shown the canvas (x-show), so Chart.js measures a visible box.
                this.$watch('metric', () => this.$nextTick(() => this.render()));
            },

            render() {
                chart?.destroy();
                chart = null;

                if (!this.plottable || !this.drawChart) {
                    return;
                }

                const decimals = metrics[this.metric]?.decimals ?? 3;
                chart = this.drawChart(this.$refs.canvas, {
                    labels: series.labels,
                    values: series.values[this.metric],
                    promoted: series.promoted,
                    label: this.label,
                    format: (value) => Number(value).toFixed(decimals),
                });
            },

            destroy() {
                chart?.destroy();
                chart = null;
            },
        };
    });
}
