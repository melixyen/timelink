/**
 * Train UI Frame：接軌時刻的共用 UI 元件庫（Element UI 風格、藍白色系）。
 *
 *   import TrainUI from '@/train_ui_frame'
 *   app.use(TrainUI)
 *
 * components/ 底下每個 train_xxx.vue 都會自動以 TrainXxx 的名稱全域註冊，
 * 樣板裡寫 <train-xxx> 或 <TrainXxx> 都可以。基礎樣式在 train_ui.css（隨 install 載入），
 * 顏色、字體、大小、圓角都是 --train-ui- 開頭的 CSS 變數，網站自己的 CSS 再覆蓋即可。
 * 用法說明見同目錄的 skill.md。
 */
import './train_ui.css'

export * from './utils'

const modules = import.meta.glob('./components/train_*.vue', { eager: true })

/** train_button_group → TrainButtonGroup */
function componentName(path) {
  const base = path.split('/').pop().replace(/\.vue$/, '')
  return base
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

export const components = Object.fromEntries(
  Object.entries(modules).map(([path, mod]) => [componentName(path), mod.default]),
)

export default {
  install(app) {
    Object.entries(components).forEach(([name, component]) => app.component(name, component))
  },
}
