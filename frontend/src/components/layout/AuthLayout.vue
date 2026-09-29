<script setup>
import NavIcon from './NavIcon.vue'

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
})

const FEATURES = [
  'Buy/sell signals with real, backtested accuracy',
  'Portfolio performance, P/L and tax reports',
  'Sector, dividend, and long-term screeners',
]
</script>

<template>
  <div class="auth-shell">
    <aside class="auth-visual">
      <div class="auth-visual-blobs" aria-hidden="true">
        <span class="blob blob-1"></span>
        <span class="blob blob-2"></span>
      </div>
      <div class="auth-visual-content">
        <div class="brand">
          <span class="brand-mark"><NavIcon name="pulse" /></span>
          <span class="brand-name">Share Market Signals</span>
        </div>
        <div>
          <h2>Trade smarter on NEPSE.</h2>
          <p>Real-time signals, portfolio tracking, and market reports — built for Nepali investors.</p>
        </div>
        <ul class="feature-list">
          <li v-for="f in FEATURES" :key="f">
            <span class="check">✓</span>
            <span>{{ f }}</span>
          </li>
        </ul>
      </div>
    </aside>

    <div class="auth-form-side">
      <div class="auth-form-wrap">
        <div class="mobile-brand">
          <span class="brand-mark small"><NavIcon name="pulse" /></span>
          <span class="brand-name">Share Market Signals</span>
        </div>
        <h1>{{ title }}</h1>
        <p v-if="subtitle" class="muted">{{ subtitle }}</p>
        <slot />
      </div>
    </div>
  </div>
</template>

<style scoped>
.auth-shell {
  min-height: 100vh;
  display: flex;
  background: var(--bg);
}

.auth-visual {
  position: relative;
  flex: 1 1 46%;
  max-width: 560px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 48px 44px;
  color: #fff;
  background: linear-gradient(155deg, #1d4ed8 0%, #1e3a8a 55%, #0f172a 100%);
  overflow: hidden;
}

.auth-visual-blobs {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.blob {
  position: absolute;
  border-radius: 50%;
  background: radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.16), rgba(255, 255, 255, 0) 70%);
}

.blob-1 {
  width: 420px;
  height: 420px;
  top: -140px;
  right: -140px;
}

.blob-2 {
  width: 320px;
  height: 320px;
  bottom: -100px;
  left: -80px;
  background: radial-gradient(circle at 30% 30%, rgba(37, 99, 235, 0.35), rgba(37, 99, 235, 0) 70%);
}

.auth-visual-content {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  gap: 40px;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
  font-size: 1.05rem;
}

.brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: var(--radius);
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.auth-visual-content h2 {
  color: #fff;
  font-size: 1.9rem;
  line-height: 1.25;
  margin-bottom: 10px;
}

.auth-visual-content p {
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.95rem;
  line-height: 1.6;
  max-width: 380px;
}

.feature-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.feature-list li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  font-size: 0.9rem;
  color: rgba(255, 255, 255, 0.88);
}

.check {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.16);
  font-size: 0.7rem;
  font-weight: 700;
  margin-top: 1px;
}

.auth-form-side {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 24px;
}

.auth-form-wrap {
  width: 100%;
  max-width: 380px;
}

.mobile-brand {
  display: none;
  align-items: center;
  gap: 8px;
  font-weight: 700;
  color: var(--text);
  margin-bottom: 28px;
}

.brand-mark.small {
  width: 28px;
  height: 28px;
  color: #fff;
  background: var(--primary);
  border: none;
}

.auth-form-wrap h1 {
  font-size: 1.6rem;
  margin-bottom: 6px;
}

.auth-form-wrap > .muted {
  margin-bottom: 24px;
}

@media (max-width: 860px) {
  .auth-visual {
    display: none;
  }

  .mobile-brand {
    display: flex;
  }
}
</style>
