<script setup>
import { reactive, watch } from 'vue'
import { useRoute } from 'vue-router'
import NavIcon from './NavIcon.vue'

const route = useRoute()

// Ordered for a retail investor's mental model first: their own money
// (Portfolio, Watchlist), the app's core value prop (Signals), and casual
// browsing (Market) — all visible with no extra click. The deeper/expert
// tooling (Screener, Technical Analysis, Compare, the full Reports suite)
// still has every route it always did, just grouped under one
// clearly-labeled "Analyst Tools" section instead of competing for
// top-billing with 10 other equally-weighted menus.
const navItems = [
  { to: '/', label: 'Dashboard', icon: 'home' },
  {
    label: 'My Portfolio',
    icon: 'wallet',
    base: '/portfolio',
    children: [
      { to: '/portfolio', label: 'Overview' },
      { to: '/portfolio/holdings', label: 'Holdings' },
      { to: '/portfolio/performance', label: 'Performance' },
      { to: '/portfolio/transactions', label: 'Transactions' },
      { to: '/portfolio/diversification', label: 'Diversification' },
      { to: '/portfolio/reports', label: 'Realized P/L / Tax Report' },
      { to: '/portfolio/statements', label: 'Statements' },
    ],
  },
  {
    label: 'Watchlist',
    icon: 'star',
    base: '/watchlist',
    children: [
      { to: '/watchlist', label: 'My Watchlist(s)' },
      { to: '/watchlist/alerts', label: 'Price Alerts' },
    ],
  },
  {
    label: 'Signals',
    icon: 'target',
    base: '/signals',
    children: [
      { to: '/signals/buy', label: 'Buy Signals' },
      { to: '/signals/sell', label: 'Sell Signals' },
      { to: '/signals/history', label: 'Signal History / Accuracy' },
    ],
  },
  {
    label: 'Market',
    icon: 'chart',
    base: '/market',
    children: [
      { to: '/stocks', label: 'All Stocks' },
      { to: '/market/gainers', label: 'Gainers' },
      { to: '/market/losers', label: 'Losers' },
      { to: '/market/turnover', label: 'Turnover' },
      { to: '/market/volume', label: 'Volume' },
      { to: '/market/52-week', label: '52 Week High/Low' },
      { to: '/market/sector-overview', label: 'Sector Overview' },
      { to: '/market/indices', label: 'Indices' },
    ],
  },
  { section: 'Analyst Tools' },
  {
    label: 'Screener',
    icon: 'filter',
    base: '/screener',
    children: [
      { to: '/screener', label: 'Custom Screener' },
      { to: '/screener/presets', label: 'Preset Screens' },
      { to: '/screener/saved', label: 'Saved Screens' },
    ],
  },
  {
    label: 'Technical Analysis',
    icon: 'pulse',
    base: '/technical',
    children: [
      { to: '/technical/charts', label: 'Stock Charts' },
      { to: '/technical/dashboard', label: 'Indicator Dashboard' },
      { to: '/technical/support-resistance', label: 'Support & Resistance Finder' },
      { to: '/technical/patterns', label: 'Chart Patterns' },
    ],
  },
  {
    label: 'Compare',
    icon: 'compare',
    base: '/compare',
    children: [
      { to: '/compare', label: 'Stock vs Stock' },
      { to: '/compare/index', label: 'Stock vs Index' },
    ],
  },
  {
    label: 'Reports',
    icon: 'report',
    base: '/reports',
    children: [
      { to: '/reports/market', label: 'Market' },
      { to: '/reports/sector', label: 'By Sector' },
      { to: '/reports/stock', label: 'By Stock' },
      { to: '/reports/horizon', label: 'Investment Horizon' },
      { to: '/reports/analyst', label: 'Analyst Report' },
      { to: '/reports/rule-scanner', label: 'Rule Scanner' },
      { to: '/reports/technical', label: 'Technical Analysis' },
      { to: '/reports/dividends', label: 'Dividend Report' },
    ],
  },
  { section: 'Account' },
  {
    label: 'Settings',
    icon: 'gear',
    base: '/settings',
    children: [
      { to: '/settings', label: 'Overview' },
      { to: '/settings/profile', label: 'Profile' },
      { to: '/settings/notifications', label: 'Notification Preferences' },
      { to: '/settings/data-source', label: 'Data Source / Scrape Settings' },
      { to: '/settings/login-history', label: 'Login History' },
      { to: '/settings/admin', label: 'Users / Admin' },
    ],
  },
]

function inGroup(item) {
  if (item.to) return false
  return route.path === item.base || route.path.startsWith(item.base + '/') || item.children.some((c) => route.path === c.to)
}

function isActive(to) {
  return to === '/' ? route.path === '/' : route.path === to
}

const expanded = reactive({})
navItems.forEach((item) => {
  if (item.children) expanded[item.label] = inGroup(item)
})

watch(
  () => route.path,
  () => {
    navItems.forEach((item) => {
      if (item.children && inGroup(item)) expanded[item.label] = true
    })
  }
)

function toggle(item) {
  expanded[item.label] = !expanded[item.label]
}
</script>

<template>
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark"><NavIcon name="pulse" /></span>
      <span class="brand-name">Share Market Signals</span>
    </div>

    <nav class="nav">
      <template v-for="item in navItems" :key="item.section || item.label">
        <div v-if="item.section" class="section-label">{{ item.section }}</div>

        <RouterLink v-else-if="!item.children" :to="item.to" class="nav-link" :class="{ active: isActive(item.to) }">
          <NavIcon :name="item.icon" />
          <span>{{ item.label }}</span>
        </RouterLink>

        <div v-else class="nav-group">
          <button class="nav-link nav-group-header" :class="{ active: inGroup(item) && !expanded[item.label] }" @click="toggle(item)">
            <NavIcon :name="item.icon" />
            <span class="group-label">{{ item.label }}</span>
            <svg class="chevron" :class="{ open: expanded[item.label] }" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 18l6-6-6-6" />
            </svg>
          </button>
          <div v-show="expanded[item.label]" class="sub-nav">
            <RouterLink v-for="child in item.children" :key="child.to" :to="child.to" class="sub-link" :class="{ active: isActive(child.to) }">
              {{ child.label }}
            </RouterLink>
          </div>
        </div>
      </template>
    </nav>
  </aside>
</template>

<style scoped>
.sidebar {
  width: 230px;
  flex-shrink: 0;
  background: #101827;
  color: #f5f7fa;
  display: flex;
  flex-direction: column;
  height: 100vh;
  position: sticky;
  top: 0;
  overflow-y: auto;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 20px 18px;
  border-bottom: 1px solid #1f2937;
}

.brand-mark {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  background: var(--primary-gradient);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.8rem;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(37, 99, 235, 0.35);
}

.brand-name {
  font-weight: 700;
  font-size: 0.92rem;
  line-height: 1.2;
}

.nav {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 14px 10px;
}

.section-label {
  margin: 14px 6px 4px;
  padding-top: 10px;
  border-top: 1px solid #1f2937;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #64748b;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 9px;
  color: #cbd5e1;
  text-decoration: none;
  font-size: 0.88rem;
  font-weight: 500;
  width: 100%;
  border: none;
  border-left: 3px solid transparent;
  background: none;
  cursor: pointer;
  font-family: inherit;
  text-align: left;
  transition: background-color 0.12s ease, color 0.12s ease, border-color 0.12s ease;
}

.nav-link:hover {
  background: #1a2333;
  color: #fff;
}

.nav-link.active {
  background: rgba(37, 99, 235, 0.18);
  border-left-color: #5b8def;
  color: #fff;
}

.nav-group-header {
  position: relative;
}

.group-label {
  flex: 1;
}

.chevron {
  transition: transform 0.15s;
  flex-shrink: 0;
}

.chevron.open {
  transform: rotate(90deg);
}

.sub-nav {
  display: flex;
  flex-direction: column;
  gap: 1px;
  padding: 2px 0 4px 30px;
}

.sub-link {
  color: #94a3b8;
  text-decoration: none;
  font-size: 0.82rem;
  padding: 7px 10px;
  border-radius: 7px;
  transition: background-color 0.12s ease, color 0.12s ease;
}

.sub-link:hover {
  background: #1a2333;
  color: #fff;
}

.sub-link.active {
  background: rgba(37, 99, 235, 0.22);
  color: #a9c6ff;
  font-weight: 600;
}
</style>
