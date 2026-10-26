/**
 * 前端設定總入口。
 *
 * - envConfig：環境變數（client/.env）── 部署環境會不同的項目
 * - menuConfig：純前端顯示設定 ── 業者分組與順序、路線順序
 */
import { envConfig } from './env'
import { menuConfig } from './menu'

export { envConfig, menuConfig }
export { groupByOperator, groupLinesByOperator, resolveOperatorId } from './menu'

/** 相容舊的 config 命名 */
export const config = envConfig

export default config
