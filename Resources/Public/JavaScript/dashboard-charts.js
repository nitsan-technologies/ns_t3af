import {
  Chart,
  BarController,
  BarElement,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  DoughnutController,
  ArcElement,
  Filler,
} from 'chart.js';

Chart.register(
  BarController,
  BarElement,
  LineController,
  LineElement,
  PointElement,
  CategoryScale,
  LinearScale,
  Tooltip,
  Legend,
  DoughnutController,
  ArcElement,
  Filler,
);

/** @type {Map<HTMLCanvasElement, import('chart.js').Chart>} */
const chartInstances = new Map();

/** @type {boolean} */
let refreshScheduled = false;

/**
 * @param {string} value
 */
function parseChartConfig(value) {
  if (!value || typeof value !== 'string') {
    return null;
  }
  try {
    return JSON.parse(value.trim());
  } catch {
    return null;
  }
}

/**
 * @param {HTMLCanvasElement} canvas
 */
function readChartConfig(canvas) {
  const configId = canvas.getAttribute('data-aiu-chart-config-id');
  if (configId) {
    const script = document.getElementById(configId);
    if (script?.textContent) {
      const config = parseChartConfig(script.textContent);
      if (config) {
        return config;
      }
    }
  }

  const inline =
    canvas.getAttribute('chart-data') ||
    canvas.getAttribute('data-chart-config');
  return parseChartConfig(inline ?? '');
}

/**
 * Destroy any Chart.js instance bound to this canvas (registry + local map).
 *
 * @param {HTMLCanvasElement} canvas
 */
function destroyChartForCanvas(canvas) {
  const fromRegistry = Chart.getChart(canvas);
  if (fromRegistry) {
    fromRegistry.destroy();
  }
  const fromMap = chartInstances.get(canvas);
  if (fromMap && fromMap !== fromRegistry) {
    fromMap.destroy();
  }
  chartInstances.delete(canvas);
}

/**
 * Drop chart instances for detached or hidden canvases so they can be recreated later.
 */
function pruneInactiveCharts() {
  chartInstances.forEach((_chart, canvas) => {
    if (!canvas.isConnected || canvas.closest('.is-hidden')) {
      destroyChartForCanvas(canvas);
    }
  });
}

/**
 * @param {ParentNode} [root]
 */
function initCharts(root = document) {
  const scope = root instanceof Document ? root : root;
  pruneInactiveCharts();

  scope.querySelectorAll('.aiu-chart[data-aiu-chart]').forEach((canvas) => {
    if (!(canvas instanceof HTMLCanvasElement)) {
      return;
    }
    const hiddenPanel = canvas.closest('.is-hidden');
    if (hiddenPanel instanceof HTMLElement) {
      destroyChartForCanvas(canvas);
      return;
    }

    const config = readChartConfig(canvas);
    if (!config) {
      return;
    }

    destroyChartForCanvas(canvas);

    try {
      const chart = new Chart(canvas.getContext('2d'), config);
      chartInstances.set(canvas, chart);
    } catch (err) {
      // Never let a chart failure break mode toggle / credits activate.
      console.error('[ns_t3af] Failed to initialise dashboard chart', err);
    }
  });
}

function refreshCharts() {
  initCharts(document);
  chartInstances.forEach((chart) => {
    try {
      chart.resize();
    } catch {
      // Ignore resize on charts mid-destroy.
    }
  });
}

function scheduleRefresh() {
  if (refreshScheduled) {
    return;
  }
  refreshScheduled = true;
  requestAnimationFrame(() => {
    refreshScheduled = false;
    refreshCharts();
  });
}

function bindModuleLoadedListener() {
  try {
    if (window.parent === window) {
      return;
    }
    const router = window.parent.document.querySelector('typo3-backend-module-router');
    const host = router?.parentElement;
    if (host && !host.dataset.aiuChartModuleListener) {
      host.dataset.aiuChartModuleListener = '1';
      host.addEventListener('typo3-module-loaded', scheduleRefresh);
    }
  } catch {
    // Cross-origin or restricted parent access — iframe document events only.
  }
}

function boot() {
  bindModuleLoadedListener();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleRefresh, { once: true });
  } else {
    scheduleRefresh();
  }
}

boot();

document.addEventListener('typo3-module-loaded', scheduleRefresh);
document.addEventListener('aiu-dashboard-view-changed', scheduleRefresh);

export { initCharts, refreshCharts };
