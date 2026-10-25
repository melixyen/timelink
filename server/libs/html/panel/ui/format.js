/* 顯示用的格式化工具與中文名稱對照 */

export const MODE_NAME = { rail: '軌道', bus: '公車' };
export const CAT_NAME = { static: '靜態資料', timetable: '時刻表／班表', daily: '指定日期' };

export function bytes(n) {
  n = Number(n) || 0;
  if (n < 1024) return n + ' B';
  if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
  if (n < 1073741824) return (n / 1048576).toFixed(2) + ' MB';
  return (n / 1073741824).toFixed(2) + ' GB';
}

export function dur(s) {
  s = Math.max(0, Math.round(Number(s) || 0));
  if (s >= 86400) return (s % 86400 === 0 ? s / 86400 : (s / 86400).toFixed(1)) + ' 天';
  if (s >= 3600) return (s % 3600 === 0 ? s / 3600 : (s / 3600).toFixed(1)) + ' 小時';
  if (s >= 60) return Math.round(s / 60) + ' 分鐘';
  return s + ' 秒';
}

export function pct(x) {
  return x == null ? '—' : (x * 100).toFixed(1) + '%';
}

/** 命中數：HIT + 確認未變 + 舊資料；總數再加 MISS 與錯誤 */
export function hitTotals(stats) {
  const s = stats || {};
  const hit = (s.hit || 0) + (s.revalidated || 0) + (s.stale || 0);
  return { hit, total: hit + (s.miss || 0) + (s.error || 0) };
}

/** 比例對應的狀態色：ok / warn / bad；invert 表示數值越高越糟 */
export function tone(ratio, invert = false, okAt = 0.7, warnAt = 0.3) {
  if (ratio == null) return '';
  const r = invert ? 1 - ratio : ratio;
  return r >= okAt ? 'ok' : r >= warnAt ? 'warn' : 'bad';
}
