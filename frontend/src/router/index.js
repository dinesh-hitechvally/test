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
  { path: '/market/sector-overview', name: 'market-sector-overview', component: () => import('../views/MarketSectorOverview.vue'), meta: { requiresAuth: true, title: 'Sector Overview' } },
  { path: '/market/indices', name: 'market-indices', component: () => import('../views/Indices.vue'), meta: { requiresAuth: true, title: 'Indices' } },
  { path: '/market/ipo', name: 'market-ipo', component: () => import('../views/MarketIpo.vue'), meta: { requiresAuth: true, title: 'IPO / New Listings' } },
  { path: '/market/news', name: 'market-news', component: () => import('../views/MarketNews.vue'), meta: { requiresAuth: true, title: 'Market News' } },

  { path: '/watchlist/alerts', name: 'watchlist-alerts', component: () => import('../views/WatchlistAlerts.vue'), meta: { requiresAuth: true, title: 'Price Alerts' } },

  { path: '/portfolio/holdings', name: 'portfolio-holdings', component: () => import('../views/PortfolioHoldings.vue'), meta: { requiresAuth: true, title: 'Holdings' } },
  { path: '/portfolio/transactions', name: 'portfolio-transactions', component: () => import('../views/PortfolioTransactions.vue'), meta: { requiresAuth: true, title: 'Transactions' } },
  { path: '/portfolio/diversification', name: 'portfolio-diversification', component: () => import('../views/PortfolioDiversification.vue'), meta: { requiresAuth: true, title: 'Diversification' } },
  { path: '/portfolio/statements', name: 'portfolio-statements', component: () => import('../views/Statements.vue'), meta: { requiresAuth: true, title: 'Statements' } },

  { path: '/signals/buy', name: 'signals-buy', component: () => import('../views/SignalsBuy.vue'), meta: { requiresAuth: true, title: 'Buy Signals' } },
  { path: '/signals/sell', name: 'signals-sell', component: () => import('../views/SignalsSell.vue'), meta: { requiresAuth: true, title: 'Sell Signals' } },
  { path: '/signals/history', name: 'signals-history', component: () => import('../views/SignalHistory.vue'), meta: { requiresAuth: true, title: 'Signal History / Accuracy' } },
  { path: '/signals/how-it-works', name: 'signals-how-it-works', component: () => import('../views/LearnTopic.vue'), meta: { requiresAuth: true, title: 'How Signals Work', topic: 'signals-explainer' } },

  { path: '/screener/presets', name: 'screener-presets', component: () => import('../views/ScreenerPresets.vue'), meta: { requiresAuth: true, title: 'Preset Screens' } },
  { path: '/screener/saved', name: 'screener-saved', component: () => import('../views/ScreenerSaved.vue'), meta: { requiresAuth: true, title: 'Saved Screens' } },

  { path: '/technical/charts/:symbol?', name: 'technical-charts', component: () => import('../views/StockCharts.vue'), meta: { requiresAuth: true, title: 'Stock Charts' } },
  { path: '/technical/dashboard', name: 'technical-dashboard', component: () => import('../views/IndicatorDashboard.vue'), meta: { requiresAuth: true, title: 'Indicator Dashboard' } },
  { path: '/technical/support-resistance', name: 'technical-support-resistance', component: () => import('../views/SupportResistanceFinder.vue'), meta: { requiresAuth: true, title: 'Support & Resistance Finder' } },
  { path: '/technical/patterns', name: 'technical-patterns', component: () => import('../views/ChartPatterns.vue'), meta: { requiresAuth: true, title: 'Chart Patterns' } },
  { path: '/technical/glossary', name: 'technical-glossary', component: () => import('../views/LearnTopic.vue'), meta: { requiresAuth: true, title: 'Technical Glossary', topic: 'indicators' } },

  { path: '/screener', name: 'screener', component: () => import('../views/Screener.vue'), meta: { requiresAuth: true, title: 'Screener' } },
  { path: '/compare', name: 'compare', component: () => import('../views/Compare.vue'), meta: { requiresAuth: true, title: 'Compare' } },
  { path: '/compare/index', name: 'compare-index', component: () => import('../views/CompareIndex.vue'), meta: { requiresAuth: true, title: 'Stock vs Index' } },

  { path: '/reports/market', name: 'reports-market', component: () => import('../views/ReportsMarket.vue'), meta: { requiresAuth: true, title: 'Market Report' } },
  { path: '/reports/sector', name: 'reports-sector', component: () => import('../views/ReportsSector.vue'), meta: { requiresAuth: true, title: 'Sector Report' } },
  { path: '/reports/stock/:symbol?', name: 'reports-stock', component: () => import('../views/ReportsStock.vue'), meta: { requiresAuth: true, title: 'Stock Report' } },
  { path: '/reports/rule-scanner', name: 'reports-rule-scanner', component: () => import('../views/ReportsRuleScanner.vue'), meta: { requiresAuth: true, title: 'Rule Scanner' } },
  { path: '/reports/technical/:symbol?', name: 'reports-technical', component: () => import('../views/ReportsTechnical.vue'), meta: { requiresAuth: true, title: 'Technical Analysis' } },
  { path: '/reports/digest', name: 'reports-digest', component: () => import('../views/DailyDigest.vue'), meta: { requiresAuth: true, title: 'Daily Digest' } },
  { path: '/reports/beginner', name: 'reports-beginner', component: () => import('../views/BeginnerReport.vue'), meta: { requiresAuth: true, title: "Beginner's Report" } },

  { path: '/learn/basics', name: 'learn-basics', component: () => import('../views/LearnTopic.vue'), meta: { requiresAuth: true, title: 'Stock Market Basics', topic: 'basics' } },
  { path: '/learn/reading-signals', name: 'learn-reading-signals', component: () => import('../views/LearnTopic.vue'), meta: { requiresAuth: true, title: 'How to Read Signals', topic: 'reading-signals' } },
  { path: '/learn/indicators', name: 'learn-indicators', component: () => import('../views/LearnTopic.vue'), meta: { requiresAuth: true, title: 'Understanding Technical Indicators', topic: 'indicators' } },
  { path: '/learn/glossary', name: 'learn-glossary', component: () => import('../views/LearnTopic.vue'), meta: { requiresAuth: true, title: 'Glossary of Terms', topic: 'glossary' } },

  { path: '/settings/profile', name: 'settings-profile', component: () => import('../views/Profile.vue'), meta: { requiresAuth: true, title: 'Profile' } },
  { path: '/settings/notifications', name: 'settings-notifications', component: () => import('../views/NotificationPreferences.vue'), meta: { requiresAuth: true, title: 'Notification Preferences' } },
  { path: '/settings/data-source', name: 'settings-data-source', component: () => import('../views/DataSourceSettings.vue'), meta: { requiresAuth: true, title: 'Data Source / Scrape Settings' } },
  { path: '/settings/admin', name: 'settings-admin', component: () => import('../views/UsersAdmin.vue'), meta: { requiresAuth: true, title: 'Users' } },
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
