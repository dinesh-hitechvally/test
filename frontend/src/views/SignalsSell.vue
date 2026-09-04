<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useStocksStore } from '../stores/stocks'
import { formatPrice } from '../utils/format'

const store = useStocksStore()
const loading = ref(true)

const ranked = computed(() =>
  [...store.todaySignals]
    .filter((s) => ['sell', 'strong_sell'].includes(s.latest_signal?.signal))
    .sort((a, b) => Number(a.latest_signal.score) - Number(b.latest_signal.score))
)

onMounted(async () => {
  loading.value = true
  await store.fetchTodaySignals()
  loading.value = false
})
</script>

<template>
  <div>
    <h1>Sell Signals</h1>
    <p class="muted">Every stock currently flagged Sell or Strong Sell, ranked by score — highest conviction first. Not financial advice.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="card">
      <table class="table" v-if="ranked.length">
        <thead><tr><th>Symbol</th><th>Company</th><th>Price</th><th>Signal</th><th>Reasons</th></tr></thead>
        <tbody>
          <tr v-for="s in ranked" :key="s.id">
            <td><RouterLink :to="{ name: 'stock-detail', params: { symbol: s.symbol } }">{{ s.symbol }}</RouterLink></td>
            <td class="muted">{{ s.company_name }}</td>
            <td>Rs. {{ formatPrice(s.latest_price?.close_price) }}</td>
            <td><span class="badge" :class="s.latest_signal.signal">{{ s.latest_signal.signal.replace('_', ' ') }}</span></td>
            <td>
              <ul class="reasons">
                <li v-for="(r, i) in s.latest_signal.reasons" :key="i">{{ r }}</li>
              </ul>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted">No sell-leaning signals right now.</p>
    </div>
  </div>
</template>

<style scoped>
.reasons {
  margin: 0;
  padding-left: 18px;
  font-size: 0.8rem;
  color: var(--text-muted);
}
</style>
