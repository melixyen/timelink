/**
 * 產生路網資料，寫進 ../canvasTaiwanRailwayMap.js 的資料區塊
 * （檔案裡 `// <railway-data>` 到 `// </railway-data>` 之間，其他程式碼不會動到）。
 *
 * 這支是「產生資料」的工具，不是前端的一部分；前端只載入那一個 JS 檔，不需要後端 API。
 *
 * 來源：
 *   reference-layout.json（同目錄）                   參考檔的版面：座標、站級、線條轉折點
 *   layout-overrides.mjs（同目錄）                    手調版面：轉乘站收攏、站外轉乘、線條轉折
 *   server/download/tdx/v2/Rail/**                    車站（代碼、站名、英文、經緯度）、捷運營運路線站序
 *   server/custom/.../transfer_groups.json            路線（配色、車站清單）、轉乘群組
 *   server/libs/.../transfer_regions.json             生活圈
 *
 * 做法（重點）：
 *   1. 參考檔的車站「逐條參考線」比對到後端那條線上的 StationUID：
 *      同一個轉乘站在後端每條線各有一個 UID，這樣兩個 UID 才會拿到同一個座標。
 *   2. layout-overrides.mjs 把同一個 TransferGroup 的車站收攏（交叉→同一點、平行→相鄰）。
 *   3. 線條照「站鏈」畫，不照後端的車站清單順序（清單把支線排在最後面）：
 *      捷運用 TDX StationOfRoute 拆成主線＋支線（環狀輕軌自然閉合），台鐵、高鐵是線性的。
 *   4. 參考檔的轉折點掛在相鄰兩個主要車站之間；中間的小站排回折線上，
 *      參考檔沒有的新站依經緯度比例排在前後站之間。
 *   5. 轉乘群組裡疊在同一點的車站，各自沿著自己的路線錯開（每一站都要有自己的圓圈可以點）。
 *   6. 一個 TransferGroup 的共構車站是一個「節點」（畫一個大圈圈起來）；
 *      站外轉乘的車站（layout-overrides 的 detached）自己一個節點，用虛線連過去。
 *
 * 用法：node build.mjs [--root=<專案根目錄>] [--out=<輸出的 JS 檔>] [--verbose]
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const args = Object.fromEntries(process.argv.slice(2).map((a) => {
  const m = /^--([^=]+)(?:=(.*))?$/.exec(a);
  return m ? [m[1], m[2] === undefined ? true : m[2]] : [a, true];
}));
const ROOT = path.resolve(args.root || path.join(HERE, '../../../..'));   // 專案根目錄
const OUT = path.resolve(args.out || path.join(HERE, '../canvasTaiwanRailwayMap.js'));
const MARK_BEGIN = '// <railway-data>';
const MARK_END = '// </railway-data>';

function fail(msg) {
  console.error('錯誤：' + msg);
  process.exit(1);
}
function warn(msg) {
  console.warn('警告：' + msg);
}
function readJson(file) {
  if (!fs.existsSync(file)) { fail('缺少資料檔：' + file); }
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}
const round2 = (v) => Math.round(v * 100) / 100;
const dist = (a, b) => Math.hypot(a[0] - b[0], a[1] - b[1]);

/** 站名正規化：臺→台、去掉括號補充、「輕軌」前綴與結尾的「站／車站／火車站」 */
function normName(s) {
  let t = String(s || '').replace(/臺/g, '台').replace(/\([^)]*\)/g, '').replace(/[\s　]/g, '').replace(/^輕軌/, '');
  const u = t.replace(/(火車站|車站|站)$/, '');
  return u !== '' ? u : t;
}
/** 顯示用名稱：臺→台 */
const twName = (s) => String(s || '').replace(/臺/g, '台');
/** 顯示用站名：臺→台、去掉結尾的「站」（「車站」保留） */
function displayName(s) {
  s = twName(s);
  if (/站$/.test(s) && !/車站$/.test(s) && [...s].length > 2) { s = s.slice(0, -1); }
  return s;
}
/** 圖上用的英文：盡量短（去掉結尾的 Station 等），但「○○車站」保留 Station */
function shortEn(en, zh) {
  let s = String(en || '').trim();
  if (s === '') { return ''; }
  if (!/車站$/.test(twName(zh))) { s = s.replace(/\s+(Station|MRT|LRT|Terminal)$/i, ''); }
  if ([...s].length > 22) { s = [...s].slice(0, 21).join('') + '…'; }
  return s;
}
/** 車站代碼的尾碼（KLRT-C12 → c12、NTALRT-K09 → k9） */
function suffixKey(s) {
  s = String(s).toLowerCase();
  const p = s.lastIndexOf('-');
  if (p >= 0) { s = s.slice(p + 1); }
  return s.replace(/^([a-z]*)0*(\d+)$/, '$1$2');
}
/** 參考檔的 key 去掉前綴後的尾碼（trtc_y16 → y16、ntmetro_V01 → v1） */
function refSuffix(key) {
  const p = key.indexOf('_');
  return suffixKey(p < 0 ? key : key.slice(p + 1));
}
/** 兩點距離（公里，近似） */
function kmDist(lat1, lon1, lat2, lon2) {
  return Math.hypot((lon1 - lon2) * 100, (lat1 - lat2) * 111);
}

// ---------- 最小平方：版面座標 = 經緯度的線性換算（給參考檔沒有的延伸段端點用） ----------

function solve3(A, b) {
  const M = A.map((row, i) => row.concat([b[i]]));
  for (let c = 0; c < 3; c++) {
    let piv = c;
    for (let r = c + 1; r < 3; r++) { if (Math.abs(M[r][c]) > Math.abs(M[piv][c])) { piv = r; } }
    if (Math.abs(M[piv][c]) < 1e-9) { return null; }
    [M[c], M[piv]] = [M[piv], M[c]];
    for (let r = 0; r < 3; r++) {
      if (r === c) { continue; }
      const f = M[r][c] / M[c][c];
      for (let k = c; k <= 3; k++) { M[r][k] -= f * M[c][k]; }
    }
  }
  return [M[0][3] / M[0][0], M[1][3] / M[1][1], M[2][3] / M[2][2]];
}
/** 目標 = p0*lon + p1*lat + p2（pts：[lon, lat, x, y]，idx＝2 或 3） */
function fit3(pts, idx) {
  const n = pts.length;
  let lon0 = 0, lat0 = 0, t0 = 0;
  pts.forEach((p) => { lon0 += p[0]; lat0 += p[1]; t0 += p[idx]; });
  lon0 /= n; lat0 /= n; t0 /= n;
  const A = [[0, 0, 0], [0, 0, 0], [0, 0, 0]];
  const b = [0, 0, 0];
  pts.forEach((p) => {
    const f = [p[0] - lon0, p[1] - lat0, 1];
    for (let i = 0; i < 3; i++) {
      for (let j = 0; j < 3; j++) { A[i][j] += f[i] * f[j]; }
      b[i] += f[i] * (p[idx] - t0);
    }
  });
  const sol = solve3(A, b);
  if (!sol) { return null; }
  return [sol[0], sol[1], sol[2] + t0 - sol[0] * lon0 - sol[1] * lat0];
}
function fitAffine(pts) {
  if (!pts || pts.length < 3) { return null; }
  const x = fit3(pts, 2), y = fit3(pts, 3);
  if (!x || !y) { return null; }
  const fit = { a: x[0], b: x[1], c: x[2], d: y[0], e: y[1], f: y[2] };
  // 穩定性檢查：換算出來的範圍不能比原本的範圍大太多（經緯度幾乎共線時換算不可靠）
  const q = pts.map((p) => applyAffine(fit, p[0], p[1]));
  if (q.some((v) => !isFinite(v[0]) || !isFinite(v[1]))) { return null; }
  const span = (arr) => Math.max(...arr) - Math.min(...arr);
  const w = Math.max(1, span(pts.map((p) => p[2]))), h = Math.max(1, span(pts.map((p) => p[3])));
  if (span(q.map((v) => v[0])) > w * 1.6 + 50 || span(q.map((v) => v[1])) > h * 1.6 + 50) { return null; }
  return fit;
}
function applyAffine(fit, lon, lat) {
  return [fit.a * lon + fit.b * lat + fit.c, fit.d * lon + fit.e * lat + fit.f];
}
function fitError(fit, pts) {
  let sum = 0;
  pts.forEach((p) => {
    const q = applyAffine(fit, p[0], p[1]);
    sum += Math.abs(q[0] - p[2]) + Math.abs(q[1] - p[3]);
  });
  return sum / pts.length;
}

// =====================================================================
// 1. 參考檔的版面
// =====================================================================

const SYS_OF = { tra: 'TRA', thsr: 'THSR', trtc: 'TRTC', ntmetro: 'NTMC', tymetro: 'TYMC', crtc: 'TMRT', krtc: 'KRTC' };
// 參考線 id → 後端 LineUID（null＝後端沒有這條線）
const LINE_MAP = {
  tra_xibu: 'TRA-WL', tra_shan: 'TRA-WL', tra_zhjy: 'TRA-WL', tra_jygx: 'TRA-WL',
  tra_pingdong: 'TRA-WL', tra_hai: 'TRA-WL-C', tra_yilan: 'TRA-EL', tra_beihui: 'TRA-EL',
  tra_huadong: 'TRA-EL', tra_nanhuei: 'TRA-SL', tra_pingxu: 'TRA-PX', tra_liujia: 'TRA-NW',
  tra_jiji: 'TRA-JJ', tra_shalun: 'TRA-SH', tra_alishan: null,
  thsr_1: 'THSR-HSRL',
  trtc_5: 'TRTC-BL', trtc_2: 'TRTC-R', trtc_3: 'TRTC-G', trtc_4: 'TRTC-O',
  trtc_1: 'TRTC-BR', trtc_6: 'NTMC-Y', trtc_7: null, trtc_minxi: null,
  ntmetro_tamsui: 'NTDLRT-V', ntmetro_ankeng: 'NTALRT-K', ntmetro_sanying: 'NTMC-LB',
  tymetro_1: 'TYMC-A', tymetro_2: null,
  crtc_1: 'TMRT-G',
  krtc_R: 'KRTC-R', krtc_O: 'KRTC-O', krtc_C: 'KLRT-C',
};

const reference = readJson(path.join(HERE, 'reference-layout.json'));
const refLines = {};          // 參考線 id → { sys, lineUid, path }
const refLineStations = {};   // 參考線 id → { key → { x, y, cls, name } }
const refStations = {};       // key → 第一次出現的那一筆
for (const l of reference.lines) {
  const sys = SYS_OF[l.group];
  if (!sys) { continue; }     // 規劃中路線等
  refLines[l.id] = {
    sys, lineUid: LINE_MAP[l.id] === undefined ? null : LINE_MAP[l.id],
    path: l.path.map((p) => ({ type: p[0], id: p[1], ox: p[2] || 0, oy: p[3] || 0 })),
  };
  refLineStations[l.id] = {};
  for (const [key, s] of Object.entries(l.stations)) {
    const st = { x: s[0], y: s[1], cls: s[2], name: s[3] };
    refLineStations[l.id][key] = st;
    if (!refStations[key]) { refStations[key] = st; }
  }
}

// =====================================================================
// 2. 後端資料
// =====================================================================

const TDX = path.join(ROOT, 'server/download/tdx/v2/Rail');
const groupsData = readJson(path.join(ROOT, 'server/custom/libs/txapi/transfer/data/transfer_groups.json'));
const regionsData = readJson(path.join(ROOT, 'server/libs/txapi/transfer/data/transfer_regions.json'));

// 車站：TDX 的 Station.json（每個系統一個檔）
const STATION_FILES = {
  TRA: 'TRA/Station.json', THSR: 'THSR/Station.json',
  TRTC: 'Metro/Station/TRTC.json', NTMC: 'Metro/Station/NTMC.json',
  NTDLRT: 'Metro/Station/NTDLRT.json', NTALRT: 'Metro/Station/NTALRT.json',
  KRTC: 'Metro/Station/KRTC.json', KLRT: 'Metro/Station/KLRT.json',
  TYMC: 'Metro/Station/TYMC.json', TMRT: 'Metro/Station/TMRT.json',
};
const stations = {};   // StationUID → { uid, system, name, en, pos:[lat, lon]|null, lines:Set, group }
for (const [sys, rel] of Object.entries(STATION_FILES)) {
  const file = path.join(TDX, rel);
  if (!fs.existsSync(file)) { warn('找不到車站檔 ' + file); continue; }
  for (const x of JSON.parse(fs.readFileSync(file, 'utf8'))) {
    const uid = sys + '-' + x.StationID;
    const p = x.StationPosition;
    stations[uid] = {
      uid, system: sys,
      name: (x.StationName && x.StationName.Zh_tw) || '', en: (x.StationName && x.StationName.En) || '',
      pos: p && p.PositionLat ? [Number(p.PositionLat), Number(p.PositionLon)] : null,
      lines: new Set(), group: null,
    };
  }
}
for (const grp of groupsData.TransferGroups) {
  for (const s of grp.Stations) {
    const st = stations[s.StationUID];
    if (!st) { continue; }
    if (!st.pos && s.Position) { st.pos = [Number(s.Position.Lat), Number(s.Position.Lon)]; }
    (s.Lines || []).forEach((l) => st.lines.add(l));
  }
}
const lineList = groupsData.Lines.map((l) => ({
  uid: l.LineUID, system: l.System,
  name: (l.LineName && l.LineName.Zh_tw) || l.LineUID, nameEn: (l.LineName && l.LineName.En) || '',
  color: l.LineColor || '#666', stations: l.Stations,
}));
const lineStations = {};
const lineColor = {};
for (const l of lineList) {
  lineStations[l.uid] = l.stations;
  lineColor[l.uid] = l.color;
  l.stations.forEach((u) => { if (stations[u]) { stations[u].lines.add(l.uid); } });
}

// =====================================================================
// 3. 參考車站 → StationUID（逐條參考線比對）
//    參考檔裡同一個轉乘站會出現在好幾條線的 station 字典（同一個 key、同一個座標），
//    後端則是每條線各有一個 StationUID（中正紀念堂＝TRTC-G10＋TRTC-R08）。
//    所以要「每條參考線各自」在對應的後端路線上找車站，兩個 UID 才會拿到同一個座標。
// =====================================================================

const layout = (await import(pathToFileURL(path.join(HERE, 'layout-overrides.mjs')).href)).default;
const pseudo = layout.pseudo || {};
const detached = new Set(layout.detached || []);

const suffixIndex = {};
for (const [lu, list] of Object.entries(lineStations)) {
  suffixIndex[lu] = {};
  for (const u of list) {
    const k = suffixKey(u);
    if (!suffixIndex[lu][k]) { suffixIndex[lu][k] = u; }
  }
}

/** 參考車站 key（在某條參考線上）→ StationUID */
function matchRefStation(key, name, sys, lu) {
  if (sys === 'TRA') {
    // 台鐵：參考檔的 key 就是車站代碼
    const cand = 'TRA-' + key.slice(4).padStart(4, '0');
    return stations[cand] ? cand : null;
  }
  if (lu === null) { return null; }
  // 1. 代碼尾碼（krtc_C12 → KLRT-C12、trtc_y08 → NTMC-Y08、tymetro_a14a → TYMC-A14a）
  //    站名改過（西子灣→哈瑪星、灣仔內→大順民族）也對得上
  const rk = refSuffix(key);
  if (suffixIndex[lu] && suffixIndex[lu][rk]) { return suffixIndex[lu][rk]; }
  // 2. 同名（只在這條後端路線上找）
  const nameKey = normName(name);
  for (const u of lineStations[lu] || []) {
    if (stations[u] && normName(stations[u].name) === nameKey) { return u; }
  }
  return null;
}

const xy = {};          // StationUID → [x, y]
const clsOf = {};       // StationUID → 參考檔的站級
const refKeyUid = {};   // 參考線 id → { key → StationUID }
const unmatched = [];
for (const [rid, rl] of Object.entries(refLines)) {
  if (rl.lineUid === null && rl.sys !== 'TRA') { continue; }   // 後端沒有的線整條略過
  if (rid === 'tra_alishan') { continue; }
  for (const [key, st] of Object.entries(refLineStations[rid])) {
    const uid = matchRefStation(key, st.name, rl.sys, rl.lineUid);
    if (uid === null) { unmatched.push(rid + ':' + key + ' ' + st.name); continue; }
    (refKeyUid[rid] = refKeyUid[rid] || {})[key] = uid;
    if (!xy[uid]) {
      xy[uid] = [st.x, st.y];
      clsOf[uid] = st.cls;
    } else {
      clsOf[uid] = Math.min(clsOf[uid], st.cls);
    }
  }
}

// =====================================================================
// 4. 參考檔的轉折點：相鄰兩個「路徑上的車站」之間的折角
//    legs['A|B'] = A → B 之間的轉折點（兩個方向都存）
// =====================================================================

const legs = {};
for (const [rid, rl] of Object.entries(refLines)) {
  if (!refKeyUid[rid]) { continue; }
  let prev = null;
  let pending = [];
  for (const e of rl.path) {
    const st = refLineStations[rid][e.id] || refStations[e.id] || null;
    if (st === null) { prev = null; pending = []; continue; }
    if (e.type === 'turn') { pending.push([st.x + e.ox, st.y + e.oy]); continue; }
    const uid = refKeyUid[rid][e.id] || null;
    if (e.type === 'move' || uid === null) {
      // move：不畫線、跳到該站（支線從這裡接出去）；對不上的車站：斷開
      prev = uid;
      pending = [];
      continue;
    }
    if (prev !== null && prev !== uid) {
      legs[prev + '|' + uid] = pending;
      legs[uid + '|' + prev] = pending.slice().reverse();
    }
    prev = uid;
    pending = [];
  }
}

// 手調版面：車站座標、轉折點
for (const [uid, p] of Object.entries(layout.stations || {})) {
  if (!stations[uid]) { warn('layout-overrides 的車站 ' + uid + ' 不存在'); continue; }
  xy[uid] = [Number(p[0]), Number(p[1])];
}
for (const [pair, turns] of Object.entries(layout.legs || {})) {
  const [a, b] = pair.split('|');
  if (!stations[a] || !stations[b]) { warn('layout-overrides 的轉折 ' + pair + ' 車站不存在'); continue; }
  legs[a + '|' + b] = turns;
  legs[b + '|' + a] = turns.slice().reverse();
}

// =====================================================================
// 5. 每條後端路線的「站鏈」（真正的相鄰關係）
//    後端的 Stations 只是車站清單，支線排在最後面（淡水之後接新北投、松山之後接小碧潭…），
//    不能直接照順序連。捷運用 TDX 的 StationOfRoute（每個營運路線的實際站序）拆成
//    主線＋支線；環狀輕軌的營運路線頭尾同站，自然就是一個閉合的環。
//    台鐵、高鐵的清單本來就是線性的。
// =====================================================================

const routesOf = {};   // LineUID → [[uid...], ...]
const sorDir = path.join(TDX, 'Metro/StationOfRoute');
for (const f of fs.existsSync(sorDir) ? fs.readdirSync(sorDir).filter((n) => n.endsWith('.json')).sort() : []) {
  const sys = path.basename(f, '.json');
  const rows = JSON.parse(fs.readFileSync(path.join(sorDir, f), 'utf8'));
  if (!Array.isArray(rows)) { continue; }
  for (const r of rows) {
    if (Number(r.Direction || 0) !== 0 || !r.Stations || !r.Stations.length) { continue; }
    const lu = sys + '-' + (r.LineID || '');
    (routesOf[lu] = routesOf[lu] || []).push(r.Stations.map((s) => sys + '-' + s.StationID));
  }
}

const chainsOf = {};   // LineUID → [[uid...], ...]
for (const l of lineList) {
  const listed = new Set(l.stations);
  const chains = [];
  const routes = (routesOf[l.uid] || []).slice().sort((a, b) => b.length - a.length);
  const seenEdge = new Set();
  const covered = new Set();
  for (const route of routes) {
    const seq = route.filter((u) => listed.has(u));
    let cur = [];
    for (let i = 0; i < seq.length - 1; i++) {
      const a = seq[i], b = seq[i + 1];
      if (seenEdge.has(a + '|' + b)) {
        if (cur.length > 1) { chains.push(cur); }
        cur = [];
        continue;
      }
      seenEdge.add(a + '|' + b);
      seenEdge.add(b + '|' + a);
      if (!cur.length) { cur.push(a); }
      cur.push(b);
      covered.add(a);
      covered.add(b);
    }
    if (cur.length > 1) { chains.push(cur); }
  }
  // 沒有營運路線資料（台鐵、高鐵），或營運路線沒涵蓋到的車站：照清單順序
  const rest = [];
  for (const u of l.stations) {
    if (pseudo[u]) { continue; }
    if (!chains.length) { rest.push(u); } else if (!covered.has(u)) { warn(l.uid + ' 的 ' + u + ' 不在任何營運路線上'); }
  }
  if (rest.length > 1) { chains.push(rest); }
  chainsOf[l.uid] = chains;
}

// =====================================================================
// 6. 座標補齊＋線條幾何
//    每條站鏈拆成一段一段（A → B）：有參考轉折的用折線，否則直線。
//    A、B 之間的小站：參考檔有座標的照用；沒有的（新站）依經緯度比例排在折線上。
// =====================================================================

function polyCum(P) {
  const cum = [0];
  for (let i = 1; i < P.length; i++) { cum.push(cum[i - 1] + dist(P[i], P[i - 1])); }
  return cum;
}
/** 點投影到折線：回傳 [沿線距離, 垂直距離] */
function polyProject(P, cum, x, y) {
  let best = [0, Infinity];
  for (let i = 0; i < P.length - 1; i++) {
    const vx = P[i + 1][0] - P[i][0], vy = P[i + 1][1] - P[i][1];
    const len2 = vx * vx + vy * vy;
    let t = len2 > 1e-9 ? ((x - P[i][0]) * vx + (y - P[i][1]) * vy) / len2 : 0;
    t = Math.max(0, Math.min(1, t));
    const d = Math.hypot(x - (P[i][0] + t * vx), y - (P[i][1] + t * vy));
    if (d < best[1] - 1e-9) { best = [cum[i] + t * Math.sqrt(len2), d]; }
  }
  return best;
}
function polyPointAt(P, cum, s) {
  const n = P.length;
  if (s <= 0) { return P[0]; }
  for (let i = 0; i < n - 1; i++) {
    if (s <= cum[i + 1] || i === n - 2) {
      const seg = cum[i + 1] - cum[i];
      const t = seg > 1e-9 ? Math.min(1, (s - cum[i]) / seg) : 0;
      return [P[i][0] + (P[i + 1][0] - P[i][0]) * t, P[i][1] + (P[i + 1][1] - P[i][1]) * t];
    }
  }
  return P[n - 1];
}

/** 一條站鏈 → 折線頂點 [[x, y, uid|null], ...]；順便把沒有座標的小站補上座標（寫回 xy） */
function buildChain(chain) {
  const n = chain.length;
  let i = 0;
  while (i < n && !xy[chain[i]]) { i++; }
  if (i >= n) { return []; }
  const out = [[xy[chain[i]][0], xy[chain[i]][1], chain[i]]];
  while (i < n - 1) {
    const a = chain[i];
    let j = null;
    let turns = [];
    for (let k = i + 1; k < n; k++) {
      if (legs[a + '|' + chain[k]] && xy[chain[k]]) { j = k; turns = legs[a + '|' + chain[k]]; break; }
    }
    if (j === null) {
      for (let k = i + 1; k < n; k++) { if (xy[chain[k]]) { j = k; break; } }
      if (j === null) { break; }   // 後面都沒有座標
    }
    const b = chain[j];
    let P = [xy[a], ...turns, xy[b]];
    let cum = polyCum(P);

    // 中間有座標的小站如果離折線太遠（參考檔把這條線畫成平行偏移），改成直接連過每一站
    if (turns.length) {
      for (let k = i + 1; k < j; k++) {
        if (xy[chain[k]] && polyProject(P, cum, xy[chain[k]][0], xy[chain[k]][1])[1] > 10) {
          turns = [];
          P = [xy[a], xy[b]];
          cum = polyCum(P);
          break;
        }
      }
    }
    const total = cum[cum.length - 1];

    // 每個中間站在折線上的位置
    const pos = { [i]: 0, [j]: total };
    for (let k = i + 1; k < j; k++) {
      if (xy[chain[k]]) { pos[k] = polyProject(P, cum, xy[chain[k]][0], xy[chain[k]][1])[0]; }
    }
    // 沒有座標的小站：在前後兩個已知站之間依經緯度距離比例排
    for (let k = i + 1; k < j; k++) {
      if (pos[k] !== undefined) { continue; }
      const p0 = k - 1;            // 前一站一定已經有位置
      let p1 = k + 1;
      while (pos[p1] === undefined) { p1++; }
      const geo = [];
      let ok = true;
      for (let q = p0; q <= p1; q++) {
        const g = stations[chain[q]] ? stations[chain[q]].pos : null;
        if (!g) { ok = false; break; }
        geo.push(g);
      }
      let frac = [];
      if (ok) {
        let acc = 0;
        const d = [0];
        for (let q = 1; q < geo.length; q++) {
          acc += kmDist(geo[q - 1][0], geo[q - 1][1], geo[q][0], geo[q][1]);
          d.push(acc);
        }
        if (acc > 1e-6) { frac = d.map((v) => v / acc); }
      }
      for (let q = p0 + 1; q < p1; q++) {
        const f = frac.length ? frac[q - p0] : (q - p0) / (p1 - p0);
        pos[q] = pos[p0] + (pos[p1] - pos[p0]) * f;
        const pt = polyPointAt(P, cum, pos[q]);
        xy[chain[q]] = [round2(pt[0]), round2(pt[1])];
      }
    }
    // 輸出：轉折點與中間站依沿線位置合併（車站彼此維持站序）
    let ti = 0;
    let last = 0;
    for (let k = i + 1; k < j; k++) {
      const s = Math.max(last, pos[k]);
      while (ti < turns.length && cum[ti + 1] <= s) { out.push([turns[ti][0], turns[ti][1], null]); ti++; }
      out.push([xy[chain[k]][0], xy[chain[k]][1], chain[k]]);
      last = s;
    }
    while (ti < turns.length) { out.push([turns[ti][0], turns[ti][1], null]); ti++; }
    out.push([xy[b][0], xy[b][1], b]);
    i = j;
  }
  return out;
}

// 生活圈（先算，幾何要標每個點的生活圈）
const regionOf = {};
const regionStations = {};
for (const [rk, list] of Object.entries(regionsData.Regions)) {
  regionStations[rk] = [];
  for (const u of list) { regionOf[u] = rk; regionStations[rk].push(u); }
}
const regionCenters = {};
for (const [rk, list] of Object.entries(regionStations)) {
  const ps = list.map((u) => stations[u] && stations[u].pos).filter(Boolean);
  if (ps.length) {
    regionCenters[rk] = [ps.reduce((s, p) => s + p[0], 0) / ps.length, ps.reduce((s, p) => s + p[1], 0) / ps.length];
  }
}
// 生活圈資料沒列到的車站：歸到最近的生活圈
for (const s of Object.values(stations)) {
  if (regionOf[s.uid] || !s.pos) { continue; }
  let best = null, bd = Infinity;
  for (const [rk, c] of Object.entries(regionCenters)) {
    const d = kmDist(c[0], c[1], s.pos[0], s.pos[1]);
    if (d < bd) { bd = d; best = rk; }
  }
  if (best !== null) { regionOf[s.uid] = best; regionStations[best].push(s.uid); }
}

// 站鏈開頭就沒有座標的車站（參考檔沒有的延伸段）：先用經緯度換算給一個位置
const regionFits = {};
for (const [rk, list] of Object.entries(regionStations)) {
  const pts = list.filter((u) => xy[u] && stations[u] && stations[u].pos)
    .map((u) => [stations[u].pos[1], stations[u].pos[0], xy[u][0], xy[u][1]]);
  if (pts.length >= 4) {
    const f = fitAffine(pts);
    if (f && fitError(f, pts) < 600) { regionFits[rk] = f; }
  }
}
const estimated = [];
for (const chains of Object.values(chainsOf)) {
  for (const chain of chains) {
    for (const u of [chain[0], chain[chain.length - 1]]) {
      if (xy[u] || !stations[u] || !stations[u].pos) { continue; }
      const rk = regionOf[u];
      if (!rk || !regionFits[rk]) { continue; }
      const p = applyAffine(regionFits[rk], stations[u].pos[1], stations[u].pos[0]);
      xy[u] = [round2(p[0]), round2(p[1])];
      estimated.push(u);
    }
  }
}

const geometry = {};   // LineUID → [[[x, y, 生活圈, uid|null], ...], ...]
for (const l of lineList) {
  for (const chain of chainsOf[l.uid]) {
    const verts = buildChain(chain);
    if (verts.length < 2) { continue; }
    // 轉折點的生活圈：前後車站同一個生活圈就跟著，否則留空（跨生活圈的連接軌道）
    const out = verts.map((v, i) => {
      if (v[2] !== null) { return [round2(v[0]), round2(v[1]), regionOf[v[2]] || null, v[2]]; }
      let ra = null, rb = null;
      for (let k = i - 1; k >= 0; k--) { if (verts[k][2] !== null) { ra = regionOf[verts[k][2]] || null; break; } }
      for (let k = i + 1; k < verts.length; k++) { if (verts[k][2] !== null) { rb = regionOf[verts[k][2]] || null; break; } }
      return [round2(v[0]), round2(v[1]), ra === rb ? ra : null, null];
    });
    (geometry[l.uid] = geometry[l.uid] || []).push(out);
  }
}

if (args['dump-core']) {   // 除錯用：錯開轉乘站之前的座標與幾何
  fs.writeFileSync(args['dump-core'], JSON.stringify({ xy, geometry }));
}

// =====================================================================
// 7. 轉乘群組：哪些車站圈在一起（共構）、哪些用虛線連（站外轉乘）
// =====================================================================

const SYS_RANK = { THSR: 5, TRA: 4, TRTC: 3, NTMC: 3, TYMC: 3, TMRT: 3, KRTC: 3, KLRT: 2, NTALRT: 2, NTDLRT: 2 };
const SYS_LABEL = { THSR: '高鐵', TRA: '台鐵', TRTC: '捷運', NTMC: '捷運', TYMC: '捷運', TMRT: '捷運', KRTC: '捷運',
  KLRT: '輕軌', NTALRT: '輕軌', NTDLRT: '輕軌' };
// 優先順序：高鐵 > 台鐵 > 捷運 > 輕軌；同一級裡 layout-overrides 的 prefer 列到的排前面（當主要車站）
const prefer = new Set(layout.prefer || []);
const byRank = (a, b) => ((SYS_RANK[stations[b].system] || 0) - (SYS_RANK[stations[a].system] || 0)) ||
  ((prefer.has(b) ? 1 : 0) - (prefer.has(a) ? 1 : 0));

const groups = {};        // TransferGroupID → { name, en, members（後端的成員，依優先順序） }
const multiGroups = [];
for (const grp of groupsData.TransferGroups) {
  const uids = grp.Stations.map((s) => s.StationUID).filter((u) => stations[u]);
  if (!uids.length) { continue; }
  const real = uids.filter((u) => !pseudo[u]).sort(byRank);
  groups[grp.TransferGroupID] = {
    name: (grp.GroupName && grp.GroupName.Zh_tw) || '', en: (grp.GroupName && grp.GroupName.En) || '',
    members: real.concat(uids.filter((u) => pseudo[u])),
  };
  if (real.length >= 2) { multiGroups.push({ id: grp.TransferGroupID, uids: real }); }
  // 單站群組：直接記在車站上
  if (real.length < 2) { uids.forEach((u) => { stations[u].group = stations[u].group || grp.TransferGroupID; }); }
}
// 車站可能同時屬於兩個群組（機場捷運台北車站＝台北、北門）：大的群組先拿
multiGroups.sort((a, b) => b.uids.length - a.uids.length);
const owner = {};         // StationUID → 擁有它的群組
for (const g of multiGroups) {
  g.mine = g.uids.filter((u) => !owner[u] && xy[u]);
  g.mine.forEach((u) => { owner[u] = g.id; stations[u].group = g.id; });
  g.ring = g.mine.filter((u) => !detached.has(u));
  if (!g.ring.length && g.mine.length) { g.ring = [g.mine[0]]; }   // 全部都是站外轉乘：第一站當主站
  g.ring.forEach((u) => detached.delete(u));
}
for (const [u, real] of Object.entries(pseudo)) {
  if (stations[u] && stations[real]) { stations[u].group = stations[u].group || stations[real].group; }
}

// =====================================================================
// 8. 把疊在一起的轉乘站錯開：每個車站要有自己的圓圈（可以分別點選）
//    交叉的轉乘站在版面上是同一個點；這裡讓每一站沿著「自己的路線」退開一點，
//    原本的交叉點留在線上當轉折點。站外轉乘的車站退得更遠（之間要畫虛線）。
// =====================================================================

const RING_SEP = 12;       // 同一個圈裡兩個站點的最小距離
const RING_STEPS = [9, 14]; // 圈裡的站可以退開多遠（先試近的）
const DETACH_SEP = 26;     // 站外轉乘的站離其他站的最小距離
const DETACH_STEPS = [26];
const NEIGHBOR_GUARD = 10; // 退開之後離同線的下一站至少要有這麼遠

function occurrences(uid) {
  const occ = [];
  for (const polys of Object.values(geometry)) {
    for (const poly of polys) {
      poly.forEach((p, idx) => { if (p[3] === uid) { occ.push({ poly, idx }); } });
    }
  }
  return occ;
}
/** 從折線的第 idx 點沿 dir 方向走 d：回傳 { pt, after }（after＝落在哪個頂點之後／之前），走不到回傳 null */
function walk(poly, idx, dir, d) {
  let pos = poly[idx];
  let remain = d;
  for (let j = idx + dir; j >= 0 && j < poly.length; j += dir) {
    const next = poly[j];
    const seg = dist(pos, next);
    if (next[3] !== null) {
      if (remain > seg - NEIGHBOR_GUARD) { return null; }   // 會太靠近（或超過）下一站
      return { pt: [pos[0] + (next[0] - pos[0]) * remain / seg, pos[1] + (next[1] - pos[1]) * remain / seg], at: j };
    }
    if (remain <= seg - 0.01) {
      return { pt: [pos[0] + (next[0] - pos[0]) * remain / seg, pos[1] + (next[1] - pos[1]) * remain / seg], at: j };
    }
    remain -= seg;
    pos = next;
  }
  return null;   // 線的盡頭
}
/** 這一站可以退到哪些位置（每條經過它的線都要能走到同一點） */
function moveOptions(uid, steps) {
  const occ = occurrences(uid);
  if (!occ.length) { return []; }
  const out = [];
  for (const step of steps) for (const dir of [-1, 1]) {
    const w = walk(occ[0].poly, occ[0].idx, dir, step);
    if (!w) { continue; }
    const plan = [];
    const ok = occ.every((o) => [-1, 1].some((d2) => {
      const w2 = walk(o.poly, o.idx, d2, step);
      if (w2 && dist(w2.pt, w.pt) < 0.5) { plan.push({ poly: o.poly, idx: o.idx, dir: d2, at: w2.at }); return true; }
      return false;
    }));
    if (ok) { out.push({ pt: [round2(w.pt[0]), round2(w.pt[1])], plan, step }); }
  }
  return out;
}
function applyMove(uid, opt) {
  for (const m of opt.plan) {
    const poly = m.poly;
    const old = poly[m.idx];
    const moved = [opt.pt[0], opt.pt[1], old[2], uid];
    const isEnd = m.idx === 0 || m.idx === poly.length - 1;
    // 新的站點插在 m.at 那個頂點的前面（往後走）或後面（往前走）
    const insertAt = m.dir > 0 ? m.at : m.at + 1;
    poly.splice(insertAt, 0, moved);
    const oldIdx = insertAt <= m.idx ? m.idx + 1 : m.idx;
    if (isEnd) { poly.splice(oldIdx, 1); }            // 終點站：線就畫到新的站點為止
    else { poly[oldIdx] = [old[0], old[1], old[2], null]; }   // 原本的位置留著當轉折點（交叉點）
  }
  xy[uid] = opt.pt;
}

const spreadLog = [];
for (const g of multiGroups) {
  const members = g.uids.filter((u) => xy[u]);
  const want = (a, b) => (detached.has(a) || detached.has(b) ? DETACH_SEP : RING_SEP);
  const ratio = (pts) => {
    let r = 1, dmin = Infinity;
    for (let i = 0; i < members.length; i++) {
      for (let j = i + 1; j < members.length; j++) {
        const d = dist(pts[i], pts[j]);
        r = Math.min(r, d / want(members[i], members[j]));
        dmin = Math.min(dmin, d);
      }
    }
    return [r, dmin];
  };
  const base = members.map((u) => xy[u]);
  if (ratio(base)[0] >= 0.92) { continue; }
  // 每一站的選項：不動，或沿自己的線往前／往後退開（只動這個群組自己擁有的站）
  const options = members.map((u) => [null].concat(owner[u] === g.id ? moveOptions(u, detached.has(u) ? DETACH_STEPS : RING_STEPS) : []));
  let best = null;
  const pick = new Array(members.length).fill(0);
  (function search(i) {
    if (i === members.length) {
      const pts = members.map((u, k) => (options[k][pick[k]] ? options[k][pick[k]].pt : xy[u]));
      const [r, dmin] = ratio(pts);
      const moves = pick.filter((v) => v > 0).length;
      const far = pick.reduce((n, v, k) => n + (v > 0 ? options[k][v].step : 0), 0);   // 退得越少越好
      const score = Math.round(Math.min(1, r) * 100) * 1e6 - moves * 1e3 - far * 10 + Math.min(9, dmin / 10);
      if (!best || score > best.score + 1e-6) { best = { score, pick: pick.slice(), r }; }
      return;
    }
    for (let k = 0; k < options[i].length; k++) { pick[i] = k; search(i + 1); }
  })(0);
  members.forEach((u, k) => { if (best.pick[k] > 0) { applyMove(u, options[k][best.pick[k]]); } });
  spreadLog.push({ id: g.id, ok: best.r >= 0.92, moved: members.filter((u, k) => best.pick[k] > 0) });
}
// 虛擬車站跟著本尊
for (const [u, real] of Object.entries(pseudo)) { if (xy[real]) { xy[u] = xy[real]; } }

// =====================================================================
// 9. 節點（畫在圖上的車站）：一個群組的共構車站＝一個節點；其餘每站一個節點
// =====================================================================

const colorOf = (uid) => {
  for (const lu of stations[uid].lines) { if (lineColor[lu]) { return lineColor[lu]; } }
  return '#333';
};
const classOf = (uid) => (clsOf[uid] !== undefined ? clsOf[uid]
  : (stations[uid].system === 'THSR' || stations[uid].system === 'TRA') ? 3 : 4);

/** members：圈在一起的車站（已依優先順序排好）；small：站外轉乘的車站，站名用小字 */
function makeNode(id, groupId, members, small) {
  const primary = stations[members[0]];
  const rawName = displayName(primary.name);
  let name = rawName;
  // 高鐵與台鐵同名但不在同一個節點 → 高鐵加前綴
  if (primary.system === 'THSR' && name.indexOf('高鐵') !== 0) {
    const mine = new Set(members);
    const clash = Object.values(stations).some((s) => s.system === 'TRA' && !mine.has(s.uid) && normName(s.name) === normName(primary.name));
    if (clash) { name = '高鐵' + name; }
  }
  const systems = new Set(members.map((u) => SYS_LABEL[stations[u].system] || stations[u].system));
  let cls = Math.min(...members.map(classOf));
  // 站級：高鐵站、跨系統轉乘站不會是小站
  if (systems.has('高鐵')) { cls = Math.min(cls, 2); }
  if (systems.size >= 3 || members.length >= 4) { cls = Math.min(cls, 1); } else if (members.length >= 2) { cls = Math.min(cls, 3); }
  // 站名跟主要站名不一樣的成員：各自的中英文小字標在自己的站點旁邊（同名的合成一筆）
  const main = [];
  const sub = [];
  for (const u of members) {
    const n = displayName(stations[u].name);
    if (n === rawName) { main.push(u); continue; }
    const en = shortEn(stations[u].en, stations[u].name);
    const hit = sub.find((s) => s.name === n && s.en === en);
    if (hit) { hit.m.push(u); } else { sub.push({ m: [u], name: n, en }); }
  }
  const node = { id, m: members, name, en: shortEn(primary.en, primary.name), cls, r: regionOf[members[0]] || null };
  if (groupId) { node.g = groupId; }
  if (small) { node.small = 1; }
  if (main.length !== members.length) { node.main = main; node.sub = sub; }
  return node;
}

const nodes = [];
const links = [];      // 站外轉乘連線 [StationUID, StationUID]
const inNode = new Set();
for (const g of multiGroups) {
  if (!g.ring.length) { continue; }
  nodes.push(makeNode(g.id, g.id, g.ring.slice().sort(byRank), false));
  g.ring.forEach((u) => inNode.add(u));
}
for (const g of multiGroups) {
  // 這個群組裡沒圈進來的成員（站外轉乘、或已經畫在別的群組）：從最近的站點連虛線過去
  for (const u of g.uids) {
    if (g.ring.includes(u) || !xy[u] || !g.ring.length) { continue; }
    let near = g.ring[0];
    g.ring.forEach((v) => { if (dist(xy[v], xy[u]) < dist(xy[near], xy[u])) { near = v; } });
    if (dist(xy[near], xy[u]) > 1) { links.push([near, u]); }
  }
}
for (const s of Object.values(stations)) {
  if (inNode.has(s.uid) || pseudo[s.uid] || !xy[s.uid]) { continue; }
  nodes.push(makeNode(s.uid, owner[s.uid] || null, [s.uid], detached.has(s.uid) && !!owner[s.uid]));
}

// =====================================================================
// 10. 輸出
// =====================================================================

// 手調版面指定的站名位置（自動排的位置不理想時用）
for (const [id, lp] of Object.entries(layout.labels || {})) {
  const pos = [Number(lp[0]), Number(lp[1]), lp[2] || 'left'].concat(lp[3] ? [lp[3]] : []);
  const node = nodes.find((n) => n.id === id);
  if (node) { node.lp = pos; continue; }
  // 不是節點 id：看是不是轉乘站裡某個成員自己的小字站名（用那個成員的 StationUID 指定）
  let sub = null;
  nodes.forEach((n) => (n.sub || []).forEach((s) => { if (s.m.includes(id)) { sub = s; } }));
  if (!sub) { warn('layout-overrides 的 labels：找不到節點或小字站名 ' + id); continue; }
  sub.lp = pos;
}

const outStations = {};
for (const s of Object.values(stations)) {
  if (!xy[s.uid]) { continue; }
  const o = { n: s.name, e: s.en, s: s.system, l: [...s.lines], r: regionOf[s.uid] || null,
    x: round2(xy[s.uid][0]), y: round2(xy[s.uid][1]), c: colorOf(s.uid) };
  if (s.group) { o.g = s.group; }
  if (pseudo[s.uid]) { o.p = pseudo[s.uid]; }
  outStations[s.uid] = o;
}
const outGroups = {};
for (const [id, g] of Object.entries(groups)) {
  const m = g.members.filter((u) => outStations[u]);
  if (m.length) { outGroups[id] = { n: g.name, e: g.en, m }; }
}
const outRegions = {};
for (const [rk, list] of Object.entries(regionStations)) {
  const pts = list.filter((u) => xy[u]).map((u) => xy[u]);
  if (!pts.length) { continue; }
  const nm = (regionsData.Names || {})[rk];
  const zh = typeof nm === 'string' ? nm : (nm && nm.Zh_tw) || rk;
  outRegions[rk] = {
    name: twName(zh.replace(/（.*$/, '')), nameEn: (nm && typeof nm === 'object' && nm.En) || '',
    center: [Math.round(pts.reduce((s, p) => s + p[0], 0) / pts.length), Math.round(pts.reduce((s, p) => s + p[1], 0) / pts.length)],
  };
}
const outSystems = {};
for (const sys of Object.keys(SYS_RANK)) { outSystems[sys] = [SYS_LABEL[sys], SYS_RANK[sys]]; }

/** 一筆一行的 JSON（方便看 diff） */
function block(obj, indent) {
  const pad = ' '.repeat(indent);
  if (Array.isArray(obj)) { return '[\n' + obj.map((v) => pad + '  ' + JSON.stringify(v)).join(',\n') + '\n' + pad + ']'; }
  return '{\n' + Object.entries(obj).map(([k, v]) => pad + '  ' + JSON.stringify(k) + ': ' + JSON.stringify(v)).join(',\n') + '\n' + pad + '}';
}
const geoText = '{\n' + Object.entries(geometry).map(([lu, polys]) =>
  '        ' + JSON.stringify(lu) + ': [\n' + polys.map((p) => '          ' + JSON.stringify(p)).join(',\n') + '\n        ]').join(',\n') + '\n      }';
const dataText =
  '    var DATA = {\n' +
  '      "generatedAt": ' + JSON.stringify(new Date().toISOString().slice(0, 19) + 'Z') + ',\n' +
  '      "systems": ' + JSON.stringify(outSystems) + ',\n' +
  '      "regions": ' + block(outRegions, 6) + ',\n' +
  '      "lines": ' + block(lineList.map((l) => ({ uid: l.uid, system: l.system, name: twName(l.name), nameEn: l.nameEn, color: l.color })), 6) + ',\n' +
  '      "stations": ' + block(outStations, 6) + ',\n' +
  '      "groups": ' + block(outGroups, 6) + ',\n' +
  '      "nodes": ' + block(nodes, 6) + ',\n' +
  '      "links": ' + JSON.stringify(links) + ',\n' +
  '      "geometry": ' + geoText + '\n' +
  '    };';

if (!fs.existsSync(OUT)) { fail('找不到要寫入的 JS 檔：' + OUT); }
const js = fs.readFileSync(OUT, 'utf8');
const i0 = js.indexOf(MARK_BEGIN);
const i1 = js.indexOf(MARK_END);
if (i0 < 0 || i1 < i0) { fail(OUT + ' 裡找不到資料區塊標記（' + MARK_BEGIN + ' … ' + MARK_END + '）'); }
const head = js.slice(0, js.indexOf('\n', i0) + 1);
fs.writeFileSync(OUT, head + dataText + '\n    ' + js.slice(i1));
console.log('已更新 ' + OUT + ' 的資料區塊（' + (Buffer.byteLength(dataText) / 1024).toFixed(1) + ' KB）');
console.log('車站 ' + Object.keys(outStations).length + ' 個、節點 ' + nodes.length + ' 個、轉乘群組 ' + multiGroups.length +
  ' 個、路線 ' + lineList.length + ' 條、生活圈 ' + Object.keys(outRegions).length + ' 個');

// ---- 檢查 ----
const noCoord = Object.values(stations).filter((s) => !xy[s.uid] && s.lines.size).map((s) => s.uid + s.name);
console.log('沒有座標的車站：' + noCoord.length + ' 個 ' + noCoord.slice(0, 20).join(' '));
if (estimated.length) { console.log('用經緯度估位置的端點站（建議到 layout-overrides.mjs 指定座標）：' + estimated.join(' ')); }
for (const l of lineList) {
  const polys = geometry[l.uid] || [];
  const on = new Set();
  polys.forEach((p) => p.forEach((q) => { if (q[3]) { on.add(q[3]); } }));
  const miss = l.stations.filter((u) => !on.has(u) && !pseudo[u]);
  if (miss.length) { console.log('  ' + l.uid.padEnd(12) + ' 線上缺 ' + miss.length + ' 站：' + miss.slice(0, 8).join(' ')); }
  if (polys.length > 1) { console.log('  ' + l.uid.padEnd(12) + ' 主線＋支線共 ' + polys.length + ' 段'); }
}
const stuck = spreadLog.filter((s) => !s.ok).map((s) => s.id);
console.log('自動錯開的轉乘群組：' + spreadLog.length + ' 個' + (stuck.length ? '；分不開的（請到 layout-overrides.mjs 調整座標）：' + stuck.join(' ') : ''));
const wide = [];
for (const n of nodes) {
  let spread = 0;
  n.m.forEach((a) => n.m.forEach((b) => { spread = Math.max(spread, dist(xy[a], xy[b])); }));
  if (spread > 40) { wide.push(n.id + ' ' + n.name + '（' + Math.round(spread) + '）'); }
}
if (wide.length) { console.log('圈起來的範圍超過 40 的節點（確認是不是該收攏，或改列站外轉乘）：\n  ' + wide.join('\n  ')); }
if (args.verbose) {
  console.log('自動錯開的車站：\n  ' + spreadLog.map((s) => s.id + ' → ' + s.moved.join(' ')).join('\n  '));
  console.log('參考檔對不上的車站：\n  ' + unmatched.join('\n  '));
}
