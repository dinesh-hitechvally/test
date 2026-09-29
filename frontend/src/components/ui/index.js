// The shared UI building blocks, registered globally (main.js) so any page
// can use them without importing: <PageHeader>, <Card>, <SortableTh>, …
import Card from './Card.vue'
import EmptyState from './EmptyState.vue'
import LoadingState from './LoadingState.vue'
import PageHeader from './PageHeader.vue'
import Pagination from './Pagination.vue'
import SearchableSelect from './SearchableSelect.vue'
import SignalBadge from './SignalBadge.vue'
import SortableTh from './SortableTh.vue'
import StatCard from './StatCard.vue'
import StockLink from './StockLink.vue'

const components = { Card, EmptyState, LoadingState, PageHeader, Pagination, SearchableSelect, SignalBadge, SortableTh, StatCard, StockLink }

export default {
  install(app) {
    for (const [name, component] of Object.entries(components)) {
      app.component(name, component)
    }
  },
}
