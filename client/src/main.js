import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { config } from './config'
import TrainUI from './train_ui_frame'
import { loadNameRules } from './i18n/nameRules'
import './assets/main.css'

// 選用的流量分析（VITE_GA_ID 有設定才載入）
if (config.gaId && typeof document !== 'undefined') {
  const script = document.createElement('script')
  script.async = true
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(config.gaId)}`
  document.head.appendChild(script)
  window.dataLayer = window.dataLayer || []
  window.gtag = function gtag() {
    window.dataLayer.push(arguments)
  }
  window.gtag('js', new Date())
  window.gtag('config', config.gaId)
}

const app = createApp(App)
app.use(createPinia())
app.use(TrainUI)
app.use(i18n)
app.use(router)
app.mount('#app')

// 多語系名稱的取用規則（日文缺名稱先退回中文等）：不擋畫面，載入後用到名稱的地方自動更新
loadNameRules()
