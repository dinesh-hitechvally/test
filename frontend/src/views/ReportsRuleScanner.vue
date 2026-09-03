<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'
import { formatPrice } from '../utils/format'

const allRules = ref([])
const selected = ref([])
const mode = ref('any')
const results = ref(null)
const loadingRules = ref(true)
const scanning = ref(false)
const error = ref('')
const hasScanned = ref(false)

const bullishRules = computed(() => allRules.value.filter((r) => r.direction === 'bullish'))
const bearishRules = computed(() => allRules.value.filter((r) => r.direction === 'bearish'))

function isSelected(key) {
  return selected.value.includes(key)
}

function toggle(key) {
  selected.value = isSelected(key) ? selected.value.filter((k) => k !== key) : [...selected.value, key]
}

function selectGroup(group) {
  const keys = group.map((r) => r.key)
  selected.value = [...new Set([...selected.value, ...keys])]
}

function clearSelection() {
  selected.value = []
}

function changeTone(pct) {
  if (pct === null || pct === undefined) return ''
  return pct > 0 ? 'positive' : pct < 0 ? 'negative' : ''
}

async function loadRules() {
  loadingRules.value = true
  const { data } = await client.get('/reports/rules')
  allRules.value = data
  loadingRules.value = false
}

async function scan() {
  if (selected.value.length === 0) return

  scanning.value = true
  error.value = ''
  hasScanned.value = true
  try {
    const { data } = await client.get('/reports/rule-scan', {
      params: { rules: selected.value, mode: mode.value },
    })
    results.value = data
  } catch (e) {
    error.value = e.response?.data?.message || 'Scan failed.'
    results.value = null
  } finally {
    scanning.value = false
  }
}

onMounted(loadRules)
</script>

<template>
  <div>
    <h1>Rule Scanner</h1>
    <p class="muted">
      Pick any combination of the buy/sell conditions the signal engine checks daily, and find every stock whose
      latest signal fired them. This reads today's already-computed signals — not financial advice.
    </p>

    <p v-if="loadingRules" class="muted">Loading rules…</p>

    <template v-else>
      <div class="card">
        <div class="rules-grid">
          <div>
            <div class="group-head">
              <h3>Bullish (Buy) Rules</h3>
              <button class="select-all-btn" @click="selectGroup(bullishRules)">Select all</button>
            </div>
            <label v-for="r in bullishRules" :key="r.key" class="rule-option">
              <input type="checkbox" :checked="isSelected(r.key)" @change="toggle(r.key)" />
              {{ r.label }}
            </label>
          </div>
          <div>
            <div class="group-head">
              <h3>Bearish (Sell) Rules</h3>
              <button class="select-all-btn" @click="selectGroup(bearishRules)">Select all</button>
            </div>
            <label v-for="r in bearishRules" :key="r.key" class="rule-option">
              <input type="checkbox" :checked="isSelected(r.key)" @change="toggle(r.key)" />
              {{ r.label }}
            </label>
          </div>
        </div>

        <div class="scan-bar">
          <label class="mode-option">
            <input type="radio" value="any" v-model="mode" />
            Match ANY selected rule
          </label>
          <label class="mode-option">
            <input type="radio" value="all" v-model="mode" />
            Match ALL selected rules
          </label>
          <div class="spacer" />
          <button class="btn-secondary btn" :disabled="selected.length === 0" @click="clearSelection">Clear</button>
          <button class="btn" :disabled="selected.length === 0 || scanning" @click="scan">
            {{ scanning ? 'Scanning…' : `Scan (${selected.length} rule${selected.length === 1 ? '' : 's'})` }}
          </button>
        </div>
      </div>

      <p v-if="error" class="muted" style="margin-top: 16px">{{ error }}</p>

      <div v-else-if="results" class="card" style="margin-top: 16px">
        <h3>{{ results.matched_count }} stock{{ results.matched_count === 1 ? '' : 's' }} matched</h3>
        <table class="table">
          <thead>
            <tr><th>Symbol</th><th>Company</th><th>Sector</th><th>Price</th><th>Change</th><th>Signal</th><th>Matched Rules</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in results.stocks" :key="s.stock_id">
              <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
              <td class="muted">{{ s.company_name }}</td>
              <td>{{ s.sector || 'Other' }}</td>
              <td>{{ s.close !== null ? `Rs. ${formatPrice(s.close)}` : '—' }}</td>
              <td :class="changeTone(s.change_pct)">{{ s.change_pct !== null ? `${s.change_pct > 0 ? '+' : ''}${s.change_pct}%` : '—' }}</td>
              <td>
                <span v-if="s.signal" class="badge" :class="s.signal">{{ s.signal.replace('_', ' ') }}</span>
                <span v-else class="muted">No data</span>
              </td>
              <td>
                <span v-for="r in s.matched_rules" :key="r.key" class="rule-tag">{{ r.label }}</span>
              </td>
            </tr>
            <tr v-if="results.stocks.length === 0">
              <td colspan="7" class="muted" style="text-align: center; padding: 24px">
                No stocks match {{ results.mode === 'all' ? 'all' : 'any' }} of the selected rules today.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p v-else-if="hasScanned === false" class="muted" style="margin-top: 16px">
        Select one or more rules above, then click Scan.
      </p>
    </template>
  </div>
</template>

<style scoped>
.rules-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 24px;
}

.group-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}

.group-head h3 {
  margin: 0;
}

.select-all-btn {
  background: none;
  border: none;
  color: #2563eb;
  font-size: 0.8rem;
  cursor: pointer;
  padding: 0;
}

.rule-option {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 0;
  font-size: 0.88rem;
  cursor: pointer;
}

.scan-bar {
  display: flex;
  align-items: center;
  gap: 18px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--border, #e5e7eb);
  flex-wrap: wrap;
}

.mode-option {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.85rem;
  cursor: pointer;
}

.spacer {
  flex: 1;
}

.rule-tag {
  display: inline-block;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 0.72rem;
  padding: 2px 8px;
  border-radius: 999px;
  margin: 2px 4px 2px 0;
}

.positive {
  color: var(--strong-buy);
}

.negative {
  color: var(--strong-sell);
}
</style>
