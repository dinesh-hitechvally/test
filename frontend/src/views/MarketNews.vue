<script setup>
import { onMounted, ref } from 'vue'
import client from '../api/client'

const articles = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  const { data } = await client.get('/news')
  articles.value = data
  loading.value = false
}

onMounted(load)
</script>

<template>
  <div>
    <h1>Market News</h1>
    <p class="muted">Latest headlines scraped from ShareSansar, newest first. Links open the original article.</p>

    <p v-if="loading" class="muted">Loading…</p>

    <div v-else class="card">
      <ul class="news-list" v-if="articles.length">
        <li v-for="a in articles" :key="a.id">
          <a :href="a.url" target="_blank" rel="noopener">{{ a.title }}</a>
          <span class="muted date">{{ a.published_date || '' }}</span>
        </li>
      </ul>
      <p v-else class="muted">No news scraped yet.</p>
    </div>
  </div>
</template>

<style scoped>
.news-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.news-list li {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid var(--border);
}

.news-list li:last-child {
  border-bottom: none;
}

.news-list a {
  flex: 1;
}

.date {
  font-size: 0.78rem;
  white-space: nowrap;
}
</style>
