// The shared UI building blocks, registered globally (main.js) so any page
// can use them without importing: <Card>, <SortableTh>, …
import Card from './Card.vue'
import EmptyState from './EmptyState.vue'
import LoadingState from './LoadingState.vue'
import Pagination from './Pagination.vue'
import ScreenOptions from './ScreenOptions.vue'
import SearchableSelect from './SearchableSelect.vue'
import SellBadge from './SellBadge.vue'
import SignalBadge from './SignalBadge.vue'
import SortableTh from './SortableTh.vue'
import StatCard from './StatCard.vue'
import StockLink from './StockLink.vue'

const components = { Card, EmptyState, LoadingState, Pagination, ScreenOptions, SearchableSelect, SellBadge, SignalBadge, SortableTh, StatCard, StockLink }

export default {
  install(app) {
    for (const [name, component] of Object.entries(components)) {
      app.component(name, component)
    }
  },
}
