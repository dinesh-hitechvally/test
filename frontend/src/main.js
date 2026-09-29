import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'
import alignNumbers from './directives/alignNumbers'

const app = createApp(App)

app.directive('align-numbers', alignNumbers)
app.use(createPinia())
app.use(router)
app.mount('#app')
