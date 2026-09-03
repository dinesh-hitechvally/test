import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('../views/Login.vue'), meta: { guestOnly: true } },
  { path: '/register', name: 'register', component: () => import('../views/Register.vue'), meta: { guestOnly: true } },
  { path: '/', name: 'dashboard', component: () => import('../views/Dashboard.vue'), meta: { requiresAuth: true, title: 'Dashboard' } },
  { path: '/stocks', name: 'stocks', component: () => import('../views/Stocks.vue'), meta: { requiresAuth: true, title: 'Stocks' } },
  { path: '/stocks/:symbol', name: 'stock-detail', component: () => import('../views/StockDetail.vue'), meta: { requiresAuth: true, title: 'Stock Detail' } },
  { path: '/watchlist', name: 'watchlist', component: () => import('../views/Watchlist.vue'), meta: { requiresAuth: true, title: 'Watchlist' } },
  { path: '/portfolio', name: 'portfolio', component: () => import('../views/Portfolio.vue'), meta: { requiresAuth: true, title: 'Portfolio' } },
  { path: '/portfolio/performance', name: 'portfolio-performance', component: () => import('../views/PortfolioPerformance.vue'), meta: { requiresAuth: true, title: 'Portfolio Performance' } },
  { path: '/portfolio/reports', name: 'portfolio-reports', component: () => import('../views/PortfolioReports.vue'), meta: { requiresAuth: true, title: 'Portfolio Reports' } },
  { path: '/market/gainers', name: 'market-gainers', component: () => import('../views/MarketList.vue'), meta: { requiresAuth: true, title: 'Gainers', preset: 'gainers' } },
  { path: '/market/losers', name: 'market-losers', component: () => import('../views/MarketList.vue'), meta: { requiresAuth: true, title: 'Losers', preset: 'losers' } },
  { path: '/market/turnover', name: 'market-turnover', component: () => import('../views/MarketList.vue'), meta: { requiresAuth: true, title: 'Turnover', preset: 'turnover' } },
  { path: '/market/volume', name: 'market-volume', component: () => import('../views/MarketList.vue'), meta: { requiresAuth: true, title: 'Volume', preset: 'volume' } },
  { path: '/market/52-week', name: 'market-52-week', component: () => import('../views/Market52Week.vue'), meta: { requiresAuth: true, title: '52 Week High/Low' } },
  { path: '/screener', name: 'screener', component: () => import('../views/Screener.vue'), meta: { requiresAuth: true, title: 'Screener' } },
  { path: '/compare', name: 'compare', component: () => import('../views/Compare.vue'), meta: { requiresAuth: true, title: 'Compare' } },
  { path: '/reports/market', name: 'reports-market', component: () => import('../views/ReportsMarket.vue'), meta: { requiresAuth: true, title: 'Market Report' } },
  { path: '/reports/sector', name: 'reports-sector', component: () => import('../views/ReportsSector.vue'), meta: { requiresAuth: true, title: 'Sector Report' } },
  { path: '/reports/stock/:symbol?', name: 'reports-stock', component: () => import('../views/ReportsStock.vue'), meta: { requiresAuth: true, title: 'Stock Report' } },
  { path: '/reports/rule-scanner', name: 'reports-rule-scanner', component: () => import('../views/ReportsRuleScanner.vue'), meta: { requiresAuth: true, title: 'Rule Scanner' } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (!auth.checked) {
    await auth.fetchUser()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }
})

export default router
