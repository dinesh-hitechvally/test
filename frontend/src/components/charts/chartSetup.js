import { Chart as ChartJS } from 'chart.js'

// Axis labels and tooltips on every chart group numbers the Nepali way (12,34,567). Chart.js's defaults are
// global, so importing this once from a chart component is enough; it is a side-effect import on purpose, and
// it lives here rather than in main.js so Chart.js stays out of the main bundle.
ChartJS.defaults.locale = 'en-IN'
