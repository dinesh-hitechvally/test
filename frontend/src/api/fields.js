// Field selections shared by the queries in this folder, so every page gets
// the same fields the API used to return. Plain strings (not GraphQL
// fragments) so they can be nested freely without duplicate definitions.

export const USER = 'id name email email_verified_at created_at updated_at'

export const PRICE = 'id stock_id trade_date open_price high_price low_price close_price volume turnover created_at updated_at'

export const SIGNAL = 'id stock_id trade_date signal score reasons rule_keys price_at_signal created_at updated_at'

export const INDICATOR = `id stock_id trade_date sma_20 sma_50 sma_100 sma_200 ema_12 ema_26 rsi_14 macd macd_signal
  macd_histogram bb_upper bb_middle bb_lower bb_percent_b stoch_k stoch_d atr_14 created_at updated_at`

export const FUNDAMENTAL = `id stock_id eps eps_fiscal_year pe_ratio book_value pbv market_cap shares_outstanding
  one_year_yield_pct fetched_at created_at updated_at`

export const AI_OPINION = 'id stock_id verdict confidence reasoning generated_at error error_at created_at updated_at'

// A stock row as lists return it: its own columns plus sector name, today's
// move and the latest price/signal.
export const STOCK_BASE = 'id symbol nepse_security_id sector_id company_name share_group is_active created_at updated_at sector'

export const STOCK_ROW = `${STOCK_BASE} change_pct latest_price { ${PRICE} } latest_signal { ${SIGNAL} }`

export const DIVIDEND = `id stock_id fiscal_year bonus_share_pct cash_dividend_pct total_dividend_pct announcement_date
  distribution_date book_closure_date bonus_listing_date created_at updated_at`

export const RIGHT_SHARE = `id stock_id ratio total_units issue_price opening_date closing_date book_closure_date listing_date
  issue_manager status created_at updated_at`

export const TRADE_SETUP = 'bias entry_reference target stop_loss risk_per_share reward_per_share risk_reward_ratio attractive'

export const SCRAPE_LOG = 'id source status records_processed message created_at'

export const SIGNAL_COUNTS = 'strong_buy buy hold sell strong_sell'

export const MOVER = 'stock_id close previous_close change_pct turnover symbol company_name'

export const SECTOR_PERFORMANCE = 'sector_id sector stock_count advancing declining avg_change_pct total_turnover'

export const TREND_POINT = 'trade_date advancing declining total_turnover'

export const STOCK_IDENTITY = 'symbol company_name sector'

export const DIVIDEND_SUMMARY = `stock_id symbol company_name sector close latest_fiscal_year latest_cash_pct latest_bonus_pct
  latest_total_pct dividend_yield_pct actual_total_yield_pct years_recorded avg_total_dividend_pct latest_signal
  history { ${DIVIDEND} } right_share_count latest_right_share_ratio latest_right_share_pct latest_right_share_year
  right_share_history { id year ratio pct issue_price opening_date closing_date listing_date status } pick_score reasons`

export const PORTFOLIO = 'id user_id name cash_balance created_at updated_at'

export const PORTFOLIO_SUMMARY = 'total_invested current_value unrealized_pnl unrealized_pnl_pct realized_pnl total_pnl holdings_count'

export const TRANSACTION = `id portfolio_id stock_id type quantity price fees transaction_date notes created_at updated_at
  stock { id symbol company_name sector }`

export const WATCHLIST = 'id user_id name created_at updated_at'

export const SAVED_SCREEN = 'id user_id name filters created_at updated_at'
