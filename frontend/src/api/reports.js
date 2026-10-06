import { gql } from './graphql'
import {
  DIVIDEND_SUMMARY, MOVER, PRICE, SCRAPE_LOG, SECTOR_PERFORMANCE, SIGNAL, SIGNAL_COUNTS, STOCK_IDENTITY, STOCK_ROW, TREND_POINT,
} from './fields'

const BREADTH = 'advancing declining unchanged'
const MOVERS = `movers { gainers { ${MOVER} } losers { ${MOVER} } }`

export async function dashboard() {
  return (await gql(`{ dashboardReport {
    totals { stocks with_signals } last_scrape { ${SCRAPE_LOG} } signal_counts { ${SIGNAL_COUNTS} } breadth { ${BREADTH} }
    sector_breakdown { ${SECTOR_PERFORMANCE} } ${MOVERS}
  } }`)).dashboardReport
}

export async function market() {
  return (await gql(`{ marketReport {
    totals { stocks with_signals } signal_counts { ${SIGNAL_COUNTS} } breadth { ${BREADTH} } ${MOVERS}
    sector_performance { ${SECTOR_PERFORMANCE} } trend { ${TREND_POINT} }
  } }`)).marketReport
}

// Which API fields each Sector List table column needs (some columns are worked out from others).
const SECTOR_COLUMN_FIELDS = {
  stock_count: ['stock_count'],
  advancing: ['advancing'],
  declining: ['declining'],
  unchanged: ['stock_count', 'advancing', 'declining'],
  advance_pct: ['stock_count', 'advancing'],
  avg_change_pct: ['avg_change_pct'],
  total_turnover: ['total_turnover'],
}

/**
 * Today's move per sector, asking the API only for the fields the visible table columns need.
 * `columns` = visible column keys; null = every field.
 */
export async function sectorPerformance(columns = null) {
  const fields = columns === null
    ? SECTOR_PERFORMANCE
    : ['sector_id', 'sector', ...new Set(columns.flatMap((c) => SECTOR_COLUMN_FIELDS[c] ?? []))].join(' ')

  return (await gql(`{ sectorPerformance { ${fields} } }`)).sectorPerformance
}

export async function sectors() {
  return (await gql('{ sectors { sector stock_count } }')).sectors
}

/** One sector by its id (the /sectors/{id} page): totals, signal mix, movers, trend and its stocks. */
export async function sectorDetail(id) {
  return (await gql(`query ($id: Int!) { sectorDetail(id: $id) {
    sector_id sector totals { stock_count advancing declining } signal_counts { ${SIGNAL_COUNTS} } avg_change_pct
    stocks { ${STOCK_ROW} } top_gainers { ${STOCK_ROW} } top_losers { ${STOCK_ROW} } trend { ${TREND_POINT} }
  } }`, { id })).sectorDetail
}

export async function sector(name) {
  return (await gql(`query ($name: String!) { sectorReport(name: $name) {
    sector totals { stock_count advancing declining } signal_counts { ${SIGNAL_COUNTS} } avg_change_pct
    stocks { ${STOCK_ROW} } top_gainers { ${STOCK_ROW} } top_losers { ${STOCK_ROW} } trend { ${TREND_POINT} }
  } }`, { name })).sectorReport
}

export async function stock(symbol) {
  return (await gql(`query ($symbol: String!) { stockReport(symbol: $symbol) {
    stock { ${STOCK_IDENTITY} } latest_price { ${PRICE} } latest_signal { ${SIGNAL} } change_pct returns signal_counts_90d
  } }`, { symbol })).stockReport
}

/** { stock, report } — report is the full technical-analysis document. */
export async function technical(symbol) {
  return (await gql(`query ($symbol: String!) { technicalReport(symbol: $symbol) { stock { ${STOCK_IDENTITY} } report } }`,
    { symbol })).technicalReport
}

export async function analyst(symbol) {
  return (await gql(`query ($symbol: String!) { analystReport(symbol: $symbol) {
    stock { ${STOCK_IDENTITY} } latest_price { ${PRICE} } change_pct returns technical dividend { ${DIVIDEND_SUMMARY} }
    signal { signal score reasons trade_date accuracy { sample_size win_rate baseline_win_rate horizon_days } }
    ml_prediction { direction probability as_of_date horizon_days model_accuracy model_baseline_accuracy beats_baseline }
  } }`, { symbol })).analystReport
}

export async function dividends(sector = null) {
  return (await gql(`query ($sector: String) { dividendReport(sector: $sector) {
    totals { stocks_with_dividends avg_yield_pct top_yield_pct stocks_with_right_shares }
    top_picks { ${DIVIDEND_SUMMARY} } stocks { ${DIVIDEND_SUMMARY} }
  } }`, { sector })).dividendReport
}

export async function nextCloseAccuracy() {
  return (await gql(`{ nextCloseAccuracy {
    available sample_size stocks_used mape naive_mape direction_accuracy beats_baseline computed_at
  } }`)).nextCloseAccuracy
}

const CANDIDATE = 'stock_id symbol company_name sector share_group close reasons'

// The investment-horizon candidate lists; each resolves to the list itself.
export const horizons = {
  async long(sector = null) {
    return (await gql(`query ($sector: String) { longTermCandidates(sector: $sector) {
      ${CANDIDATE} dividend_years_recorded avg_total_dividend_pct right_share_count return_3y_pct volatility_pct avg_turnover
      latest_signal long_term_score
    } }`, { sector })).longTermCandidates
  },
  async mid(sector = null) {
    return (await gql(`query ($sector: String) { midTermCandidates(sector: $sector) {
      ${CANDIDATE} change_pct turnover sma_50 sma_200 rsi_14 return_6m_pct volatility_pct mid_term_score mid_term_signal
    } }`, { sector })).midTermCandidates
  },
  async short(sector = null) {
    return (await gql(`query ($sector: String) { shortTermCandidates(sector: $sector) {
      ${CANDIDATE} change_pct turnover sma_20 sma_50 rsi_14 macd_histogram bb_upper bb_lower short_term_score short_term_signal
    } }`, { sector })).shortTermCandidates
  },
}

export async function signalRules() {
  return (await gql('{ signalRules { key label direction } }')).signalRules
}

/** Stocks whose latest signal fired any (mode 'any') or all (mode 'all') of the rules. */
export async function ruleScan(rules, mode = null) {
  return (await gql(`query ($rules: [String!]!, $mode: String) { ruleScan(rules: $rules, mode: $mode) {
    mode requested_rules matched_count
    stocks { stock_id symbol company_name sector close change_pct signal trade_date matched_rules { key label } }
  } }`, { rules, mode })).ruleScan
}
