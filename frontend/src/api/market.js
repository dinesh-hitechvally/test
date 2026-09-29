import { gql } from './graphql'
import { INDICATOR, SCRAPE_LOG, SIGNAL, STOCK_BASE, PRICE, STOCK_ROW, TRADE_SETUP } from './fields'

/** Every stock's latest signal (optionally one signal type), highest score first. */
export async function todaySignals(signal = null) {
  return (await gql(`query ($signal: String) { todaySignals(signal: $signal) {
    ${STOCK_BASE} latest_signal { ${SIGNAL} } latest_price { ${PRICE} }
  } }`, { signal })).todaySignals
}

/** Buy (or sell) signals with a trade setup. */
export async function actionableSignals(bias = 'buy') {
  return (await gql(`query ($bias: String) { actionableSignals(bias: $bias) {
    stock_id symbol company_name sector close signal score reasons trade_setup { ${TRADE_SETUP} }
  } }`, { bias })).actionableSignals
}

export async function signalAccuracy() {
  return (await gql(`{ signalAccuracy {
    available computed_at horizon_days disclaimer
    stats { id signal_type horizon_days sample_size win_rate avg_forward_return_pct baseline_win_rate computed_at created_at updated_at }
  } }`)).signalAccuracy
}

/** Holdings past their stop-loss/target and watched stocks past their alert price. */
export async function priceAlerts() {
  return (await gql(`{ priceAlerts {
    kind portfolio_id portfolio_name watchlist_id watchlist_name stock_id symbol company_name current_price
    stop_loss target_price status alert_price alert_direction
  } }`)).priceAlerts
}

export async function indices(days = 90) {
  return (await gql(`query ($days: Int) { indices(days: $days) {
    index_name
    latest { id index_name trade_date close high low previous_close change change_pct fifty_two_week_high fifty_two_week_low created_at updated_at }
    history { trade_date close }
  } }`, { days })).indices
}

export async function candlestickPatterns() {
  return (await gql('{ candlestickPatterns { stock_id symbol company_name trade_date pattern signal } }')).candlestickPatterns
}

/** Every stock with its latest price, signal and indicators. */
export async function screener() {
  return (await gql(`{ screener { ${STOCK_ROW} latest_indicator { ${INDICATOR} } } }`)).screener
}

export async function fiftyTwoWeek() {
  return (await gql(`{ fiftyTwoWeek {
    stock_id symbol company_name sector current_price high_52w low_52w pct_from_high pct_from_low
  } }`)).fiftyTwoWeek
}

export async function scrapeLogs(limit = 20) {
  return (await gql(`query ($limit: Int) { scrapeLogs(limit: $limit) { ${SCRAPE_LOG} } }`, { limit })).scrapeLogs
}

/** Whether CRON_SECRET is set, and the stocks flagged with a failed fetch. */
export async function dataSourceStatus() {
  return (await gql(`{ dataSourceStatus {
    secret_configured flagged_stocks { id symbol company_name scrape_error_source scrape_error scrape_error_at }
  } }`)).dataSourceStatus
}
