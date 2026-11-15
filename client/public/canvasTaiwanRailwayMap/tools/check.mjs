/**
 * 檢查 ../canvasTaiwanRailwayMap.js（資料＋繪圖＋點擊判定），不需要瀏覽器。
 *
 *   1. 資料：每條線的車站都在線上、每個車站剛好屬於一個節點、轉乘站的站點沒有疊在一起
 *   2. 排版：全台灣與每個生活圈各排一次，看有沒有站名塞不下（被省略）
 *   3. 點擊：每個站點的圓圈點下去要回傳自己的 StationUID；站名點下去要回傳主要車站
 *
 * 用法：node check.mjs          有錯誤時結束碼是 1
 *       node check.mjs --list   另外列出每個轉乘群組的站點
 */
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
await import(pathToFileURL(path.join(HERE, '../canvasTaiwanRailwayMap.js')).href);
const lib = globalThis.canvasTaiwanRailwayMap;
const D = lib.data;
const errors = [];
const warns = [];

// ---------------------------------------------------------------- 1. 資料
const S = D.stations;
console.log('資料：' + D.generatedAt + '　車站 ' + Object.keys(S).length + '、節點 ' + D.nodes.length +
  '、路線 ' + D.lines.length + '、生活圈 ' + Object.keys(D.regions).length);

const onLine = {};
for (const line of D.lines) {
  const polys = D.geometry[line.uid] || [];
  if (!polys.length) { errors.push(line.uid + ' 沒有線條幾何'); }
  polys.forEach((poly) => poly.forEach((p) => {
    if (!p[3]) { return; }
    (onLine[line.uid] = onLine[line.uid] || new Set()).add(p[3]);
    const s = S[p[3]];
    if (!s) { errors.push(line.uid + ' 的幾何用到不存在的車站 ' + p[3]); return; }
    if (Math.hypot(s.x - p[0], s.y - p[1]) > 0.02) { errors.push(p[3] + ' 的座標跟 ' + line.uid + ' 線上的位置不一致'); }
  }));
}
for (const [uid, s] of Object.entries(S)) {
  if (s.p) { continue; }                      // 虛擬車站不畫
  for (const lu of s.l) {
    if (D.geometry[lu] && !(onLine[lu] && onLine[lu].has(uid))) { errors.push(uid + ' ' + s.n + ' 不在 ' + lu + ' 的線上'); }
  }
}
const nodeCount = {};
D.nodes.forEach((n) => n.m.forEach((u) => { nodeCount[u] = (nodeCount[u] || 0) + 1; }));
for (const [uid, s] of Object.entries(S)) {
  if (!s.p && nodeCount[uid] !== 1) { errors.push(uid + ' ' + s.n + ' 屬於 ' + (nodeCount[uid] || 0) + ' 個節點（應該剛好 1 個）'); }
}
for (const [id, g] of Object.entries(D.groups)) {
  g.m.forEach((u) => { if (!S[u]) { errors.push(id + ' 的成員 ' + u + ' 不存在'); } });
}
for (const n of D.nodes) {
  let dmin = Infinity, dmax = 0;
  n.m.forEach((a, i) => n.m.forEach((b, j) => {
    if (i >= j) { return; }
    const d = Math.hypot(S[a].x - S[b].x, S[a].y - S[b].y);
    dmin = Math.min(dmin, d);
    dmax = Math.max(dmax, d);
  }));
  if (dmin < 8.5) { errors.push(n.id + ' ' + n.name + '：站點疊在一起（最近 ' + dmin.toFixed(1) + '），請到 layout-overrides.mjs 調整'); }
  if (dmax > 80) { warns.push(n.id + ' ' + n.name + '：圈起來的範圍很大（' + Math.round(dmax) + '）'); }
}
if (process.argv.includes('--list')) {
  console.log('\n轉乘群組：');
  for (const [id, g] of Object.entries(D.groups)) {
    if (g.m.length < 2) { continue; }
    console.log('  ' + id.padEnd(30) + g.m.map((u) => u + ' ' + S[u].n + (S[u].p ? '（虛擬）' : '(' + S[u].x + ',' + S[u].y + ')')).join('　'));
  }
  console.log('站外轉乘連線：' + D.links.map((l) => l.join('—')).join('　'));
}

// ---------------------------------------------------------------- 2、3. 排版與點擊（不需要真的 canvas）
function stubCanvas() {
  const state = { font: '10px sans-serif' };
  const ctx = new Proxy(state, {
    get(t, k) {
      if (k === 'measureText') {
        return (text) => {
          const size = parseFloat((/([\d.]+)px/.exec(t.font) || [0, 10])[1]);
          let w = 0;
          for (const ch of String(text)) { w += ch.codePointAt(0) > 0x2e7f ? size : size * 0.55; }
          return { width: w };
        };
      }
      return k in t ? t[k] : () => {};
    },
    set(t, k, v) { t[k] = v; return true; },
  });
  return { width: 0, height: 0, style: {}, getContext: () => ctx };
}

const views = [['全台灣', 'all']].concat(lib.regions().map((r) => [r.name, r.key]));
console.log('\n範圍          尺寸(scale 0.8)   省略的站名   點擊檢查');
for (const [name, key] of views) {
  const view = lib.draw(stubCanvas(), { regions: key, scale: 0.8, pixelRatio: 1 });
  let tested = 0, bad = 0;
  const expectNodes = D.nodes.filter((n) => key === 'all' || n.r === key);
  for (const n of expectNodes) {
    for (const uid of n.m) {
      const p = view.locate(uid);
      tested++;
      if (!p) { bad++; errors.push(name + '：' + uid + ' 沒有畫出來'); continue; }
      const hit = view.hitTest(p.x, p.y, 0);
      if (!hit || hit.uid !== uid || hit.target !== 'station') {
        bad++;
        errors.push(name + '：點 ' + uid + ' ' + S[uid].n + ' 的圓圈卻得到 ' + (hit ? hit.uid : '空白'));
      }
    }
  }
  if (view.dropped) { warns.push(name + '：scale 0.8 時有 ' + view.dropped + ' 個站名塞不下被省略：' + view.droppedNames.join('、')); }
  console.log(name.padEnd(8, '　') + (view.width + '×' + view.height).padStart(12) + String(view.dropped).padStart(12) +
    ('      ' + (tested - bad) + '/' + tested));
}

// 不相連的生活圈要分塊、相連的要畫在一起
const multi = lib.draw(stubCanvas(), { regions: ['taipei', 'taoyuan', 'kaohsiung'], scale: 0.8, pixelRatio: 1 });
console.log('\n北北基＋桃園＋高雄 → ' + multi.blocks.map((b) => '[' + b.regions.join('+') + ']').join(' '));
if (multi.blocks.length !== 2) { errors.push('北北基＋桃園＋高雄 應該分成 2 塊，結果是 ' + multi.blocks.length + ' 塊'); }

console.log('');
warns.forEach((w) => console.log('注意：' + w));
errors.slice(0, 40).forEach((e) => console.log('錯誤：' + e));
console.log(errors.length ? '共 ' + errors.length + ' 個錯誤' : '檢查通過');
process.exit(errors.length ? 1 : 0);
