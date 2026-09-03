<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useStocksStore } from '../stores/stocks'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const stocksStore = useStocksStore()

const title = computed(() => route.meta.title || 'Share Market Signals')
const lastScrape = computed(() => stocksStore.scrapeLogs[0] || null)

const searchTerm = ref('')
const showResults = ref(false)

const matches = computed(() => {
  const term = searchTerm.value.trim().toLowerCase()
  if (!term) return []
  return stocksStore.stocks
    .filter((s) => s.symbol.toLowerCase().includes(term) || (s.company_name || '').toLowerCase().includes(term))
    .slice(0, 8)
})

function goToStock(symbol) {
  searchTerm.value = ''
  showResults.value = false
  router.push({ name: 'stock-detail', params: { symbol } })
}

function handleEnter() {
  if (matches.value.length > 0) goToStock(matches.value[0].symbol)
}

function handleSearchBlur() {
  // delay so a click on a result registers before the list unmounts
  setTimeout(() => {
    showResults.value = false
  }, 150)
}

const scraping = ref(false)
const scrapeMessage = ref('')

async function handleScrape() {
  scraping.value = true
  scrapeMessage.value = ''
  try {
    const result = await stocksStore.runScrape()
    scrapeMessage.value = `Updated ${result.updated_prices} (${result.created_stocks} new)`
    await stocksStore.fetchScrapeLogs()
  } catch {
    scrapeMessage.value = stocksStore.lastError
  } finally {
    scraping.value = false
  }
}

async function handleLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <header class="topbar">
    <h1 class="page-title">{{ title }}</h1>

    <div class="search-wrap">
      <input
        v-model="searchTerm"
        class="input search-input"
        placeholder="Jump to a stock…"
        @focus="showResults = true"
        @blur="handleSearchBlur"
        @keyup.enter="handleEnter"
      />
      <div v-if="showResults && matches.length" class="search-results">
        <button v-for="m in matches" :key="m.id" class="search-result" @mousedown.prevent="goToStock(m.symbol)">
          <strong>{{ m.symbol }}</strong>
          <span class="muted">{{ m.company_name }}</span>
        </button>
      </div>
    </div>

    <div class="topbar-actions">
      <div class="scrape-block">
        <button class="btn" :disabled="scraping" @click="handleScrape">
          {{ scraping ? 'Scraping…' : 'Scrape Latest Data' }}
        </button>
        <span v-if="scrapeMessage" class="muted scrape-msg">{{ scrapeMessage }}</span>
        <span v-else-if="lastScrape" class="muted scrape-msg">
          Last: {{ new Date(lastScrape.created_at).toLocaleTimeString() }}
        </span>
      </div>

      <div class="user-area">
        <span>{{ auth.user?.name }}</span>
        <button class="link-btn" @click="handleLogout">Log out</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.topbar {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 14px 24px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
}

.page-title {
  margin: 0;
  font-size: 1.15rem;
  white-space: nowrap;
}

.search-wrap {
  position: relative;
  flex: 1;
  max-width: 320px;
}

.search-input {
  width: 100%;
}

.search-results {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.1);
  z-index: 20;
  overflow: hidden;
}

.search-result {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  width: 100%;
  padding: 8px 12px;
  background: none;
  border: none;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  text-align: left;
  font-size: 0.85rem;
}

.search-result:last-child {
  border-bottom: none;
}

.search-result:hover {
  background: #f1f5f9;
}

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-left: auto;
}

.scrape-block {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 2px;
}

.scrape-msg {
  font-size: 0.75rem;
}

.user-area {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 0.88rem;
  color: var(--text-muted);
  white-space: nowrap;
}

.link-btn {
  background: none;
  border: 1px solid var(--border);
  color: var(--text-muted);
  padding: 5px 10px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.82rem;
}

.link-btn:hover {
  background: #f1f5f9;
}
</style>
