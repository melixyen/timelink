/**
 * 把參考原始碼（melixyen/railtime 的 canvasTaiwanRailwayMap.js）的版面抽出來，
 * 存成同目錄的 reference-layout.json。
 *
 * 平常「不需要」跑這支：reference-layout.json 已經跟著專案一起存放，build.mjs 直接讀它。
 * 只有參考原始碼改版、想重新匯入版面時才用。
 *
 * 用法：
 *   node import-reference.mjs                 從 GitHub 抓
 *   node import-reference.mjs <本地檔案路徑>   讀本地檔案
 *
 * 參考原始碼是一段瀏覽器用的 JS，這裡只取出「錨點 pos」與「lineData」兩段，
 * 放進沒有任何權限的沙箱（node:vm，沒有 require／檔案／網路）裡求值。
 */
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const URL_REF = 'https://raw.githubusercontent.com/melixyen/railtime/gh-pages/canvasTaiwanRailwayMap.js';
const OUT = path.join(HERE, 'reference-layout.json');

const arg = process.argv[2];
let src;
if (arg) {
  src = fs.readFileSync(arg, 'utf8');
} else {
  const res = await fetch(URL_REF);
  if (!res.ok) { throw new Error('無法取得參考檔：' + res.status + ' ' + URL_REF); }
  src = await res.text();
}

function between(a, b) {
  const i = src.indexOf(a);
  const j = src.indexOf(b, i);
  if (i < 0 || j < 0) { throw new Error('參考檔的結構變了，找不到：' + (i < 0 ? a : b)); }
  return src.slice(i, j);
}
const code = '(function (cfg) {' +
  between('var pos = {', 'if(cfg.linePOS)') + '\n' +
  between('var lineData = {', 'if(cfg.lineData) lineData') + '\n;return lineData; })({})';
const lineData = vm.runInNewContext(code, Object.create(null), { timeout: 5000 });

const num = (v) => (typeof v === 'number' && isFinite(v) ? Math.round(v * 100) / 100 : 0);
const lines = [];
for (const [group, arr] of Object.entries(lineData)) {
  if (!Array.isArray(arr)) { continue; }
  for (const l of arr) {
    const stations = {};
    for (const [key, s] of Object.entries(l.station || {})) {
      if (typeof s.x !== 'number' || typeof s.y !== 'number') { continue; }
      stations[key] = [num(s.x), num(s.y), Number(s.stationClass) || 0, String(s.name || '')];
    }
    const pathNodes = (l.line || []).map((p) => [String(p.type), String(p.id), num(p.ox), num(p.oy)]);
    lines.push({ id: String(l.id), group, name: String(l.name || ''), stations, path: pathNodes });
  }
}

// 一條參考線一行，日後重新匯入時 diff 比較好讀
const body = lines.map((l) => '    ' + JSON.stringify(l)).join(',\n');
fs.writeFileSync(OUT,
  '{\n  "source": ' + JSON.stringify(URL_REF) + ',\n' +
  '  "note": "由 import-reference.mjs 產生。stations：key → [x, y, 站級, 站名]；path：[類型(station/turn/move), key, ox, oy]",\n' +
  '  "lines": [\n' + body + '\n  ]\n}\n');
console.log('已寫出 ' + OUT + '：' + lines.length + ' 條參考線、' +
  lines.reduce((n, l) => n + Object.keys(l.stations).length, 0) + ' 個車站座標');
