<script setup lang="ts">
import {
    CategoryScale,
    Chart as ChartJS,
    Filler,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
} from 'chart.js';
import type { ChartConfiguration } from 'chart.js';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

ChartJS.register(
    CategoryScale,
    Filler,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
);

const props = defineProps<{
    points: number[];
    trend: 'up' | 'down';
}>();

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: ChartJS<'line'> | null = null;

const color = computed(() =>
    props.trend === 'up' ? 'rgb(45, 212, 191)' : 'rgb(248, 113, 113)',
);
const fillColor = computed(() =>
    props.trend === 'up'
        ? 'rgba(45, 212, 191, 0.12)'
        : 'rgba(248, 113, 113, 0.12)',
);

function config(): ChartConfiguration<'line'> {
    return {
        type: 'line',
        data: {
            labels: props.points.map((_, index) => index.toString()),
            datasets: [
                {
                    data: props.points,
                    borderColor: color.value,
                    backgroundColor: fillColor.value,
                    borderWidth: 2,
                    cubicInterpolationMode: 'monotone',
                    fill: true,
                    pointRadius: 0,
                    pointHoverRadius: 0,
                    tension: 0.38,
                },
            ],
        },
        options: {
            animation: false,
            events: [],
            maintainAspectRatio: false,
            normalized: true,
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false },
            },
            scales: {
                x: { display: false },
                y: {
                    display: false,
                    grace: '18%',
                },
            },
        },
    };
}

function renderChart(): void {
    if (!canvas.value) {
        return;
    }

    chart?.destroy();
    chart = new ChartJS(canvas.value, config());
}

watch(
    () => [props.points, props.trend],
    () => renderChart(),
    { deep: true },
);

onMounted(renderChart);
onBeforeUnmount(() => chart?.destroy());
</script>

<template>
    <canvas ref="canvas" aria-hidden="true" />
</template>
