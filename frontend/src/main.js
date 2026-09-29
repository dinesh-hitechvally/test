import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import alignNumbers from './directives/alignNumbers'
import ui from './components/ui'

const app = createApp(App)

app.use(ui)
app.directive('align-numbers', alignNumbers)
app.use(createPinia())
app.use(router)
app.mount('#app')
