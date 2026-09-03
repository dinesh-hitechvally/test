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
    ],
  },
  { to: '/watchlist', label: 'Watchlist', icon: 'star' },
  {
    label: 'Portfolio',
    icon: 'wallet',
    base: '/portfolio',
    children: [
      { to: '/portfolio', label: 'Overview' },
      { to: '/portfolio/performance', label: 'Performance' },
      { to: '/portfolio/reports', label: 'Reports' },
    ],
  },
  { to: '/screener', label: 'Screener', icon: 'filter' },
  { to: '/compare', label: 'Compare', icon: 'compare' },
  {
    label: 'Reports',
    icon: 'report',
    base: '/reports',
    children: [
      { to: '/reports/market', label: 'Market' },
      { to: '/reports/sector', label: 'By Sector' },
      { to: '/reports/stock', label: 'By Stock' },
      { to: '/reports/rule-scanner', label: 'Rule Scanner' },
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
          <svg v-else-if="item.icon === 'star'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
          </svg>
          <svg v-else-if="item.icon === 'filter'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 4h16l-6 8v6l-4 2v-8L4 4z" />
          </svg>
          <svg v-else-if="item.icon === 'compare'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M8 3v18M16 3v18M4 8l4-4 4 4M20 16l-4 4-4-4" />
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
