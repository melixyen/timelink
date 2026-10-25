// 在 node 載入 railtime（接軌時刻）的 ttlib（唯讀：只讀取對方專案的檔案，不修改）。
// 用法：const TT = require('./railtime_load.js')('/path/to/railtime');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function deepExtend(deep, target, ...srcs) {
    for (const s of srcs) {
        if (s == null) continue;
        for (const k of Object.keys(s)) {
            const v = s[k];
            if (deep && v && typeof v === 'object') {
                const base = Array.isArray(v) ? (Array.isArray(target[k]) ? target[k] : []) : (target[k] && typeof target[k] === 'object' ? target[k] : {});
                target[k] = deepExtend(true, base, v);
            } else if (v !== undefined) {
                target[k] = v;
            }
        }
    }
    return target;
}

module.exports = function (root) {
    const $ = function () { return { ready() {}, on() {}, html() {}, val() {}, append() {}, find() { return $(); }, each() {} }; };
    $.extend = function (...args) {
        if (typeof args[0] === 'boolean') return deepExtend(args[0], args[1], ...args.slice(2));
        return deepExtend(false, args[0], ...args.slice(1));
    };
    $.ajax = function () {};
    const doc = { createElement: () => ({}), getElementsByTagName: () => [{ appendChild() {} }], getElementById: () => null, addEventListener() {} };
    const sandbox = { console, setTimeout, clearTimeout, $, jQuery: $, document: doc, navigator: { userAgent: 'node' },
                      location: { href: '', search: '', hash: '' }, Promise, fetch: () => Promise.reject(new Error('offline')) };
    sandbox.window = sandbox;
    sandbox.self = sandbox;
    vm.createContext(sandbox);
    for (const f of ['defined.js', 'data.js', 'fn.js', 'fn_tymetro.js']) {
        const p = path.join(root, 'ttlib', f);
        if (fs.existsSync(p)) vm.runInContext(fs.readFileSync(p, 'utf8'), sandbox, { filename: f });
    }
    return sandbox.$trainTaiwanLib;
};
