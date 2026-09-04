<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { learnTopics } from '../content/learnTopics'

const route = useRoute()
const topic = computed(() => learnTopics[route.meta.topic])
</script>

<template>
  <div v-if="topic">
    <h1>{{ topic.title }}</h1>
    <div class="card section-card" v-for="s in topic.sections" :key="s.heading">
      <h3>{{ s.heading }}</h3>
      <p>{{ s.body }}</p>
    </div>
  </div>
</template>

<style scoped>
.section-card {
  margin-top: 16px;
  max-width: 720px;
}

.section-card h3 {
  margin-top: 0;
}

.section-card p {
  margin-bottom: 0;
  line-height: 1.6;
  color: var(--text);
}
</style>
