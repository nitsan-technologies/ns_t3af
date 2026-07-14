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

/** @type {Map<HTMLCanvasElement, Chart>} */
const chartInstances = new Map();

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
 * @param {ParentNode} [root]
 */
function initCharts(root = document) {
  const scope = root instanceof Document ? root : root;
  scope.querySelectorAll('.aiu-chart[data-aiu-chart]').forEach((canvas) => {
    if (!(canvas instanceof HTMLCanvasElement)) {
      return;
    }
    const hiddenPanel = canvas.closest('.is-hidden');
    if (hiddenPanel instanceof HTMLElement) {
      return;
    }

    const config = readChartConfig(canvas);
    if (!config) {
      return;
    }

    const existing = chartInstances.get(canvas);
    if (existing) {
      existing.destroy();
      chartInstances.delete(canvas);
    }

    chartInstances.set(canvas, new Chart(canvas.getContext('2d'), config));
  });
}

function refreshCharts() {
  initCharts(document);
  chartInstances.forEach((chart) => {
    chart.resize();
  });
}

function scheduleRefresh() {
  requestAnimationFrame(() => {
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
