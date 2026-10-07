import { gql } from './graphql'
import { AI_OPINION, DIVIDEND, FUNDAMENTAL, INDICATOR, PRICE, RIGHT_SHARE, SIGNAL, STOCK_BASE, STOCK_ROW } from './fields'

// Which price field each price-based table column needs.
const PRICE_COLUMNS = { last_close: 'close_price', high: 'high_price', low: 'low_price', turnover: 'turnover', volume: 'volume' }

/**
 * The fields a stocks table needs for the columns that are switched on, so the API (which only
 * does the work for fields it is asked for) isn't made to load prices, signals, AI opinions or
 * today's change for columns nobody sees. `columns` = the visible column keys; null = everything.
 */
export function stockSelection(columns = null) {
  if (!columns) return `${STOCK_ROW} ai_opinion { ${AI_OPINION} }`

  const on = (key) => columns.includes(key)
  const priceFields = Object.entries(PRICE_COLUMNS).filter(([key]) => on(key)).map(([, field]) => field)

  return [
    'id symbol', // always: row identity and the link
    on('company_name') && 'company_name',
    on('sector') && 'sector',
    on('change_pct') && 'change_pct',
    on('high_52w') && 'high_52w',
    on('low_52w') && 'low_52w',
    priceFields.length > 0 && `latest_price { ${priceFields.join(' ')} }`,
    on('signal') && 'latest_signal { signal }',
    on('ai_opinion') && 'ai_opinion { verdict reasoning }',
  ].filter(Boolean).join(' ')
}

/** Every stock; with `columns` only the fields those table columns show (see stockSelection). */
export async function list(search = null, columns = null) {
  return (await gql(`query ($search: String) { stocks(search: $search) { ${stockSelection(columns)} } }`,
    { search })).stocks
}

export async function get(symbol) {
  return (await gql(`query ($symbol: String!) { stock(symbol: $symbol) {
    ${STOCK_BASE} latest_price { ${PRICE} } fundamental { ${FUNDAMENTAL} }
    latest_signal { ${SIGNAL} breakdown { buy_pct sell_pct hold_pct hold_type category_scores conditions } }
  } }`, { symbol })).stock
}

export async function create({ symbol, company_name, sector }) {
  return (await gql(`mutation ($symbol: String, $company_name: String, $sector: String) {
    createStock(symbol: $symbol, company_name: $company_name, sector: $sector) { ${STOCK_BASE} }
  }`, { symbol, company_name, sector })).createStock
}

/** The newest `days` daily prices, oldest → newest. */
export async function prices(symbol, days = 365) {
  return (await gql(`query ($symbol: String!, $days: Int) { stockPrices(symbol: $symbol, days: $days) { ${PRICE} } }`,
    { symbol, days })).stockPrices
}

export async function indicators(symbol, days = 365) {
  return (await gql(`query ($symbol: String!, $days: Int) { stockIndicators(symbol: $symbol, days: $days) { ${INDICATOR} } }`,
    { symbol, days })).stockIndicators
}

export async function forecasts(symbol, days = 365) {
  return (await gql(`query ($symbol: String!, $days: Int) { stockForecasts(symbol: $symbol, days: $days) { trade_date next_close } }`,
    { symbol, days })).stockForecasts
}

/** Paged signal history: { data, page, per_page, total, total_pages }. */
export async function signals(symbol, { from = null, to = null, signal = null, page = 1, per_page = 30 } = {}) {
  return (await gql(`query ($symbol: String!, $from: String, $to: String, $signal: [String!], $page: Int, $per_page: Int) {
    stockSignals(symbol: $symbol, from: $from, to: $to, signal: $signal, page: $page, per_page: $per_page) {
      data { ${SIGNAL} forecast_price breakdown { buy_pct sell_pct hold_pct hold_type } } page per_page total total_pages
    }
  }`, { symbol, from, to, signal, page, per_page })).stockSignals
}

/** One day's full Buy / Sell / Hold breakdown (category % and every condition), or null when no signal exists for that date. */
export async function signalDay(symbol, date) {
  const page = (await gql(`query ($symbol: String!, $from: String, $to: String) {
    stockSignals(symbol: $symbol, from: $from, to: $to, page: 1, per_page: 1) { data { ${SIGNAL} breakdown { buy_pct sell_pct hold_pct hold_type category_scores conditions } } }
  }`, { symbol, from: date, to: date })).stockSignals
  return page.data[0] || null
}

export async function mlPrediction(symbol) {
  return (await gql(`query ($symbol: String!) { stockMlPrediction(symbol: $symbol) {
    prediction { direction probability as_of_date }
    model { trained_at horizon_days accuracy baseline_accuracy beats_baseline precision recall test_samples stocks_used }
  } }`, { symbol })).stockMlPrediction
}

export async function aiOpinion(symbol) {
  return (await gql(`query ($symbol: String!) { stockAiOpinion(symbol: $symbol) {
    available message verdict confidence reasoning generated_at
  } }`, { symbol })).stockAiOpinion
}

export async function nextCloseForecast(symbol) {
  return (await gql(`query ($symbol: String!) { stockNextCloseForecast(symbol: $symbol) {
    available message trade_date next_close reasons
  } }`, { symbol })).stockNextCloseForecast
}

export async function dividends(symbol) {
  return (await gql(`query ($symbol: String!) { stockDividends(symbol: $symbol) { ${DIVIDEND} } }`, { symbol })).stockDividends
}

export async function rightShares(symbol) {
  return (await gql(`query ($symbol: String!) { stockRightShares(symbol: $symbol) { ${RIGHT_SHARE} } }`, { symbol })).stockRightShares
}

/** Re-fetch dividend/bonus data from nepalstock.com: { dividends, right_shares, sources }. */
export async function refreshCorporateActions(symbol) {
  return (await gql(`mutation ($symbol: String!) { refreshCorporateActions(symbol: $symbol) { dividends right_shares sources } }`,
    { symbol })).refreshCorporateActions
}

/**
 * Everything the Stock Detail page needs on load, in ONE request instead of the ~10 separate
 * ones each of the calls above would make — GraphQL lets unrelated root fields ride together in
 * a single operation. Signal history is included for page 1 with no filters; later pages/filters
 * still go through `signals()` above.
 */
export async function detail(symbol, days = 1000) {
  return gql(`query ($symbol: String!, $days: Int) {
    stock(symbol: $symbol) { ${STOCK_BASE} latest_price { ${PRICE} } latest_signal { ${SIGNAL} } fundamental { ${FUNDAMENTAL} } }
    stockPrices(symbol: $symbol, days: $days) { ${PRICE} }
    stockIndicators(symbol: $symbol, days: $days) { ${INDICATOR} }
    stockForecasts(symbol: $symbol, days: $days) { trade_date next_close }
    stockMlPrediction(symbol: $symbol) {
      prediction { direction probability as_of_date }
      model { trained_at horizon_days accuracy baseline_accuracy beats_baseline precision recall test_samples stocks_used }
    }
    stockDividends(symbol: $symbol) { ${DIVIDEND} }
    stockRightShares(symbol: $symbol) { ${RIGHT_SHARE} }
    stockAiOpinion(symbol: $symbol) { available message verdict confidence reasoning generated_at }
    stockNextCloseForecast(symbol: $symbol) { available message trade_date next_close reasons }
    stockSignals(symbol: $symbol, page: 1, per_page: 30) { data { ${SIGNAL} forecast_price } page per_page total total_pages }
    nextCloseAccuracy { available sample_size stocks_used mape naive_mape direction_accuracy beats_baseline computed_at }
  }`, { symbol, days })
}
