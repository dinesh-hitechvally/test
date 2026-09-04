<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import client from '../api/client'

const sectors = ref([])
const loading = ref(true)

// Green the more positive, red the more negative — capped so one outlier
// sector doesn't wash out the rest of the scale.
function tileStyle(pct) {
  if (pct === null || pct === undefined) return { background: '#e2e8f0', color: '#334155' }
  const capped = Math.max(-5, Math.min(5, pct))
  const intensity = Math.abs(capped) / 5
  const bg = pct >= 0
    ? `rgba(21, 128, 61, ${0.15 + intensity * 0.7})`
    : `rgba(185, 28, 28, ${0.15 + intensity * 0.7})`
  return { background: bg, color: intensity > 0.45 ? '#fff' : '#0f172a' }
}

async function load() {
  loading.value = true
  const { data } = await client.get('/reports/market')
  sectors.value = [...data.sector_performance].sort((a, b) => (b.avg_change_pct ?? -999) - (a.avg_change_pct ?? -999))
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Sector Overview</h1>
    <p class="muted">Today's average move per sector — tile size roughly reflects how many stocks are in it, color reflects today's average change.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="heatmap">
      <RouterLink
        v-for="s in sectors"
        :key="s.sector"
        :to="{ name: 'reports-sector', query: { name: s.sector } }"
        class="tile"
        :style="tileStyle(s.avg_change_pct)"
      >
        <div class="tile-sector">{{ s.sector }}</div>
        <div class="tile-pct">{{ s.avg_change_pct !== null ? `${s.avg_change_pct > 0 ? '+' : ''}${s.avg_change_pct}%` : '—' }}</div>
        <div class="tile-count">{{ s.stock_count }} stocks</div>
      </RouterLink>
    </div>
  </div>
</template>

<style scoped>
.heatmap {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 10px;
  margin-top: 16px;
}

.tile {
  display: block;
  padding: 14px;
  border-radius: 10px;
  text-decoration: none;
  transition: transform 0.1s;
}

.tile:hover {
  transform: scale(1.03);
}

.tile-sector {
  font-weight: 700;
  font-size: 0.85rem;
  margin-bottom: 6px;
}

.tile-pct {
  font-size: 1.1rem;
  font-weight: 700;
}

.tile-count {
  font-size: 0.72rem;
  opacity: 0.85;
  margin-top: 4px;
}
</style>
