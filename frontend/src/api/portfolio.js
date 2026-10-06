import { gql, num } from './graphql'
import { PORTFOLIO, PORTFOLIO_SUMMARY, SIGNAL, TRANSACTION } from './fields'

/** The user's portfolios, each with its summary. */
export async function list() {
  return (await gql(`{ portfolios { ${PORTFOLIO} summary { ${PORTFOLIO_SUMMARY} } } }`)).portfolios
}

export async function create(name) {
  return (await gql(`mutation ($name: String) { createPortfolio(name: $name) { ${PORTFOLIO} } }`, { name })).createPortfolio
}

/** { portfolio, summary, holdings, realized } */
export async function get(id) {
  return (await gql(`query ($id: Int!) { portfolio(id: $id) {
    portfolio { ${PORTFOLIO} }
    summary { ${PORTFOLIO_SUMMARY} }
    holdings {
      stock_id symbol company_name sector quantity avg_cost invested current_price current_value unrealized_pnl
      unrealized_pnl_pct latest_signal { ${SIGNAL} } stop_loss target_price target_notes position_status pct_to_stop
      pct_to_target bonus_shares_received
    }
    realized { stock_id symbol transaction_id transaction_date quantity sell_price avg_cost_at_time realized_pnl }
  } }`, { id })).portfolio
}

/** Newest first. */
export async function transactions(portfolioId) {
  return (await gql(`query ($portfolio_id: Int!) { portfolioTransactions(portfolio_id: $portfolio_id) { ${TRANSACTION} } }`,
    { portfolio_id: portfolioId })).portfolioTransactions
}

export async function addTransaction(portfolioId, { stock_id, type, quantity, price, fees, transaction_date, notes }) {
  return (await gql(`mutation ($portfolio_id: Int!, $stock_id: Int, $type: String, $quantity: Float, $price: Float, $fees: Float,
      $transaction_date: String, $notes: String) {
    addTransaction(portfolio_id: $portfolio_id, stock_id: $stock_id, type: $type, quantity: $quantity, price: $price, fees: $fees,
      transaction_date: $transaction_date, notes: $notes) { ${TRANSACTION} }
  }`, {
    portfolio_id: portfolioId,
    stock_id: num(stock_id),
    type,
    quantity: num(quantity),
    price: num(price),
    fees: num(fees),
    transaction_date,
    notes,
  })).addTransaction
}

export async function deleteTransaction(portfolioId, transactionId) {
  return (await gql('mutation ($portfolio_id: Int!, $transaction_id: Int!) { deleteTransaction(portfolio_id: $portfolio_id, transaction_id: $transaction_id) }',
    { portfolio_id: portfolioId, transaction_id: transactionId })).deleteTransaction
}

/** Set (or clear, with nulls) the stop-loss / target watched for one holding. */
export async function setPositionTarget(portfolioId, stockId, { stop_loss, target_price, notes }) {
  return (await gql(`mutation ($portfolio_id: Int!, $stock_id: Int!, $stop_loss: Float, $target_price: Float, $notes: String) {
    setPositionTarget(portfolio_id: $portfolio_id, stock_id: $stock_id, stop_loss: $stop_loss, target_price: $target_price, notes: $notes) {
      id portfolio_id stock_id stop_loss target_price notes created_at updated_at
    }
  }`, { portfolio_id: portfolioId, stock_id: stockId, stop_loss: num(stop_loss), target_price: num(target_price), notes: notes || null }))
    .setPositionTarget
}

/** { history: [{ date, value, invested }], metrics } */
export async function performance(portfolioId) {
  return (await gql(`query ($portfolio_id: Int!) { portfolioPerformance(portfolio_id: $portfolio_id) {
    history { date value invested }
    metrics {
      total_invested current_value total_pnl total_pnl_pct best_day { date change } worst_day { date change }
      all_time_high { date value } xirr_pct
    }
  } }`, { portfolio_id: portfolioId })).portfolioPerformance
}

const BUY_ORDER = `id portfolio_id stock_id status trade_date signal_score quantity entry_price stop_loss target_price risk_per_share
  risk_amount position_value fees risk_reward stock { id symbol company_name }`

/** Sell / hold for every holding, with each rule that fired: [{ stock_id, action, primary_reason, reasons, trailing_stop, … }]. */
export async function sellChecks(portfolioId) {
  return (await gql(`query ($portfolio_id: Int!) { sellChecks(portfolio_id: $portfolio_id) {
    stock_id symbol quantity avg_cost current_price action primary_reason reasons { rule detail } note
    stop_loss trailing_stop effective_stop target_price highest_close
  } }`, { portfolio_id: portfolioId })).sellChecks
}

/** What a stock's BUY signal would become for this portfolio, or why not. Nothing is stored. */
export async function buyOrderPreview(portfolioId, symbol) {
  return (await gql(`query ($portfolio_id: Int!, $symbol: String!) { buyOrderPreview(portfolio_id: $portfolio_id, symbol: $symbol) {
    approved reason checks { name passed detail }
    plan { stock_id quantity entry_price stop_loss target_price risk_per_share risk_amount position_value fees risk_reward }
  } }`, { portfolio_id: portfolioId, symbol })).buyOrderPreview
}

export async function buyOrders(portfolioId, status = null) {
  return (await gql(`query ($portfolio_id: Int!, $status: String) { buyOrders(portfolio_id: $portfolio_id, status: $status) { ${BUY_ORDER} } }`,
    { portfolio_id: portfolioId, status })).buyOrders
}

/** Rejects (422) with the first failed check's reason. */
export async function placeBuyOrder(portfolioId, symbol) {
  return (await gql(`mutation ($portfolio_id: Int!, $symbol: String!) { placeBuyOrder(portfolio_id: $portfolio_id, symbol: $symbol) { ${BUY_ORDER} } }`,
    { portfolio_id: portfolioId, symbol })).placeBuyOrder
}

export async function cancelBuyOrder(portfolioId, orderId) {
  return (await gql(`mutation ($portfolio_id: Int!, $order_id: Int!) { cancelBuyOrder(portfolio_id: $portfolio_id, order_id: $order_id) { ${BUY_ORDER} } }`,
    { portfolio_id: portfolioId, order_id: orderId })).cancelBuyOrder
}

/** Cash available for buying; pending orders reserve part of it. */
export async function setCash(portfolioId, cashBalance) {
  return (await gql(`mutation ($portfolio_id: Int!, $cash_balance: Float) { setPortfolioCash(portfolio_id: $portfolio_id, cash_balance: $cash_balance) { ${PORTFOLIO} } }`,
    { portfolio_id: portfolioId, cash_balance: num(cashBalance) })).setPortfolioCash
}
