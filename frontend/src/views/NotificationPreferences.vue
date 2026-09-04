<script setup>
import { ref } from 'vue'
import { getNotificationPrefs, setNotificationPrefs } from '../utils/notificationPrefs'

const prefs = ref(getNotificationPrefs())
const saved = ref(false)

function save() {
  setNotificationPrefs(prefs.value)
  saved.value = true
  setTimeout(() => (saved.value = false), 2000)
}
</script>

<template>
  <div>
    <h1>Notification Preferences</h1>
    <p class="muted">
      The only notifications in this app today are the in-app topbar bell (stop-loss/target hits on your portfolio
      holdings) — there's no email or push yet. These preferences are saved on this browser only.
    </p>

    <div class="card form-stack" style="margin-top: 16px; max-width: 420px">
      <label class="toggle-row">
        <input type="checkbox" v-model="prefs.showStopLossAlerts" />
        Show stop-loss-hit alerts
      </label>
      <label class="toggle-row">
        <input type="checkbox" v-model="prefs.showTargetAlerts" />
        Show target-hit alerts
      </label>
      <label class="field-row">
        Check for new alerts every
        <select v-model.number="prefs.pollIntervalSeconds" class="input" style="width: auto">
          <option :value="30">30 seconds</option>
          <option :value="60">1 minute</option>
          <option :value="90">90 seconds</option>
          <option :value="300">5 minutes</option>
        </select>
      </label>
      <button class="btn" @click="save" style="align-self: flex-start">Save</button>
      <p v-if="saved" class="muted">Saved — takes effect next page load.</p>
    </div>
  </div>
</template>

<style scoped>
.toggle-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9rem;
}

.field-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.9rem;
}
</style>
