<script setup>
import { reactive, watch } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const navItems = [
  { to: '/', label: 'Dashboard', icon: 'grid' },
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
      { to: '/market/ipo', label: 'IPO / New Listings' },
      { to: '/market/news', label: 'Market News' },
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
    label: 'Portfolio',
    icon: 'wallet',
    base: '/portfolio',
    children: [
      { to: '/portfolio', label: 'Overview' },
      { to: '/portfolio/holdings', label: 'Holdings' },
      { to: '/portfolio/transactions', label: 'Transactions' },
      { to: '/portfolio/performance', label: 'Performance' },
      { to: '/portfolio/diversification', label: 'Diversification' },
      { to: '/portfolio/reports', label: 'Realized P/L / Tax Report' },
      { to: '/portfolio/statements', label: 'Statements' },
    ],
  },
  {
    label: 'Signals & Recommendations',
    icon: 'target',
    base: '/signals',
    children: [
      { to: '/signals/buy', label: 'Buy Signals' },
      { to: '/signals/sell', label: 'Sell Signals' },
      { to: '/signals/history', label: 'Signal History / Accuracy' },
      { to: '/signals/how-it-works', label: 'How Signals Work' },
    ],
  },
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
      { to: '/technical/glossary', label: 'Technical Glossary' },
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
      { to: '/reports/rule-scanner', label: 'Rule Scanner' },
      { to: '/reports/technical', label: 'Technical Analysis' },
      { to: '/reports/digest', label: 'Daily Digest' },
      { to: '/reports/beginner', label: "Beginner's Report" },
    ],
  },
  {
    label: 'Learn',
    icon: 'book',
    base: '/learn',
    children: [
      { to: '/learn/basics', label: 'Stock Market Basics' },
      { to: '/learn/reading-signals', label: 'How to Read Signals' },
      { to: '/learn/indicators', label: 'Understanding Technical Indicators' },
      { to: '/learn/glossary', label: 'Glossary of Terms' },
    ],
  },
  {
    label: 'Settings',
    icon: 'gear',
    base: '/settings',
    children: [
      { to: '/settings', label: 'Overview' },
      { to: '/settings/profile', label: 'Profile' },
      { to: '/settings/notifications', label: 'Notification Preferences' },
      { to: '/settings/data-source', label: 'Data Source / Scrape Settings' },
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
      <span class="brand-mark">SM</span>
      <span class="brand-name">Share Market Signals</span>
    </div>

    <nav class="nav">
      <template v-for="item in navItems" :key="item.label">
        <RouterLink v-if="!item.children" :to="item.to" class="nav-link" :class="{ active: isActive(item.to) }">
          <svg v-if="item.icon === 'grid'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" />
            <rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" />
          </svg>
          <span>{{ item.label }}</span>
        </RouterLink>

        <div v-else class="nav-group">
          <button class="nav-link nav-group-header" :class="{ active: inGroup(item) && !expanded[item.label] }" @click="toggle(item)">
            <svg v-if="item.icon === 'chart'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 3v18h18" /><path d="M7 15l4-5 3 3 5-7" />
            </svg>
            <svg v-else-if="item.icon === 'wallet'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="2" y="7" width="20" height="13" rx="2" /><path d="M2 10h20" /><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
            </svg>
            <svg v-else-if="item.icon === 'report'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M6 2h9l5 5v15H6z" /><path d="M9 13v4M12 10v7M15 13v4" />
            </svg>
            <svg v-else-if="item.icon === 'star'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
            </svg>
            <svg v-else-if="item.icon === 'filter'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4h16l-6 8v6l-4 2v-8L4 4z" />
            </svg>
            <svg v-else-if="item.icon === 'compare'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M8 3v18M16 3v18M4 8l4-4 4 4M20 16l-4 4-4-4" />
            </svg>
            <svg v-else-if="item.icon === 'target'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="5" /><circle cx="12" cy="12" r="1" />
            </svg>
            <svg v-else-if="item.icon === 'pulse'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M2 12h4l2-7 4 14 3-9 2 5h5" />
            </svg>
            <svg v-else-if="item.icon === 'book'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M4 4.5A2.5 2.5 0 0 1 6.5 2H20v17H6.5A2.5 2.5 0 0 0 4 21.5v-17z" /><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
            </svg>
            <svg v-else-if="item.icon === 'gear'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="3" />
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
            </svg>
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
  border-radius: 8px;
  background: #2563eb;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.8rem;
  flex-shrink: 0;
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

.nav-link {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: 8px;
  color: #cbd5e1;
  text-decoration: none;
  font-size: 0.88rem;
  font-weight: 500;
  width: 100%;
  border: none;
  background: none;
  cursor: pointer;
  font-family: inherit;
  text-align: left;
}

.nav-link:hover {
  background: #1e293b;
  color: #fff;
}

.nav-link.active {
  background: #2563eb;
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
  border-radius: 6px;
}

.sub-link:hover {
  background: #1e293b;
  color: #fff;
}

.sub-link.active {
  background: #1e3a8a;
  color: #fff;
}
</style>
