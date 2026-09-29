import { gql, num } from './graphql'
import { SAVED_SCREEN, STOCK_ROW, WATCHLIST } from './fields'

const WATCHLIST_STOCKS = `stocks { ${STOCK_ROW} pivot { watchlist_id stock_id id alert_price alert_direction } }`

/** The user's watchlists, each stock with its latest price, signal, today's move and alert. */
export async function list() {
  return (await gql(`{ watchlists { ${WATCHLIST} ${WATCHLIST_STOCKS} } }`)).watchlists
}

export async function create(name) {
  return (await gql(`mutation ($name: String) { createWatchlist(name: $name) { ${WATCHLIST} } }`, { name })).createWatchlist
}

export async function addStock(watchlistId, stockId) {
  return (await gql(`mutation ($watchlist_id: Int!, $stock_id: Int) {
    addWatchlistStock(watchlist_id: $watchlist_id, stock_id: $stock_id) { ${WATCHLIST} ${WATCHLIST_STOCKS} }
  }`, { watchlist_id: watchlistId, stock_id: num(stockId) })).addWatchlistStock
}

export async function removeStock(watchlistId, stockId) {
  return (await gql('mutation ($watchlist_id: Int!, $stock_id: Int!) { removeWatchlistStock(watchlist_id: $watchlist_id, stock_id: $stock_id) }',
    { watchlist_id: watchlistId, stock_id: stockId })).removeWatchlistStock
}

/** Set (or clear, with nulls) a price alert; direction 'above' | 'below'. */
export async function setAlert(watchlistId, stockId, { alert_price, alert_direction }) {
  return (await gql(`mutation ($watchlist_id: Int!, $stock_id: Int!, $alert_price: Float, $alert_direction: String) {
    setWatchlistAlert(watchlist_id: $watchlist_id, stock_id: $stock_id, alert_price: $alert_price, alert_direction: $alert_direction)
  }`, { watchlist_id: watchlistId, stock_id: stockId, alert_price: num(alert_price), alert_direction: alert_direction || null }))
    .setWatchlistAlert
}

export async function savedScreens() {
  return (await gql(`{ savedScreens { ${SAVED_SCREEN} } }`)).savedScreens
}

export async function createSavedScreen({ name, filters }) {
  return (await gql(`mutation ($name: String, $filters: JSON) { createSavedScreen(name: $name, filters: $filters) { ${SAVED_SCREEN} } }`,
    { name, filters })).createSavedScreen
}

export async function deleteSavedScreen(id) {
  return (await gql('mutation ($id: Int!) { deleteSavedScreen(id: $id) }', { id })).deleteSavedScreen
}
