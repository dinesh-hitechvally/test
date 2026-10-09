<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import * as authApi from '../api/auth'

const LABELS = {
  technical: ['Technical', 'RSI, Bollinger, MACD, stochastic, MA20/50, golden/death cross'],
  fundamental: ['Fundamental', 'Profitable? Return on equity (latest day only)'],
  trend: ['Trend', 'Price vs MA20/50/200, MA50 slope, ADX / DI'],
  momentum: ['Momentum', 'MACD histogram, RSI direction, 10-day change'],
  volume: ['Volume', "Participation behind the day's move"],
  risk: ['Risk', 'Volatility (ATR), room to resistance vs support'],
  valuation: ['Valuation', 'P/E and price-to-book (latest day only)'],
}

const loading = ref(true)
const loadError = ref('')
const defaults = ref(null)
const isCustom = ref(false)

const weights = ref({})
const minPct = ref(50)
const margin = ref(0)
const guard = ref(true)

const saving = ref(false)
const message = ref('')
const error = ref('')
let timer = null

const total = computed(() => Object.values(weights.value).reduce((sum, w) => sum + (Number(w) || 0), 0))
const totalOk = computed(() => total.value === 100)

function apply(s) {
  weights.value = Object.fromEntries(s.weights.map((w) => [w.category, w.weight]))
  minPct.value = s.min_pct
  margin.value = s.margin
  guard.value = s.guard_extremes
  isCustom.value = s.is_custom
  defaults.value = s.defaults
}

function flash(target, text) {
  message.value = ''
  error.value = ''
  target.value = text
  clearTimeout(timer)
  timer = setTimeout(() => (target.value = ''), 6000)
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    apply(await authApi.signalSettings())
  } catch (e) {
    loadError.value = e.response?.data?.message || 'Could not load your signal settings.'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    apply(
      await authApi.updateSignalSettings({
        weights: Object.fromEntries(Object.keys(LABELS).map((k) => [k, Math.round(Number(weights.value[k]) || 0)])),
        min_pct: Math.round(Number(minPct.value)),
        margin: Math.round(Number(margin.value)),
        guard_extremes: guard.value,
      }),
    )
    flash(message, 'Saved. The Signals page now decides BUY / SELL / HOLD with your settings.')
  } catch (e) {
    const errors = e.response?.data?.errors || {}
    flash(error, Object.values(errors).flat()[0] || e.response?.data?.message || 'Could not save your settings.')
  } finally {
    saving.value = false
  }
}

async function reset() {
  saving.value = true
  try {
    apply(await authApi.resetSignalSettings())
    flash(message, 'Back to the system defaults.')
  } catch (e) {
    flash(error, e.response?.data?.message || 'Could not reset your settings.')
  } finally {
    saving.value = false
  }
}

function useDefaults() {
  if (defaults.value) apply({ ...defaults.value, is_custom: isCustom.value, defaults: defaults.value })
}

onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <div>
    <LoadingState v-if="loading" style="margin-top: 16px" />

    <Card v-else-if="loadError" style="margin-top: 16px">
      <p class="error-text">{{ loadError }}</p>
      <button class="btn" type="button" @click="load">Try again</button>
    </Card>

    <form v-else class="grid-form" @submit.prevent="save">
      <Card>
        <h3>How a signal is decided</h3>
        <p class="muted">
          Every stock's conditions are averaged into seven categories. Your weights decide how much each category counts
          towards the final BUY / SELL / HOLD %. Your settings change only <strong>your</strong> Signals page; other users, the
          dashboard, reports and backtests keep using the system defaults.
        </p>
        <p v-if="isCustom" class="muted">You are using your own settings.</p>
        <p v-else class="muted">You have not changed anything yet, so the system defaults apply.</p>

        <table v-align-numbers class="table">
          <thead>
            <tr>
              <th>Category</th>
              <th>What it looks at</th>
              <th>Weight</th>
              <th>System default</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(info, key) in LABELS" :key="key">
              <td>
                <label :for="`w-${key}`">{{ info[0] }}</label>
              </td>
              <td class="muted">{{ info[1] }}</td>
              <td>
                <input :id="`w-${key}`" v-model.number="weights[key]" type="number" min="0" max="100" step="1" class="input weight" />
              </td>
              <td class="muted">{{ defaults?.weights.find((w) => w.category === key)?.weight }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2"><strong>Total</strong> (must be 100)</td>
              <td :class="totalOk ? 'ok-text' : 'error-text'">
                <strong>{{ total }}</strong>
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        <p class="muted small-note">A category with no data that day (fundamental and valuation only exist for the latest day) is left out and the rest share its weight.</p>
      </Card>

      <Card>
        <h3>When to act</h3>
        <div class="field">
          <label for="min-pct">Minimum % to win</label>
          <input id="min-pct" v-model.number="minPct" type="number" min="40" max="90" step="1" class="input weight" />
          <p class="muted small-note">
            A side needs at least this final % to be BUY or SELL; otherwise it is HOLD. Higher = fewer, stronger signals (system default
            {{ defaults?.min_pct }}).
          </p>
        </div>
        <div class="field">
          <label for="margin">Lead margin (points)</label>
          <input id="margin" v-model.number="margin" type="number" min="0" max="50" step="1" class="input weight" />
          <p class="muted small-note">
            ...and the winning side must also lead the other side by this many points. 0 = no extra requirement (system default
            {{ defaults?.margin }}).
          </p>
        </div>
        <div class="field">
          <label class="check">
            <input v-model="guard" type="checkbox" />
            Do not act against an extreme reading
          </label>
          <p class="muted small-note">
            When on, a SELL while the price is oversold (RSI under 30, or on/below the lower Bollinger band) and a BUY while it is
            overbought (RSI over 70, or on/above the upper band) become a HOLD with the reason shown — so you are not selling the low or
            buying the high.
          </p>
        </div>
      </Card>

      <p v-if="message" class="ok-text" role="status">{{ message }}</p>
      <p v-if="error" class="error-text" role="alert">{{ error }}</p>

      <div class="actions">
        <button class="btn" type="submit" :disabled="saving || !totalOk">{{ saving ? 'Saving…' : 'Save settings' }}</button>
        <button class="btn btn-secondary" type="button" :disabled="saving" @click="useDefaults">Fill in the defaults</button>
        <button v-if="isCustom" class="btn btn-secondary" type="button" :disabled="saving" @click="reset">Reset to system defaults</button>
        <span v-if="!totalOk" class="error-text">The weights add up to {{ total }}, not 100.</span>
      </div>
    </form>
  </div>
</template>

<style scoped>
.grid-form {
  display: grid;
  gap: 16px;
  margin-top: 16px;
}

.weight {
  width: 90px;
}

.field {
  margin-bottom: 14px;
}

.field > label {
  display: block;
  font-weight: 600;
  margin-bottom: 4px;
}

.check {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
}

.small-note {
  font-size: 0.85rem;
  margin: 4px 0 0;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.ok-text {
  color: #15803d;
}

.error-text {
  color: #b91c1c;
}
</style>
