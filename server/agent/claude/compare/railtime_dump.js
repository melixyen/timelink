// railtime（接軌時刻 tratrtc.html）的資料與找路結果輸出成 JSON，給 PHP 比對工具用。只讀 railtime 專案，不修改。
//   node railtime_dump.js <railtime 目錄> stations            車站（id、名稱、公司、所在路線、區域 sect）
//   node railtime_dump.js <railtime 目錄> lines               路線站序 {路線: [車站...]}
//   node railtime_dump.js <railtime 目錄> bypass              routeMap 裡帶 bypass*Reg 的走法（依起訖站排除的轉乘走法）
//   node railtime_dump.js <railtime 目錄> routes <pairs.json>  每組 [起站 id, 迄站 id] 的所有路由（路線串＋轉乘站）
const fs = require('fs');
const [root, cmd, arg] = process.argv.slice(2);
const TT = require('./railtime_load.js')(root);
const D = TT.data;

function stations() {
    const out = [];
    const lines = {};
    for (const comp of ['tra', 'trtc', 'tymetro']) {
        for (const l of (D[comp].line || [])) {
            for (const s of (l.station || [])) (lines[s] = lines[s] || []).push(l.id);
        }
    }
    if (!D.tymetro.line) lines.__tymetro = true;
    for (const comp of ['tra', 'trtc', 'tymetro']) {
        for (const s of D[comp].station_ary) {
            out.push({ id: s.id, name: s.name, company: comp, sect: s.sect || null, lines: lines[s.id] || (comp === 'tymetro' ? ['tymc_1'] : []) });
        }
    }
    return out;
}

function transStation(id) {
    const t = TT.fn.getCommon_transStationByID(id);
    return t ? { id, name: t.name, changeLine: t.changeLine, changeStation: t.changeStation, walkMinute: t.walkMinute } : { id };
}

function bypass() {
    const out = [];
    D.routeMap.forEach((rm, i) => {
        rm.route.forEach((r, j) => {
            const keys = Object.keys(r).filter(k => /^bypass.*Reg$/.test(k));
            if (!keys.length) return;
            const rule = { map: rm.id, index: j, fromToLine: rm.fromToLine, fromToLineReg: rm.fromToLineReg || null, sect: rm.sect || null,
                           line: r.line, transStation: (r.transStation || []).map(transStation) };
            for (const k of keys) rule[k] = r[k];
            out.push(rule);
        });
    });
    return out;
}

function routes(pairs) {
    const out = {};
    for (const [a, b] of pairs) {
        let res;
        try { res = TT.fn.getRoute_allRouteByFromTo(a, b); } catch (e) { res = { error: String(e) }; }
        if (!res || res.error) { out[a + '>' + b] = res && res.error ? { error: res.error } : []; continue; }
        const list = [];
        for (const r of (res.route || [])) list.push({ line: r.line, transStation: (r.transStation || []).map(id => transStation(id).changeStation || id), takeRange: r.takeRange || null });
        out[a + '>' + b] = list;
    }
    return out;
}

function lines() {
    const out = {};
    for (const comp of ['tra', 'trtc', 'tymetro']) {
        for (const l of (D[comp].line || [])) out[l.id] = l.station || [];
    }
    if (!out.tymetro_1) out.tymetro_1 = D.tymetro.station_ary.map(s => s.id);
    return out;
}

let data;
if (cmd === 'stations') data = stations();
else if (cmd === 'lines') data = lines();
else if (cmd === 'bypass') data = bypass();
else if (cmd === 'routes') data = routes(JSON.parse(fs.readFileSync(arg, 'utf8')));
else { console.error('用法：node railtime_dump.js <railtime 目錄> stations|lines|bypass|routes <pairs.json>'); process.exit(2); }
process.stdout.write(JSON.stringify(data));
