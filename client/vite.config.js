import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

// 前端專案根目錄：client/
// 開發時以 proxy 把 /txapi 轉到後端服務，避免瀏覽器跨網域問題；
// 埠號與轉發目標都可在 .env 調整（VITE_DEV_PORT、VITE_DEV_PROXY_TARGET）。
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, fileURLToPath(new URL('./', import.meta.url)), '')
  const devPort = Number(env.VITE_DEV_PORT) || 5173
  const proxyTarget = env.VITE_DEV_PROXY_TARGET || 'https://tdx.jiapi.net'

  return {
    // 相對路徑：打包後的檔案引用都是 ./assets/...，
    // 可以放在靜態主機的任意目錄（例如 https://example.com/app/）下直接使用
    base: './',
    plugins: [vue()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: devPort,
      proxy: {
        '/txapi': {
          target: proxyTarget,
          changeOrigin: true,
          secure: true,
        },
      },
    },
    build: {
      outDir: 'dist',
      emptyOutDir: true,
    },
  }
})
