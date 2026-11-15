/*!
 * canvasTaiwanRailwayMap — TimeLink 台灣軌道路網圖（canvas）
 *
 * 單一檔案的 library：程式與路網資料都在這個檔案裡，不需要後端 API，也不依賴其他套件。
 * 載入後只會在 window 底下掛上 canvasTaiwanRailwayMap，不會自動畫圖。
 *
 * 用法：
 *   var map = window.canvasTaiwanRailwayMap.setup(div, {
 *       regions: 'all',                  // 'all'＝全台灣；'taipei'；['taipei', 'taoyuan', 'kaohsiung']
 *       selected: 'TRA-1000',            // 一開始標示的車站（可省略）
 *       onClick: function (e) {          // e.x / e.y：點擊的畫布座標；e.station、e.group：點到的車站與轉乘群組
 *           if (e.station) { console.log(e.station.StationUID, e.station.name); }
 *       }
 *   });
 *   map.select('THSR-1070');             // 標示某一站
 *   map.setRegions(['kaohsiung']);       // 換範圍重畫
 *   map.destroy();                       // 元件卸載時釋放
 *
 * div 可以是 DOM 元素、CSS 選擇器字串、Vue 的 ref（{ value: el }）或元件實例（$el）。
 * canvas 由 library 建立，大小由 library 決定（剛好容納要畫的路網）；div 太小時由 div 捲動。
 * 完整說明見同目錄的 README.md。
 *
 * 版面（座標、站級、線條轉折點）參考 melixyen/railtime 的 canvasTaiwanRailwayMap.js（極光駭客原作）。
 * 資料區塊由 tools/build.mjs 產生：路網有變化時改 tools/layout-overrides.mjs 後重新產生，不要手改資料區塊。
 */
(function (global) {
    'use strict';

    // <railway-data> 以下到 </railway-data> 由 tools/build.mjs 產生，請勿手改
    var DATA = {
      "generatedAt": "2026-11-15T15:34:54Z",
      "systems": {"THSR":["高鐵",5],"TRA":["台鐵",4],"TRTC":["捷運",3],"NTMC":["捷運",3],"TYMC":["捷運",3],"TMRT":["捷運",3],"KRTC":["捷運",3],"KLRT":["輕軌",2],"NTALRT":["輕軌",2],"NTDLRT":["輕軌",2]},
      "regions": {
        "taipei": {"name":"北北基","nameEn":"","center":[75,33]},
        "taoyuan": {"name":"桃園市","nameEn":"","center":[-430,696]},
        "hsinchu": {"name":"新竹","nameEn":"","center":[-54,1064]},
        "miaoli": {"name":"苗栗縣","nameEn":"","center":[-255,1277]},
        "taichung": {"name":"台中市","nameEn":"","center":[-86,1626]},
        "changhua": {"name":"彰化","nameEn":"","center":[-119,2005]},
        "yunlin": {"name":"雲林縣","nameEn":"","center":[-203,2129]},
        "chiayi": {"name":"嘉義","nameEn":"","center":[-199,2268]},
        "tainan": {"name":"台南市","nameEn":"","center":[-297,2524]},
        "kaohsiung": {"name":"高雄市","nameEn":"","center":[-287,3058]},
        "pingtung": {"name":"屏東縣","nameEn":"","center":[577,3159]},
        "yilan": {"name":"宜蘭縣","nameEn":"","center":[836,336]},
        "hualien": {"name":"花蓮縣","nameEn":"","center":[820,1057]},
        "taitung": {"name":"台東縣","nameEn":"","center":[820,1920]}
      },
      "lines": [
        {"uid":"TRA-WL","system":"TRA","name":"西部幹線","nameEn":"Western Main Line","color":"#000050"},
        {"uid":"TRA-WL-C","system":"TRA","name":"西部幹線 (海線)","nameEn":"Western Main Line (Coast Line)","color":"#2050c0"},
        {"uid":"TRA-SL","system":"TRA","name":"南迴線","nameEn":"South Link Line","color":"#7d3810"},
        {"uid":"TRA-EL","system":"TRA","name":"東部幹線","nameEn":"Eastern Main Line","color":"#500000"},
        {"uid":"TRA-SU","system":"TRA","name":"蘇澳新-蘇澳","nameEn":"Su'aoxin-Su'ao","color":"#500000"},
        {"uid":"TRA-PX","system":"TRA","name":"平溪線","nameEn":"Pingxi Line","color":"#003030"},
        {"uid":"TRA-SA","system":"TRA","name":"深澳線","nameEn":"Shenao Line","color":"#003030"},
        {"uid":"TRA-NW","system":"TRA","name":"內灣線","nameEn":"Neiwan Line","color":"#403090"},
        {"uid":"TRA-LJ","system":"TRA","name":"六家線","nameEn":"Liujia Line","color":"#403090"},
        {"uid":"TRA-CZ","system":"TRA","name":"成追線","nameEn":"Chengzhui Line","color":"#2050c0"},
        {"uid":"TRA-JJ","system":"TRA","name":"集集線","nameEn":"Jiji Line","color":"#22a050"},
        {"uid":"TRA-SH","system":"TRA","name":"沙崙線","nameEn":"Shalun Line","color":"#124060"},
        {"uid":"THSR-HSRL","system":"THSR","name":"台灣高鐵線","nameEn":"","color":"#ef5210"},
        {"uid":"TRTC-BL","system":"TRTC","name":"板南線","nameEn":"Bannan Line","color":"#0a59ae"},
        {"uid":"TRTC-BR","system":"TRTC","name":"文湖線","nameEn":"Wenhu Line","color":"#b57a25"},
        {"uid":"TRTC-G","system":"TRTC","name":"松山新店線","nameEn":"Songshan-Xindian Line","color":"#107547"},
        {"uid":"TRTC-O","system":"TRTC","name":"中和新蘆線","nameEn":"Zhonghe-Xinlu Line","color":"#f5a818"},
        {"uid":"TRTC-R","system":"TRTC","name":"淡水信義線","nameEn":"Tamsui-Xinyi Line","color":"#d90023"},
        {"uid":"NTMC-LB","system":"NTMC","name":"三鶯線","nameEn":"Sanying Line","color":"#47c1e1"},
        {"uid":"NTMC-Y","system":"NTMC","name":"環狀線","nameEn":"Circular Line","color":"#fedb00"},
        {"uid":"NTDLRT-V","system":"NTDLRT","name":"淡海輕軌","nameEn":"Danhai LRT","color":"#ff2a00"},
        {"uid":"NTALRT-K","system":"NTALRT","name":"安坑輕軌","nameEn":"Ankeng LRT","color":"#9e925e"},
        {"uid":"KRTC-O","system":"KRTC","name":"橘線","nameEn":"","color":"#ff9300"},
        {"uid":"KRTC-R","system":"KRTC","name":"紅線","nameEn":"","color":"#f7005c"},
        {"uid":"KLRT-C","system":"KLRT","name":"環狀輕軌","nameEn":"","color":"#61c851"},
        {"uid":"TYMC-A","system":"TYMC","name":"桃園機場捷運線","nameEn":"Airport MRT Line","color":"#8246af"},
        {"uid":"TMRT-G","system":"TMRT","name":"烏日文心北屯線","nameEn":"Wuriwenxin  Beitun Line","color":"#84bd00"}
      ],
      "stations": {
        "TRA-0900": {"n":"基隆","e":"Keelung","s":"TRA","l":["TRA-WL"],"r":"taipei","x":650,"y":-320,"c":"#000050","g":"TRANSGRP_keelung"},
        "TRA-0910": {"n":"三坑","e":"Sankeng","s":"TRA","l":["TRA-WL"],"r":"taipei","x":650,"y":-280,"c":"#000050"},
        "TRA-0920": {"n":"八堵","e":"Badu","s":"TRA","l":["TRA-EL","TRA-WL"],"r":"taipei","x":650,"y":-240,"c":"#500000","g":"TRANSGRP_badu"},
        "TRA-0930": {"n":"七堵","e":"Qidu","s":"TRA","l":["TRA-WL"],"r":"taipei","x":650,"y":-200,"c":"#000050","g":"TRANSGRP_qidu"},
        "TRA-0940": {"n":"百福","e":"Baifu","s":"TRA","l":["TRA-WL"],"r":"taipei","x":650,"y":-160,"c":"#000050"},
        "TRA-0950": {"n":"五堵","e":"Wudu","s":"TRA","l":["TRA-WL"],"r":"taipei","x":650,"y":-120,"c":"#000050"},
        "TRA-0960": {"n":"汐止","e":"Xizhi","s":"TRA","l":["TRA-WL"],"r":"taipei","x":650,"y":-80,"c":"#000050","g":"TRANSGRP_xizhi"},
        "TRA-0970": {"n":"汐科","e":"Xike","s":"TRA","l":["TRA-WL"],"r":"taipei","x":650,"y":-20,"c":"#000050"},
        "TRA-0980": {"n":"南港","e":"Nangang","s":"TRA","l":["TRA-WL"],"r":"taipei","x":550,"y":-20,"c":"#000050","g":"TRANSGRP_nangang"},
        "TRA-0990": {"n":"松山","e":"Songshan","s":"TRA","l":["TRA-WL"],"r":"taipei","x":450,"y":-20,"c":"#000050","g":"TRANSGRP_songshan"},
        "TRA-1000": {"n":"臺北","e":"Taipei","s":"TRA","l":["TRA-WL"],"r":"taipei","x":10,"y":0,"c":"#000050","g":"TRANSGRP_taipei"},
        "TRA-1001": {"n":"臺北-環島","e":"Taipei Surround Island","s":"TRA","l":["TRA-WL"],"r":"taipei","x":10,"y":0,"c":"#000050","g":"TRANSGRP_taipei","p":"TRA-1000"},
        "TRA-1010": {"n":"萬華","e":"Wanhua","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-130,"y":90,"c":"#000050","g":"TRANSGRP_wanhua"},
        "TRA-1020": {"n":"板橋","e":"Banqiao","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-300,"y":90,"c":"#000050","g":"TRANSGRP_banqiao"},
        "TRA-1030": {"n":"浮洲","e":"Fuzhou","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-370,"y":100,"c":"#000050"},
        "TRA-1040": {"n":"樹林","e":"Shulin","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-440,"y":170,"c":"#000050","g":"TRANSGRP_shulin"},
        "TRA-1050": {"n":"南樹林","e":"South Shulin","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-440,"y":255,"c":"#000050"},
        "TRA-1060": {"n":"山佳","e":"Shanjia","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-440,"y":285,"c":"#000050"},
        "TRA-1070": {"n":"鶯歌","e":"Yingge","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-440,"y":335,"c":"#000050","g":"TRANSGRP_yingge"},
        "TRA-1075": {"n":"鳳鳴","e":"Fengming","s":"TRA","l":["TRA-WL"],"r":"taipei","x":-240,"y":460,"c":"#000050"},
        "TRA-1080": {"n":"桃園","e":"Taoyuan","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":630,"c":"#000050","g":"TRANSGRP_taoyuan1"},
        "TRA-1090": {"n":"內壢","e":"Neili","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":760,"c":"#000050"},
        "TRA-1100": {"n":"中壢","e":"Zhongli_Taoyuan","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":816,"c":"#000050","g":"TRANSGRP_zhongli"},
        "TRA-1105": {"n":"平鎮","e":"Pingzhen","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":853,"c":"#000050"},
        "TRA-1110": {"n":"埔心","e":"Puxin","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":876,"c":"#000050"},
        "TRA-1120": {"n":"楊梅","e":"Yangmei","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":899,"c":"#000050"},
        "TRA-1130": {"n":"富岡","e":"Fugang","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":922,"c":"#000050"},
        "TRA-1140": {"n":"新富","e":"Xinfu","s":"TRA","l":["TRA-WL"],"r":"taoyuan","x":-240,"y":945,"c":"#000050"},
        "TRA-1150": {"n":"北湖","e":"Beihu","s":"TRA","l":["TRA-WL"],"r":"hsinchu","x":-240,"y":970,"c":"#000050","g":"TRANSGRP_beihu"},
        "TRA-1160": {"n":"湖口","e":"Hukou","s":"TRA","l":["TRA-WL"],"r":"hsinchu","x":-240,"y":995,"c":"#000050"},
        "TRA-1170": {"n":"新豐","e":"Xinfeng","s":"TRA","l":["TRA-WL"],"r":"hsinchu","x":-240,"y":1020,"c":"#000050"},
        "TRA-1180": {"n":"竹北","e":"Zhubei","s":"TRA","l":["TRA-WL"],"r":"hsinchu","x":-240,"y":1045,"c":"#000050"},
        "TRA-1190": {"n":"北新竹","e":"North Hsinchu","s":"TRA","l":["TRA-NW","TRA-WL"],"r":"hsinchu","x":-240,"y":1070,"c":"#403090","g":"TRANSGRP_northhsinchu"},
        "TRA-1191": {"n":"千甲","e":"Qianjia","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":-193,"y":1070,"c":"#403090"},
        "TRA-1192": {"n":"新莊","e":"Xinzhuang","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":-150,"y":1070,"c":"#403090"},
        "TRA-1193": {"n":"竹中","e":"Zhuzhong","s":"TRA","l":["TRA-LJ","TRA-NW"],"r":"hsinchu","x":-100,"y":1070,"c":"#403090","g":"TRANSGRP_zhuzhong"},
        "TRA-1194": {"n":"六家","e":"Liujia","s":"TRA","l":["TRA-LJ"],"r":"hsinchu","x":-35.07,"y":1045.03,"c":"#403090","g":"TRANSGRP_liujia"},
        "TRA-1201": {"n":"上員","e":"Shangyuan","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":20,"y":1070,"c":"#403090"},
        "TRA-1202": {"n":"榮華","e":"Ronghua","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":60,"y":1070,"c":"#403090"},
        "TRA-1203": {"n":"竹東","e":"Zhudong","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":100,"y":1070,"c":"#403090"},
        "TRA-1204": {"n":"橫山","e":"Hengshan","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":140,"y":1070,"c":"#403090"},
        "TRA-1205": {"n":"九讚頭","e":"Jiuzantou","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":180,"y":1070,"c":"#403090"},
        "TRA-1206": {"n":"合興","e":"Hexing","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":220,"y":1070,"c":"#403090"},
        "TRA-1207": {"n":"富貴","e":"Fugui","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":260,"y":1070,"c":"#403090"},
        "TRA-1208": {"n":"內灣","e":"Neiwan","s":"TRA","l":["TRA-NW"],"r":"hsinchu","x":300,"y":1070,"c":"#403090","g":"TRANSGRP_neiwan"},
        "TRA-1210": {"n":"新竹","e":"Hsinchu","s":"TRA","l":["TRA-WL"],"r":"hsinchu","x":-240,"y":1100,"c":"#000050","g":"TRANSGRP_hsinchu"},
        "TRA-1220": {"n":"三姓橋","e":"Sanxingqiao","s":"TRA","l":["TRA-WL"],"r":"hsinchu","x":-240,"y":1130,"c":"#000050"},
        "TRA-1230": {"n":"香山","e":"Xiangshan","s":"TRA","l":["TRA-WL"],"r":"hsinchu","x":-240,"y":1155,"c":"#000050"},
        "TRA-1240": {"n":"崎頂","e":"Qiding","s":"TRA","l":["TRA-WL"],"r":"miaoli","x":-240,"y":1180,"c":"#000050"},
        "TRA-1250": {"n":"竹南","e":"Zhunan","s":"TRA","l":["TRA-WL","TRA-WL-C"],"r":"miaoli","x":-240,"y":1210,"c":"#000050","g":"TRANSGRP_zhunan"},
        "TRA-2110": {"n":"談文","e":"Tanwen","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-320,"y":1220,"c":"#2050c0"},
        "TRA-2120": {"n":"大山","e":"Dashan","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-360,"y":1240,"c":"#2050c0"},
        "TRA-2130": {"n":"後龍","e":"Houlong","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-400,"y":1260,"c":"#2050c0","g":"TRANSGRP_houlong"},
        "TRA-2140": {"n":"龍港","e":"Longgang","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-440,"y":1280,"c":"#2050c0"},
        "TRA-2150": {"n":"白沙屯","e":"Baishatun","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-480,"y":1300,"c":"#2050c0"},
        "TRA-2160": {"n":"新埔","e":"Xinpu","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-520,"y":1320,"c":"#2050c0"},
        "TRA-2170": {"n":"通霄","e":"Tongxiao","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-560,"y":1340,"c":"#2050c0","g":"TRANSGRP_tongxiao"},
        "TRA-2180": {"n":"苑裡","e":"Yuanli","s":"TRA","l":["TRA-WL-C"],"r":"miaoli","x":-560,"y":1380,"c":"#2050c0","g":"TRANSGRP_yuanli"},
        "TRA-2190": {"n":"日南","e":"Rinan","s":"TRA","l":["TRA-WL-C"],"r":"taichung","x":-560,"y":1420,"c":"#2050c0"},
        "TRA-2200": {"n":"大甲","e":"Dajia","s":"TRA","l":["TRA-WL-C"],"r":"taichung","x":-560,"y":1470,"c":"#2050c0","g":"TRANSGRP_dajia"},
        "TRA-2210": {"n":"臺中港","e":"Taichung Port","s":"TRA","l":["TRA-WL-C"],"r":"taichung","x":-560,"y":1530,"c":"#2050c0"},
        "TRA-2220": {"n":"清水","e":"Qingshui","s":"TRA","l":["TRA-WL-C"],"r":"taichung","x":-560,"y":1580,"c":"#2050c0","g":"TRANSGRP_qingshui"},
        "TRA-2230": {"n":"沙鹿","e":"Shalu","s":"TRA","l":["TRA-WL-C"],"r":"taichung","x":-560,"y":1630,"c":"#2050c0","g":"TRANSGRP_shalu"},
        "TRA-2240": {"n":"龍井","e":"Longjing","s":"TRA","l":["TRA-WL-C"],"r":"taichung","x":-560,"y":1690,"c":"#2050c0"},
        "TRA-2250": {"n":"大肚","e":"Dadu","s":"TRA","l":["TRA-WL-C"],"r":"taichung","x":-560,"y":1790,"c":"#2050c0"},
        "TRA-2260": {"n":"追分","e":"Zhuifen","s":"TRA","l":["TRA-CZ","TRA-WL-C"],"r":"taichung","x":-320,"y":1850,"c":"#2050c0","g":"TRANSGRP_zhuifen"},
        "TRA-3140": {"n":"造橋","e":"Zaoqiao","s":"TRA","l":["TRA-WL"],"r":"miaoli","x":-160,"y":1220,"c":"#000050"},
        "TRA-3150": {"n":"豐富","e":"Fengfu","s":"TRA","l":["TRA-WL"],"r":"miaoli","x":-70,"y":1250,"c":"#000050","g":"TRANSGRP_fengfu"},
        "TRA-3160": {"n":"苗栗","e":"Miaoli","s":"TRA","l":["TRA-WL"],"r":"miaoli","x":-30,"y":1280,"c":"#000050","g":"TRANSGRP_miaoli"},
        "TRA-3170": {"n":"南勢","e":"Nanshi","s":"TRA","l":["TRA-WL"],"r":"miaoli","x":10,"y":1305,"c":"#000050"},
        "TRA-3180": {"n":"銅鑼","e":"Tongluo","s":"TRA","l":["TRA-WL"],"r":"miaoli","x":40,"y":1330,"c":"#000050"},
        "TRA-3190": {"n":"三義","e":"Sanyi","s":"TRA","l":["TRA-WL"],"r":"miaoli","x":70,"y":1355,"c":"#000050"},
        "TRA-3210": {"n":"泰安","e":"Tai'an","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1380,"c":"#000050"},
        "TRA-3220": {"n":"后里","e":"Houli","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1410,"c":"#000050","g":"TRANSGRP_houli"},
        "TRA-3230": {"n":"豐原","e":"Fengyuan","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1440,"c":"#000050","g":"TRANSGRP_fengyuan"},
        "TRA-3240": {"n":"栗林","e":"Lilin","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1470,"c":"#000050"},
        "TRA-3250": {"n":"潭子","e":"Tanzi","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1495,"c":"#000050"},
        "TRA-3260": {"n":"頭家厝","e":"Toujiacuo","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1520,"c":"#000050"},
        "TRA-3270": {"n":"松竹","e":"Songzhu","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1545,"c":"#000050","g":"TRANSGRP_songzhu"},
        "TRA-3280": {"n":"太原","e":"Taiyuan","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1570,"c":"#000050"},
        "TRA-3290": {"n":"精武","e":"Jingwu","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1595,"c":"#000050"},
        "TRA-3300": {"n":"臺中","e":"Taichung","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1630,"c":"#000050","g":"TRANSGRP_taichung"},
        "TRA-3310": {"n":"五權","e":"Wuquan","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1665,"c":"#000050"},
        "TRA-3320": {"n":"大慶","e":"Daqing","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1700,"c":"#000050","g":"TRANSGRP_daqing"},
        "TRA-3330": {"n":"烏日","e":"Wuri","s":"TRA","l":["TRA-WL"],"r":"taichung","x":100,"y":1780,"c":"#000050","g":"TRANSGRP_wuri"},
        "TRA-3340": {"n":"新烏日","e":"Xinwuri","s":"TRA","l":["TRA-WL"],"r":"taichung","x":-113.76,"y":1837.58,"c":"#000050","g":"TRANSGRP_xinwuri"},
        "TRA-3350": {"n":"成功","e":"Chenggong","s":"TRA","l":["TRA-CZ","TRA-WL"],"r":"taichung","x":-180,"y":1850,"c":"#2050c0","g":"TRANSGRP_chenggong"},
        "TRA-3360": {"n":"彰化","e":"Changhua","s":"TRA","l":["TRA-WL","TRA-WL-C"],"r":"changhua","x":-240,"y":1880,"c":"#000050","g":"TRANSGRP_changhua1"},
        "TRA-3370": {"n":"花壇","e":"Huatan","s":"TRA","l":["TRA-WL"],"r":"changhua","x":-215,"y":1907,"c":"#000050"},
        "TRA-3380": {"n":"大村","e":"Dacun","s":"TRA","l":["TRA-WL"],"r":"changhua","x":-190,"y":1930,"c":"#000050"},
        "TRA-3390": {"n":"員林","e":"Yuanlin","s":"TRA","l":["TRA-WL"],"r":"changhua","x":-165,"y":1955,"c":"#000050","g":"TRANSGRP_yuanlin"},
        "TRA-3400": {"n":"永靖","e":"Yongjing","s":"TRA","l":["TRA-WL"],"r":"changhua","x":-165,"y":1980,"c":"#000050"},
        "TRA-3410": {"n":"社頭","e":"Shetou","s":"TRA","l":["TRA-WL"],"r":"changhua","x":-165,"y":2003,"c":"#000050"},
        "TRA-3420": {"n":"田中","e":"Tianzhong","s":"TRA","l":["TRA-WL"],"r":"changhua","x":-165,"y":2028,"c":"#000050","g":"TRANSGRP_tianzhong"},
        "TRA-3430": {"n":"二水","e":"Ershui","s":"TRA","l":["TRA-JJ","TRA-WL"],"r":"changhua","x":-165,"y":2053,"c":"#22a050","g":"TRANSGRP_ershui"},
        "TRA-3431": {"n":"源泉","e":"Yuanquan","s":"TRA","l":["TRA-JJ"],"r":"changhua","x":-115,"y":2053,"c":"#22a050"},
        "TRA-3432": {"n":"濁水","e":"Zhuoshui","s":"TRA","l":["TRA-JJ"],"r":"changhua","x":-65,"y":2053,"c":"#22a050"},
        "TRA-3433": {"n":"龍泉","e":"Longquan","s":"TRA","l":["TRA-JJ"],"r":"changhua","x":-15,"y":2053,"c":"#22a050"},
        "TRA-3434": {"n":"集集","e":"Jiji","s":"TRA","l":["TRA-JJ"],"r":"changhua","x":35,"y":2053,"c":"#22a050"},
        "TRA-3435": {"n":"水里","e":"Shuili","s":"TRA","l":["TRA-JJ"],"r":"changhua","x":85,"y":2053,"c":"#22a050"},
        "TRA-3436": {"n":"車埕","e":"Checheng","s":"TRA","l":["TRA-JJ"],"r":"changhua","x":135,"y":2053,"c":"#22a050","g":"TRANSGRP_checheng"},
        "TRA-3450": {"n":"林內","e":"Linnei","s":"TRA","l":["TRA-WL"],"r":"yunlin","x":-170,"y":2080,"c":"#000050"},
        "TRA-3460": {"n":"石榴","e":"Shiliu","s":"TRA","l":["TRA-WL"],"r":"yunlin","x":-170,"y":2103,"c":"#000050"},
        "TRA-3470": {"n":"斗六","e":"Douliu","s":"TRA","l":["TRA-WL"],"r":"yunlin","x":-170,"y":2128,"c":"#000050","g":"TRANSGRP_douliu"},
        "TRA-3480": {"n":"斗南","e":"Dounan","s":"TRA","l":["TRA-WL"],"r":"yunlin","x":-170,"y":2154,"c":"#000050","g":"TRANSGRP_dounan"},
        "TRA-3490": {"n":"石龜","e":"Shigui","s":"TRA","l":["TRA-WL"],"r":"yunlin","x":-170,"y":2177,"c":"#000050"},
        "TRA-4050": {"n":"大林","e":"Dalin","s":"TRA","l":["TRA-WL"],"r":"chiayi","x":-170,"y":2200,"c":"#000050"},
        "TRA-4060": {"n":"民雄","e":"Minxiong","s":"TRA","l":["TRA-WL"],"r":"chiayi","x":-170,"y":2225,"c":"#000050"},
        "TRA-4070": {"n":"嘉北","e":"Jiabei","s":"TRA","l":["TRA-WL"],"r":"chiayi","x":-170,"y":2250,"c":"#000050"},
        "TRA-4080": {"n":"嘉義","e":"Chiayi","s":"TRA","l":["TRA-WL"],"r":"chiayi","x":-170,"y":2280,"c":"#000050","g":"TRANSGRP_chiayi1"},
        "TRA-4090": {"n":"水上","e":"Shuishang","s":"TRA","l":["TRA-WL"],"r":"chiayi","x":-170,"y":2310,"c":"#000050"},
        "TRA-4100": {"n":"南靖","e":"Nanjing","s":"TRA","l":["TRA-WL"],"r":"chiayi","x":-170,"y":2333,"c":"#000050"},
        "TRA-4110": {"n":"後壁","e":"Houbi","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-190,"y":2355,"c":"#000050"},
        "TRA-4120": {"n":"新營","e":"Xinying","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-200,"y":2380,"c":"#000050","g":"TRANSGRP_xinying"},
        "TRA-4130": {"n":"柳營","e":"Liuying","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-210,"y":2405,"c":"#000050"},
        "TRA-4140": {"n":"林鳳營","e":"Linfengying","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-220,"y":2430,"c":"#000050"},
        "TRA-4150": {"n":"隆田","e":"Longtian","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-230,"y":2455,"c":"#000050"},
        "TRA-4160": {"n":"拔林","e":"Balin","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-280,"y":2455,"c":"#000050"},
        "TRA-4170": {"n":"善化","e":"Shanhua","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-330,"y":2455,"c":"#000050","g":"TRANSGRP_shanhua"},
        "TRA-4180": {"n":"南科","e":"Nanke","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-350,"y":2478,"c":"#000050"},
        "TRA-4190": {"n":"新市","e":"Xinshi","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-360,"y":2502,"c":"#000050"},
        "TRA-4200": {"n":"永康","e":"Yongkang","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-370,"y":2526,"c":"#000050","g":"TRANSGRP_yongkang"},
        "TRA-4210": {"n":"大橋","e":"Daqiao","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-380,"y":2550,"c":"#000050"},
        "TRA-4220": {"n":"臺南","e":"Tainan","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-400,"y":2580,"c":"#000050","g":"TRANSGRP_tainan"},
        "TRA-4250": {"n":"保安","e":"Bao'an","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-400,"y":2608,"c":"#000050"},
        "TRA-4260": {"n":"仁德","e":"Rende","s":"TRA","l":["TRA-WL"],"r":"tainan","x":-400,"y":2632,"c":"#000050"},
        "TRA-4270": {"n":"中洲","e":"Zhongzhou","s":"TRA","l":["TRA-SH","TRA-WL"],"r":"tainan","x":-400,"y":2656,"c":"#124060","g":"TRANSGRP_zhongzhou"},
        "TRA-4271": {"n":"長榮大學","e":"Chang Jung Christian University","s":"TRA","l":["TRA-SH"],"r":"tainan","x":-290,"y":2656,"c":"#124060"},
        "TRA-4272": {"n":"沙崙","e":"Shalun","s":"TRA","l":["TRA-SH"],"r":"tainan","x":-174,"y":2655.11,"c":"#124060","g":"TRANSGRP_shalun"},
        "TRA-4290": {"n":"大湖","e":"Dahu","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":2680,"c":"#000050"},
        "TRA-4300": {"n":"路竹","e":"Luzhu","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":2705,"c":"#000050"},
        "TRA-4310": {"n":"岡山","e":"Gangshan","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":2730,"c":"#000050","g":"TRANSGRP_gangshan"},
        "TRA-4320": {"n":"橋頭","e":"Qiaotou","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":2775,"c":"#000050","g":"TRANSGRP_qiaotou"},
        "TRA-4330": {"n":"楠梓","e":"Nanzi","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":2830,"c":"#000050"},
        "TRA-4340": {"n":"新左營","e":"Xinzuoying","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":2870,"c":"#000050","g":"TRANSGRP_xinzuoying"},
        "TRA-4350": {"n":"左營","e":"Zuoying","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":2930,"c":"#000050"},
        "TRA-4360": {"n":"內惟","e":"Neiwei","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-520,"y":2960,"c":"#000050"},
        "TRA-4370": {"n":"美術館","e":"Museum of Fine Arts","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-495,"y":2990,"c":"#000050","g":"TRANSGRP_meishuguan"},
        "TRA-4380": {"n":"鼓山","e":"Gushan","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-495,"y":3034,"c":"#000050","g":"TRANSGRP_gushan"},
        "TRA-4390": {"n":"三塊厝","e":"Sankuaicuo","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-400,"y":3034,"c":"#000050"},
        "TRA-4400": {"n":"高雄","e":"Kaohsiung","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-315,"y":3080,"c":"#000050","g":"TRANSGRP_kaohsiung"},
        "TRA-4410": {"n":"民族","e":"Minzu","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-180,"y":3080,"c":"#000050"},
        "TRA-4420": {"n":"科工館","e":"Science And Technology Museum","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-114,"y":3080,"c":"#000050","g":"TRANSGRP_kegongguan"},
        "TRA-4430": {"n":"正義","e":"Zhengyi","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":-30,"y":3080,"c":"#000050"},
        "TRA-4440": {"n":"鳳山","e":"Fongshan","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":90,"y":3080,"c":"#000050","g":"TRANSGRP_fongshan"},
        "TRA-4450": {"n":"後庄","e":"Houzhuang","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":190,"y":3080,"c":"#000050"},
        "TRA-4460": {"n":"九曲堂","e":"Jiuqutang","s":"TRA","l":["TRA-WL"],"r":"kaohsiung","x":250,"y":3080,"c":"#000050","g":"TRANSGRP_jiuqutang"},
        "TRA-4470": {"n":"六塊厝","e":"Liukuaicuo","s":"TRA","l":["TRA-WL"],"r":"pingtung","x":300,"y":3080,"c":"#000050"},
        "TRA-5000": {"n":"屏東","e":"Pingtung","s":"TRA","l":["TRA-SL","TRA-WL"],"r":"pingtung","x":350,"y":3080,"c":"#7d3810","g":"TRANSGRP_pingtung"},
        "TRA-5010": {"n":"歸來","e":"Guilai","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":400,"y":3080,"c":"#7d3810"},
        "TRA-5020": {"n":"麟洛","e":"Linluo","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":450,"y":3080,"c":"#7d3810"},
        "TRA-5030": {"n":"西勢","e":"Xishi","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":500,"y":3080,"c":"#7d3810"},
        "TRA-5040": {"n":"竹田","e":"Zhutian","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":550,"y":3080,"c":"#7d3810"},
        "TRA-5050": {"n":"潮州","e":"Chaozhou","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3080,"c":"#7d3810","g":"TRANSGRP_chaozhou"},
        "TRA-5060": {"n":"崁頂","e":"Kanding","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3110,"c":"#7d3810"},
        "TRA-5070": {"n":"南州","e":"Nanzhou","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3140,"c":"#7d3810","g":"TRANSGRP_nanzhou"},
        "TRA-5080": {"n":"鎮安","e":"Zhen'an","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3170,"c":"#7d3810"},
        "TRA-5090": {"n":"林邊","e":"Linbian","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3200,"c":"#7d3810","g":"TRANSGRP_linbian"},
        "TRA-5100": {"n":"佳冬","e":"Jiadong","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3230,"c":"#7d3810"},
        "TRA-5110": {"n":"東海","e":"Donghai","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3260,"c":"#7d3810"},
        "TRA-5120": {"n":"枋寮","e":"Fangliao","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":600,"y":3290,"c":"#7d3810","g":"TRANSGRP_fangliao"},
        "TRA-5130": {"n":"加祿","e":"Jialu","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":670,"y":3290,"c":"#7d3810"},
        "TRA-5140": {"n":"內獅","e":"Neishi","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":750,"y":3290,"c":"#7d3810"},
        "TRA-5160": {"n":"枋山","e":"Fangshan","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":810,"y":3290,"c":"#7d3810"},
        "TRA-5170": {"n":"枋野","e":"Fangye","s":"TRA","l":["TRA-SL"],"r":"pingtung","x":812.24,"y":3036.6,"c":"#7d3810"},
        "TRA-5190": {"n":"大武","e":"Dawu","s":"TRA","l":["TRA-SL"],"r":"taitung","x":820,"y":2160,"c":"#7d3810","g":"TRANSGRP_dawu"},
        "TRA-5200": {"n":"瀧溪","e":"Longxi","s":"TRA","l":["TRA-SL"],"r":"taitung","x":820,"y":2120,"c":"#7d3810"},
        "TRA-5210": {"n":"金崙","e":"Jinlun","s":"TRA","l":["TRA-SL"],"r":"taitung","x":820,"y":2080,"c":"#7d3810","g":"TRANSGRP_jinlun"},
        "TRA-5220": {"n":"太麻里","e":"Taimali","s":"TRA","l":["TRA-SL"],"r":"taitung","x":820,"y":2040,"c":"#7d3810","g":"TRANSGRP_taimali"},
        "TRA-5230": {"n":"知本","e":"Zhiben","s":"TRA","l":["TRA-SL"],"r":"taitung","x":820,"y":2000,"c":"#7d3810","g":"TRANSGRP_zhiben"},
        "TRA-5240": {"n":"康樂","e":"Kangle","s":"TRA","l":["TRA-SL"],"r":"taitung","x":820,"y":1960,"c":"#7d3810"},
        "TRA-6000": {"n":"臺東","e":"Taitung","s":"TRA","l":["TRA-EL","TRA-SL"],"r":"taitung","x":820,"y":1920,"c":"#500000","g":"TRANSGRP_taitung"},
        "TRA-6010": {"n":"山里","e":"Shanli","s":"TRA","l":["TRA-EL"],"r":"taitung","x":820,"y":1890,"c":"#500000"},
        "TRA-6020": {"n":"鹿野","e":"Luye","s":"TRA","l":["TRA-EL"],"r":"taitung","x":820,"y":1860,"c":"#500000","g":"TRANSGRP_luye"},
        "TRA-6030": {"n":"瑞源","e":"Ruiyuan","s":"TRA","l":["TRA-EL"],"r":"taitung","x":820,"y":1830,"c":"#500000"},
        "TRA-6040": {"n":"瑞和","e":"Ruihe","s":"TRA","l":["TRA-EL"],"r":"taitung","x":820,"y":1800,"c":"#500000"},
        "TRA-6050": {"n":"關山","e":"Guanshan","s":"TRA","l":["TRA-EL"],"r":"taitung","x":820,"y":1770,"c":"#500000","g":"TRANSGRP_guanshan"},
        "TRA-6060": {"n":"海端","e":"Haiduan","s":"TRA","l":["TRA-EL"],"r":"taitung","x":820,"y":1740,"c":"#500000"},
        "TRA-6070": {"n":"池上","e":"Chishang","s":"TRA","l":["TRA-EL"],"r":"taitung","x":820,"y":1710,"c":"#500000","g":"TRANSGRP_chishang"},
        "TRA-6080": {"n":"富里","e":"Fuli","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1420,"c":"#500000","g":"TRANSGRP_fuli"},
        "TRA-6090": {"n":"東竹","e":"Dongzhu","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1390,"c":"#500000"},
        "TRA-6100": {"n":"東里","e":"Dongli","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1360,"c":"#500000"},
        "TRA-6110": {"n":"玉里","e":"Yuli","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1330,"c":"#500000","g":"TRANSGRP_yuli"},
        "TRA-6120": {"n":"三民","e":"Sanmin","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1300,"c":"#500000"},
        "TRA-6130": {"n":"瑞穗","e":"Ruisui","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1270,"c":"#500000","g":"TRANSGRP_ruisui"},
        "TRA-6140": {"n":"富源","e":"Fuyuan","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1240,"c":"#500000"},
        "TRA-6150": {"n":"大富","e":"Dafu","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1210,"c":"#500000"},
        "TRA-6160": {"n":"光復","e":"Guangfu","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1180,"c":"#500000","g":"TRANSGRP_guangfu"},
        "TRA-6170": {"n":"萬榮","e":"Wanrong","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1150,"c":"#500000"},
        "TRA-6180": {"n":"鳳林","e":"Fenglin","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1120,"c":"#500000","g":"TRANSGRP_fenglin"},
        "TRA-6190": {"n":"南平","e":"Nanping","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1090,"c":"#500000"},
        "TRA-6200": {"n":"林榮新光","e":"Linrong Shin Kong","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1060,"c":"#500000"},
        "TRA-6210": {"n":"豐田","e":"Fengtian","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1030,"c":"#500000"},
        "TRA-6220": {"n":"壽豐","e":"Shoufeng","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":1000,"c":"#500000","g":"TRANSGRP_shoufeng"},
        "TRA-6230": {"n":"平和","e":"Pinghe","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":970,"c":"#500000"},
        "TRA-6240": {"n":"志學","e":"Zhixue","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":940,"c":"#500000","g":"TRANSGRP_zhixue"},
        "TRA-6250": {"n":"吉安","e":"Ji'an","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":910,"c":"#500000","g":"TRANSGRP_jian"},
        "TRA-7000": {"n":"花蓮","e":"Hualien","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":870,"c":"#500000","g":"TRANSGRP_hualien"},
        "TRA-7010": {"n":"北埔","e":"Beipu","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":840,"c":"#500000"},
        "TRA-7020": {"n":"景美","e":"Jingmei","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":810,"c":"#500000"},
        "TRA-7030": {"n":"新城","e":"Xincheng","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":780,"c":"#500000","g":"TRANSGRP_xincheng"},
        "TRA-7040": {"n":"崇德","e":"Chongde","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":750,"c":"#500000"},
        "TRA-7050": {"n":"和仁","e":"Heren","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":720,"c":"#500000"},
        "TRA-7060": {"n":"和平","e":"Heping","s":"TRA","l":["TRA-EL"],"r":"hualien","x":820,"y":690,"c":"#500000"},
        "TRA-7070": {"n":"漢本","e":"Hanben","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":660,"c":"#500000"},
        "TRA-7080": {"n":"武塔","e":"Wuta","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":630,"c":"#500000"},
        "TRA-7090": {"n":"南澳","e":"Nan'ao","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":600,"c":"#500000","g":"TRANSGRP_nanao"},
        "TRA-7100": {"n":"東澳","e":"Dong'ao","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":570,"c":"#500000"},
        "TRA-7110": {"n":"永樂","e":"Yongle","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":540,"c":"#500000"},
        "TRA-7120": {"n":"蘇澳","e":"Su'ao","s":"TRA","l":["TRA-SU"],"r":"yilan","x":890,"y":470,"c":"#500000","g":"TRANSGRP_suao"},
        "TRA-7130": {"n":"蘇澳新","e":"Su'aoxin","s":"TRA","l":["TRA-EL","TRA-SU"],"r":"yilan","x":820,"y":470,"c":"#500000","g":"TRANSGRP_suaoxin"},
        "TRA-7140": {"n":"新馬","e":"Xinma","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":440,"c":"#500000"},
        "TRA-7150": {"n":"冬山","e":"Dongshan","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":410,"c":"#500000"},
        "TRA-7160": {"n":"羅東","e":"Luodong","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":380,"c":"#500000","g":"TRANSGRP_luodong"},
        "TRA-7170": {"n":"中里","e":"Zhongli_Yilan","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":350,"c":"#500000"},
        "TRA-7180": {"n":"二結","e":"Erjie","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":320,"c":"#500000"},
        "TRA-7190": {"n":"宜蘭","e":"Yilan","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":290,"c":"#500000","g":"TRANSGRP_yilan"},
        "TRA-7200": {"n":"四城","e":"Sicheng","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":260,"c":"#500000"},
        "TRA-7210": {"n":"礁溪","e":"Jiaoxi","s":"TRA","l":["TRA-EL"],"r":"yilan","x":820,"y":230,"c":"#500000","g":"TRANSGRP_jiaoxi"},
        "TRA-7220": {"n":"頂埔","e":"Dingpu","s":"TRA","l":["TRA-EL"],"r":"yilan","x":830,"y":200,"c":"#500000"},
        "TRA-7230": {"n":"頭城","e":"Toucheng","s":"TRA","l":["TRA-EL"],"r":"yilan","x":840,"y":170,"c":"#500000","g":"TRANSGRP_toucheng"},
        "TRA-7240": {"n":"外澳","e":"Wai'ao","s":"TRA","l":["TRA-EL"],"r":"yilan","x":850,"y":140,"c":"#500000"},
        "TRA-7250": {"n":"龜山","e":"Guishan","s":"TRA","l":["TRA-EL"],"r":"yilan","x":860,"y":110,"c":"#500000"},
        "TRA-7260": {"n":"大溪","e":"Daxi","s":"TRA","l":["TRA-EL"],"r":"yilan","x":870,"y":80,"c":"#500000"},
        "TRA-7270": {"n":"大里","e":"Dali","s":"TRA","l":["TRA-EL"],"r":"yilan","x":880,"y":50,"c":"#500000"},
        "TRA-7280": {"n":"石城","e":"Shicheng","s":"TRA","l":["TRA-EL"],"r":"yilan","x":890,"y":20,"c":"#500000"},
        "TRA-7290": {"n":"福隆","e":"Fulong","s":"TRA","l":["TRA-EL"],"r":"taipei","x":890,"y":-60,"c":"#500000"},
        "TRA-7300": {"n":"貢寮","e":"Gongliao","s":"TRA","l":["TRA-EL"],"r":"taipei","x":890,"y":-90,"c":"#500000"},
        "TRA-7310": {"n":"雙溪","e":"Shuangxi","s":"TRA","l":["TRA-EL"],"r":"taipei","x":890,"y":-120,"c":"#500000"},
        "TRA-7320": {"n":"牡丹","e":"Mudan","s":"TRA","l":["TRA-EL"],"r":"taipei","x":890,"y":-150,"c":"#500000"},
        "TRA-7330": {"n":"三貂嶺","e":"Sandiaoling","s":"TRA","l":["TRA-EL","TRA-PX"],"r":"taipei","x":890,"y":-180,"c":"#500000","g":"TRANSGRP_sandiaoling"},
        "TRA-7331": {"n":"大華","e":"Dahua","s":"TRA","l":["TRA-PX"],"r":"taipei","x":830,"y":-180,"c":"#003030"},
        "TRA-7332": {"n":"十分","e":"Shifen","s":"TRA","l":["TRA-PX"],"r":"taipei","x":830,"y":-150,"c":"#003030"},
        "TRA-7333": {"n":"望古","e":"Wanggu","s":"TRA","l":["TRA-PX"],"r":"taipei","x":830,"y":-120,"c":"#003030"},
        "TRA-7334": {"n":"嶺腳","e":"Lingjiao","s":"TRA","l":["TRA-PX"],"r":"taipei","x":830,"y":-90,"c":"#003030"},
        "TRA-7335": {"n":"平溪","e":"Pingxi","s":"TRA","l":["TRA-PX"],"r":"taipei","x":830,"y":-60,"c":"#003030"},
        "TRA-7336": {"n":"菁桐","e":"Jingtong","s":"TRA","l":["TRA-PX"],"r":"taipei","x":830,"y":-30,"c":"#003030","g":"TRANSGRP_jingtong"},
        "TRA-7350": {"n":"猴硐","e":"Houtong","s":"TRA","l":["TRA-EL"],"r":"taipei","x":890,"y":-240,"c":"#500000"},
        "TRA-7360": {"n":"瑞芳","e":"Ruifang","s":"TRA","l":["TRA-EL","TRA-SA"],"r":"taipei","x":830,"y":-240,"c":"#500000","g":"TRANSGRP_ruifang"},
        "TRA-7361": {"n":"海科館","e":"Haikeguan","s":"TRA","l":["TRA-SA"],"r":"taipei","x":830,"y":-320,"c":"#003030"},
        "TRA-7362": {"n":"八斗子","e":"Badouzi","s":"TRA","l":["TRA-SA"],"r":"taipei","x":880,"y":-320,"c":"#003030","g":"TRANSGRP_badouzi"},
        "TRA-7380": {"n":"四腳亭","e":"Sijiaoting","s":"TRA","l":["TRA-EL"],"r":"taipei","x":770,"y":-240,"c":"#500000"},
        "TRA-7390": {"n":"暖暖","e":"Nuannuan","s":"TRA","l":["TRA-EL"],"r":"taipei","x":710,"y":-240,"c":"#500000"},
        "THSR-0990": {"n":"南港","e":"Nangang","s":"THSR","l":["THSR-HSRL"],"r":"taipei","x":550,"y":5,"c":"#ef5210","g":"TRANSGRP_nangang"},
        "THSR-1000": {"n":"台北","e":"Taipei","s":"THSR","l":["THSR-HSRL"],"r":"taipei","x":10,"y":28,"c":"#ef5210","g":"TRANSGRP_taipei"},
        "THSR-1010": {"n":"板橋","e":"Banqiao","s":"THSR","l":["THSR-HSRL"],"r":"taipei","x":-300,"y":114,"c":"#ef5210","g":"TRANSGRP_banqiao"},
        "THSR-1020": {"n":"桃園","e":"Taoyuan","s":"THSR","l":["THSR-HSRL"],"r":"taoyuan","x":-530,"y":780,"c":"#ef5210","g":"TRANSGRP_taoyuan2"},
        "THSR-1030": {"n":"新竹","e":"Hsinchu","s":"THSR","l":["THSR-HSRL"],"r":"hsinchu","x":-22,"y":1040,"c":"#ef5210","g":"TRANSGRP_liujia"},
        "THSR-1035": {"n":"苗栗","e":"Miaoli","s":"THSR","l":["THSR-HSRL"],"r":"miaoli","x":-66.88,"y":1236.35,"c":"#ef5210","g":"TRANSGRP_fengfu"},
        "THSR-1040": {"n":"台中","e":"Taichung","s":"THSR","l":["THSR-HSRL"],"r":"taichung","x":-100,"y":1835,"c":"#ef5210","g":"TRANSGRP_xinwuri"},
        "THSR-1043": {"n":"彰化","e":"Changhua","s":"THSR","l":["THSR-HSRL"],"r":"changhua","x":-368,"y":2015,"c":"#ef5210","g":"TRANSGRP_changhua2"},
        "THSR-1047": {"n":"雲林","e":"Yunlin","s":"THSR","l":["THSR-HSRL"],"r":"yunlin","x":-370,"y":2130,"c":"#ef5210","g":"TRANSGRP_yunlin"},
        "THSR-1050": {"n":"嘉義","e":"Chiayi","s":"THSR","l":["THSR-HSRL"],"r":"chiayi","x":-370,"y":2280,"c":"#ef5210","g":"TRANSGRP_chiayi2"},
        "THSR-1060": {"n":"台南","e":"Tainan","s":"THSR","l":["THSR-HSRL"],"r":"tainan","x":-160,"y":2655,"c":"#ef5210","g":"TRANSGRP_shalun"},
        "THSR-1070": {"n":"左營","e":"Zuoying","s":"THSR","l":["THSR-HSRL"],"r":"kaohsiung","x":-400,"y":2884,"c":"#ef5210","g":"TRANSGRP_xinzuoying"},
        "TRTC-BL01": {"n":"頂埔","e":"Dingpu","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-300,"y":316,"c":"#0a59ae","g":"TRANSGRP_dingpu"},
        "TRTC-BL02": {"n":"永寧","e":"Yongning","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-300,"y":300,"c":"#0a59ae"},
        "TRTC-BL03": {"n":"土城","e":"Tucheng","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-300,"y":275,"c":"#0a59ae"},
        "TRTC-BL04": {"n":"海山","e":"Haishan","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-300,"y":250,"c":"#0a59ae"},
        "TRTC-BL05": {"n":"亞東醫院","e":"Far Eastern Hospital","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-300,"y":225,"c":"#0a59ae"},
        "TRTC-BL06": {"n":"府中","e":"Fuzhong","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-300,"y":200,"c":"#0a59ae"},
        "TRTC-BL07": {"n":"板橋","e":"Banqiao","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-300,"y":138,"c":"#0a59ae","g":"TRANSGRP_banqiao"},
        "TRTC-BL08": {"n":"新埔","e":"Xinpu","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-220,"y":138,"c":"#0a59ae","g":"TRANSGRP_xinpu"},
        "TRTC-BL09": {"n":"江子翠","e":"Jiangzicui","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-175,"y":138,"c":"#0a59ae"},
        "TRTC-BL10": {"n":"龍山寺","e":"Longshan Temple","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-120,"y":138,"c":"#0a59ae","g":"TRANSGRP_wanhua"},
        "TRTC-BL11": {"n":"西門","e":"Ximen","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":-80,"y":100,"c":"#0a59ae","g":"TRANSGRP_ximen"},
        "TRTC-BL12": {"n":"台北車站","e":"Taipei Main Station","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":10,"y":60,"c":"#0a59ae","g":"TRANSGRP_taipei"},
        "TRTC-BL13": {"n":"善導寺","e":"Shandao Temple","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":90,"y":60,"c":"#0a59ae"},
        "TRTC-BL14": {"n":"忠孝新生","e":"Zhongxiao Xinsheng","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":150,"y":60,"c":"#0a59ae","g":"TRANSGRP_zhongxiaoxinsheng"},
        "TRTC-BL15": {"n":"忠孝復興","e":"Zhongxiao Fuxing","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":220,"y":60,"c":"#0a59ae","g":"TRANSGRP_zhongxiaofuxing"},
        "TRTC-BL16": {"n":"忠孝敦化","e":"Zhongxiao Dunhua","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":282,"y":60,"c":"#0a59ae"},
        "TRTC-BL17": {"n":"國父紀念館","e":"Sun Yat-Sen Memorial Hall","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":346,"y":60,"c":"#0a59ae"},
        "TRTC-BL18": {"n":"市政府","e":"Taipei City Hall","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":407,"y":60,"c":"#0a59ae"},
        "TRTC-BL19": {"n":"永春","e":"Yongchun","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":450,"y":60,"c":"#0a59ae"},
        "TRTC-BL20": {"n":"後山埤","e":"Houshanpi","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":500,"y":60,"c":"#0a59ae"},
        "TRTC-BL21": {"n":"昆陽","e":"Kunyang","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":550,"y":60,"c":"#0a59ae"},
        "TRTC-BL22": {"n":"南港","e":"Nangang","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":550,"y":30,"c":"#0a59ae","g":"TRANSGRP_nangang"},
        "TRTC-BL23": {"n":"南港展覽館","e":"Taipei Nangang Exhibition Center","s":"TRTC","l":["TRTC-BL"],"r":"taipei","x":550,"y":-60,"c":"#0a59ae","g":"TRANSGRP_nangangzhanlanguan"},
        "TRTC-BR01": {"n":"動物園","e":"Taipei Zoo","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":400,"y":420,"c":"#b57a25"},
        "TRTC-BR02": {"n":"木柵","e":"Muzha","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":360,"y":380,"c":"#b57a25"},
        "TRTC-BR03": {"n":"萬芳社區","e":"Wanfang Community","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":340,"y":350,"c":"#b57a25"},
        "TRTC-BR04": {"n":"萬芳醫院","e":"Wanfang Hospital","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":340,"y":320,"c":"#b57a25"},
        "TRTC-BR05": {"n":"辛亥","e":"Xinhai","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":340,"y":290,"c":"#b57a25"},
        "TRTC-BR06": {"n":"麟光","e":"Linguang","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":340,"y":260,"c":"#b57a25"},
        "TRTC-BR07": {"n":"六張犁","e":"Liuzhangli","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":280,"y":230,"c":"#b57a25"},
        "TRTC-BR08": {"n":"科技大樓","e":"Technology Building","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":220,"y":200,"c":"#b57a25"},
        "TRTC-BR09": {"n":"大安","e":"Daan","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":220,"y":150,"c":"#b57a25","g":"TRANSGRP_daan"},
        "TRTC-BR10": {"n":"忠孝復興","e":"Zhongxiao Fuxing","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":220,"y":74,"c":"#b57a25","g":"TRANSGRP_zhongxiaofuxing"},
        "TRTC-BR11": {"n":"南京復興","e":"Nanjing Fuxing","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":220,"y":-55,"c":"#b57a25","g":"TRANSGRP_nanjingfuxing"},
        "TRTC-BR12": {"n":"中山國中","e":"Zhongshan Junior High School","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":220,"y":-105,"c":"#b57a25"},
        "TRTC-BR13": {"n":"松山機場","e":"Songshan Airport","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":220,"y":-130,"c":"#b57a25"},
        "TRTC-BR14": {"n":"大直","e":"Dazhi","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":220,"y":-185,"c":"#b57a25"},
        "TRTC-BR15": {"n":"劍南路","e":"Jiannan Rd.","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":300,"y":-235,"c":"#b57a25"},
        "TRTC-BR16": {"n":"西湖","e":"Xihu","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":350,"y":-235,"c":"#b57a25"},
        "TRTC-BR17": {"n":"港墘","e":"Gangqian","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":400,"y":-235,"c":"#b57a25"},
        "TRTC-BR18": {"n":"文德","e":"Wende","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":450,"y":-235,"c":"#b57a25"},
        "TRTC-BR19": {"n":"內湖","e":"Neihu","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":500,"y":-235,"c":"#b57a25"},
        "TRTC-BR20": {"n":"大湖公園","e":"Dahu Park","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":520,"y":-205,"c":"#b57a25"},
        "TRTC-BR21": {"n":"葫洲","e":"Huzhou","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":540,"y":-175,"c":"#b57a25"},
        "TRTC-BR22": {"n":"東湖","e":"Donghu","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":550,"y":-150,"c":"#b57a25"},
        "TRTC-BR23": {"n":"南港軟體園區","e":"Nangang Software Park","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":550,"y":-85,"c":"#b57a25"},
        "TRTC-BR24": {"n":"南港展覽館","e":"Taipei Nangang Exhibition Center","s":"TRTC","l":["TRTC-BR"],"r":"taipei","x":550,"y":-74,"c":"#b57a25","g":"TRANSGRP_nangangzhanlanguan"},
        "TRTC-G01": {"n":"新店","e":"Xindian","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":140,"y":460,"c":"#107547"},
        "TRTC-G02": {"n":"新店區公所","e":"Xindian District Office","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":140,"y":430,"c":"#107547"},
        "TRTC-G03": {"n":"七張","e":"Qizhang","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":140,"y":400,"c":"#107547","g":"TRANSGRP_qizhang"},
        "TRTC-G04": {"n":"大坪林","e":"Dapinglin","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":140,"y":370,"c":"#107547","g":"TRANSGRP_dapinglin"},
        "TRTC-G05": {"n":"景美","e":"Jingmei","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":140,"y":335,"c":"#107547"},
        "TRTC-G06": {"n":"萬隆","e":"Wanlong","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":120,"y":310,"c":"#107547"},
        "TRTC-G07": {"n":"公館","e":"Gongguan","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":100,"y":280,"c":"#107547"},
        "TRTC-G08": {"n":"台電大樓","e":"Taipower Building","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":80,"y":250,"c":"#107547"},
        "TRTC-G09": {"n":"古亭","e":"Guting","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":50,"y":220,"c":"#107547","g":"TRANSGRP_guting"},
        "TRTC-G10": {"n":"中正紀念堂","e":"Chiang Kai-Shek Memorial Hall","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":-1.71,"y":162.32,"c":"#107547","g":"TRANSGRP_zhongzhengjiniantang"},
        "TRTC-G11": {"n":"小南門","e":"Xiaonanmen","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":-51,"y":130,"c":"#107547"},
        "TRTC-G12": {"n":"西門","e":"Ximen","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":-70.27,"y":110.07,"c":"#107547","g":"TRANSGRP_ximen"},
        "TRTC-G13": {"n":"北門","e":"Beimen","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":-80,"y":-45,"c":"#107547","g":"TRANSGRP_beimen"},
        "TRTC-G14": {"n":"中山","e":"Zhongshan","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":10,"y":-55,"c":"#107547","g":"TRANSGRP_zhongshan"},
        "TRTC-G15": {"n":"松江南京","e":"Songjiang Nanjing","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":150,"y":-55,"c":"#107547","g":"TRANSGRP_songjiangnanjing"},
        "TRTC-G16": {"n":"南京復興","e":"Nanjing Fuxing","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":206,"y":-55,"c":"#107547","g":"TRANSGRP_nanjingfuxing"},
        "TRTC-G17": {"n":"台北小巨蛋","e":"Taipei Arena","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":300,"y":-55,"c":"#107547"},
        "TRTC-G18": {"n":"南京三民","e":"Nanjing Sanmin","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":370,"y":-55,"c":"#107547"},
        "TRTC-G19": {"n":"松山","e":"Songshan","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":450,"y":-47,"c":"#107547","g":"TRANSGRP_songshan"},
        "TRTC-G03A": {"n":"小碧潭","e":"Xiaobitan","s":"TRTC","l":["TRTC-G"],"r":"taipei","x":100,"y":408,"c":"#107547"},
        "TRTC-O01": {"n":"南勢角","e":"Nanshijiao","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-120,"y":370,"c":"#f5a818"},
        "TRTC-O02": {"n":"景安","e":"Jingan","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-90,"y":340,"c":"#f5a818","g":"TRANSGRP_jingan"},
        "TRTC-O03": {"n":"永安市場","e":"Yongan Market","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-60,"y":310,"c":"#f5a818"},
        "TRTC-O04": {"n":"頂溪","e":"Dingxi","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-20,"y":260,"c":"#f5a818"},
        "TRTC-O05": {"n":"古亭","e":"Guting","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":37.84,"y":226.95,"c":"#f5a818","g":"TRANSGRP_guting"},
        "TRTC-O06": {"n":"東門","e":"Dongmen","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":83.05,"y":162.16,"c":"#f5a818","g":"TRANSGRP_dongmen"},
        "TRTC-O07": {"n":"忠孝新生","e":"Zhongxiao Xinsheng","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":142.23,"y":71.65,"c":"#f5a818","g":"TRANSGRP_zhongxiaoxinsheng"},
        "TRTC-O08": {"n":"松江南京","e":"Songjiang Nanjing","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":141.25,"y":-65.93,"c":"#f5a818","g":"TRANSGRP_songjiangnanjing"},
        "TRTC-O09": {"n":"行天宮","e":"Xingtian Temple","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":130,"y":-80,"c":"#f5a818"},
        "TRTC-O10": {"n":"中山國小","e":"Zhongshan Elementary School","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":90,"y":-107,"c":"#f5a818"},
        "TRTC-O11": {"n":"民權西路","e":"Minquan W. Rd.","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-4,"y":-107,"c":"#f5a818","g":"TRANSGRP_minquanxilu"},
        "TRTC-O12": {"n":"大橋頭","e":"Daqiaotou","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-60,"y":-107,"c":"#f5a818","g":"TRANSGRP_daqiaotou"},
        "TRTC-O13": {"n":"台北橋","e":"Taipei Bridge","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-130,"y":-80,"c":"#f5a818"},
        "TRTC-O14": {"n":"菜寮","e":"Cailiao","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-140,"y":-55,"c":"#f5a818"},
        "TRTC-O15": {"n":"三重","e":"Sanchong","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-155,"y":-30,"c":"#f5a818","g":"TRANSGRP_sanchong"},
        "TRTC-O16": {"n":"先嗇宮","e":"Xianse Temple","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-240,"y":-20,"c":"#f5a818"},
        "TRTC-O17": {"n":"頭前庄","e":"Touqianzhuang","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-290,"y":-20,"c":"#f5a818","g":"TRANSGRP_touqianzhuang"},
        "TRTC-O18": {"n":"新莊","e":"Xinzhuang","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-350,"y":-20,"c":"#f5a818"},
        "TRTC-O19": {"n":"輔大","e":"Fu Jen University","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-400,"y":-20,"c":"#f5a818"},
        "TRTC-O20": {"n":"丹鳳","e":"Danfeng","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-440,"y":-20,"c":"#f5a818"},
        "TRTC-O21": {"n":"迴龍","e":"Huilong","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-480,"y":-20,"c":"#f5a818"},
        "TRTC-O50": {"n":"三重國小","e":"Sanchong Elementary School","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-140,"y":-120,"c":"#f5a818"},
        "TRTC-O51": {"n":"三和國中","e":"Sanhe Junior High School","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-175,"y":-150,"c":"#f5a818"},
        "TRTC-O52": {"n":"徐匯中學","e":"St.lgnatius High School","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-215,"y":-180,"c":"#f5a818"},
        "TRTC-O53": {"n":"三民高中","e":"Sanmin Senior High School","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-245,"y":-210,"c":"#f5a818"},
        "TRTC-O54": {"n":"蘆洲","e":"Luzhou","s":"TRTC","l":["TRTC-O"],"r":"taipei","x":-280,"y":-240,"c":"#f5a818"},
        "TRTC-R01": {"n":"廣慈/奉天宮","e":"Guangci/Fengtian Temple","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":500,"y":150,"c":"#d90023"},
        "TRTC-R02": {"n":"象山","e":"Xiangshan","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":435,"y":150,"c":"#d90023"},
        "TRTC-R03": {"n":"台北101/世貿","e":"Taipei 101/World Trade Center","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":370,"y":150,"c":"#d90023"},
        "TRTC-R04": {"n":"信義安和","e":"Xinyi Anhe","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":290,"y":150,"c":"#d90023"},
        "TRTC-R05": {"n":"大安","e":"Daan","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":234,"y":150,"c":"#d90023","g":"TRANSGRP_daan"},
        "TRTC-R06": {"n":"大安森林公園","e":"Daan Park","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":155,"y":150,"c":"#d90023"},
        "TRTC-R07": {"n":"東門","e":"Dongmen","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":90,"y":150,"c":"#d90023","g":"TRANSGRP_dongmen"},
        "TRTC-R08": {"n":"中正紀念堂","e":"Chiang Kai-Shek Memorial Hall","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":170,"c":"#d90023","g":"TRANSGRP_zhongzhengjiniantang"},
        "TRTC-R09": {"n":"台大醫院","e":"NTU Hospital","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":100,"c":"#d90023"},
        "TRTC-R10": {"n":"台北車站","e":"Taipei Main Station","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":74,"c":"#d90023","g":"TRANSGRP_taipei"},
        "TRTC-R11": {"n":"中山","e":"Zhongshan","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-41,"c":"#d90023","g":"TRANSGRP_zhongshan"},
        "TRTC-R12": {"n":"雙連","e":"Shuanglian","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-80,"c":"#d90023"},
        "TRTC-R13": {"n":"民權西路","e":"Minquan W. Rd.","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-107,"c":"#d90023","g":"TRANSGRP_minquanxilu"},
        "TRTC-R14": {"n":"圓山","e":"Yuanshan","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-133,"c":"#d90023"},
        "TRTC-R15": {"n":"劍潭","e":"Jiantan","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-165,"c":"#d90023"},
        "TRTC-R16": {"n":"士林","e":"Shilin","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-189,"c":"#d90023"},
        "TRTC-R17": {"n":"芝山","e":"Zhishan","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-217,"c":"#d90023"},
        "TRTC-R18": {"n":"明德","e":"Mingde","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-239,"c":"#d90023"},
        "TRTC-R19": {"n":"石牌","e":"Shipai","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-263,"c":"#d90023"},
        "TRTC-R20": {"n":"唭哩岸","e":"Qilian","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-285,"c":"#d90023"},
        "TRTC-R21": {"n":"奇岩","e":"Qiyan","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-309,"c":"#d90023"},
        "TRTC-R22": {"n":"北投","e":"Beitou","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":10,"y":-332,"c":"#d90023","g":"TRANSGRP_beitou"},
        "TRTC-R23": {"n":"復興崗","e":"Fuxinggang","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":-45,"y":-330,"c":"#d90023"},
        "TRTC-R24": {"n":"忠義","e":"Zhongyi","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":-90,"y":-328,"c":"#d90023"},
        "TRTC-R25": {"n":"關渡","e":"Guandu","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":-130,"y":-326,"c":"#d90023"},
        "TRTC-R26": {"n":"竹圍","e":"Zhuwei","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":-170,"y":-325,"c":"#d90023"},
        "TRTC-R27": {"n":"紅樹林","e":"Hongshulin","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":-210,"y":-325,"c":"#d90023","g":"TRANSGRP_hongshulin"},
        "TRTC-R28": {"n":"淡水","e":"Tamsui","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":-260,"y":-325,"c":"#d90023"},
        "TRTC-R22A": {"n":"新北投","e":"Xinbeitou","s":"TRTC","l":["TRTC-R"],"r":"taipei","x":70,"y":-335,"c":"#d90023"},
        "NTMC-LB01": {"n":"頂埔","e":"Dingpu","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-300,"y":330,"c":"#47c1e1","g":"TRANSGRP_dingpu"},
        "NTMC-LB02": {"n":"媽祖田","e":"Mazutian","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-260,"y":355,"c":"#47c1e1"},
        "NTMC-LB03": {"n":"長壽山","e":"Changshoushan","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-260,"y":380,"c":"#47c1e1"},
        "NTMC-LB04": {"n":"橫溪","e":"Hengxi","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-300,"y":380,"c":"#47c1e1"},
        "NTMC-LB05": {"n":"龍埔","e":"Longpu","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-340,"y":380,"c":"#47c1e1"},
        "NTMC-LB06": {"n":"三峽","e":"Sanxia","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-390,"y":380,"c":"#47c1e1"},
        "NTMC-LB07": {"n":"台北大學","e":"National Taipei University","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-390,"y":355,"c":"#47c1e1"},
        "NTMC-LB08": {"n":"鶯歌車站","e":"Yingge Station","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-420,"y":335,"c":"#47c1e1","g":"TRANSGRP_yingge"},
        "NTMC-LB09": {"n":"陶瓷老街","e":"Ceramics Old Street","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-420,"y":362,"c":"#47c1e1"},
        "NTMC-LB10": {"n":"國華","e":"Guohua","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-405,"y":390,"c":"#47c1e1"},
        "NTMC-LB11": {"n":"永吉公園","e":"Yongji Park","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-160,"y":460,"c":"#47c1e1"},
        "NTMC-LB12": {"n":"鶯桃福德","e":"Yingtao Fude","s":"NTMC","l":["NTMC-LB"],"r":"taipei","x":-90,"y":535,"c":"#47c1e1"},
        "NTMC-Y07": {"n":"大坪林","e":"Dapinglin","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":126,"y":370,"c":"#fedb00","g":"TRANSGRP_dapinglin"},
        "NTMC-Y08": {"n":"十四張","e":"Shisizhang","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":65,"y":370,"c":"#fedb00","g":"TRANSGRP_shisizhang"},
        "NTMC-Y09": {"n":"秀朗橋","e":"Xiulang Bridge","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":10,"y":360,"c":"#fedb00"},
        "NTMC-Y10": {"n":"景平","e":"Jingping","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-40,"y":350,"c":"#fedb00"},
        "NTMC-Y11": {"n":"景安","e":"Jingan","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-96.95,"y":327.84,"c":"#fedb00","g":"TRANSGRP_jingan"},
        "NTMC-Y12": {"n":"中和","e":"Zhonghe","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-130,"y":270,"c":"#fedb00"},
        "NTMC-Y13": {"n":"橋和","e":"Qiaohe","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-150,"y":240,"c":"#fedb00"},
        "NTMC-Y14": {"n":"中原","e":"Zhongyuan","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-180,"y":210,"c":"#fedb00"},
        "NTMC-Y15": {"n":"板新","e":"Banxin","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-210,"y":180,"c":"#fedb00"},
        "NTMC-Y16": {"n":"板橋","e":"Banqiao","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-300,"y":162,"c":"#fedb00","g":"TRANSGRP_banqiao"},
        "NTMC-Y17": {"n":"新埔民生","e":"Xinpu Minsheng","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-230.53,"y":114.23,"c":"#fedb00","g":"TRANSGRP_xinpu"},
        "NTMC-Y18": {"n":"頭前庄","e":"Touqianzhuang","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-290,"y":-34,"c":"#fedb00","g":"TRANSGRP_touqianzhuang"},
        "NTMC-Y19": {"n":"幸福","e":"Xingfu","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-290,"y":-50,"c":"#fedb00"},
        "NTMC-Y20": {"n":"新北產業園區","e":"New Taipei Industrial Park","s":"NTMC","l":["NTMC-Y"],"r":"taipei","x":-290,"y":-80,"c":"#fedb00","g":"TRANSGRP_xinbeichanyeyuanqu"},
        "NTDLRT-V01": {"n":"紅樹林","e":"Hongshulin","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-210,"y":-339,"c":"#ff2a00","g":"TRANSGRP_hongshulin"},
        "NTDLRT-V02": {"n":"竿蓁林","e":"Ganzhenlin","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-210,"y":-365,"c":"#ff2a00"},
        "NTDLRT-V03": {"n":"淡金鄧公","e":"Danjin Denggong","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-210,"y":-385,"c":"#ff2a00"},
        "NTDLRT-V04": {"n":"淡江大學","e":"Tamkang University","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-260,"y":-385,"c":"#ff2a00"},
        "NTDLRT-V05": {"n":"淡金北新","e":"Danjin Beixin","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-310,"y":-385,"c":"#ff2a00"},
        "NTDLRT-V06": {"n":"新市一路","e":"Xinshi 1st Rd.","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-360,"y":-385,"c":"#ff2a00"},
        "NTDLRT-V07": {"n":"淡水行政中心","e":"Tamsui District Office","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-420,"y":-385,"c":"#ff2a00"},
        "NTDLRT-V08": {"n":"濱海義山","e":"Binhai Yishan","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-480,"y":-385,"c":"#ff2a00"},
        "NTDLRT-V09": {"n":"濱海沙崙","e":"Binhai Shalun","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-530,"y":-385,"c":"#ff2a00","g":"TRANSGRP_binhaishalun"},
        "NTDLRT-V10": {"n":"淡海新市鎮","e":"Danhai New Town","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-560,"y":-365,"c":"#ff2a00"},
        "NTDLRT-V11": {"n":"崁頂","e":"Kanding","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-560,"y":-345,"c":"#ff2a00"},
        "NTDLRT-V28": {"n":"台北海洋大學","e":"Taipei University of Marine Technology","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-490,"y":-365,"c":"#ff2a00"},
        "NTDLRT-V27": {"n":"沙崙","e":"Shalun","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-490,"y":-345,"c":"#ff2a00"},
        "NTDLRT-V26": {"n":"淡水漁人碼頭","e":"Tamsui Fisherman's Wharf","s":"NTDLRT","l":["NTDLRT-V"],"r":"taipei","x":-490,"y":-325,"c":"#ff2a00"},
        "NTALRT-K01": {"n":"雙城","e":"Shuangcheng","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":175,"y":530,"c":"#9e925e"},
        "NTALRT-K02": {"n":"玫瑰中國城","e":"Rose China Town","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":125,"y":530,"c":"#9e925e"},
        "NTALRT-K03": {"n":"台北小城","e":"Taipei Xiaocheng","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":65,"y":530,"c":"#9e925e"},
        "NTALRT-K04": {"n":"耕莘安康院區","e":"Cardinal Tien Hospital An Kang Branch","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":65,"y":510,"c":"#9e925e"},
        "NTALRT-K05": {"n":"景文科大","e":"Jinwen University of Science and Technology","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":65,"y":490,"c":"#9e925e"},
        "NTALRT-K06": {"n":"安康","e":"Ankang","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":65,"y":470,"c":"#9e925e"},
        "NTALRT-K07": {"n":"陽光運動公園","e":"Sunshine Sports Park","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":65,"y":450,"c":"#9e925e"},
        "NTALRT-K08": {"n":"新和國小","e":"Xinhe Elementary School","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":65,"y":430,"c":"#9e925e"},
        "NTALRT-K09": {"n":"十四張","e":"Shisizhang","s":"NTALRT","l":["NTALRT-K"],"r":"taipei","x":65,"y":384,"c":"#9e925e","g":"TRANSGRP_shisizhang"},
        "KRTC-O1": {"n":"哈瑪星","e":"Hamasen","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-501,"y":3135,"c":"#ff9300","g":"TRANSGRP_hamasen"},
        "KRTC-O2": {"n":"鹽埕埔","e":"Yanchengpu","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-420,"y":3135,"c":"#ff9300"},
        "KRTC-O4": {"n":"前金","e":"Cianjin","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-370,"y":3135,"c":"#ff9300"},
        "KRTC-O5": {"n":"美麗島","e":"Formosa Boulevard","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-315,"y":3135,"c":"#ff9300","g":"TRANSGRP_meilidao"},
        "KRTC-O6": {"n":"信義國小","e":"Sinyi Elementary School","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-260,"y":3135,"c":"#ff9300"},
        "KRTC-O7": {"n":"文化中心","e":"Cultural Center","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-200,"y":3135,"c":"#ff9300"},
        "KRTC-O8": {"n":"五塊厝","e":"Wukuaicuo","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-130,"y":3135,"c":"#ff9300"},
        "KRTC-O9": {"n":"苓雅運動園區","e":"Lingya Sports Park","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-80,"y":3135,"c":"#ff9300"},
        "KRTC-O10": {"n":"衛武營","e":"Weiwuying","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":-30,"y":3135,"c":"#ff9300"},
        "KRTC-O11": {"n":"鳳山西站","e":"Fongshan West","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":30,"y":3135,"c":"#ff9300"},
        "KRTC-O12": {"n":"鳳山","e":"Fongshan","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":90,"y":3135,"c":"#ff9300"},
        "KRTC-O13": {"n":"大東","e":"Dadong","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":150,"y":3135,"c":"#ff9300"},
        "KRTC-O14": {"n":"鳳山國中","e":"Fongshan Junior High School","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":200,"y":3135,"c":"#ff9300"},
        "KRTC-OT1": {"n":"大寮","e":"Daliao","s":"KRTC","l":["KRTC-O"],"r":"kaohsiung","x":230,"y":3165,"c":"#ff9300"},
        "KRTC-R3": {"n":"小港","e":"Siaogang","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-65,"y":3300,"c":"#f7005c"},
        "KRTC-R4": {"n":"高雄國際機場","e":"Kaohsiung International Airport","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-125,"y":3300,"c":"#f7005c"},
        "KRTC-R4A": {"n":"草衙","e":"Caoya","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-195,"y":3300,"c":"#f7005c"},
        "KRTC-R5": {"n":"前鎮高中","e":"Cianjhen Senior High School","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-225,"y":3275,"c":"#f7005c"},
        "KRTC-R6": {"n":"凱旋","e":"Kaisyuan","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-255,"y":3240,"c":"#f7005c","g":"TRANSGRP_kaisyuan"},
        "KRTC-R7": {"n":"獅甲","e":"Shihjia","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-285,"y":3210,"c":"#f7005c"},
        "KRTC-R8": {"n":"三多商圈","e":"Sanduo Shopping District","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":3185,"c":"#f7005c"},
        "KRTC-R9": {"n":"中央公園","e":"Central Park","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":3160,"c":"#f7005c"},
        "KRTC-R10": {"n":"美麗島","e":"Formosa Boulevard","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":3149,"c":"#f7005c","g":"TRANSGRP_meilidao"},
        "KRTC-R11": {"n":"高雄車站","e":"Kaohsiung Main Station","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":3094,"c":"#f7005c","g":"TRANSGRP_kaohsiung"},
        "KRTC-R12": {"n":"後驛","e":"Houyi","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":3045,"c":"#f7005c"},
        "KRTC-R13": {"n":"凹子底","e":"Aozihdi","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":2990,"c":"#f7005c","g":"TRANSGRP_aozihdi"},
        "KRTC-R14": {"n":"巨蛋","e":"Kaohsiung Arena","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":2935,"c":"#f7005c"},
        "KRTC-R15": {"n":"生態園區","e":"Ecological District","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-315,"y":2910,"c":"#f7005c"},
        "KRTC-R16": {"n":"左營","e":"Zuoying","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-386.61,"y":2888.1,"c":"#f7005c","g":"TRANSGRP_xinzuoying"},
        "KRTC-R17": {"n":"世運","e":"World Game","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-480,"y":2884,"c":"#f7005c"},
        "KRTC-R18": {"n":"油廠國小","e":"Oil Refinery Elementary School","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-530,"y":2884,"c":"#f7005c"},
        "KRTC-R19": {"n":"楠梓科技園區","e":"Nanzih Technology Industrial Park","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-530,"y":2862,"c":"#f7005c"},
        "KRTC-R20": {"n":"後勁","e":"Houjing","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-530,"y":2840,"c":"#f7005c"},
        "KRTC-R21": {"n":"都會公園","e":"Metropolitan Park","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-480,"y":2840,"c":"#f7005c"},
        "KRTC-R22": {"n":"青埔","e":"Cingpu","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-480,"y":2819,"c":"#f7005c"},
        "KRTC-R22A": {"n":"橋頭糖廠","e":"Ciaotou Sugar Refinery","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-480,"y":2797,"c":"#f7005c"},
        "KRTC-R23": {"n":"橋頭火車站","e":"Ciaotou Station","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-420,"y":2775,"c":"#f7005c","g":"TRANSGRP_qiaotou"},
        "KRTC-R24": {"n":"岡山高醫","e":"Kaohsiung Medical University Gangshan Hospital","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-420,"y":2752,"c":"#f7005c"},
        "KRTC-RK1": {"n":"岡山車站","e":"Gangshan Station","s":"KRTC","l":["KRTC-R"],"r":"kaohsiung","x":-420,"y":2730,"c":"#f7005c","g":"TRANSGRP_gangshan"},
        "KLRT-C1": {"n":"籬仔內","e":"Lizihnei","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-155,"y":3240,"c":"#61c851"},
        "KLRT-C2": {"n":"凱旋瑞田","e":"Kaisyuan Rueitian","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-208,"y":3240,"c":"#61c851"},
        "KLRT-C3": {"n":"前鎮之星","e":"Cianjhen Star","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-265.41,"y":3249.37,"c":"#61c851","g":"TRANSGRP_kaisyuan"},
        "KLRT-C4": {"n":"凱旋中華","e":"Kaisyuan Jhonghua","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-305,"y":3285,"c":"#61c851"},
        "KLRT-C5": {"n":"夢時代","e":"Dream Mall","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-355,"y":3285,"c":"#61c851"},
        "KLRT-C6": {"n":"經貿園區","e":"Commerce and Trade Park","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-365,"y":3262,"c":"#61c851"},
        "KLRT-C7": {"n":"軟體園區","e":"Software Technology Park","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-375,"y":3239,"c":"#61c851"},
        "KLRT-C8": {"n":"高雄展覽館","e":"Kaohsiung Exhibition Center","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-385,"y":3216,"c":"#61c851"},
        "KLRT-C9": {"n":"旅運中心","e":"Cruise Terminal","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-395,"y":3193,"c":"#61c851"},
        "KLRT-C10": {"n":"光榮碼頭","e":"Glory Pier","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-395,"y":3170,"c":"#61c851"},
        "KLRT-C11": {"n":"真愛碼頭","e":"Love Pier","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-455,"y":3170,"c":"#61c851"},
        "KLRT-C12": {"n":"駁二大義","e":"Dayi Pier-2","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-515,"y":3170,"c":"#61c851"},
        "KLRT-C13": {"n":"駁二蓬萊","e":"Penglai Pier-2","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-515,"y":3153,"c":"#61c851"},
        "KLRT-C14": {"n":"哈瑪星","e":"Hamasen","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-515,"y":3135,"c":"#61c851","g":"TRANSGRP_hamasen"},
        "KLRT-C15": {"n":"壽山公園站","e":"Shoushan Park","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-505,"y":3100,"c":"#61c851"},
        "KLRT-C16": {"n":"文武聖殿站","e":"Wenwu Temple","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-495,"y":3078,"c":"#61c851"},
        "KLRT-C17": {"n":"鼓山區公所站","e":"Gushan District Office","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-485,"y":3056,"c":"#61c851"},
        "KLRT-C18": {"n":"鼓山","e":"Gushan","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-475,"y":3034,"c":"#61c851","g":"TRANSGRP_gushan"},
        "KLRT-C19": {"n":"馬卡道","e":"Makadao","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-475,"y":3012,"c":"#61c851"},
        "KLRT-C20": {"n":"臺鐵美術館","e":"TRA Museum of Fine Arts","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-475,"y":2990,"c":"#61c851","g":"TRANSGRP_meishuguan"},
        "KLRT-C21A": {"n":"內惟藝術中心","e":"Neiwei Arts Center","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-465,"y":2968,"c":"#61c851"},
        "KLRT-C21": {"n":"美術館","e":"Kaohsiung Museum of Fine Arts","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-410,"y":2968,"c":"#61c851"},
        "KLRT-C22": {"n":"聯合醫院","e":"Kaohsiung Municipal United Hospital","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-410,"y":2990,"c":"#61c851"},
        "KLRT-C23": {"n":"龍華國小","e":"Longhua Elementary School","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-353,"y":2990,"c":"#61c851"},
        "KLRT-C24": {"n":"愛河之心","e":"Heart of Love River","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-329,"y":2990,"c":"#61c851","g":"TRANSGRP_aozihdi"},
        "KLRT-C25": {"n":"新上國小","e":"Sinshang Elementary School","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-234,"y":2990,"c":"#61c851"},
        "KLRT-C26": {"n":"大順民族","e":"Dashun Minzu","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-174,"y":2990,"c":"#61c851"},
        "KLRT-C27": {"n":"灣仔內(大順鼎山)","e":"Wanzihnei(Dashun Dingshan)","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-114,"y":2990,"c":"#61c851"},
        "KLRT-C28": {"n":"高雄高工","e":"Kaohsiung Industrial High School","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-114,"y":3013,"c":"#61c851"},
        "KLRT-C29": {"n":"樹德家商","e":"Shu-Te Home-Economics & Commercial High School","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-114,"y":3036,"c":"#61c851"},
        "KLRT-C30": {"n":"科工館","e":"Science and Technology Museum","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-114,"y":3066,"c":"#61c851","g":"TRANSGRP_kegongguan"},
        "KLRT-C31": {"n":"聖功醫院","e":"St.Joseph Hospital","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-164,"y":3110,"c":"#61c851"},
        "KLRT-C32": {"n":"凱旋公園站","e":"Kaisyuan Park","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-164,"y":3153,"c":"#61c851"},
        "KLRT-C33": {"n":"衛生局站","e":"Department of Health","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-164,"y":3175,"c":"#61c851"},
        "KLRT-C34": {"n":"五權國小站","e":"Wucyuan Elementary School","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-104,"y":3175,"c":"#61c851"},
        "KLRT-C35": {"n":"凱旋武昌站","e":"Kaisyuan Wuchang","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-104,"y":3197,"c":"#61c851"},
        "KLRT-C36": {"n":"凱旋二聖站","e":"Kaisyuan Ersheng","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-104,"y":3219,"c":"#61c851"},
        "KLRT-C37": {"n":"輕軌機廠站","e":"LRT Depot","s":"KLRT","l":["KLRT-C"],"r":"kaohsiung","x":-104,"y":3241,"c":"#61c851"},
        "TYMC-A1": {"n":"台北車站","e":"Taipei Main Station","s":"TYMC","l":["TYMC-A"],"r":"taipei","x":-52,"y":-27,"c":"#8246af","g":"TRANSGRP_taipei"},
        "TYMC-A2": {"n":"三重站","e":"Sanchong Station","s":"TYMC","l":["TYMC-A"],"r":"taipei","x":-168.13,"y":-34.86,"c":"#8246af","g":"TRANSGRP_sanchong"},
        "TYMC-A3": {"n":"新北產業園區站","e":"New Taipei Industrial Park Station","s":"TYMC","l":["TYMC-A"],"r":"taipei","x":-300.84,"y":-88.87,"c":"#8246af","g":"TRANSGRP_xinbeichanyeyuanqu"},
        "TYMC-A4": {"n":"新莊副都心站","e":"Xinzhuang Fuduxin Station","s":"TYMC","l":["TYMC-A"],"r":"taipei","x":-345,"y":-125,"c":"#8246af"},
        "TYMC-A5": {"n":"泰山站","e":"Taishan Station","s":"TYMC","l":["TYMC-A"],"r":"taipei","x":-395,"y":-125,"c":"#8246af"},
        "TYMC-A6": {"n":"泰山貴和站","e":"Taishan Guihe Station","s":"TYMC","l":["TYMC-A"],"r":"taipei","x":-445,"y":-125,"c":"#8246af"},
        "TYMC-A7": {"n":"體育大學站","e":"National Taiwan Sport University Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":350,"c":"#8246af"},
        "TYMC-A8": {"n":"長庚醫院站","e":"Chang Gung Memorial Hospital Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":380,"c":"#8246af","g":"TRANSGRP_changgengyiyuan"},
        "TYMC-A9": {"n":"林口站","e":"Linkou Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":410,"c":"#8246af"},
        "TYMC-A10": {"n":"山鼻站","e":"Shanbi Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":440,"c":"#8246af"},
        "TYMC-A11": {"n":"坑口站","e":"Kengkou Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":470,"c":"#8246af"},
        "TYMC-A12": {"n":"機場第一航廈站","e":"Airport Terminal 1 Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":500,"c":"#8246af"},
        "TYMC-A13": {"n":"機場第二航廈站","e":"Airport Terminal 2 Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":530,"c":"#8246af"},
        "TYMC-A14a": {"n":"機場旅館站","e":"Airport Hotel Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":590,"c":"#8246af"},
        "TYMC-A15": {"n":"大園站","e":"Dayuan Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":690,"c":"#8246af"},
        "TYMC-A16": {"n":"橫山站","e":"Hengshan Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":720,"c":"#8246af"},
        "TYMC-A17": {"n":"領航站","e":"Linghang Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":750,"c":"#8246af"},
        "TYMC-A18": {"n":"高鐵桃園站","e":"Taoyuan HSR Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":780,"c":"#8246af","g":"TRANSGRP_taoyuan2"},
        "TYMC-A19": {"n":"桃園體育園區站","e":"Taoyuan Sports Park Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-550,"y":830,"c":"#8246af"},
        "TYMC-A20": {"n":"興南站","e":"Xingnan Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-480,"y":830,"c":"#8246af"},
        "TYMC-A21": {"n":"環北站","e":"Huanbei Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-400,"y":830,"c":"#8246af"},
        "TYMC-A22": {"n":"老街溪站","e":"Laojie River Station","s":"TYMC","l":["TYMC-A"],"r":"taoyuan","x":-262,"y":830,"c":"#8246af","g":"TRANSGRP_zhongli"},
        "TMRT-G0": {"n":"北屯總站","e":"Beitun Main Station","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":250,"y":1515,"c":"#84bd00"},
        "TMRT-G3": {"n":"舊社","e":"Jiushe","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":250,"y":1545,"c":"#84bd00"},
        "TMRT-G4": {"n":"松竹","e":"Songzhu","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":114,"y":1545,"c":"#84bd00","g":"TRANSGRP_songzhu"},
        "TMRT-G5": {"n":"四維國小","e":"Sihwei Elementary School","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":36,"y":1565,"c":"#84bd00"},
        "TMRT-G6": {"n":"文心崇德","e":"Wenxin Chongde","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-20,"y":1565,"c":"#84bd00"},
        "TMRT-G7": {"n":"文心中清","e":"Wenxin Zhongqing","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-70,"y":1565,"c":"#84bd00"},
        "TMRT-G8": {"n":"文華高中","e":"Wenhua Senior High School","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-120,"y":1565,"c":"#84bd00"},
        "TMRT-G8a": {"n":"文心櫻花","e":"Wenxin Yinghua","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-180,"y":1595,"c":"#84bd00"},
        "TMRT-G9": {"n":"市政府","e":"Taichung City Hall","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-230,"y":1620,"c":"#84bd00"},
        "TMRT-G10": {"n":"水安宮","e":"Shui-an Temple","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-230,"y":1645,"c":"#84bd00"},
        "TMRT-G10a": {"n":"文心森林公園","e":"Wenxin Forest Park","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-160,"y":1670,"c":"#84bd00"},
        "TMRT-G11": {"n":"南屯","e":"Nantun","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-90,"y":1700,"c":"#84bd00"},
        "TMRT-G12": {"n":"豐樂公園","e":"Feng-le Park","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-45,"y":1700,"c":"#84bd00"},
        "TMRT-G13": {"n":"大慶","e":"Daqing","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":80,"y":1700,"c":"#84bd00","g":"TRANSGRP_daqing"},
        "TMRT-G14": {"n":"九張犁","e":"Jiuzhangli","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":80,"y":1730,"c":"#84bd00"},
        "TMRT-G15": {"n":"九德","e":"Jiude","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":80,"y":1755,"c":"#84bd00"},
        "TMRT-G16": {"n":"烏日","e":"Wuri","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":80,"y":1780,"c":"#84bd00","g":"TRANSGRP_wuri"},
        "TMRT-G17": {"n":"高鐵臺中站","e":"HSR Taichung Station","s":"TMRT","l":["TMRT-G"],"r":"taichung","x":-86.61,"y":1830.91,"c":"#84bd00","g":"TRANSGRP_xinwuri"}
      },
      "groups": {
        "TRANSGRP_aozihdi": {"n":"凹子底","e":"Aozihdi","m":["KRTC-R13","KLRT-C24"]},
        "TRANSGRP_badouzi": {"n":"八斗子","e":"Badouzi","m":["TRA-7362"]},
        "TRANSGRP_badu": {"n":"八堵","e":"Badu","m":["TRA-0920"]},
        "TRANSGRP_banqiao": {"n":"板橋","e":"Banqiao","m":["THSR-1010","TRA-1020","TRTC-BL07","NTMC-Y16"]},
        "TRANSGRP_beihu": {"n":"北湖","e":"Beihu","m":["TRA-1150"]},
        "TRANSGRP_beimen": {"n":"北門","e":"Beimen","m":["TRTC-G13","TYMC-A1"]},
        "TRANSGRP_beitou": {"n":"北投","e":"Beitou","m":["TRTC-R22"]},
        "TRANSGRP_binhaishalun": {"n":"濱海沙崙","e":"Binhai Shalun","m":["NTDLRT-V09"]},
        "TRANSGRP_changgengyiyuan": {"n":"長庚醫院站","e":"Chang Gung Memorial Hospital Station","m":["TYMC-A8"]},
        "TRANSGRP_changhua1": {"n":"彰化","e":"Changhua","m":["TRA-3360"]},
        "TRANSGRP_changhua2": {"n":"彰化","e":"Changhua","m":["THSR-1043"]},
        "TRANSGRP_chaozhou": {"n":"潮州","e":"Chaozhou","m":["TRA-5050"]},
        "TRANSGRP_checheng": {"n":"車埕","e":"Checheng","m":["TRA-3436"]},
        "TRANSGRP_chenggong": {"n":"成功","e":"Chenggong","m":["TRA-3350"]},
        "TRANSGRP_chiayi1": {"n":"嘉義","e":"Chiayi","m":["TRA-4080"]},
        "TRANSGRP_chiayi2": {"n":"嘉義","e":"Chiayi","m":["THSR-1050"]},
        "TRANSGRP_chishang": {"n":"池上","e":"Chishang","m":["TRA-6070"]},
        "TRANSGRP_daan": {"n":"大安","e":"Daan","m":["TRTC-BR09","TRTC-R05"]},
        "TRANSGRP_dajia": {"n":"大甲","e":"Dajia","m":["TRA-2200"]},
        "TRANSGRP_dapinglin": {"n":"大坪林","e":"Dapinglin","m":["TRTC-G04","NTMC-Y07"]},
        "TRANSGRP_daqiaotou": {"n":"大橋頭","e":"Daqiaotou","m":["TRTC-O12"]},
        "TRANSGRP_daqing": {"n":"大慶","e":"Daqing","m":["TRA-3320","TMRT-G13"]},
        "TRANSGRP_dawu": {"n":"大武","e":"Dawu","m":["TRA-5190"]},
        "TRANSGRP_dingpu": {"n":"頂埔","e":"Dingpu","m":["TRTC-BL01","NTMC-LB01"]},
        "TRANSGRP_dongmen": {"n":"東門","e":"Dongmen","m":["TRTC-O06","TRTC-R07"]},
        "TRANSGRP_douliu": {"n":"斗六","e":"Douliu","m":["TRA-3470"]},
        "TRANSGRP_dounan": {"n":"斗南","e":"Dounan","m":["TRA-3480"]},
        "TRANSGRP_ershui": {"n":"二水","e":"Ershui","m":["TRA-3430"]},
        "TRANSGRP_fangliao": {"n":"枋寮","e":"Fangliao","m":["TRA-5120"]},
        "TRANSGRP_fengfu": {"n":"豐富","e":"Fengfu","m":["THSR-1035","TRA-3150"]},
        "TRANSGRP_fenglin": {"n":"鳳林","e":"Fenglin","m":["TRA-6180"]},
        "TRANSGRP_fengyuan": {"n":"豐原","e":"Fengyuan","m":["TRA-3230"]},
        "TRANSGRP_fongshan": {"n":"鳳山","e":"Fongshan","m":["TRA-4440"]},
        "TRANSGRP_fuli": {"n":"富里","e":"Fuli","m":["TRA-6080"]},
        "TRANSGRP_gangshan": {"n":"岡山","e":"Gangshan","m":["TRA-4310","KRTC-RK1"]},
        "TRANSGRP_guangfu": {"n":"光復","e":"Guangfu","m":["TRA-6160"]},
        "TRANSGRP_guanshan": {"n":"關山","e":"Guanshan","m":["TRA-6050"]},
        "TRANSGRP_gushan": {"n":"鼓山","e":"Gushan","m":["TRA-4380","KLRT-C18"]},
        "TRANSGRP_guting": {"n":"古亭","e":"Guting","m":["TRTC-G09","TRTC-O05"]},
        "TRANSGRP_hamasen": {"n":"哈瑪星","e":"Hamasen","m":["KRTC-O1","KLRT-C14"]},
        "TRANSGRP_hongshulin": {"n":"紅樹林","e":"Hongshulin","m":["TRTC-R27","NTDLRT-V01"]},
        "TRANSGRP_houli": {"n":"后里","e":"Houli","m":["TRA-3220"]},
        "TRANSGRP_houlong": {"n":"後龍","e":"Houlong","m":["TRA-2130"]},
        "TRANSGRP_hsinchu": {"n":"新竹","e":"Hsinchu","m":["TRA-1210"]},
        "TRANSGRP_hualien": {"n":"花蓮","e":"Hualien","m":["TRA-7000"]},
        "TRANSGRP_jian": {"n":"吉安","e":"Ji'an","m":["TRA-6250"]},
        "TRANSGRP_jiaoxi": {"n":"礁溪","e":"Jiaoxi","m":["TRA-7210"]},
        "TRANSGRP_jingan": {"n":"景安","e":"Jingan","m":["TRTC-O02","NTMC-Y11"]},
        "TRANSGRP_jingtong": {"n":"菁桐","e":"Jingtong","m":["TRA-7336"]},
        "TRANSGRP_jinlun": {"n":"金崙","e":"Jinlun","m":["TRA-5210"]},
        "TRANSGRP_jiuqutang": {"n":"九曲堂","e":"Jiuqutang","m":["TRA-4460"]},
        "TRANSGRP_kaisyuan": {"n":"凱旋","e":"Kaisyuan","m":["KRTC-R6","KLRT-C3"]},
        "TRANSGRP_kaohsiung": {"n":"高雄","e":"Kaohsiung","m":["TRA-4400","KRTC-R11"]},
        "TRANSGRP_keelung": {"n":"基隆","e":"Keelung","m":["TRA-0900"]},
        "TRANSGRP_kegongguan": {"n":"科工館","e":"Science And Technology Museum","m":["TRA-4420","KLRT-C30"]},
        "TRANSGRP_linbian": {"n":"林邊","e":"Linbian","m":["TRA-5090"]},
        "TRANSGRP_liujia": {"n":"六家","e":"Liujia","m":["THSR-1030","TRA-1194"]},
        "TRANSGRP_luodong": {"n":"羅東","e":"Luodong","m":["TRA-7160"]},
        "TRANSGRP_luye": {"n":"鹿野","e":"Luye","m":["TRA-6020"]},
        "TRANSGRP_meilidao": {"n":"美麗島","e":"Formosa Boulevard","m":["KRTC-O5","KRTC-R10"]},
        "TRANSGRP_meishuguan": {"n":"美術館","e":"Museum of Fine Arts","m":["TRA-4370","KLRT-C20"]},
        "TRANSGRP_miaoli": {"n":"苗栗","e":"Miaoli","m":["TRA-3160"]},
        "TRANSGRP_minquanxilu": {"n":"民權西路","e":"Minquan W. Rd.","m":["TRTC-R13","TRTC-O11"]},
        "TRANSGRP_nanao": {"n":"南澳","e":"Nan'ao","m":["TRA-7090"]},
        "TRANSGRP_nangang": {"n":"南港","e":"Nangang","m":["THSR-0990","TRA-0980","TRTC-BL22"]},
        "TRANSGRP_nangangzhanlanguan": {"n":"南港展覽館","e":"Taipei Nangang Exhibition Center","m":["TRTC-BL23","TRTC-BR24"]},
        "TRANSGRP_nanjingfuxing": {"n":"南京復興","e":"Nanjing Fuxing","m":["TRTC-BR11","TRTC-G16"]},
        "TRANSGRP_nanzhou": {"n":"南州","e":"Nanzhou","m":["TRA-5070"]},
        "TRANSGRP_neiwan": {"n":"內灣","e":"Neiwan","m":["TRA-1208"]},
        "TRANSGRP_northhsinchu": {"n":"北新竹","e":"North Hsinchu","m":["TRA-1190"]},
        "TRANSGRP_pingtung": {"n":"屏東","e":"Pingtung","m":["TRA-5000"]},
        "TRANSGRP_qiaotou": {"n":"橋頭","e":"Qiaotou","m":["TRA-4320","KRTC-R23"]},
        "TRANSGRP_qidu": {"n":"七堵","e":"Qidu","m":["TRA-0930"]},
        "TRANSGRP_qingshui": {"n":"清水","e":"Qingshui","m":["TRA-2220"]},
        "TRANSGRP_qizhang": {"n":"七張","e":"Qizhang","m":["TRTC-G03"]},
        "TRANSGRP_ruifang": {"n":"瑞芳","e":"Ruifang","m":["TRA-7360"]},
        "TRANSGRP_ruisui": {"n":"瑞穗","e":"Ruisui","m":["TRA-6130"]},
        "TRANSGRP_sanchong": {"n":"三重","e":"Sanchong","m":["TRTC-O15","TYMC-A2"]},
        "TRANSGRP_sandiaoling": {"n":"三貂嶺","e":"Sandiaoling","m":["TRA-7330"]},
        "TRANSGRP_shalu": {"n":"沙鹿","e":"Shalu","m":["TRA-2230"]},
        "TRANSGRP_shalun": {"n":"沙崙","e":"Shalun","m":["THSR-1060","TRA-4272"]},
        "TRANSGRP_shanhua": {"n":"善化","e":"Shanhua","m":["TRA-4170"]},
        "TRANSGRP_shisizhang": {"n":"十四張","e":"Shisizhang","m":["NTMC-Y08","NTALRT-K09"]},
        "TRANSGRP_shoufeng": {"n":"壽豐","e":"Shoufeng","m":["TRA-6220"]},
        "TRANSGRP_shulin": {"n":"樹林","e":"Shulin","m":["TRA-1040"]},
        "TRANSGRP_songjiangnanjing": {"n":"松江南京","e":"Songjiang Nanjing","m":["TRTC-G15","TRTC-O08"]},
        "TRANSGRP_songshan": {"n":"松山","e":"Songshan","m":["TRA-0990","TRTC-G19"]},
        "TRANSGRP_songzhu": {"n":"松竹","e":"Songzhu","m":["TRA-3270","TMRT-G4"]},
        "TRANSGRP_suao": {"n":"蘇澳","e":"Su'ao","m":["TRA-7120"]},
        "TRANSGRP_suaoxin": {"n":"蘇澳新","e":"Su'aoxin","m":["TRA-7130"]},
        "TRANSGRP_taichung": {"n":"臺中","e":"Taichung","m":["TRA-3300"]},
        "TRANSGRP_taimali": {"n":"太麻里","e":"Taimali","m":["TRA-5220"]},
        "TRANSGRP_tainan": {"n":"臺南","e":"Tainan","m":["TRA-4220"]},
        "TRANSGRP_taipei": {"n":"臺北","e":"Taipei","m":["THSR-1000","TRA-1000","TRTC-BL12","TRTC-R10","TYMC-A1","TRA-1001"]},
        "TRANSGRP_taitung": {"n":"臺東","e":"Taitung","m":["TRA-6000"]},
        "TRANSGRP_taoyuan1": {"n":"桃園","e":"Taoyuan","m":["TRA-1080"]},
        "TRANSGRP_taoyuan2": {"n":"桃園","e":"Taoyuan","m":["THSR-1020","TYMC-A18"]},
        "TRANSGRP_tianzhong": {"n":"田中","e":"Tianzhong","m":["TRA-3420"]},
        "TRANSGRP_tongxiao": {"n":"通霄","e":"Tongxiao","m":["TRA-2170"]},
        "TRANSGRP_toucheng": {"n":"頭城","e":"Toucheng","m":["TRA-7230"]},
        "TRANSGRP_touqianzhuang": {"n":"頭前庄","e":"Touqianzhuang","m":["TRTC-O17","NTMC-Y18"]},
        "TRANSGRP_wanhua": {"n":"萬華","e":"Wanhua","m":["TRA-1010","TRTC-BL10"]},
        "TRANSGRP_wuri": {"n":"烏日","e":"Wuri","m":["TRA-3330","TMRT-G16"]},
        "TRANSGRP_ximen": {"n":"西門","e":"Ximen","m":["TRTC-BL11","TRTC-G12"]},
        "TRANSGRP_xinbeichanyeyuanqu": {"n":"新北產業園區站","e":"New Taipei Industrial Park Station","m":["TYMC-A3","NTMC-Y20"]},
        "TRANSGRP_xincheng": {"n":"新城","e":"Xincheng","m":["TRA-7030"]},
        "TRANSGRP_xinpu": {"n":"新埔","e":"Xinpu","m":["TRTC-BL08","NTMC-Y17"]},
        "TRANSGRP_xinwuri": {"n":"新烏日","e":"Xinwuri","m":["THSR-1040","TRA-3340","TMRT-G17"]},
        "TRANSGRP_xinying": {"n":"新營","e":"Xinying","m":["TRA-4120"]},
        "TRANSGRP_xinzuoying": {"n":"新左營","e":"Xinzuoying","m":["THSR-1070","TRA-4340","KRTC-R16"]},
        "TRANSGRP_xizhi": {"n":"汐止","e":"Xizhi","m":["TRA-0960"]},
        "TRANSGRP_yilan": {"n":"宜蘭","e":"Yilan","m":["TRA-7190"]},
        "TRANSGRP_yingge": {"n":"鶯歌","e":"Yingge","m":["TRA-1070","NTMC-LB08"]},
        "TRANSGRP_yongkang": {"n":"永康","e":"Yongkang","m":["TRA-4200"]},
        "TRANSGRP_yuanli": {"n":"苑裡","e":"Yuanli","m":["TRA-2180"]},
        "TRANSGRP_yuanlin": {"n":"員林","e":"Yuanlin","m":["TRA-3390"]},
        "TRANSGRP_yuli": {"n":"玉里","e":"Yuli","m":["TRA-6110"]},
        "TRANSGRP_yunlin": {"n":"雲林","e":"Yunlin","m":["THSR-1047"]},
        "TRANSGRP_zhiben": {"n":"知本","e":"Zhiben","m":["TRA-5230"]},
        "TRANSGRP_zhixue": {"n":"志學","e":"Zhixue","m":["TRA-6240"]},
        "TRANSGRP_zhongli": {"n":"中壢","e":"Zhongli_Taoyuan","m":["TRA-1100","TYMC-A22"]},
        "TRANSGRP_zhongshan": {"n":"中山","e":"Zhongshan","m":["TRTC-G14","TRTC-R11"]},
        "TRANSGRP_zhongxiaofuxing": {"n":"忠孝復興","e":"Zhongxiao Fuxing","m":["TRTC-BL15","TRTC-BR10"]},
        "TRANSGRP_zhongxiaoxinsheng": {"n":"忠孝新生","e":"Zhongxiao Xinsheng","m":["TRTC-BL14","TRTC-O07"]},
        "TRANSGRP_zhongzhengjiniantang": {"n":"中正紀念堂","e":"Chiang Kai-Shek Memorial Hall","m":["TRTC-G10","TRTC-R08"]},
        "TRANSGRP_zhongzhou": {"n":"中洲","e":"Zhongzhou","m":["TRA-4270"]},
        "TRANSGRP_zhuifen": {"n":"追分","e":"Zhuifen","m":["TRA-2260"]},
        "TRANSGRP_zhunan": {"n":"竹南","e":"Zhunan","m":["TRA-1250"]},
        "TRANSGRP_zhuzhong": {"n":"竹中","e":"Zhuzhong","m":["TRA-1193"]}
      },
      "nodes": [
        {"id":"TRANSGRP_taipei","m":["THSR-1000","TRA-1000","TRTC-BL12","TRTC-R10"],"name":"台北","en":"Taipei","cls":0,"r":"taipei","g":"TRANSGRP_taipei","main":["THSR-1000","TRA-1000"],"sub":[{"m":["TRTC-BL12","TRTC-R10"],"name":"台北車站","en":"Taipei Main Station","lp":[22,75,"left"]}],"lp":[23,44,"left","zh"]},
        {"id":"TRANSGRP_banqiao","m":["THSR-1010","TRA-1020","TRTC-BL07","NTMC-Y16"],"name":"板橋","en":"Banqiao","cls":1,"r":"taipei","g":"TRANSGRP_banqiao"},
        {"id":"TRANSGRP_nangang","m":["THSR-0990","TRA-0980","TRTC-BL22"],"name":"南港","en":"Nangang","cls":1,"r":"taipei","g":"TRANSGRP_nangang"},
        {"id":"TRANSGRP_xinwuri","m":["THSR-1040","TRA-3340","TMRT-G17"],"name":"高鐵台中","en":"Taichung","cls":1,"r":"taichung","g":"TRANSGRP_xinwuri","main":["THSR-1040"],"sub":[{"m":["TRA-3340"],"name":"新烏日","en":"Xinwuri"},{"m":["TMRT-G17"],"name":"高鐵台中","en":"HSR Taichung"}]},
        {"id":"TRANSGRP_xinzuoying","m":["THSR-1070","TRA-4340","KRTC-R16"],"name":"高鐵左營","en":"Zuoying","cls":1,"r":"kaohsiung","g":"TRANSGRP_xinzuoying","main":["THSR-1070","KRTC-R16"],"sub":[{"m":["TRA-4340"],"name":"新左營","en":"Xinzuoying"}]},
        {"id":"TRANSGRP_aozihdi","m":["KRTC-R13","KLRT-C24"],"name":"凹子底","en":"Aozihdi","cls":3,"r":"kaohsiung","g":"TRANSGRP_aozihdi","main":["KRTC-R13"],"sub":[{"m":["KLRT-C24"],"name":"愛河之心","en":"Heart of Love River"}]},
        {"id":"TRANSGRP_beimen","m":["TRTC-G13"],"name":"北門","en":"Beimen","cls":4,"r":"taipei","g":"TRANSGRP_beimen"},
        {"id":"TRANSGRP_daan","m":["TRTC-BR09","TRTC-R05"],"name":"大安","en":"Daan","cls":3,"r":"taipei","g":"TRANSGRP_daan"},
        {"id":"TRANSGRP_dapinglin","m":["TRTC-G04","NTMC-Y07"],"name":"大坪林","en":"Dapinglin","cls":3,"r":"taipei","g":"TRANSGRP_dapinglin"},
        {"id":"TRANSGRP_daqing","m":["TRA-3320","TMRT-G13"],"name":"大慶","en":"Daqing","cls":3,"r":"taichung","g":"TRANSGRP_daqing"},
        {"id":"TRANSGRP_dingpu","m":["TRTC-BL01","NTMC-LB01"],"name":"頂埔","en":"Dingpu","cls":3,"r":"taipei","g":"TRANSGRP_dingpu"},
        {"id":"TRANSGRP_dongmen","m":["TRTC-O06","TRTC-R07"],"name":"東門","en":"Dongmen","cls":3,"r":"taipei","g":"TRANSGRP_dongmen"},
        {"id":"TRANSGRP_fengfu","m":["THSR-1035","TRA-3150"],"name":"高鐵苗栗","en":"Miaoli","cls":2,"r":"miaoli","g":"TRANSGRP_fengfu","main":["THSR-1035"],"sub":[{"m":["TRA-3150"],"name":"豐富","en":"Fengfu"}]},
        {"id":"TRANSGRP_gangshan","m":["TRA-4310","KRTC-RK1"],"name":"岡山","en":"Gangshan","cls":3,"r":"kaohsiung","g":"TRANSGRP_gangshan","main":["TRA-4310"],"sub":[{"m":["KRTC-RK1"],"name":"岡山車站","en":"Gangshan Station"}]},
        {"id":"TRANSGRP_gushan","m":["TRA-4380","KLRT-C18"],"name":"鼓山","en":"Gushan","cls":3,"r":"kaohsiung","g":"TRANSGRP_gushan"},
        {"id":"TRANSGRP_guting","m":["TRTC-G09","TRTC-O05"],"name":"古亭","en":"Guting","cls":3,"r":"taipei","g":"TRANSGRP_guting"},
        {"id":"TRANSGRP_hamasen","m":["KRTC-O1","KLRT-C14"],"name":"哈瑪星","en":"Hamasen","cls":3,"r":"kaohsiung","g":"TRANSGRP_hamasen","lp":[-527,3132,"right"]},
        {"id":"TRANSGRP_hongshulin","m":["TRTC-R27","NTDLRT-V01"],"name":"紅樹林","en":"Hongshulin","cls":3,"r":"taipei","g":"TRANSGRP_hongshulin"},
        {"id":"TRANSGRP_jingan","m":["TRTC-O02","NTMC-Y11"],"name":"景安","en":"Jingan","cls":3,"r":"taipei","g":"TRANSGRP_jingan"},
        {"id":"TRANSGRP_kaisyuan","m":["KRTC-R6","KLRT-C3"],"name":"凱旋","en":"Kaisyuan","cls":3,"r":"kaohsiung","g":"TRANSGRP_kaisyuan","main":["KRTC-R6"],"sub":[{"m":["KLRT-C3"],"name":"前鎮之星","en":"Cianjhen Star"}]},
        {"id":"TRANSGRP_kaohsiung","m":["TRA-4400","KRTC-R11"],"name":"高雄","en":"Kaohsiung","cls":1,"r":"kaohsiung","g":"TRANSGRP_kaohsiung","main":["TRA-4400"],"sub":[{"m":["KRTC-R11"],"name":"高雄車站","en":"Kaohsiung Main Station"}]},
        {"id":"TRANSGRP_kegongguan","m":["TRA-4420","KLRT-C30"],"name":"科工館","en":"Science And Technolog…","cls":3,"r":"kaohsiung","g":"TRANSGRP_kegongguan"},
        {"id":"TRANSGRP_liujia","m":["THSR-1030","TRA-1194"],"name":"高鐵新竹","en":"Hsinchu","cls":2,"r":"hsinchu","g":"TRANSGRP_liujia","main":["THSR-1030"],"sub":[{"m":["TRA-1194"],"name":"六家","en":"Liujia"}]},
        {"id":"TRANSGRP_meilidao","m":["KRTC-O5","KRTC-R10"],"name":"美麗島","en":"Formosa Boulevard","cls":3,"r":"kaohsiung","g":"TRANSGRP_meilidao"},
        {"id":"TRANSGRP_meishuguan","m":["TRA-4370","KLRT-C20"],"name":"美術館","en":"Museum of Fine Arts","cls":3,"r":"kaohsiung","g":"TRANSGRP_meishuguan","main":["TRA-4370"],"sub":[{"m":["KLRT-C20"],"name":"台鐵美術館","en":"TRA Museum of Fine Ar…"}]},
        {"id":"TRANSGRP_minquanxilu","m":["TRTC-R13","TRTC-O11"],"name":"民權西路","en":"Minquan W. Rd.","cls":3,"r":"taipei","g":"TRANSGRP_minquanxilu","lp":[22,-121.5,"left","zh"]},
        {"id":"TRANSGRP_nangangzhanlanguan","m":["TRTC-BL23","TRTC-BR24"],"name":"南港展覽館","en":"Taipei Nangang Exhibi…","cls":3,"r":"taipei","g":"TRANSGRP_nangangzhanlanguan"},
        {"id":"TRANSGRP_nanjingfuxing","m":["TRTC-BR11","TRTC-G16"],"name":"南京復興","en":"Nanjing Fuxing","cls":3,"r":"taipei","g":"TRANSGRP_nanjingfuxing"},
        {"id":"TRANSGRP_qiaotou","m":["TRA-4320","KRTC-R23"],"name":"橋頭","en":"Qiaotou","cls":3,"r":"kaohsiung","g":"TRANSGRP_qiaotou","main":["TRA-4320"],"sub":[{"m":["KRTC-R23"],"name":"橋頭火車站","en":"Ciaotou Station"}]},
        {"id":"TRANSGRP_sanchong","m":["TRTC-O15","TYMC-A2"],"name":"三重","en":"Sanchong","cls":3,"r":"taipei","g":"TRANSGRP_sanchong"},
        {"id":"TRANSGRP_shalun","m":["THSR-1060","TRA-4272"],"name":"高鐵台南","en":"Tainan","cls":2,"r":"tainan","g":"TRANSGRP_shalun","main":["THSR-1060"],"sub":[{"m":["TRA-4272"],"name":"沙崙","en":"Shalun"}]},
        {"id":"TRANSGRP_shisizhang","m":["NTMC-Y08","NTALRT-K09"],"name":"十四張","en":"Shisizhang","cls":3,"r":"taipei","g":"TRANSGRP_shisizhang"},
        {"id":"TRANSGRP_songjiangnanjing","m":["TRTC-G15","TRTC-O08"],"name":"松江南京","en":"Songjiang Nanjing","cls":3,"r":"taipei","g":"TRANSGRP_songjiangnanjing"},
        {"id":"TRANSGRP_songshan","m":["TRA-0990","TRTC-G19"],"name":"松山","en":"Songshan","cls":2,"r":"taipei","g":"TRANSGRP_songshan"},
        {"id":"TRANSGRP_songzhu","m":["TRA-3270","TMRT-G4"],"name":"松竹","en":"Songzhu","cls":3,"r":"taichung","g":"TRANSGRP_songzhu"},
        {"id":"TRANSGRP_taoyuan2","m":["THSR-1020","TYMC-A18"],"name":"高鐵桃園","en":"Taoyuan","cls":2,"r":"taoyuan","g":"TRANSGRP_taoyuan2","main":["THSR-1020"],"sub":[{"m":["TYMC-A18"],"name":"高鐵桃園","en":"Taoyuan HSR"}]},
        {"id":"TRANSGRP_touqianzhuang","m":["TRTC-O17","NTMC-Y18"],"name":"頭前庄","en":"Touqianzhuang","cls":3,"r":"taipei","g":"TRANSGRP_touqianzhuang"},
        {"id":"TRANSGRP_wanhua","m":["TRA-1010"],"name":"萬華","en":"Wanhua","cls":4,"r":"taipei","g":"TRANSGRP_wanhua"},
        {"id":"TRANSGRP_wuri","m":["TRA-3330","TMRT-G16"],"name":"烏日","en":"Wuri","cls":3,"r":"taichung","g":"TRANSGRP_wuri"},
        {"id":"TRANSGRP_ximen","m":["TRTC-BL11","TRTC-G12"],"name":"西門","en":"Ximen","cls":3,"r":"taipei","g":"TRANSGRP_ximen"},
        {"id":"TRANSGRP_xinbeichanyeyuanqu","m":["TYMC-A3","NTMC-Y20"],"name":"新北產業園區","en":"New Taipei Industrial…","cls":1,"r":"taipei","g":"TRANSGRP_xinbeichanyeyuanqu"},
        {"id":"TRANSGRP_xinpu","m":["TRTC-BL08"],"name":"新埔","en":"Xinpu","cls":4,"r":"taipei","g":"TRANSGRP_xinpu"},
        {"id":"TRANSGRP_yingge","m":["TRA-1070","NTMC-LB08"],"name":"鶯歌","en":"Yingge","cls":3,"r":"taipei","g":"TRANSGRP_yingge","main":["TRA-1070"],"sub":[{"m":["NTMC-LB08"],"name":"鶯歌車站","en":"Yingge Station","lp":[-425,315,"left","zh"]}],"lp":[-450,339,"right"]},
        {"id":"TRANSGRP_zhongli","m":["TRA-1100"],"name":"中壢","en":"Zhongli_Taoyuan","cls":2,"r":"taoyuan","g":"TRANSGRP_zhongli"},
        {"id":"TRANSGRP_zhongshan","m":["TRTC-G14","TRTC-R11"],"name":"中山","en":"Zhongshan","cls":3,"r":"taipei","g":"TRANSGRP_zhongshan"},
        {"id":"TRANSGRP_zhongxiaofuxing","m":["TRTC-BL15","TRTC-BR10"],"name":"忠孝復興","en":"Zhongxiao Fuxing","cls":3,"r":"taipei","g":"TRANSGRP_zhongxiaofuxing"},
        {"id":"TRANSGRP_zhongxiaoxinsheng","m":["TRTC-BL14","TRTC-O07"],"name":"忠孝新生","en":"Zhongxiao Xinsheng","cls":3,"r":"taipei","g":"TRANSGRP_zhongxiaoxinsheng"},
        {"id":"TRANSGRP_zhongzhengjiniantang","m":["TRTC-G10","TRTC-R08"],"name":"中正紀念堂","en":"Chiang Kai-Shek Memor…","cls":3,"r":"taipei","g":"TRANSGRP_zhongzhengjiniantang"},
        {"id":"TRA-0900","m":["TRA-0900"],"name":"基隆","en":"Keelung","cls":1,"r":"taipei"},
        {"id":"TRA-0910","m":["TRA-0910"],"name":"三坑","en":"Sankeng","cls":4,"r":"taipei"},
        {"id":"TRA-0920","m":["TRA-0920"],"name":"八堵","en":"Badu","cls":2,"r":"taipei"},
        {"id":"TRA-0930","m":["TRA-0930"],"name":"七堵","en":"Qidu","cls":2,"r":"taipei"},
        {"id":"TRA-0940","m":["TRA-0940"],"name":"百福","en":"Baifu","cls":4,"r":"taipei"},
        {"id":"TRA-0950","m":["TRA-0950"],"name":"五堵","en":"Wudu","cls":4,"r":"taipei"},
        {"id":"TRA-0960","m":["TRA-0960"],"name":"汐止","en":"Xizhi","cls":2,"r":"taipei"},
        {"id":"TRA-0970","m":["TRA-0970"],"name":"汐科","en":"Xike","cls":4,"r":"taipei"},
        {"id":"TRA-1030","m":["TRA-1030"],"name":"浮洲","en":"Fuzhou","cls":4,"r":"taipei"},
        {"id":"TRA-1040","m":["TRA-1040"],"name":"樹林","en":"Shulin","cls":2,"r":"taipei"},
        {"id":"TRA-1050","m":["TRA-1050"],"name":"南樹林","en":"South Shulin","cls":4,"r":"taipei"},
        {"id":"TRA-1060","m":["TRA-1060"],"name":"山佳","en":"Shanjia","cls":4,"r":"taipei"},
        {"id":"TRA-1075","m":["TRA-1075"],"name":"鳳鳴","en":"Fengming","cls":4,"r":"taipei"},
        {"id":"TRA-1080","m":["TRA-1080"],"name":"桃園","en":"Taoyuan","cls":1,"r":"taoyuan"},
        {"id":"TRA-1090","m":["TRA-1090"],"name":"內壢","en":"Neili","cls":4,"r":"taoyuan"},
        {"id":"TRA-1105","m":["TRA-1105"],"name":"平鎮","en":"Pingzhen","cls":4,"r":"taoyuan"},
        {"id":"TRA-1110","m":["TRA-1110"],"name":"埔心","en":"Puxin","cls":4,"r":"taoyuan"},
        {"id":"TRA-1120","m":["TRA-1120"],"name":"楊梅","en":"Yangmei","cls":4,"r":"taoyuan"},
        {"id":"TRA-1130","m":["TRA-1130"],"name":"富岡","en":"Fugang","cls":4,"r":"taoyuan"},
        {"id":"TRA-1140","m":["TRA-1140"],"name":"新富","en":"Xinfu","cls":4,"r":"taoyuan"},
        {"id":"TRA-1150","m":["TRA-1150"],"name":"北湖","en":"Beihu","cls":4,"r":"hsinchu"},
        {"id":"TRA-1160","m":["TRA-1160"],"name":"湖口","en":"Hukou","cls":4,"r":"hsinchu"},
        {"id":"TRA-1170","m":["TRA-1170"],"name":"新豐","en":"Xinfeng","cls":4,"r":"hsinchu"},
        {"id":"TRA-1180","m":["TRA-1180"],"name":"竹北","en":"Zhubei","cls":4,"r":"hsinchu"},
        {"id":"TRA-1190","m":["TRA-1190"],"name":"北新竹","en":"North Hsinchu","cls":4,"r":"hsinchu"},
        {"id":"TRA-1191","m":["TRA-1191"],"name":"千甲","en":"Qianjia","cls":4,"r":"hsinchu"},
        {"id":"TRA-1192","m":["TRA-1192"],"name":"新莊","en":"Xinzhuang","cls":4,"r":"hsinchu"},
        {"id":"TRA-1193","m":["TRA-1193"],"name":"竹中","en":"Zhuzhong","cls":3,"r":"hsinchu"},
        {"id":"TRA-1201","m":["TRA-1201"],"name":"上員","en":"Shangyuan","cls":4,"r":"hsinchu"},
        {"id":"TRA-1202","m":["TRA-1202"],"name":"榮華","en":"Ronghua","cls":4,"r":"hsinchu"},
        {"id":"TRA-1203","m":["TRA-1203"],"name":"竹東","en":"Zhudong","cls":4,"r":"hsinchu"},
        {"id":"TRA-1204","m":["TRA-1204"],"name":"橫山","en":"Hengshan","cls":4,"r":"hsinchu"},
        {"id":"TRA-1205","m":["TRA-1205"],"name":"九讚頭","en":"Jiuzantou","cls":4,"r":"hsinchu"},
        {"id":"TRA-1206","m":["TRA-1206"],"name":"合興","en":"Hexing","cls":4,"r":"hsinchu"},
        {"id":"TRA-1207","m":["TRA-1207"],"name":"富貴","en":"Fugui","cls":4,"r":"hsinchu"},
        {"id":"TRA-1208","m":["TRA-1208"],"name":"內灣","en":"Neiwan","cls":3,"r":"hsinchu"},
        {"id":"TRA-1210","m":["TRA-1210"],"name":"新竹","en":"Hsinchu","cls":1,"r":"hsinchu"},
        {"id":"TRA-1220","m":["TRA-1220"],"name":"三姓橋","en":"Sanxingqiao","cls":4,"r":"hsinchu"},
        {"id":"TRA-1230","m":["TRA-1230"],"name":"香山","en":"Xiangshan","cls":4,"r":"hsinchu"},
        {"id":"TRA-1240","m":["TRA-1240"],"name":"崎頂","en":"Qiding","cls":4,"r":"miaoli"},
        {"id":"TRA-1250","m":["TRA-1250"],"name":"竹南","en":"Zhunan","cls":3,"r":"miaoli"},
        {"id":"TRA-2110","m":["TRA-2110"],"name":"談文","en":"Tanwen","cls":4,"r":"miaoli"},
        {"id":"TRA-2120","m":["TRA-2120"],"name":"大山","en":"Dashan","cls":4,"r":"miaoli"},
        {"id":"TRA-2130","m":["TRA-2130"],"name":"後龍","en":"Houlong","cls":4,"r":"miaoli"},
        {"id":"TRA-2140","m":["TRA-2140"],"name":"龍港","en":"Longgang","cls":4,"r":"miaoli"},
        {"id":"TRA-2150","m":["TRA-2150"],"name":"白沙屯","en":"Baishatun","cls":4,"r":"miaoli"},
        {"id":"TRA-2160","m":["TRA-2160"],"name":"新埔","en":"Xinpu","cls":4,"r":"miaoli"},
        {"id":"TRA-2170","m":["TRA-2170"],"name":"通霄","en":"Tongxiao","cls":4,"r":"miaoli"},
        {"id":"TRA-2180","m":["TRA-2180"],"name":"苑裡","en":"Yuanli","cls":4,"r":"miaoli"},
        {"id":"TRA-2190","m":["TRA-2190"],"name":"日南","en":"Rinan","cls":4,"r":"taichung"},
        {"id":"TRA-2200","m":["TRA-2200"],"name":"大甲","en":"Dajia","cls":4,"r":"taichung"},
        {"id":"TRA-2210","m":["TRA-2210"],"name":"台中港","en":"Taichung Port","cls":4,"r":"taichung"},
        {"id":"TRA-2220","m":["TRA-2220"],"name":"清水","en":"Qingshui","cls":4,"r":"taichung"},
        {"id":"TRA-2230","m":["TRA-2230"],"name":"沙鹿","en":"Shalu","cls":4,"r":"taichung"},
        {"id":"TRA-2240","m":["TRA-2240"],"name":"龍井","en":"Longjing","cls":4,"r":"taichung"},
        {"id":"TRA-2250","m":["TRA-2250"],"name":"大肚","en":"Dadu","cls":4,"r":"taichung"},
        {"id":"TRA-2260","m":["TRA-2260"],"name":"追分","en":"Zhuifen","cls":4,"r":"taichung"},
        {"id":"TRA-3140","m":["TRA-3140"],"name":"造橋","en":"Zaoqiao","cls":4,"r":"miaoli"},
        {"id":"TRA-3160","m":["TRA-3160"],"name":"苗栗","en":"Miaoli","cls":3,"r":"miaoli"},
        {"id":"TRA-3170","m":["TRA-3170"],"name":"南勢","en":"Nanshi","cls":4,"r":"miaoli"},
        {"id":"TRA-3180","m":["TRA-3180"],"name":"銅鑼","en":"Tongluo","cls":4,"r":"miaoli"},
        {"id":"TRA-3190","m":["TRA-3190"],"name":"三義","en":"Sanyi","cls":4,"r":"miaoli"},
        {"id":"TRA-3210","m":["TRA-3210"],"name":"泰安","en":"Tai'an","cls":4,"r":"taichung"},
        {"id":"TRA-3220","m":["TRA-3220"],"name":"后里","en":"Houli","cls":4,"r":"taichung"},
        {"id":"TRA-3230","m":["TRA-3230"],"name":"豐原","en":"Fengyuan","cls":4,"r":"taichung"},
        {"id":"TRA-3240","m":["TRA-3240"],"name":"栗林","en":"Lilin","cls":4,"r":"taichung"},
        {"id":"TRA-3250","m":["TRA-3250"],"name":"潭子","en":"Tanzi","cls":4,"r":"taichung"},
        {"id":"TRA-3260","m":["TRA-3260"],"name":"頭家厝","en":"Toujiacuo","cls":4,"r":"taichung"},
        {"id":"TRA-3280","m":["TRA-3280"],"name":"太原","en":"Taiyuan","cls":4,"r":"taichung"},
        {"id":"TRA-3290","m":["TRA-3290"],"name":"精武","en":"Jingwu","cls":4,"r":"taichung"},
        {"id":"TRA-3300","m":["TRA-3300"],"name":"台中","en":"Taichung","cls":1,"r":"taichung"},
        {"id":"TRA-3310","m":["TRA-3310"],"name":"五權","en":"Wuquan","cls":4,"r":"taichung"},
        {"id":"TRA-3350","m":["TRA-3350"],"name":"成功","en":"Chenggong","cls":4,"r":"taichung"},
        {"id":"TRA-3360","m":["TRA-3360"],"name":"彰化","en":"Changhua","cls":1,"r":"changhua"},
        {"id":"TRA-3370","m":["TRA-3370"],"name":"花壇","en":"Huatan","cls":4,"r":"changhua"},
        {"id":"TRA-3380","m":["TRA-3380"],"name":"大村","en":"Dacun","cls":4,"r":"changhua"},
        {"id":"TRA-3390","m":["TRA-3390"],"name":"員林","en":"Yuanlin","cls":2,"r":"changhua"},
        {"id":"TRA-3400","m":["TRA-3400"],"name":"永靖","en":"Yongjing","cls":4,"r":"changhua"},
        {"id":"TRA-3410","m":["TRA-3410"],"name":"社頭","en":"Shetou","cls":4,"r":"changhua"},
        {"id":"TRA-3420","m":["TRA-3420"],"name":"田中","en":"Tianzhong","cls":3,"r":"changhua"},
        {"id":"TRA-3430","m":["TRA-3430"],"name":"二水","en":"Ershui","cls":3,"r":"changhua"},
        {"id":"TRA-3431","m":["TRA-3431"],"name":"源泉","en":"Yuanquan","cls":4,"r":"changhua"},
        {"id":"TRA-3432","m":["TRA-3432"],"name":"濁水","en":"Zhuoshui","cls":4,"r":"changhua"},
        {"id":"TRA-3433","m":["TRA-3433"],"name":"龍泉","en":"Longquan","cls":4,"r":"changhua"},
        {"id":"TRA-3434","m":["TRA-3434"],"name":"集集","en":"Jiji","cls":4,"r":"changhua"},
        {"id":"TRA-3435","m":["TRA-3435"],"name":"水里","en":"Shuili","cls":4,"r":"changhua"},
        {"id":"TRA-3436","m":["TRA-3436"],"name":"車埕","en":"Checheng","cls":4,"r":"changhua"},
        {"id":"TRA-3450","m":["TRA-3450"],"name":"林內","en":"Linnei","cls":4,"r":"yunlin"},
        {"id":"TRA-3460","m":["TRA-3460"],"name":"石榴","en":"Shiliu","cls":4,"r":"yunlin"},
        {"id":"TRA-3470","m":["TRA-3470"],"name":"斗六","en":"Douliu","cls":2,"r":"yunlin"},
        {"id":"TRA-3480","m":["TRA-3480"],"name":"斗南","en":"Dounan","cls":4,"r":"yunlin"},
        {"id":"TRA-3490","m":["TRA-3490"],"name":"石龜","en":"Shigui","cls":4,"r":"yunlin"},
        {"id":"TRA-4050","m":["TRA-4050"],"name":"大林","en":"Dalin","cls":4,"r":"chiayi"},
        {"id":"TRA-4060","m":["TRA-4060"],"name":"民雄","en":"Minxiong","cls":4,"r":"chiayi"},
        {"id":"TRA-4070","m":["TRA-4070"],"name":"嘉北","en":"Jiabei","cls":4,"r":"chiayi"},
        {"id":"TRA-4080","m":["TRA-4080"],"name":"嘉義","en":"Chiayi","cls":1,"r":"chiayi"},
        {"id":"TRA-4090","m":["TRA-4090"],"name":"水上","en":"Shuishang","cls":4,"r":"chiayi"},
        {"id":"TRA-4100","m":["TRA-4100"],"name":"南靖","en":"Nanjing","cls":4,"r":"chiayi"},
        {"id":"TRA-4110","m":["TRA-4110"],"name":"後壁","en":"Houbi","cls":4,"r":"tainan"},
        {"id":"TRA-4120","m":["TRA-4120"],"name":"新營","en":"Xinying","cls":3,"r":"tainan"},
        {"id":"TRA-4130","m":["TRA-4130"],"name":"柳營","en":"Liuying","cls":4,"r":"tainan"},
        {"id":"TRA-4140","m":["TRA-4140"],"name":"林鳳營","en":"Linfengying","cls":4,"r":"tainan"},
        {"id":"TRA-4150","m":["TRA-4150"],"name":"隆田","en":"Longtian","cls":4,"r":"tainan"},
        {"id":"TRA-4160","m":["TRA-4160"],"name":"拔林","en":"Balin","cls":4,"r":"tainan"},
        {"id":"TRA-4170","m":["TRA-4170"],"name":"善化","en":"Shanhua","cls":4,"r":"tainan"},
        {"id":"TRA-4180","m":["TRA-4180"],"name":"南科","en":"Nanke","cls":4,"r":"tainan"},
        {"id":"TRA-4190","m":["TRA-4190"],"name":"新市","en":"Xinshi","cls":4,"r":"tainan"},
        {"id":"TRA-4200","m":["TRA-4200"],"name":"永康","en":"Yongkang","cls":4,"r":"tainan"},
        {"id":"TRA-4210","m":["TRA-4210"],"name":"大橋","en":"Daqiao","cls":4,"r":"tainan"},
        {"id":"TRA-4220","m":["TRA-4220"],"name":"台南","en":"Tainan","cls":1,"r":"tainan"},
        {"id":"TRA-4250","m":["TRA-4250"],"name":"保安","en":"Bao'an","cls":4,"r":"tainan"},
        {"id":"TRA-4260","m":["TRA-4260"],"name":"仁德","en":"Rende","cls":4,"r":"tainan"},
        {"id":"TRA-4270","m":["TRA-4270"],"name":"中洲","en":"Zhongzhou","cls":2,"r":"tainan"},
        {"id":"TRA-4271","m":["TRA-4271"],"name":"長榮大學","en":"Chang Jung Christian …","cls":4,"r":"tainan"},
        {"id":"TRA-4290","m":["TRA-4290"],"name":"大湖","en":"Dahu","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4300","m":["TRA-4300"],"name":"路竹","en":"Luzhu","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4330","m":["TRA-4330"],"name":"楠梓","en":"Nanzi","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4350","m":["TRA-4350"],"name":"左營","en":"Zuoying","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4360","m":["TRA-4360"],"name":"內惟","en":"Neiwei","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4390","m":["TRA-4390"],"name":"三塊厝","en":"Sankuaicuo","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4410","m":["TRA-4410"],"name":"民族","en":"Minzu","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4430","m":["TRA-4430"],"name":"正義","en":"Zhengyi","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4440","m":["TRA-4440"],"name":"鳳山","en":"Fongshan","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4450","m":["TRA-4450"],"name":"後庄","en":"Houzhuang","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4460","m":["TRA-4460"],"name":"九曲堂","en":"Jiuqutang","cls":4,"r":"kaohsiung"},
        {"id":"TRA-4470","m":["TRA-4470"],"name":"六塊厝","en":"Liukuaicuo","cls":4,"r":"pingtung"},
        {"id":"TRA-5000","m":["TRA-5000"],"name":"屏東","en":"Pingtung","cls":2,"r":"pingtung"},
        {"id":"TRA-5010","m":["TRA-5010"],"name":"歸來","en":"Guilai","cls":4,"r":"pingtung"},
        {"id":"TRA-5020","m":["TRA-5020"],"name":"麟洛","en":"Linluo","cls":4,"r":"pingtung"},
        {"id":"TRA-5030","m":["TRA-5030"],"name":"西勢","en":"Xishi","cls":4,"r":"pingtung"},
        {"id":"TRA-5040","m":["TRA-5040"],"name":"竹田","en":"Zhutian","cls":4,"r":"pingtung"},
        {"id":"TRA-5050","m":["TRA-5050"],"name":"潮州","en":"Chaozhou","cls":2,"r":"pingtung"},
        {"id":"TRA-5060","m":["TRA-5060"],"name":"崁頂","en":"Kanding","cls":4,"r":"pingtung"},
        {"id":"TRA-5070","m":["TRA-5070"],"name":"南州","en":"Nanzhou","cls":4,"r":"pingtung"},
        {"id":"TRA-5080","m":["TRA-5080"],"name":"鎮安","en":"Zhen'an","cls":4,"r":"pingtung"},
        {"id":"TRA-5090","m":["TRA-5090"],"name":"林邊","en":"Linbian","cls":4,"r":"pingtung"},
        {"id":"TRA-5100","m":["TRA-5100"],"name":"佳冬","en":"Jiadong","cls":4,"r":"pingtung"},
        {"id":"TRA-5110","m":["TRA-5110"],"name":"東海","en":"Donghai","cls":4,"r":"pingtung"},
        {"id":"TRA-5120","m":["TRA-5120"],"name":"枋寮","en":"Fangliao","cls":4,"r":"pingtung"},
        {"id":"TRA-5130","m":["TRA-5130"],"name":"加祿","en":"Jialu","cls":4,"r":"pingtung"},
        {"id":"TRA-5140","m":["TRA-5140"],"name":"內獅","en":"Neishi","cls":4,"r":"pingtung"},
        {"id":"TRA-5160","m":["TRA-5160"],"name":"枋山","en":"Fangshan","cls":4,"r":"pingtung"},
        {"id":"TRA-5170","m":["TRA-5170"],"name":"枋野","en":"Fangye","cls":3,"r":"pingtung"},
        {"id":"TRA-5190","m":["TRA-5190"],"name":"大武","en":"Dawu","cls":4,"r":"taitung"},
        {"id":"TRA-5200","m":["TRA-5200"],"name":"瀧溪","en":"Longxi","cls":4,"r":"taitung"},
        {"id":"TRA-5210","m":["TRA-5210"],"name":"金崙","en":"Jinlun","cls":4,"r":"taitung"},
        {"id":"TRA-5220","m":["TRA-5220"],"name":"太麻里","en":"Taimali","cls":4,"r":"taitung"},
        {"id":"TRA-5230","m":["TRA-5230"],"name":"知本","en":"Zhiben","cls":4,"r":"taitung"},
        {"id":"TRA-5240","m":["TRA-5240"],"name":"康樂","en":"Kangle","cls":4,"r":"taitung"},
        {"id":"TRA-6000","m":["TRA-6000"],"name":"台東","en":"Taitung","cls":2,"r":"taitung"},
        {"id":"TRA-6010","m":["TRA-6010"],"name":"山里","en":"Shanli","cls":4,"r":"taitung"},
        {"id":"TRA-6020","m":["TRA-6020"],"name":"鹿野","en":"Luye","cls":4,"r":"taitung"},
        {"id":"TRA-6030","m":["TRA-6030"],"name":"瑞源","en":"Ruiyuan","cls":4,"r":"taitung"},
        {"id":"TRA-6040","m":["TRA-6040"],"name":"瑞和","en":"Ruihe","cls":4,"r":"taitung"},
        {"id":"TRA-6050","m":["TRA-6050"],"name":"關山","en":"Guanshan","cls":4,"r":"taitung"},
        {"id":"TRA-6060","m":["TRA-6060"],"name":"海端","en":"Haiduan","cls":4,"r":"taitung"},
        {"id":"TRA-6070","m":["TRA-6070"],"name":"池上","en":"Chishang","cls":4,"r":"taitung"},
        {"id":"TRA-6080","m":["TRA-6080"],"name":"富里","en":"Fuli","cls":4,"r":"hualien"},
        {"id":"TRA-6090","m":["TRA-6090"],"name":"東竹","en":"Dongzhu","cls":4,"r":"hualien"},
        {"id":"TRA-6100","m":["TRA-6100"],"name":"東里","en":"Dongli","cls":4,"r":"hualien"},
        {"id":"TRA-6110","m":["TRA-6110"],"name":"玉里","en":"Yuli","cls":4,"r":"hualien"},
        {"id":"TRA-6120","m":["TRA-6120"],"name":"三民","en":"Sanmin","cls":4,"r":"hualien"},
        {"id":"TRA-6130","m":["TRA-6130"],"name":"瑞穗","en":"Ruisui","cls":4,"r":"hualien"},
        {"id":"TRA-6140","m":["TRA-6140"],"name":"富源","en":"Fuyuan","cls":4,"r":"hualien"},
        {"id":"TRA-6150","m":["TRA-6150"],"name":"大富","en":"Dafu","cls":4,"r":"hualien"},
        {"id":"TRA-6160","m":["TRA-6160"],"name":"光復","en":"Guangfu","cls":4,"r":"hualien"},
        {"id":"TRA-6170","m":["TRA-6170"],"name":"萬榮","en":"Wanrong","cls":4,"r":"hualien"},
        {"id":"TRA-6180","m":["TRA-6180"],"name":"鳳林","en":"Fenglin","cls":4,"r":"hualien"},
        {"id":"TRA-6190","m":["TRA-6190"],"name":"南平","en":"Nanping","cls":4,"r":"hualien"},
        {"id":"TRA-6200","m":["TRA-6200"],"name":"林榮新光","en":"Linrong Shin Kong","cls":4,"r":"hualien"},
        {"id":"TRA-6210","m":["TRA-6210"],"name":"豐田","en":"Fengtian","cls":4,"r":"hualien"},
        {"id":"TRA-6220","m":["TRA-6220"],"name":"壽豐","en":"Shoufeng","cls":4,"r":"hualien"},
        {"id":"TRA-6230","m":["TRA-6230"],"name":"平和","en":"Pinghe","cls":4,"r":"hualien"},
        {"id":"TRA-6240","m":["TRA-6240"],"name":"志學","en":"Zhixue","cls":4,"r":"hualien"},
        {"id":"TRA-6250","m":["TRA-6250"],"name":"吉安","en":"Ji'an","cls":4,"r":"hualien"},
        {"id":"TRA-7000","m":["TRA-7000"],"name":"花蓮","en":"Hualien","cls":0,"r":"hualien"},
        {"id":"TRA-7010","m":["TRA-7010"],"name":"北埔","en":"Beipu","cls":4,"r":"hualien"},
        {"id":"TRA-7020","m":["TRA-7020"],"name":"景美","en":"Jingmei","cls":4,"r":"hualien"},
        {"id":"TRA-7030","m":["TRA-7030"],"name":"新城","en":"Xincheng","cls":4,"r":"hualien"},
        {"id":"TRA-7040","m":["TRA-7040"],"name":"崇德","en":"Chongde","cls":4,"r":"hualien"},
        {"id":"TRA-7050","m":["TRA-7050"],"name":"和仁","en":"Heren","cls":4,"r":"hualien"},
        {"id":"TRA-7060","m":["TRA-7060"],"name":"和平","en":"Heping","cls":4,"r":"hualien"},
        {"id":"TRA-7070","m":["TRA-7070"],"name":"漢本","en":"Hanben","cls":4,"r":"yilan"},
        {"id":"TRA-7080","m":["TRA-7080"],"name":"武塔","en":"Wuta","cls":4,"r":"yilan"},
        {"id":"TRA-7090","m":["TRA-7090"],"name":"南澳","en":"Nan'ao","cls":4,"r":"yilan"},
        {"id":"TRA-7100","m":["TRA-7100"],"name":"東澳","en":"Dong'ao","cls":4,"r":"yilan"},
        {"id":"TRA-7110","m":["TRA-7110"],"name":"永樂","en":"Yongle","cls":5,"r":"yilan"},
        {"id":"TRA-7120","m":["TRA-7120"],"name":"蘇澳","en":"Su'ao","cls":2,"r":"yilan"},
        {"id":"TRA-7130","m":["TRA-7130"],"name":"蘇澳新","en":"Su'aoxin","cls":3,"r":"yilan"},
        {"id":"TRA-7140","m":["TRA-7140"],"name":"新馬","en":"Xinma","cls":4,"r":"yilan"},
        {"id":"TRA-7150","m":["TRA-7150"],"name":"冬山","en":"Dongshan","cls":4,"r":"yilan"},
        {"id":"TRA-7160","m":["TRA-7160"],"name":"羅東","en":"Luodong","cls":2,"r":"yilan"},
        {"id":"TRA-7170","m":["TRA-7170"],"name":"中里","en":"Zhongli_Yilan","cls":4,"r":"yilan"},
        {"id":"TRA-7180","m":["TRA-7180"],"name":"二結","en":"Erjie","cls":4,"r":"yilan"},
        {"id":"TRA-7190","m":["TRA-7190"],"name":"宜蘭","en":"Yilan","cls":2,"r":"yilan"},
        {"id":"TRA-7200","m":["TRA-7200"],"name":"四城","en":"Sicheng","cls":4,"r":"yilan"},
        {"id":"TRA-7210","m":["TRA-7210"],"name":"礁溪","en":"Jiaoxi","cls":4,"r":"yilan"},
        {"id":"TRA-7220","m":["TRA-7220"],"name":"頂埔","en":"Dingpu","cls":4,"r":"yilan"},
        {"id":"TRA-7230","m":["TRA-7230"],"name":"頭城","en":"Toucheng","cls":4,"r":"yilan"},
        {"id":"TRA-7240","m":["TRA-7240"],"name":"外澳","en":"Wai'ao","cls":4,"r":"yilan"},
        {"id":"TRA-7250","m":["TRA-7250"],"name":"龜山","en":"Guishan","cls":4,"r":"yilan"},
        {"id":"TRA-7260","m":["TRA-7260"],"name":"大溪","en":"Daxi","cls":4,"r":"yilan"},
        {"id":"TRA-7270","m":["TRA-7270"],"name":"大里","en":"Dali","cls":4,"r":"yilan"},
        {"id":"TRA-7280","m":["TRA-7280"],"name":"石城","en":"Shicheng","cls":4,"r":"yilan"},
        {"id":"TRA-7290","m":["TRA-7290"],"name":"福隆","en":"Fulong","cls":3,"r":"taipei"},
        {"id":"TRA-7300","m":["TRA-7300"],"name":"貢寮","en":"Gongliao","cls":4,"r":"taipei"},
        {"id":"TRA-7310","m":["TRA-7310"],"name":"雙溪","en":"Shuangxi","cls":4,"r":"taipei"},
        {"id":"TRA-7320","m":["TRA-7320"],"name":"牡丹","en":"Mudan","cls":4,"r":"taipei"},
        {"id":"TRA-7330","m":["TRA-7330"],"name":"三貂嶺","en":"Sandiaoling","cls":4,"r":"taipei"},
        {"id":"TRA-7331","m":["TRA-7331"],"name":"大華","en":"Dahua","cls":4,"r":"taipei"},
        {"id":"TRA-7332","m":["TRA-7332"],"name":"十分","en":"Shifen","cls":4,"r":"taipei"},
        {"id":"TRA-7333","m":["TRA-7333"],"name":"望古","en":"Wanggu","cls":4,"r":"taipei"},
        {"id":"TRA-7334","m":["TRA-7334"],"name":"嶺腳","en":"Lingjiao","cls":4,"r":"taipei"},
        {"id":"TRA-7335","m":["TRA-7335"],"name":"平溪","en":"Pingxi","cls":4,"r":"taipei"},
        {"id":"TRA-7336","m":["TRA-7336"],"name":"菁桐","en":"Jingtong","cls":4,"r":"taipei"},
        {"id":"TRA-7350","m":["TRA-7350"],"name":"猴硐","en":"Houtong","cls":4,"r":"taipei"},
        {"id":"TRA-7360","m":["TRA-7360"],"name":"瑞芳","en":"Ruifang","cls":3,"r":"taipei"},
        {"id":"TRA-7361","m":["TRA-7361"],"name":"海科館","en":"Haikeguan","cls":4,"r":"taipei"},
        {"id":"TRA-7362","m":["TRA-7362"],"name":"八斗子","en":"Badouzi","cls":4,"r":"taipei"},
        {"id":"TRA-7380","m":["TRA-7380"],"name":"四腳亭","en":"Sijiaoting","cls":4,"r":"taipei"},
        {"id":"TRA-7390","m":["TRA-7390"],"name":"暖暖","en":"Nuannuan","cls":4,"r":"taipei"},
        {"id":"THSR-1043","m":["THSR-1043"],"name":"高鐵彰化","en":"Changhua","cls":2,"r":"changhua"},
        {"id":"THSR-1047","m":["THSR-1047"],"name":"雲林","en":"Yunlin","cls":2,"r":"yunlin"},
        {"id":"THSR-1050","m":["THSR-1050"],"name":"高鐵嘉義","en":"Chiayi","cls":2,"r":"chiayi"},
        {"id":"TRTC-BL02","m":["TRTC-BL02"],"name":"永寧","en":"Yongning","cls":4,"r":"taipei"},
        {"id":"TRTC-BL03","m":["TRTC-BL03"],"name":"土城","en":"Tucheng","cls":4,"r":"taipei"},
        {"id":"TRTC-BL04","m":["TRTC-BL04"],"name":"海山","en":"Haishan","cls":4,"r":"taipei"},
        {"id":"TRTC-BL05","m":["TRTC-BL05"],"name":"亞東醫院","en":"Far Eastern Hospital","cls":4,"r":"taipei"},
        {"id":"TRTC-BL06","m":["TRTC-BL06"],"name":"府中","en":"Fuzhong","cls":4,"r":"taipei"},
        {"id":"TRTC-BL09","m":["TRTC-BL09"],"name":"江子翠","en":"Jiangzicui","cls":4,"r":"taipei"},
        {"id":"TRTC-BL10","m":["TRTC-BL10"],"name":"龍山寺","en":"Longshan Temple","cls":4,"r":"taipei","g":"TRANSGRP_wanhua","small":1},
        {"id":"TRTC-BL13","m":["TRTC-BL13"],"name":"善導寺","en":"Shandao Temple","cls":4,"r":"taipei"},
        {"id":"TRTC-BL16","m":["TRTC-BL16"],"name":"忠孝敦化","en":"Zhongxiao Dunhua","cls":4,"r":"taipei"},
        {"id":"TRTC-BL17","m":["TRTC-BL17"],"name":"國父紀念館","en":"Sun Yat-Sen Memorial …","cls":4,"r":"taipei"},
        {"id":"TRTC-BL18","m":["TRTC-BL18"],"name":"市政府","en":"Taipei City Hall","cls":3,"r":"taipei"},
        {"id":"TRTC-BL19","m":["TRTC-BL19"],"name":"永春","en":"Yongchun","cls":4,"r":"taipei"},
        {"id":"TRTC-BL20","m":["TRTC-BL20"],"name":"後山埤","en":"Houshanpi","cls":4,"r":"taipei"},
        {"id":"TRTC-BL21","m":["TRTC-BL21"],"name":"昆陽","en":"Kunyang","cls":4,"r":"taipei"},
        {"id":"TRTC-BR01","m":["TRTC-BR01"],"name":"動物園","en":"Taipei Zoo","cls":3,"r":"taipei"},
        {"id":"TRTC-BR02","m":["TRTC-BR02"],"name":"木柵","en":"Muzha","cls":4,"r":"taipei"},
        {"id":"TRTC-BR03","m":["TRTC-BR03"],"name":"萬芳社區","en":"Wanfang Community","cls":4,"r":"taipei"},
        {"id":"TRTC-BR04","m":["TRTC-BR04"],"name":"萬芳醫院","en":"Wanfang Hospital","cls":4,"r":"taipei"},
        {"id":"TRTC-BR05","m":["TRTC-BR05"],"name":"辛亥","en":"Xinhai","cls":4,"r":"taipei"},
        {"id":"TRTC-BR06","m":["TRTC-BR06"],"name":"麟光","en":"Linguang","cls":4,"r":"taipei"},
        {"id":"TRTC-BR07","m":["TRTC-BR07"],"name":"六張犁","en":"Liuzhangli","cls":4,"r":"taipei"},
        {"id":"TRTC-BR08","m":["TRTC-BR08"],"name":"科技大樓","en":"Technology Building","cls":4,"r":"taipei"},
        {"id":"TRTC-BR12","m":["TRTC-BR12"],"name":"中山國中","en":"Zhongshan Junior High…","cls":4,"r":"taipei"},
        {"id":"TRTC-BR13","m":["TRTC-BR13"],"name":"松山機場","en":"Songshan Airport","cls":4,"r":"taipei"},
        {"id":"TRTC-BR14","m":["TRTC-BR14"],"name":"大直","en":"Dazhi","cls":4,"r":"taipei"},
        {"id":"TRTC-BR15","m":["TRTC-BR15"],"name":"劍南路","en":"Jiannan Rd.","cls":4,"r":"taipei"},
        {"id":"TRTC-BR16","m":["TRTC-BR16"],"name":"西湖","en":"Xihu","cls":4,"r":"taipei"},
        {"id":"TRTC-BR17","m":["TRTC-BR17"],"name":"港墘","en":"Gangqian","cls":4,"r":"taipei"},
        {"id":"TRTC-BR18","m":["TRTC-BR18"],"name":"文德","en":"Wende","cls":4,"r":"taipei"},
        {"id":"TRTC-BR19","m":["TRTC-BR19"],"name":"內湖","en":"Neihu","cls":4,"r":"taipei"},
        {"id":"TRTC-BR20","m":["TRTC-BR20"],"name":"大湖公園","en":"Dahu Park","cls":4,"r":"taipei"},
        {"id":"TRTC-BR21","m":["TRTC-BR21"],"name":"葫洲","en":"Huzhou","cls":4,"r":"taipei"},
        {"id":"TRTC-BR22","m":["TRTC-BR22"],"name":"東湖","en":"Donghu","cls":4,"r":"taipei"},
        {"id":"TRTC-BR23","m":["TRTC-BR23"],"name":"南港軟體園區","en":"Nangang Software Park","cls":4,"r":"taipei"},
        {"id":"TRTC-G01","m":["TRTC-G01"],"name":"新店","en":"Xindian","cls":3,"r":"taipei"},
        {"id":"TRTC-G02","m":["TRTC-G02"],"name":"新店區公所","en":"Xindian District Offi…","cls":4,"r":"taipei","lp":[190,434,"left"]},
        {"id":"TRTC-G03","m":["TRTC-G03"],"name":"七張","en":"Qizhang","cls":4,"r":"taipei","lp":[148,400,"left"]},
        {"id":"TRTC-G05","m":["TRTC-G05"],"name":"景美","en":"Jingmei","cls":4,"r":"taipei"},
        {"id":"TRTC-G06","m":["TRTC-G06"],"name":"萬隆","en":"Wanlong","cls":4,"r":"taipei"},
        {"id":"TRTC-G07","m":["TRTC-G07"],"name":"公館","en":"Gongguan","cls":4,"r":"taipei"},
        {"id":"TRTC-G08","m":["TRTC-G08"],"name":"台電大樓","en":"Taipower Building","cls":4,"r":"taipei"},
        {"id":"TRTC-G11","m":["TRTC-G11"],"name":"小南門","en":"Xiaonanmen","cls":4,"r":"taipei"},
        {"id":"TRTC-G17","m":["TRTC-G17"],"name":"台北小巨蛋","en":"Taipei Arena","cls":4,"r":"taipei"},
        {"id":"TRTC-G18","m":["TRTC-G18"],"name":"南京三民","en":"Nanjing Sanmin","cls":4,"r":"taipei"},
        {"id":"TRTC-G03A","m":["TRTC-G03A"],"name":"小碧潭","en":"Xiaobitan","cls":4,"r":"taipei"},
        {"id":"TRTC-O01","m":["TRTC-O01"],"name":"南勢角","en":"Nanshijiao","cls":3,"r":"taipei"},
        {"id":"TRTC-O03","m":["TRTC-O03"],"name":"永安市場","en":"Yongan Market","cls":4,"r":"taipei"},
        {"id":"TRTC-O04","m":["TRTC-O04"],"name":"頂溪","en":"Dingxi","cls":4,"r":"taipei"},
        {"id":"TRTC-O09","m":["TRTC-O09"],"name":"行天宮","en":"Xingtian Temple","cls":4,"r":"taipei"},
        {"id":"TRTC-O10","m":["TRTC-O10"],"name":"中山國小","en":"Zhongshan Elementary …","cls":4,"r":"taipei"},
        {"id":"TRTC-O12","m":["TRTC-O12"],"name":"大橋頭","en":"Daqiaotou","cls":4,"r":"taipei"},
        {"id":"TRTC-O13","m":["TRTC-O13"],"name":"台北橋","en":"Taipei Bridge","cls":4,"r":"taipei"},
        {"id":"TRTC-O14","m":["TRTC-O14"],"name":"菜寮","en":"Cailiao","cls":4,"r":"taipei"},
        {"id":"TRTC-O16","m":["TRTC-O16"],"name":"先嗇宮","en":"Xianse Temple","cls":4,"r":"taipei"},
        {"id":"TRTC-O18","m":["TRTC-O18"],"name":"新莊","en":"Xinzhuang","cls":4,"r":"taipei"},
        {"id":"TRTC-O19","m":["TRTC-O19"],"name":"輔大","en":"Fu Jen University","cls":4,"r":"taipei"},
        {"id":"TRTC-O20","m":["TRTC-O20"],"name":"丹鳳","en":"Danfeng","cls":4,"r":"taipei"},
        {"id":"TRTC-O21","m":["TRTC-O21"],"name":"迴龍","en":"Huilong","cls":3,"r":"taipei"},
        {"id":"TRTC-O50","m":["TRTC-O50"],"name":"三重國小","en":"Sanchong Elementary S…","cls":4,"r":"taipei"},
        {"id":"TRTC-O51","m":["TRTC-O51"],"name":"三和國中","en":"Sanhe Junior High Sch…","cls":4,"r":"taipei"},
        {"id":"TRTC-O52","m":["TRTC-O52"],"name":"徐匯中學","en":"St.lgnatius High Scho…","cls":4,"r":"taipei"},
        {"id":"TRTC-O53","m":["TRTC-O53"],"name":"三民高中","en":"Sanmin Senior High Sc…","cls":4,"r":"taipei"},
        {"id":"TRTC-O54","m":["TRTC-O54"],"name":"蘆洲","en":"Luzhou","cls":3,"r":"taipei"},
        {"id":"TRTC-R01","m":["TRTC-R01"],"name":"廣慈/奉天宮","en":"Guangci/Fengtian Temp…","cls":3,"r":"taipei"},
        {"id":"TRTC-R02","m":["TRTC-R02"],"name":"象山","en":"Xiangshan","cls":3,"r":"taipei"},
        {"id":"TRTC-R03","m":["TRTC-R03"],"name":"台北101/世貿","en":"Taipei 101/World Trad…","cls":4,"r":"taipei"},
        {"id":"TRTC-R04","m":["TRTC-R04"],"name":"信義安和","en":"Xinyi Anhe","cls":4,"r":"taipei"},
        {"id":"TRTC-R06","m":["TRTC-R06"],"name":"大安森林公園","en":"Daan Park","cls":4,"r":"taipei"},
        {"id":"TRTC-R09","m":["TRTC-R09"],"name":"台大醫院","en":"NTU Hospital","cls":4,"r":"taipei"},
        {"id":"TRTC-R12","m":["TRTC-R12"],"name":"雙連","en":"Shuanglian","cls":4,"r":"taipei"},
        {"id":"TRTC-R14","m":["TRTC-R14"],"name":"圓山","en":"Yuanshan","cls":4,"r":"taipei"},
        {"id":"TRTC-R15","m":["TRTC-R15"],"name":"劍潭","en":"Jiantan","cls":4,"r":"taipei"},
        {"id":"TRTC-R16","m":["TRTC-R16"],"name":"士林","en":"Shilin","cls":4,"r":"taipei"},
        {"id":"TRTC-R17","m":["TRTC-R17"],"name":"芝山","en":"Zhishan","cls":4,"r":"taipei"},
        {"id":"TRTC-R18","m":["TRTC-R18"],"name":"明德","en":"Mingde","cls":4,"r":"taipei"},
        {"id":"TRTC-R19","m":["TRTC-R19"],"name":"石牌","en":"Shipai","cls":4,"r":"taipei","lp":[-12,-255,"right"]},
        {"id":"TRTC-R20","m":["TRTC-R20"],"name":"唭哩岸","en":"Qilian","cls":4,"r":"taipei","lp":[22,-275,"left"]},
        {"id":"TRTC-R21","m":["TRTC-R21"],"name":"奇岩","en":"Qiyan","cls":4,"r":"taipei","lp":[-12,-305,"right"]},
        {"id":"TRTC-R22","m":["TRTC-R22"],"name":"北投","en":"Beitou","cls":3,"r":"taipei"},
        {"id":"TRTC-R23","m":["TRTC-R23"],"name":"復興崗","en":"Fuxinggang","cls":4,"r":"taipei"},
        {"id":"TRTC-R24","m":["TRTC-R24"],"name":"忠義","en":"Zhongyi","cls":4,"r":"taipei"},
        {"id":"TRTC-R25","m":["TRTC-R25"],"name":"關渡","en":"Guandu","cls":4,"r":"taipei"},
        {"id":"TRTC-R26","m":["TRTC-R26"],"name":"竹圍","en":"Zhuwei","cls":4,"r":"taipei"},
        {"id":"TRTC-R28","m":["TRTC-R28"],"name":"淡水","en":"Tamsui","cls":3,"r":"taipei"},
        {"id":"TRTC-R22A","m":["TRTC-R22A"],"name":"新北投","en":"Xinbeitou","cls":3,"r":"taipei"},
        {"id":"NTMC-LB02","m":["NTMC-LB02"],"name":"媽祖田","en":"Mazutian","cls":4,"r":"taipei"},
        {"id":"NTMC-LB03","m":["NTMC-LB03"],"name":"長壽山","en":"Changshoushan","cls":4,"r":"taipei"},
        {"id":"NTMC-LB04","m":["NTMC-LB04"],"name":"橫溪","en":"Hengxi","cls":4,"r":"taipei"},
        {"id":"NTMC-LB05","m":["NTMC-LB05"],"name":"龍埔","en":"Longpu","cls":4,"r":"taipei"},
        {"id":"NTMC-LB06","m":["NTMC-LB06"],"name":"三峽","en":"Sanxia","cls":4,"r":"taipei","lp":[-398,400,"left"]},
        {"id":"NTMC-LB07","m":["NTMC-LB07"],"name":"台北大學","en":"National Taipei Unive…","cls":4,"r":"taipei","lp":[-345,360,"center"]},
        {"id":"NTMC-LB09","m":["NTMC-LB09"],"name":"陶瓷老街","en":"Ceramics Old Street","cls":4,"r":"taipei","lp":[-426,372,"right"]},
        {"id":"NTMC-LB10","m":["NTMC-LB10"],"name":"國華","en":"Guohua","cls":4,"r":"taipei","lp":[-412,393,"right"]},
        {"id":"NTMC-LB11","m":["NTMC-LB11"],"name":"永吉公園","en":"Yongji Park","cls":4,"r":"taipei"},
        {"id":"NTMC-LB12","m":["NTMC-LB12"],"name":"鶯桃福德","en":"Yingtao Fude","cls":4,"r":"taipei"},
        {"id":"NTMC-Y09","m":["NTMC-Y09"],"name":"秀朗橋","en":"Xiulang Bridge","cls":4,"r":"taipei"},
        {"id":"NTMC-Y10","m":["NTMC-Y10"],"name":"景平","en":"Jingping","cls":4,"r":"taipei"},
        {"id":"NTMC-Y12","m":["NTMC-Y12"],"name":"中和","en":"Zhonghe","cls":3,"r":"taipei"},
        {"id":"NTMC-Y13","m":["NTMC-Y13"],"name":"橋和","en":"Qiaohe","cls":4,"r":"taipei"},
        {"id":"NTMC-Y14","m":["NTMC-Y14"],"name":"中原","en":"Zhongyuan","cls":4,"r":"taipei"},
        {"id":"NTMC-Y15","m":["NTMC-Y15"],"name":"板新","en":"Banxin","cls":4,"r":"taipei"},
        {"id":"NTMC-Y17","m":["NTMC-Y17"],"name":"新埔民生","en":"Xinpu Minsheng","cls":4,"r":"taipei","g":"TRANSGRP_xinpu","small":1},
        {"id":"NTMC-Y19","m":["NTMC-Y19"],"name":"幸福","en":"Xingfu","cls":4,"r":"taipei"},
        {"id":"NTDLRT-V02","m":["NTDLRT-V02"],"name":"竿蓁林","en":"Ganzhenlin","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V03","m":["NTDLRT-V03"],"name":"淡金鄧公","en":"Danjin Denggong","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V04","m":["NTDLRT-V04"],"name":"淡江大學","en":"Tamkang University","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V05","m":["NTDLRT-V05"],"name":"淡金北新","en":"Danjin Beixin","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V06","m":["NTDLRT-V06"],"name":"新市一路","en":"Xinshi 1st Rd.","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V07","m":["NTDLRT-V07"],"name":"淡水行政中心","en":"Tamsui District Office","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V08","m":["NTDLRT-V08"],"name":"濱海義山","en":"Binhai Yishan","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V09","m":["NTDLRT-V09"],"name":"濱海沙崙","en":"Binhai Shalun","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V10","m":["NTDLRT-V10"],"name":"淡海新市鎮","en":"Danhai New Town","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V11","m":["NTDLRT-V11"],"name":"崁頂","en":"Kanding","cls":5,"r":"taipei"},
        {"id":"NTDLRT-V28","m":["NTDLRT-V28"],"name":"台北海洋大學","en":"Taipei University of …","cls":5,"r":"taipei","lp":[-485,-365,"left","zh"]},
        {"id":"NTDLRT-V27","m":["NTDLRT-V27"],"name":"沙崙","en":"Shalun","cls":5,"r":"taipei","lp":[-485,-345,"left"]},
        {"id":"NTDLRT-V26","m":["NTDLRT-V26"],"name":"淡水漁人碼頭","en":"Tamsui Fisherman's Wh…","cls":5,"r":"taipei","lp":[-490,-311,"center"]},
        {"id":"NTALRT-K01","m":["NTALRT-K01"],"name":"雙城","en":"Shuangcheng","cls":5,"r":"taipei"},
        {"id":"NTALRT-K02","m":["NTALRT-K02"],"name":"玫瑰中國城","en":"Rose China Town","cls":5,"r":"taipei"},
        {"id":"NTALRT-K03","m":["NTALRT-K03"],"name":"台北小城","en":"Taipei Xiaocheng","cls":5,"r":"taipei"},
        {"id":"NTALRT-K04","m":["NTALRT-K04"],"name":"耕莘安康院區","en":"Cardinal Tien Hospita…","cls":5,"r":"taipei"},
        {"id":"NTALRT-K05","m":["NTALRT-K05"],"name":"景文科大","en":"Jinwen University of …","cls":5,"r":"taipei"},
        {"id":"NTALRT-K06","m":["NTALRT-K06"],"name":"安康","en":"Ankang","cls":5,"r":"taipei"},
        {"id":"NTALRT-K07","m":["NTALRT-K07"],"name":"陽光運動公園","en":"Sunshine Sports Park","cls":5,"r":"taipei"},
        {"id":"NTALRT-K08","m":["NTALRT-K08"],"name":"新和國小","en":"Xinhe Elementary Scho…","cls":5,"r":"taipei"},
        {"id":"KRTC-O2","m":["KRTC-O2"],"name":"鹽埕埔","en":"Yanchengpu","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O4","m":["KRTC-O4"],"name":"前金","en":"Cianjin","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O6","m":["KRTC-O6"],"name":"信義國小","en":"Sinyi Elementary Scho…","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O7","m":["KRTC-O7"],"name":"文化中心","en":"Cultural Center","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O8","m":["KRTC-O8"],"name":"五塊厝","en":"Wukuaicuo","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O9","m":["KRTC-O9"],"name":"苓雅運動園區","en":"Lingya Sports Park","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O10","m":["KRTC-O10"],"name":"衛武營","en":"Weiwuying","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O11","m":["KRTC-O11"],"name":"鳳山西","en":"Fongshan West","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O12","m":["KRTC-O12"],"name":"鳳山","en":"Fongshan","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O13","m":["KRTC-O13"],"name":"大東","en":"Dadong","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-O14","m":["KRTC-O14"],"name":"鳳山國中","en":"Fongshan Junior High …","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-OT1","m":["KRTC-OT1"],"name":"大寮","en":"Daliao","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R3","m":["KRTC-R3"],"name":"小港","en":"Siaogang","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R4","m":["KRTC-R4"],"name":"高雄國際機場","en":"Kaohsiung Internation…","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R4A","m":["KRTC-R4A"],"name":"草衙","en":"Caoya","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R5","m":["KRTC-R5"],"name":"前鎮高中","en":"Cianjhen Senior High …","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R7","m":["KRTC-R7"],"name":"獅甲","en":"Shihjia","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R8","m":["KRTC-R8"],"name":"三多商圈","en":"Sanduo Shopping Distr…","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R9","m":["KRTC-R9"],"name":"中央公園","en":"Central Park","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R12","m":["KRTC-R12"],"name":"後驛","en":"Houyi","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R14","m":["KRTC-R14"],"name":"巨蛋","en":"Kaohsiung Arena","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R15","m":["KRTC-R15"],"name":"生態園區","en":"Ecological District","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R17","m":["KRTC-R17"],"name":"世運","en":"World Game","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R18","m":["KRTC-R18"],"name":"油廠國小","en":"Oil Refinery Elementa…","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R19","m":["KRTC-R19"],"name":"楠梓科技園區","en":"Nanzih Technology Ind…","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R20","m":["KRTC-R20"],"name":"後勁","en":"Houjing","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R21","m":["KRTC-R21"],"name":"都會公園","en":"Metropolitan Park","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R22","m":["KRTC-R22"],"name":"青埔","en":"Cingpu","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R22A","m":["KRTC-R22A"],"name":"橋頭糖廠","en":"Ciaotou Sugar Refinery","cls":4,"r":"kaohsiung"},
        {"id":"KRTC-R24","m":["KRTC-R24"],"name":"岡山高醫","en":"Kaohsiung Medical Uni…","cls":4,"r":"kaohsiung"},
        {"id":"KLRT-C1","m":["KLRT-C1"],"name":"籬仔內","en":"Lizihnei","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C2","m":["KLRT-C2"],"name":"凱旋瑞田","en":"Kaisyuan Rueitian","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C4","m":["KLRT-C4"],"name":"凱旋中華","en":"Kaisyuan Jhonghua","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C5","m":["KLRT-C5"],"name":"夢時代","en":"Dream Mall","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C6","m":["KLRT-C6"],"name":"經貿園區","en":"Commerce and Trade Pa…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C7","m":["KLRT-C7"],"name":"軟體園區","en":"Software Technology P…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C8","m":["KLRT-C8"],"name":"高雄展覽館","en":"Kaohsiung Exhibition …","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C9","m":["KLRT-C9"],"name":"旅運中心","en":"Cruise","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C10","m":["KLRT-C10"],"name":"光榮碼頭","en":"Glory Pier","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C11","m":["KLRT-C11"],"name":"真愛碼頭","en":"Love Pier","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C12","m":["KLRT-C12"],"name":"駁二大義","en":"Dayi Pier-2","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C13","m":["KLRT-C13"],"name":"駁二蓬萊","en":"Penglai Pier-2","cls":5,"r":"kaohsiung","lp":[-510,3153,"left","zh"]},
        {"id":"KLRT-C15","m":["KLRT-C15"],"name":"壽山公園","en":"Shoushan Park","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C16","m":["KLRT-C16"],"name":"文武聖殿","en":"Wenwu Temple","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C17","m":["KLRT-C17"],"name":"鼓山區公所","en":"Gushan District Office","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C19","m":["KLRT-C19"],"name":"馬卡道","en":"Makadao","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C21A","m":["KLRT-C21A"],"name":"內惟藝術中心","en":"Neiwei Arts Center","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C21","m":["KLRT-C21"],"name":"美術館","en":"Kaohsiung Museum of F…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C22","m":["KLRT-C22"],"name":"聯合醫院","en":"Kaohsiung Municipal U…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C23","m":["KLRT-C23"],"name":"龍華國小","en":"Longhua Elementary Sc…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C25","m":["KLRT-C25"],"name":"新上國小","en":"Sinshang Elementary S…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C26","m":["KLRT-C26"],"name":"大順民族","en":"Dashun Minzu","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C27","m":["KLRT-C27"],"name":"灣仔內(大順鼎山)","en":"Wanzihnei(Dashun Ding…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C28","m":["KLRT-C28"],"name":"高雄高工","en":"Kaohsiung Industrial …","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C29","m":["KLRT-C29"],"name":"樹德家商","en":"Shu-Te Home-Economics…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C31","m":["KLRT-C31"],"name":"聖功醫院","en":"St.Joseph Hospital","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C32","m":["KLRT-C32"],"name":"凱旋公園","en":"Kaisyuan Park","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C33","m":["KLRT-C33"],"name":"衛生局","en":"Department of Health","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C34","m":["KLRT-C34"],"name":"五權國小","en":"Wucyuan Elementary Sc…","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C35","m":["KLRT-C35"],"name":"凱旋武昌","en":"Kaisyuan Wuchang","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C36","m":["KLRT-C36"],"name":"凱旋二聖","en":"Kaisyuan Ersheng","cls":5,"r":"kaohsiung"},
        {"id":"KLRT-C37","m":["KLRT-C37"],"name":"輕軌機廠","en":"LRT Depot","cls":5,"r":"kaohsiung"},
        {"id":"TYMC-A1","m":["TYMC-A1"],"name":"台北車站","en":"Taipei Main Station","cls":0,"r":"taipei","g":"TRANSGRP_taipei","small":1},
        {"id":"TYMC-A4","m":["TYMC-A4"],"name":"新莊副都心","en":"Xinzhuang Fuduxin","cls":4,"r":"taipei","lp":[-340,-125,"left"]},
        {"id":"TYMC-A5","m":["TYMC-A5"],"name":"泰山","en":"Taishan","cls":4,"r":"taipei"},
        {"id":"TYMC-A6","m":["TYMC-A6"],"name":"泰山貴和","en":"Taishan Guihe","cls":4,"r":"taipei"},
        {"id":"TYMC-A7","m":["TYMC-A7"],"name":"體育大學","en":"National Taiwan Sport…","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A8","m":["TYMC-A8"],"name":"長庚醫院","en":"Chang Gung Memorial H…","cls":3,"r":"taoyuan"},
        {"id":"TYMC-A9","m":["TYMC-A9"],"name":"林口","en":"Linkou","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A10","m":["TYMC-A10"],"name":"山鼻","en":"Shanbi","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A11","m":["TYMC-A11"],"name":"坑口","en":"Kengkou","cls":3,"r":"taoyuan"},
        {"id":"TYMC-A12","m":["TYMC-A12"],"name":"機場第一航廈","en":"Airport Terminal 1","cls":3,"r":"taoyuan"},
        {"id":"TYMC-A13","m":["TYMC-A13"],"name":"機場第二航廈","en":"Airport Terminal 2","cls":3,"r":"taoyuan"},
        {"id":"TYMC-A14a","m":["TYMC-A14a"],"name":"機場旅館","en":"Airport Hotel","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A15","m":["TYMC-A15"],"name":"大園","en":"Dayuan","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A16","m":["TYMC-A16"],"name":"橫山","en":"Hengshan","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A17","m":["TYMC-A17"],"name":"領航","en":"Linghang","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A19","m":["TYMC-A19"],"name":"桃園體育園區","en":"Taoyuan Sports Park","cls":3,"r":"taoyuan"},
        {"id":"TYMC-A20","m":["TYMC-A20"],"name":"興南","en":"Xingnan","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A21","m":["TYMC-A21"],"name":"環北","en":"Huanbei","cls":4,"r":"taoyuan"},
        {"id":"TYMC-A22","m":["TYMC-A22"],"name":"老街溪","en":"Laojie River","cls":4,"r":"taoyuan","g":"TRANSGRP_zhongli","small":1},
        {"id":"TMRT-G0","m":["TMRT-G0"],"name":"北屯總","en":"Beitun Main","cls":4,"r":"taichung"},
        {"id":"TMRT-G3","m":["TMRT-G3"],"name":"舊社","en":"Jiushe","cls":4,"r":"taichung"},
        {"id":"TMRT-G5","m":["TMRT-G5"],"name":"四維國小","en":"Sihwei Elementary Sch…","cls":4,"r":"taichung"},
        {"id":"TMRT-G6","m":["TMRT-G6"],"name":"文心崇德","en":"Wenxin Chongde","cls":4,"r":"taichung"},
        {"id":"TMRT-G7","m":["TMRT-G7"],"name":"文心中清","en":"Wenxin Zhongqing","cls":4,"r":"taichung"},
        {"id":"TMRT-G8","m":["TMRT-G8"],"name":"文華高中","en":"Wenhua Senior High Sc…","cls":4,"r":"taichung"},
        {"id":"TMRT-G8a","m":["TMRT-G8a"],"name":"文心櫻花","en":"Wenxin Yinghua","cls":4,"r":"taichung"},
        {"id":"TMRT-G9","m":["TMRT-G9"],"name":"市政府","en":"Taichung City Hall","cls":4,"r":"taichung"},
        {"id":"TMRT-G10","m":["TMRT-G10"],"name":"水安宮","en":"Shui-an Temple","cls":4,"r":"taichung"},
        {"id":"TMRT-G10a","m":["TMRT-G10a"],"name":"文心森林公園","en":"Wenxin Forest Park","cls":4,"r":"taichung"},
        {"id":"TMRT-G11","m":["TMRT-G11"],"name":"南屯","en":"Nantun","cls":4,"r":"taichung"},
        {"id":"TMRT-G12","m":["TMRT-G12"],"name":"豐樂公園","en":"Feng-le Park","cls":4,"r":"taichung"},
        {"id":"TMRT-G14","m":["TMRT-G14"],"name":"九張犁","en":"Jiuzhangli","cls":4,"r":"taichung"},
        {"id":"TMRT-G15","m":["TMRT-G15"],"name":"九德","en":"Jiude","cls":4,"r":"taichung"}
      ],
      "links": [["TRA-1000","TYMC-A1"],["TRTC-G13","TYMC-A1"],["TRA-1010","TRTC-BL10"],["TRTC-BL08","NTMC-Y17"],["TRA-1100","TYMC-A22"]],
      "geometry": {
        "TRA-WL": [
          [[650,-320,"taipei","TRA-0900"],[650,-280,"taipei","TRA-0910"],[650,-240,"taipei","TRA-0920"],[650,-200,"taipei","TRA-0930"],[650,-160,"taipei","TRA-0940"],[650,-120,"taipei","TRA-0950"],[650,-80,"taipei","TRA-0960"],[650,-20,"taipei","TRA-0970"],[550,-20,"taipei","TRA-0980"],[450,-20,"taipei","TRA-0990"],[260,-20,"taipei",null],[160,0,"taipei",null],[10,0,"taipei","TRA-1000"],[-40,0,"taipei",null],[-130,90,"taipei","TRA-1010"],[-300,90,"taipei","TRA-1020"],[-370,100,"taipei","TRA-1030"],[-440,170,"taipei","TRA-1040"],[-440,255,"taipei","TRA-1050"],[-440,285,"taipei","TRA-1060"],[-440,335,"taipei","TRA-1070"],[-440,415,"taipei",null],[-240,460,"taipei","TRA-1075"],[-240,630,"taoyuan","TRA-1080"],[-240,760,"taoyuan","TRA-1090"],[-240,816,"taoyuan","TRA-1100"],[-240,830,"taoyuan",null],[-240,853,"taoyuan","TRA-1105"],[-240,876,"taoyuan","TRA-1110"],[-240,899,"taoyuan","TRA-1120"],[-240,922,"taoyuan","TRA-1130"],[-240,945,"taoyuan","TRA-1140"],[-240,970,"hsinchu","TRA-1150"],[-240,995,"hsinchu","TRA-1160"],[-240,1020,"hsinchu","TRA-1170"],[-240,1045,"hsinchu","TRA-1180"],[-240,1070,"hsinchu","TRA-1190"],[-240,1100,"hsinchu","TRA-1210"],[-240,1130,"hsinchu","TRA-1220"],[-240,1155,"hsinchu","TRA-1230"],[-240,1180,"miaoli","TRA-1240"],[-240,1210,"miaoli","TRA-1250"],[-160,1220,"miaoli","TRA-3140"],[-110,1220,"miaoli",null],[-70,1250,"miaoli","TRA-3150"],[-30,1280,"miaoli","TRA-3160"],[10,1305,"miaoli","TRA-3170"],[40,1330,"miaoli","TRA-3180"],[70,1355,"miaoli","TRA-3190"],[100,1380,"taichung","TRA-3210"],[100,1410,"taichung","TRA-3220"],[100,1440,"taichung","TRA-3230"],[100,1470,"taichung","TRA-3240"],[100,1495,"taichung","TRA-3250"],[100,1520,"taichung","TRA-3260"],[100,1545,"taichung","TRA-3270"],[100,1570,"taichung","TRA-3280"],[100,1595,"taichung","TRA-3290"],[100,1630,"taichung","TRA-3300"],[100,1665,"taichung","TRA-3310"],[100,1700,"taichung","TRA-3320"],[100,1780,"taichung","TRA-3330"],[100,1835,"taichung",null],[-100,1835,"taichung",null],[-113.76,1837.58,"taichung","TRA-3340"],[-180,1850,"taichung","TRA-3350"],[-240,1880,"changhua","TRA-3360"],[-215,1907,"changhua","TRA-3370"],[-190,1930,"changhua","TRA-3380"],[-165,1955,"changhua","TRA-3390"],[-165,1980,"changhua","TRA-3400"],[-165,2003,"changhua","TRA-3410"],[-165,2028,"changhua","TRA-3420"],[-165,2053,"changhua","TRA-3430"],[-170,2080,"yunlin","TRA-3450"],[-170,2103,"yunlin","TRA-3460"],[-170,2128,"yunlin","TRA-3470"],[-170,2154,"yunlin","TRA-3480"],[-170,2177,"yunlin","TRA-3490"],[-170,2200,"chiayi","TRA-4050"],[-170,2225,"chiayi","TRA-4060"],[-170,2250,"chiayi","TRA-4070"],[-170,2280,"chiayi","TRA-4080"],[-170,2310,"chiayi","TRA-4090"],[-170,2333,"chiayi","TRA-4100"],[-190,2355,"tainan","TRA-4110"],[-200,2380,"tainan","TRA-4120"],[-210,2405,"tainan","TRA-4130"],[-220,2430,"tainan","TRA-4140"],[-230,2455,"tainan","TRA-4150"],[-280,2455,"tainan","TRA-4160"],[-330,2455,"tainan","TRA-4170"],[-350,2478,"tainan","TRA-4180"],[-360,2502,"tainan","TRA-4190"],[-370,2526,"tainan","TRA-4200"],[-380,2550,"tainan","TRA-4210"],[-400,2580,"tainan","TRA-4220"],[-400,2608,"tainan","TRA-4250"],[-400,2632,"tainan","TRA-4260"],[-400,2656,"tainan","TRA-4270"],[-400,2680,"kaohsiung","TRA-4290"],[-400,2705,"kaohsiung","TRA-4300"],[-400,2730,"kaohsiung","TRA-4310"],[-400,2775,"kaohsiung","TRA-4320"],[-400,2830,"kaohsiung","TRA-4330"],[-400,2870,"kaohsiung","TRA-4340"],[-400,2884,"kaohsiung",null],[-400,2930,"kaohsiung","TRA-4350"],[-520,2960,"kaohsiung","TRA-4360"],[-495,2990,"kaohsiung","TRA-4370"],[-495,3034,"kaohsiung","TRA-4380"],[-400,3034,"kaohsiung","TRA-4390"],[-315,3080,"kaohsiung","TRA-4400"],[-180,3080,"kaohsiung","TRA-4410"],[-114,3080,"kaohsiung","TRA-4420"],[-30,3080,"kaohsiung","TRA-4430"],[90,3080,"kaohsiung","TRA-4440"],[190,3080,"kaohsiung","TRA-4450"],[250,3080,"kaohsiung","TRA-4460"],[300,3080,"pingtung","TRA-4470"],[350,3080,"pingtung","TRA-5000"]]
        ],
        "TRA-WL-C": [
          [[-240,1210,"miaoli","TRA-1250"],[-320,1220,"miaoli","TRA-2110"],[-360,1240,"miaoli","TRA-2120"],[-400,1260,"miaoli","TRA-2130"],[-440,1280,"miaoli","TRA-2140"],[-480,1300,"miaoli","TRA-2150"],[-520,1320,"miaoli","TRA-2160"],[-560,1340,"miaoli","TRA-2170"],[-560,1380,"miaoli","TRA-2180"],[-560,1420,"taichung","TRA-2190"],[-560,1470,"taichung","TRA-2200"],[-560,1530,"taichung","TRA-2210"],[-560,1580,"taichung","TRA-2220"],[-560,1630,"taichung","TRA-2230"],[-560,1690,"taichung","TRA-2240"],[-560,1790,"taichung","TRA-2250"],[-320,1850,"taichung","TRA-2260"],[-240,1880,"changhua","TRA-3360"]]
        ],
        "TRA-SL": [
          [[350,3080,"pingtung","TRA-5000"],[400,3080,"pingtung","TRA-5010"],[450,3080,"pingtung","TRA-5020"],[500,3080,"pingtung","TRA-5030"],[550,3080,"pingtung","TRA-5040"],[600,3080,"pingtung","TRA-5050"],[600,3110,"pingtung","TRA-5060"],[600,3140,"pingtung","TRA-5070"],[600,3170,"pingtung","TRA-5080"],[600,3200,"pingtung","TRA-5090"],[600,3230,"pingtung","TRA-5100"],[600,3260,"pingtung","TRA-5110"],[600,3290,"pingtung","TRA-5120"],[670,3290,"pingtung","TRA-5130"],[750,3290,"pingtung","TRA-5140"],[810,3290,"pingtung","TRA-5160"],[812.24,3036.6,"pingtung","TRA-5170"],[820,2160,"taitung","TRA-5190"],[820,2120,"taitung","TRA-5200"],[820,2080,"taitung","TRA-5210"],[820,2040,"taitung","TRA-5220"],[820,2000,"taitung","TRA-5230"],[820,1960,"taitung","TRA-5240"],[820,1920,"taitung","TRA-6000"]]
        ],
        "TRA-EL": [
          [[650,-240,"taipei","TRA-0920"],[710,-240,"taipei","TRA-7390"],[770,-240,"taipei","TRA-7380"],[830,-240,"taipei","TRA-7360"],[890,-240,"taipei","TRA-7350"],[890,-180,"taipei","TRA-7330"],[890,-150,"taipei","TRA-7320"],[890,-120,"taipei","TRA-7310"],[890,-90,"taipei","TRA-7300"],[890,-60,"taipei","TRA-7290"],[890,20,"yilan","TRA-7280"],[880,50,"yilan","TRA-7270"],[870,80,"yilan","TRA-7260"],[860,110,"yilan","TRA-7250"],[850,140,"yilan","TRA-7240"],[840,170,"yilan","TRA-7230"],[830,200,"yilan","TRA-7220"],[820,230,"yilan","TRA-7210"],[820,260,"yilan","TRA-7200"],[820,290,"yilan","TRA-7190"],[820,320,"yilan","TRA-7180"],[820,350,"yilan","TRA-7170"],[820,380,"yilan","TRA-7160"],[820,410,"yilan","TRA-7150"],[820,440,"yilan","TRA-7140"],[820,470,"yilan","TRA-7130"],[820,540,"yilan","TRA-7110"],[820,570,"yilan","TRA-7100"],[820,600,"yilan","TRA-7090"],[820,630,"yilan","TRA-7080"],[820,660,"yilan","TRA-7070"],[820,690,"hualien","TRA-7060"],[820,720,"hualien","TRA-7050"],[820,750,"hualien","TRA-7040"],[820,780,"hualien","TRA-7030"],[820,810,"hualien","TRA-7020"],[820,840,"hualien","TRA-7010"],[820,870,"hualien","TRA-7000"],[820,910,"hualien","TRA-6250"],[820,940,"hualien","TRA-6240"],[820,970,"hualien","TRA-6230"],[820,1000,"hualien","TRA-6220"],[820,1030,"hualien","TRA-6210"],[820,1060,"hualien","TRA-6200"],[820,1090,"hualien","TRA-6190"],[820,1120,"hualien","TRA-6180"],[820,1150,"hualien","TRA-6170"],[820,1180,"hualien","TRA-6160"],[820,1210,"hualien","TRA-6150"],[820,1240,"hualien","TRA-6140"],[820,1270,"hualien","TRA-6130"],[820,1300,"hualien","TRA-6120"],[820,1330,"hualien","TRA-6110"],[820,1360,"hualien","TRA-6100"],[820,1390,"hualien","TRA-6090"],[820,1420,"hualien","TRA-6080"],[820,1710,"taitung","TRA-6070"],[820,1740,"taitung","TRA-6060"],[820,1770,"taitung","TRA-6050"],[820,1800,"taitung","TRA-6040"],[820,1830,"taitung","TRA-6030"],[820,1860,"taitung","TRA-6020"],[820,1890,"taitung","TRA-6010"],[820,1920,"taitung","TRA-6000"]]
        ],
        "TRA-SU": [
          [[820,470,"yilan","TRA-7130"],[890,470,"yilan","TRA-7120"]]
        ],
        "TRA-PX": [
          [[890,-180,"taipei","TRA-7330"],[830,-180,"taipei","TRA-7331"],[830,-150,"taipei","TRA-7332"],[830,-120,"taipei","TRA-7333"],[830,-90,"taipei","TRA-7334"],[830,-60,"taipei","TRA-7335"],[830,-30,"taipei","TRA-7336"]]
        ],
        "TRA-SA": [
          [[830,-240,"taipei","TRA-7360"],[830,-320,"taipei","TRA-7361"],[880,-320,"taipei","TRA-7362"]]
        ],
        "TRA-NW": [
          [[-240,1070,"hsinchu","TRA-1190"],[-193,1070,"hsinchu","TRA-1191"],[-150,1070,"hsinchu","TRA-1192"],[-100,1070,"hsinchu","TRA-1193"],[20,1070,"hsinchu","TRA-1201"],[60,1070,"hsinchu","TRA-1202"],[100,1070,"hsinchu","TRA-1203"],[140,1070,"hsinchu","TRA-1204"],[180,1070,"hsinchu","TRA-1205"],[220,1070,"hsinchu","TRA-1206"],[260,1070,"hsinchu","TRA-1207"],[300,1070,"hsinchu","TRA-1208"]]
        ],
        "TRA-LJ": [
          [[-100,1070,"hsinchu","TRA-1193"],[-35.07,1045.03,"hsinchu","TRA-1194"]]
        ],
        "TRA-CZ": [
          [[-180,1850,"taichung","TRA-3350"],[-320,1850,"taichung","TRA-2260"]]
        ],
        "TRA-JJ": [
          [[-165,2053,"changhua","TRA-3430"],[-115,2053,"changhua","TRA-3431"],[-65,2053,"changhua","TRA-3432"],[-15,2053,"changhua","TRA-3433"],[35,2053,"changhua","TRA-3434"],[85,2053,"changhua","TRA-3435"],[135,2053,"changhua","TRA-3436"]]
        ],
        "TRA-SH": [
          [[-400,2656,"tainan","TRA-4270"],[-290,2656,"tainan","TRA-4271"],[-174,2655.11,"tainan","TRA-4272"]]
        ],
        "THSR-HSRL": [
          [[550,5,"taipei","THSR-0990"],[260,5,"taipei",null],[160,28,"taipei",null],[10,28,"taipei","THSR-1000"],[-40,28,"taipei",null],[-125,114,"taipei",null],[-300,114,"taipei","THSR-1010"],[-530,275,null,null],[-530,780,"taoyuan","THSR-1020"],[-530,845,null,null],[-22,1040,"hsinchu","THSR-1030"],[-66.88,1236.35,"miaoli","THSR-1035"],[-70,1250,"miaoli",null],[-300,1590,null,null],[-300,1690,null,null],[-100,1835,"taichung","THSR-1040"],[-120,2015,null,null],[-368,2015,"changhua","THSR-1043"],[-370,2130,"yunlin","THSR-1047"],[-370,2280,"chiayi","THSR-1050"],[-370,2365,null,null],[-160,2655,"tainan","THSR-1060"],[-400,2884,"kaohsiung","THSR-1070"]]
        ],
        "TRTC-BL": [
          [[-300,316,"taipei","TRTC-BL01"],[-300,300,"taipei","TRTC-BL02"],[-300,275,"taipei","TRTC-BL03"],[-300,250,"taipei","TRTC-BL04"],[-300,225,"taipei","TRTC-BL05"],[-300,200,"taipei","TRTC-BL06"],[-300,138,"taipei","TRTC-BL07"],[-220,138,"taipei","TRTC-BL08"],[-175,138,"taipei","TRTC-BL09"],[-120,138,"taipei","TRTC-BL10"],[-80,100,"taipei","TRTC-BL11"],[-40,60,"taipei",null],[10,60,"taipei","TRTC-BL12"],[90,60,"taipei","TRTC-BL13"],[150,60,"taipei","TRTC-BL14"],[220,60,"taipei","TRTC-BL15"],[282,60,"taipei","TRTC-BL16"],[346,60,"taipei","TRTC-BL17"],[407,60,"taipei","TRTC-BL18"],[450,60,"taipei","TRTC-BL19"],[500,60,"taipei","TRTC-BL20"],[550,60,"taipei","TRTC-BL21"],[550,30,"taipei","TRTC-BL22"],[550,-60,"taipei","TRTC-BL23"]]
        ],
        "TRTC-BR": [
          [[400,420,"taipei","TRTC-BR01"],[360,380,"taipei","TRTC-BR02"],[340,350,"taipei","TRTC-BR03"],[340,320,"taipei","TRTC-BR04"],[340,290,"taipei","TRTC-BR05"],[340,260,"taipei","TRTC-BR06"],[280,230,"taipei","TRTC-BR07"],[220,230,"taipei",null],[220,200,"taipei","TRTC-BR08"],[220,150,"taipei","TRTC-BR09"],[220,74,"taipei","TRTC-BR10"],[220,60,"taipei",null],[220,-55,"taipei","TRTC-BR11"],[220,-105,"taipei","TRTC-BR12"],[220,-130,"taipei","TRTC-BR13"],[220,-185,"taipei","TRTC-BR14"],[300,-235,"taipei","TRTC-BR15"],[350,-235,"taipei","TRTC-BR16"],[400,-235,"taipei","TRTC-BR17"],[450,-235,"taipei","TRTC-BR18"],[500,-235,"taipei","TRTC-BR19"],[520,-205,"taipei","TRTC-BR20"],[540,-175,"taipei","TRTC-BR21"],[550,-150,"taipei","TRTC-BR22"],[550,-85,"taipei","TRTC-BR23"],[550,-74,"taipei","TRTC-BR24"]]
        ],
        "TRTC-G": [
          [[140,460,"taipei","TRTC-G01"],[140,430,"taipei","TRTC-G02"],[140,400,"taipei","TRTC-G03"],[140,370,"taipei","TRTC-G04"],[140,335,"taipei","TRTC-G05"],[120,310,"taipei","TRTC-G06"],[100,280,"taipei","TRTC-G07"],[80,250,"taipei","TRTC-G08"],[50,220,"taipei","TRTC-G09"],[10,170,"taipei",null],[-1.71,162.32,"taipei","TRTC-G10"],[-51,130,"taipei","TRTC-G11"],[-70.27,110.07,"taipei","TRTC-G12"],[-80,100,"taipei",null],[-80,-45,"taipei","TRTC-G13"],[10,-55,"taipei","TRTC-G14"],[150,-55,"taipei","TRTC-G15"],[206,-55,"taipei","TRTC-G16"],[220,-55,"taipei",null],[300,-55,"taipei","TRTC-G17"],[370,-55,"taipei","TRTC-G18"],[450,-47,"taipei","TRTC-G19"]],
          [[140,400,"taipei","TRTC-G03"],[100,408,"taipei","TRTC-G03A"]]
        ],
        "TRTC-O": [
          [[-120,370,"taipei","TRTC-O01"],[-90,340,"taipei","TRTC-O02"],[-60,310,"taipei","TRTC-O03"],[-20,260,"taipei","TRTC-O04"],[37.84,226.95,"taipei","TRTC-O05"],[50,220,"taipei",null],[83.05,162.16,"taipei","TRTC-O06"],[90,150,"taipei",null],[142.23,71.65,"taipei","TRTC-O07"],[150,60,"taipei",null],[150,-55,"taipei",null],[141.25,-65.93,"taipei","TRTC-O08"],[130,-80,"taipei","TRTC-O09"],[90,-107,"taipei","TRTC-O10"],[-4,-107,"taipei","TRTC-O11"],[-60,-107,"taipei","TRTC-O12"],[-130,-80,"taipei","TRTC-O13"],[-140,-55,"taipei","TRTC-O14"],[-155,-30,"taipei","TRTC-O15"],[-240,-20,"taipei","TRTC-O16"],[-290,-20,"taipei","TRTC-O17"],[-350,-20,"taipei","TRTC-O18"],[-400,-20,"taipei","TRTC-O19"],[-440,-20,"taipei","TRTC-O20"],[-480,-20,"taipei","TRTC-O21"]],
          [[-60,-107,"taipei","TRTC-O12"],[-140,-120,"taipei","TRTC-O50"],[-175,-150,"taipei","TRTC-O51"],[-215,-180,"taipei","TRTC-O52"],[-245,-210,"taipei","TRTC-O53"],[-280,-240,"taipei","TRTC-O54"]]
        ],
        "TRTC-R": [
          [[500,150,"taipei","TRTC-R01"],[435,150,"taipei","TRTC-R02"],[370,150,"taipei","TRTC-R03"],[290,150,"taipei","TRTC-R04"],[234,150,"taipei","TRTC-R05"],[220,150,"taipei",null],[155,150,"taipei","TRTC-R06"],[90,150,"taipei","TRTC-R07"],[10,170,"taipei","TRTC-R08"],[10,100,"taipei","TRTC-R09"],[10,74,"taipei","TRTC-R10"],[10,60,"taipei",null],[10,-41,"taipei","TRTC-R11"],[10,-55,"taipei",null],[10,-80,"taipei","TRTC-R12"],[10,-107,"taipei","TRTC-R13"],[10,-133,"taipei","TRTC-R14"],[10,-165,"taipei","TRTC-R15"],[10,-189,"taipei","TRTC-R16"],[10,-217,"taipei","TRTC-R17"],[10,-239,"taipei","TRTC-R18"],[10,-263,"taipei","TRTC-R19"],[10,-285,"taipei","TRTC-R20"],[10,-309,"taipei","TRTC-R21"],[10,-332,"taipei","TRTC-R22"],[-45,-330,"taipei","TRTC-R23"],[-90,-328,"taipei","TRTC-R24"],[-130,-326,"taipei","TRTC-R25"],[-170,-325,"taipei","TRTC-R26"],[-210,-325,"taipei","TRTC-R27"],[-260,-325,"taipei","TRTC-R28"]],
          [[10,-332,"taipei","TRTC-R22"],[70,-335,"taipei","TRTC-R22A"]]
        ],
        "NTMC-LB": [
          [[-300,330,"taipei","NTMC-LB01"],[-260,355,"taipei","NTMC-LB02"],[-260,380,"taipei","NTMC-LB03"],[-300,380,"taipei","NTMC-LB04"],[-340,380,"taipei","NTMC-LB05"],[-390,380,"taipei","NTMC-LB06"],[-390,355,"taipei","NTMC-LB07"],[-420,335,"taipei","NTMC-LB08"],[-420,362,"taipei","NTMC-LB09"],[-405,390,"taipei","NTMC-LB10"],[-160,460,"taipei","NTMC-LB11"],[-90,535,"taipei","NTMC-LB12"]]
        ],
        "NTMC-Y": [
          [[126,370,"taipei","NTMC-Y07"],[65,370,"taipei","NTMC-Y08"],[10,360,"taipei","NTMC-Y09"],[-40,350,"taipei","NTMC-Y10"],[-90,340,"taipei",null],[-96.95,327.84,"taipei","NTMC-Y11"],[-130,270,"taipei","NTMC-Y12"],[-150,240,"taipei","NTMC-Y13"],[-180,210,"taipei","NTMC-Y14"],[-210,180,"taipei","NTMC-Y15"],[-300,162,"taipei","NTMC-Y16"],[-220,138,"taipei",null],[-230.53,114.23,"taipei","NTMC-Y17"],[-290,-20,"taipei",null],[-290,-34,"taipei","NTMC-Y18"],[-290,-50,"taipei","NTMC-Y19"],[-290,-80,"taipei","NTMC-Y20"]]
        ],
        "NTDLRT-V": [
          [[-210,-339,"taipei","NTDLRT-V01"],[-210,-365,"taipei","NTDLRT-V02"],[-210,-385,"taipei","NTDLRT-V03"],[-260,-385,"taipei","NTDLRT-V04"],[-310,-385,"taipei","NTDLRT-V05"],[-360,-385,"taipei","NTDLRT-V06"],[-420,-385,"taipei","NTDLRT-V07"],[-480,-385,"taipei","NTDLRT-V08"],[-530,-385,"taipei","NTDLRT-V09"],[-490,-365,"taipei","NTDLRT-V28"],[-490,-345,"taipei","NTDLRT-V27"],[-490,-325,"taipei","NTDLRT-V26"]],
          [[-530,-385,"taipei","NTDLRT-V09"],[-560,-365,"taipei","NTDLRT-V10"],[-560,-345,"taipei","NTDLRT-V11"]]
        ],
        "NTALRT-K": [
          [[175,530,"taipei","NTALRT-K01"],[125,530,"taipei","NTALRT-K02"],[65,530,"taipei","NTALRT-K03"],[65,510,"taipei","NTALRT-K04"],[65,490,"taipei","NTALRT-K05"],[65,470,"taipei","NTALRT-K06"],[65,450,"taipei","NTALRT-K07"],[65,430,"taipei","NTALRT-K08"],[65,384,"taipei","NTALRT-K09"]]
        ],
        "KRTC-O": [
          [[-501,3135,"kaohsiung","KRTC-O1"],[-420,3135,"kaohsiung","KRTC-O2"],[-370,3135,"kaohsiung","KRTC-O4"],[-315,3135,"kaohsiung","KRTC-O5"],[-260,3135,"kaohsiung","KRTC-O6"],[-200,3135,"kaohsiung","KRTC-O7"],[-130,3135,"kaohsiung","KRTC-O8"],[-80,3135,"kaohsiung","KRTC-O9"],[-30,3135,"kaohsiung","KRTC-O10"],[30,3135,"kaohsiung","KRTC-O11"],[90,3135,"kaohsiung","KRTC-O12"],[150,3135,"kaohsiung","KRTC-O13"],[200,3135,"kaohsiung","KRTC-O14"],[230,3165,"kaohsiung","KRTC-OT1"]]
        ],
        "KRTC-R": [
          [[-65,3300,"kaohsiung","KRTC-R3"],[-125,3300,"kaohsiung","KRTC-R4"],[-195,3300,"kaohsiung","KRTC-R4A"],[-225,3275,"kaohsiung","KRTC-R5"],[-255,3240,"kaohsiung","KRTC-R6"],[-285,3210,"kaohsiung","KRTC-R7"],[-315,3185,"kaohsiung","KRTC-R8"],[-315,3160,"kaohsiung","KRTC-R9"],[-315,3149,"kaohsiung","KRTC-R10"],[-315,3135,"kaohsiung",null],[-315,3094,"kaohsiung","KRTC-R11"],[-315,3080,"kaohsiung",null],[-315,3045,"kaohsiung","KRTC-R12"],[-315,2990,"kaohsiung","KRTC-R13"],[-315,2935,"kaohsiung","KRTC-R14"],[-315,2910,"kaohsiung","KRTC-R15"],[-386.61,2888.1,"kaohsiung","KRTC-R16"],[-400,2884,"kaohsiung",null],[-480,2884,"kaohsiung","KRTC-R17"],[-530,2884,"kaohsiung","KRTC-R18"],[-530,2862,"kaohsiung","KRTC-R19"],[-530,2840,"kaohsiung","KRTC-R20"],[-480,2840,"kaohsiung","KRTC-R21"],[-480,2819,"kaohsiung","KRTC-R22"],[-480,2797,"kaohsiung","KRTC-R22A"],[-420,2775,"kaohsiung","KRTC-R23"],[-420,2752,"kaohsiung","KRTC-R24"],[-420,2730,"kaohsiung","KRTC-RK1"]]
        ],
        "KLRT-C": [
          [[-155,3240,"kaohsiung","KLRT-C1"],[-208,3240,"kaohsiung","KLRT-C2"],[-255,3240,"kaohsiung",null],[-265.41,3249.37,"kaohsiung","KLRT-C3"],[-305,3285,"kaohsiung","KLRT-C4"],[-355,3285,"kaohsiung","KLRT-C5"],[-365,3262,"kaohsiung","KLRT-C6"],[-375,3239,"kaohsiung","KLRT-C7"],[-385,3216,"kaohsiung","KLRT-C8"],[-395,3193,"kaohsiung","KLRT-C9"],[-395,3170,"kaohsiung","KLRT-C10"],[-455,3170,"kaohsiung","KLRT-C11"],[-515,3170,"kaohsiung","KLRT-C12"],[-515,3153,"kaohsiung","KLRT-C13"],[-515,3135,"kaohsiung","KLRT-C14"],[-505,3100,"kaohsiung","KLRT-C15"],[-495,3078,"kaohsiung","KLRT-C16"],[-485,3056,"kaohsiung","KLRT-C17"],[-475,3034,"kaohsiung","KLRT-C18"],[-475,3012,"kaohsiung","KLRT-C19"],[-475,2990,"kaohsiung","KLRT-C20"],[-465,2968,"kaohsiung","KLRT-C21A"],[-410,2968,"kaohsiung","KLRT-C21"],[-410,2990,"kaohsiung","KLRT-C22"],[-353,2990,"kaohsiung","KLRT-C23"],[-329,2990,"kaohsiung","KLRT-C24"],[-315,2990,"kaohsiung",null],[-234,2990,"kaohsiung","KLRT-C25"],[-174,2990,"kaohsiung","KLRT-C26"],[-114,2990,"kaohsiung","KLRT-C27"],[-114,3013,"kaohsiung","KLRT-C28"],[-114,3036,"kaohsiung","KLRT-C29"],[-114,3066,"kaohsiung","KLRT-C30"],[-114,3080,"kaohsiung",null],[-164,3110,"kaohsiung","KLRT-C31"],[-164,3153,"kaohsiung","KLRT-C32"],[-164,3175,"kaohsiung","KLRT-C33"],[-104,3175,"kaohsiung","KLRT-C34"],[-104,3197,"kaohsiung","KLRT-C35"],[-104,3219,"kaohsiung","KLRT-C36"],[-104,3241,"kaohsiung","KLRT-C37"],[-155,3240,"kaohsiung","KLRT-C1"]]
        ],
        "TYMC-A": [
          [[-52,-27,"taipei","TYMC-A1"],[-155,-30,"taipei",null],[-168.13,-34.86,"taipei","TYMC-A2"],[-290,-80,"taipei",null],[-300.84,-88.87,"taipei","TYMC-A3"],[-345,-125,"taipei","TYMC-A4"],[-395,-125,"taipei","TYMC-A5"],[-445,-125,"taipei","TYMC-A6"],[-550,-125,null,null],[-550,350,"taoyuan","TYMC-A7"],[-550,380,"taoyuan","TYMC-A8"],[-550,410,"taoyuan","TYMC-A9"],[-550,440,"taoyuan","TYMC-A10"],[-550,470,"taoyuan","TYMC-A11"],[-550,500,"taoyuan","TYMC-A12"],[-550,530,"taoyuan","TYMC-A13"],[-550,590,"taoyuan","TYMC-A14a"],[-550,690,"taoyuan","TYMC-A15"],[-550,720,"taoyuan","TYMC-A16"],[-550,750,"taoyuan","TYMC-A17"],[-550,780,"taoyuan","TYMC-A18"],[-550,830,"taoyuan","TYMC-A19"],[-480,830,"taoyuan","TYMC-A20"],[-400,830,"taoyuan","TYMC-A21"],[-262,830,"taoyuan","TYMC-A22"]]
        ],
        "TMRT-G": [
          [[250,1515,"taichung","TMRT-G0"],[250,1545,"taichung","TMRT-G3"],[114,1545,"taichung","TMRT-G4"],[100,1545,"taichung",null],[36,1565,"taichung","TMRT-G5"],[-20,1565,"taichung","TMRT-G6"],[-70,1565,"taichung","TMRT-G7"],[-120,1565,"taichung","TMRT-G8"],[-180,1595,"taichung","TMRT-G8a"],[-230,1620,"taichung","TMRT-G9"],[-230,1645,"taichung","TMRT-G10"],[-160,1670,"taichung","TMRT-G10a"],[-90,1700,"taichung","TMRT-G11"],[-45,1700,"taichung","TMRT-G12"],[80,1700,"taichung","TMRT-G13"],[80,1730,"taichung","TMRT-G14"],[80,1755,"taichung","TMRT-G15"],[80,1780,"taichung","TMRT-G16"],[-86.61,1830.91,"taichung","TMRT-G17"]]
        ]
      }
    };
    // </railway-data>

    var VERSION = '2.0.0';

    var FONT_SIZE = [29.25, 25.5, 24, 21.75, 18, 15.75, 12];   // 依站級（0 最大）的站名字級（fontScale＝1 時）
    var DOT_SIZE = [7.5, 6.5, 5.5, 4.5, 3.8, 3.2, 2.8];  // 依站級的站點半徑
    var RING_EDGE = '#2b2b33';       // 轉乘站大圈的外框
    var CONNECTOR_BG = '#efefef';    // 生活圈之間的連接軌道：底色用淡灰，表示這一段是生活圈的分界
    // 路網維持緊湊（站距、站點、線條都照原本的版面），只有站名的字比較大。
    // 想整張圖放大請調 scale；這兩個倍率是「不改字級、只拉開站距／加粗」用的，平常都是 1。
    var SPREAD = 1;                  // 版面座標 → 畫面座標的倍率（再乘上 scale）
    var MARK = 1;                    // 站點、線條粗細的倍率（再乘上 scale）
    var STUB_LEN = 46;               // 通往沒畫出來的生活圈的連接軌道長度（版面座標）
    var FONT = '"Noto Sans TC","PingFang TC","Microsoft JhengHei",sans-serif';
    var FONT_EN = '"Noto Sans TC","Helvetica Neue",Arial,sans-serif';
    var SELECT_COLOR = '#e6194b';

    var DEFAULTS = {
        regions: 'all',          // 'all' | 生活圈代碼 | 生活圈代碼陣列
        scale: 'auto',           // 數字＝固定比例；'auto'＝配合容器寬度，但不小於 minScale；'fit'＝一律縮到容器寬度
        minScale: null,          // null＝自動：滑鼠裝置 0.8；觸控裝置（手機）1，字才夠大
        maxScale: 1,
        showEnglish: true,
        fontScale: 1,            // 站名字級的倍率（1＝基礎大小）
        showRegionNames: true,   // 畫超過一個生活圈時，在底下淡淡地寫上生活圈名稱
        selectOnClick: true,     // 點到車站就標示它，點空白就取消
        scroll: true,            // 容器沒有設定 overflow 時，自動設成 auto（圖比容器大時可以捲動）
        background: '#ffffff',
        maxPixels: 12000000,     // canvas 實際像素的上限（約 48MB，手機記憶體）：超過就降低解析度倍率
        pixelRatio: 0,           // 0＝用裝置的 devicePixelRatio
        selected: null,
        onClick: null
    };

    // ------------------------------------------------------------ 資料索引

    var IDX = null;

    function index() {
        if (IDX) { return IDX; }
        var S = DATA.stations || {};
        var idx = { nodeOf: {}, lineOf: {}, polys: [], adj: {}, lean: {} };
        (DATA.lines || []).forEach(function (l) { idx.lineOf[l.uid] = l; });
        (DATA.nodes || []).forEach(function (n) {
            n.m.forEach(function (u) { idx.nodeOf[u] = n; });
        });
        (DATA.lines || []).forEach(function (line) {
            ((DATA.geometry || {})[line.uid] || []).forEach(function (poly) {
                var pts = poly.map(function (p) { return { x: p[0], y: p[1], r: p[2] || null, uid: p[3] || null, ra: null, rb: null }; });
                var i, last = null;
                // 沒有生活圈的轉折點（在兩個生活圈之間）：記下前後車站的生活圈
                for (i = 0; i < pts.length; i++) {
                    if (pts[i].uid) {
                        if (last && pts[i].r && last !== pts[i].r) {
                            (idx.adj[last] = idx.adj[last] || {})[pts[i].r] = true;
                            (idx.adj[pts[i].r] = idx.adj[pts[i].r] || {})[last] = true;
                        }
                        last = pts[i].r || last;
                    }
                    pts[i].ra = last;
                }
                last = null;
                for (i = pts.length - 1; i >= 0; i--) {
                    if (pts[i].uid) { last = pts[i].r || last; }
                    pts[i].rb = last;
                }
                // 每個車站前後的線段是偏橫向還是偏直向（站名優先放哪一邊用）
                for (i = 0; i < pts.length; i++) {
                    if (!pts[i].uid) { continue; }
                    var ln = idx.lean[pts[i].uid] || (idx.lean[pts[i].uid] = [0, 0]);
                    [pts[i - 1], pts[i + 1]].forEach(function (q) {
                        if (!q) { return; }
                        var dx = Math.abs(q.x - pts[i].x), dy = Math.abs(q.y - pts[i].y), len = Math.sqrt(dx * dx + dy * dy);
                        if (len > 0.01) { ln[0] += dx / len; ln[1] += dy / len; }
                    });
                }
                idx.polys.push({ line: line, pts: pts });
            });
        });
        // 虛擬車站（例如「臺北-環島」）跟著本尊的節點
        Object.keys(S).forEach(function (u) {
            if (S[u].p && idx.nodeOf[S[u].p]) { idx.nodeOf[u] = idx.nodeOf[S[u].p]; }
        });
        IDX = idx;
        return idx;
    }

    /** 顯示用站名：臺→台、去掉結尾的「站」（「車站」保留） */
    function displayName(s) {
        s = String(s == null ? '' : s).replace(/臺/g, '台');
        if (/站$/.test(s) && !/車站$/.test(s) && s.length > 2) { s = s.slice(0, -1); }
        return s;
    }

    /** 車站資訊（欄位名稱比照後端／TDX） */
    function stationInfo(uid) {
        var s = (DATA.stations || {})[uid];
        if (!s) { return undefined; }
        var sys = (DATA.systems || {})[s.s] || [s.s, 0];
        return {
            StationUID: uid,
            StationName: { Zh_tw: s.n, En: s.e },
            name: displayName(s.n),
            System: s.s,
            SystemName: sys[0],
            Lines: s.l.slice(),
            Region: s.r,
            TransferGroupID: s.g || null,
            x: s.x, y: s.y
        };
    }

    /** 轉乘群組資訊：Stations 依優先順序（高鐵 > 台鐵 > 捷運 > 輕軌 > 其他） */
    function groupInfo(id) {
        var g = (DATA.groups || {})[id];
        if (!g) { return undefined; }
        return {
            TransferGroupID: id,
            GroupName: { Zh_tw: g.n, En: g.e },
            name: displayName(g.n),
            primary: g.m[0],
            StationUIDs: g.m.slice(),
            Stations: g.m.map(stationInfo)
        };
    }

    function regionList() {
        return Object.keys(DATA.regions || {}).map(function (k) {
            return { key: k, name: DATA.regions[k].name, nameEn: DATA.regions[k].nameEn };
        });
    }

    /** 要畫哪些生活圈：null＝全台灣；否則是有效的生活圈代碼陣列（照資料的順序、不重複） */
    function resolveRegions(v) {
        if (v == null || v === '' || v === 'all' || v === '*') { return null; }
        var list = typeof v === 'string' ? v.split(',') : v;
        var want = {};
        (list || []).forEach(function (k) { want[String(k).trim()] = true; });
        if (want.all) { return null; }
        var keys = Object.keys(DATA.regions || {}).filter(function (k) { return want[k]; });
        return keys.length ? keys : null;
    }

    /** 把選到的生活圈分成「相連的一塊一塊」（有路線直接相連的畫在一起），由北到南排 */
    function components(keys) {
        var adj = index().adj;
        var left = {};
        keys.forEach(function (k) { left[k] = true; });
        var out = [];
        keys.forEach(function (k) {
            if (!left[k]) { return; }
            var comp = [], queue = [k];
            delete left[k];
            while (queue.length) {
                var cur = queue.shift();
                comp.push(cur);
                Object.keys(adj[cur] || {}).forEach(function (n) {
                    if (left[n]) { delete left[n]; queue.push(n); }
                });
            }
            out.push(comp);
        });
        var top = function (comp) {
            return Math.min.apply(null, comp.map(function (r) { return DATA.regions[r].center[1]; }));
        };
        return out.sort(function (a, b) { return top(a) - top(b); });
    }

    // ------------------------------------------------------------ 排版（算出要畫什麼、畫在哪）

    function overlaps(a, b) {
        return !(a.x2 < b.x1 || b.x2 < a.x1 || a.y2 < b.y1 || b.y2 < a.y1);
    }

    /** 線段有沒有穿過外框（Liang–Barsky；外框先往外擴 grow） */
    function segHitsBox(sg, b, grow) {
        var x1 = b.x1 - grow, y1 = b.y1 - grow, x2 = b.x2 + grow, y2 = b.y2 + grow;
        var dx = sg[2] - sg[0], dy = sg[3] - sg[1];
        var t0 = 0, t1 = 1;
        var p = [-dx, dx, -dy, dy];
        var q = [sg[0] - x1, x2 - sg[0], sg[1] - y1, y2 - sg[1]];
        for (var i = 0; i < 4; i++) {
            if (p[i] === 0) {
                if (q[i] < 0) { return false; }
            } else {
                var r = q[i] / p[i];
                if (p[i] < 0) { if (r > t1) { return false; } if (r > t0) { t0 = r; } }
                else { if (r < t0) { return false; } if (r < t1) { t1 = r; } }
            }
        }
        return t0 <= t1;
    }

    /** 凸包（Andrew monotone chain） */
    function convexHull(pts) {
        var p = pts.slice().sort(function (a, b) { return a.x - b.x || a.y - b.y; });
        if (p.length < 3) { return p; }
        function cross(o, a, b) { return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x); }
        var lower = [], upper = [], i;
        for (i = 0; i < p.length; i++) {
            while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p[i]) <= 0) { lower.pop(); }
            lower.push(p[i]);
        }
        for (i = p.length - 1; i >= 0; i--) {
            while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p[i]) <= 0) { upper.pop(); }
            upper.push(p[i]);
        }
        lower.pop(); upper.pop();
        return lower.concat(upper);
    }

    function isRail(line) {
        return line.system === 'TRA' || line.system === 'THSR';
    }

    function trackWidth(line, scale, connector) {
        if (isRail(line)) { return Math.max(3.2, 6 * scale); }
        return connector ? Math.max(3.2, 6.4 * scale) : Math.max(2, 5 * scale);
    }

    /**
     * 一條折線 → 要畫的軌道片段。
     *   sel＝要畫的生活圈（null＝全部）。不在範圍內的點不畫，只朝那個方向留一小段連接軌道。
     *   範圍內跨生活圈的那一段畫成連接軌道（底色淡灰）。
     */
    function trackPieces(poly, sel, scale, pieces) {
        var pts = poly.pts, line = poly.line;
        function inside(p) {
            if (!sel) { return true; }
            return p.r ? !!sel[p.r] : (!!sel[p.ra] && !!sel[p.rb]);
        }
        function piece(list, conn) {
            var xy = [];
            for (var i = 0; i < list.length; i++) { xy.push(list[i].x * scale, list[i].y * scale); }
            pieces.push({ line: line, xy: xy, conn: conn });
        }
        function stub(a, b) {
            var dx = b.x - a.x, dy = b.y - a.y;
            var len = Math.sqrt(dx * dx + dy * dy);
            if (len < 1e-6) { return; }
            var t = Math.min(1, STUB_LEN / len);
            piece([a, { x: a.x + dx * t, y: a.y + dy * t }], true);
        }
        function flush(run) {
            if (run.length < 2) { return; }
            var seg = [], lastRegion = null;
            for (var i = 0; i < run.length; i++) {
                var p = run[i];
                if (seg.length && p.r !== null && lastRegion !== null && lastRegion !== p.r) {
                    piece([seg[seg.length - 1], p], true);      // 進入另一個生活圈：這一段是連接軌道
                    if (seg.length > 1) { piece(seg, false); }
                    seg = [];
                }
                if (p.r !== null) { lastRegion = p.r; }
                seg.push(p);
            }
            if (seg.length > 1) { piece(seg, false); }
        }
        var run = [], skipped = null;
        for (var i = 0; i < pts.length; i++) {
            var p = pts[i];
            if (!inside(p)) {
                if (run.length) { stub(run[run.length - 1], p); }
                flush(run);
                run = [];
                skipped = p;
                continue;
            }
            if (!run.length && skipped) { stub(p, skipped); }
            run.push(p);
        }
        flush(run);
    }

    /** 量一塊文字（多行）：回傳 { w, h, lines }；第一行是主要的字 */
    function measureText(ctx, lines) {
        var w = 0, h = 0;
        lines.forEach(function (ln, i) {
            ctx.font = ln.font;
            ln.w = ctx.measureText(ln.text).width;
            w = Math.max(w, ln.w);
            h += i === 0 ? ln.size : ln.size * 1.05;
        });
        return { w: w, h: h + lines[0].size * 0.2, lines: lines };
    }

    function fontOf(size, bold, en) {
        return (bold ? 'bold ' : '') + size.toFixed(1) + 'px ' + (en ? FONT_EN : FONT);
    }

    /** 候選位置的外框：middle＝第一行對齊基準點、bottom＝整塊在基準點上方、top＝整塊在下方 */
    function boxOf(cand, m) {
        var x1, x2, y1, y2;
        if (cand.align === 'left') { x1 = cand.x; x2 = cand.x + m.w; }
        else if (cand.align === 'right') { x1 = cand.x - m.w; x2 = cand.x; }
        else { x1 = cand.x - m.w / 2; x2 = cand.x + m.w / 2; }
        if (cand.va === 'middle') { y1 = cand.y - m.lines[0].size * 0.6; y2 = y1 + m.h; }
        else if (cand.va === 'bottom') { y1 = cand.y - m.h; y2 = cand.y; }
        else { y1 = cand.y; y2 = cand.y + m.h; }
        return { x1: x1, y1: y1, x2: x2, y2: y2 };
    }

    /**
     * 空間索引（均勻格子）：站名避讓要查「這個外框附近有哪些車站、站名、線段」，
     * 用格子只看附近的幾個，手機上排版才不會慢。
     */
    function Grid(cell) {
        this.cell = cell;
        this.map = {};
        this.stamp = 0;
    }
    Grid.prototype.add = function (item, x1, y1, x2, y2) {
        var c = this.cell;
        for (var gx = Math.floor(x1 / c); gx <= Math.floor(x2 / c); gx++) {
            for (var gy = Math.floor(y1 / c); gy <= Math.floor(y2 / c); gy++) {
                var key = (gx + 2048) * 8192 + (gy + 2048);
                (this.map[key] || (this.map[key] = [])).push(item);
            }
        }
    };
    /** 對外框附近的每個項目呼叫 fn（每個項目只會叫一次）；fn 回傳 true 就停 */
    Grid.prototype.each = function (x1, y1, x2, y2, fn) {
        var c = this.cell, stamp = ++this.stamp;
        for (var gx = Math.floor(x1 / c); gx <= Math.floor(x2 / c); gx++) {
            for (var gy = Math.floor(y1 / c); gy <= Math.floor(y2 / c); gy++) {
                var list = this.map[(gx + 2048) * 8192 + (gy + 2048)];
                if (!list) { continue; }
                for (var i = 0; i < list.length; i++) {
                    if (list[i]._q === stamp) { continue; }
                    list[i]._q = stamp;
                    if (fn(list[i])) { return true; }
                }
            }
        }
        return false;
    };

    /**
     * 幫一塊文字找位置。
     *   先試站點周圍 16 個標準位置；都會壓到東西的話，再沿著站點四周一格一格滑動找空位。
     *   路網要維持緊湊、字又大，所以站名之間可以靠得很近甚至稍微重疊，但盡量不蓋到站點（站點是點選的地方）。
     *   評分：蓋到別站的站點（最糟）＞ 跟別的站名重疊（依重疊的比例）＞ 貼著別站的站點（會看錯是哪一站的站名）
     *         ＞ 壓到線條 ＞ 離站點的遠近。
     *   own：自己的站點外框（不算碰撞）。回傳 { x, align, box, hit }。
     *   flat：車站在橫向的線上 → 優先放正上方、正下方（放左右會壓在線上）；否則優先放右邊、左邊。
     */
    function placeText(m, anchor, own, env, wide, flat) {
        var cx = (anchor.x1 + anchor.x2) / 2, cy = (anchor.y1 + anchor.y2) / 2;
        var best = null;
        function test(box, x, align, base) {
            // onDot：蓋到別站站點的程度（各站點被蓋住的比例加總）；lap：跟別的站名重疊的面積佔自己的比例
            var onDot = 0, lap = 0, area = Math.max(1, (box.x2 - box.x1) * (box.y2 - box.y1));
            env.boxes.each(box.x1, box.y1, box.x2, box.y2, function (b) {
                if (own.indexOf(b) >= 0) { return false; }
                var w = Math.min(box.x2, b.x2) - Math.max(box.x1, b.x1), h = Math.min(box.y2, b.y2) - Math.max(box.y1, b.y1);
                if (w <= 0 || h <= 0) { return false; }
                if (b.dot) { onDot += w * h / Math.max(1, (b.x2 - b.x1) * (b.y2 - b.y1)); } else { lap += w * h / area; }
                return false;
            });
            var hit = onDot > 0 || lap > 0;
            var cross = 0;
            var g = env.halfLine;
            env.segs.each(box.x1 - g, box.y1 - g, box.x2 + g, box.y2 + g, function (sg) {
                if (segHitsBox(sg, box, g)) { cross++; }
                return false;
            });
            // 站名離別站的站點比離自己的站點還近（或差不多近）：容易看錯是哪一站的
            var near = 0;
            if (!onDot) {
                var ox = Math.max(anchor.x1 - box.x2, box.x1 - anchor.x2, 0), oy = Math.max(anchor.y1 - box.y2, box.y1 - anchor.y2, 0);
                var lim = Math.sqrt(ox * ox + oy * oy) + env.nearDot;
                env.boxes.each(box.x1 - lim, box.y1 - lim, box.x2 + lim, box.y2 + lim, function (b) {
                    if (!b.dot || own.indexOf(b) >= 0) { return false; }
                    var dx = Math.max(b.x1 - box.x2, box.x1 - b.x2, 0), dy = Math.max(b.y1 - box.y2, box.y1 - b.y2, 0);
                    if (Math.sqrt(dx * dx + dy * dy) < lim) { near = 1; return true; }
                    return false;
                });
            }
            var score = (onDot ? 300 + 700 * Math.min(1, onDot) : 0) + (lap ? 15 + 250 * Math.min(1, lap) : 0) +
                near * 14 + cross * 10 + base;
            if (!best || score < best.score) {
                best = { x: x, align: align, box: box, score: score, hit: hit, onDot: onDot, lap: lap, cross: cross, near: near };
            }
            return score;
        }
        var i = 0, done = false;
        [2, Math.max(7, m.lines[0].size * 0.7)].forEach(function (gap) {
            var L = anchor.x1 - gap, R = anchor.x2 + gap, T = anchor.y1 - (gap - 2), B = anchor.y2 + (gap - 2);
            var side = [
                { x: R, y: cy, align: 'left', va: 'middle' },
                { x: L, y: cy, align: 'right', va: 'middle' }
            ];
            var corner = [
                { x: R, y: T, align: 'left', va: 'bottom' },
                { x: L, y: T, align: 'right', va: 'bottom' },
                { x: R, y: B, align: 'left', va: 'top' },
                { x: L, y: B, align: 'right', va: 'top' }
            ];
            var over = [
                { x: cx, y: T - 2, align: 'center', va: 'bottom' },
                { x: cx, y: B + 2, align: 'center', va: 'top' }
            ];
            (flat ? over.concat(corner, side) : side.concat(corner, over)).forEach(function (c) {
                if (!done && test(boxOf(c, m), c.x, c.align, i++) === 0) { done = true; }
            });
        });
        if (best.score < 10) { return best; }

        // 標準位置都會壓到線或別的站名：沿著站點四周滑動找空位（離站點越近越好）
        var step = Math.max(3, m.lines[0].size * 0.3);
        // wide：附近真的都滿了，再往外多找兩圈（站名離站點遠一點，總比不畫好）
        var gaps = [2, m.lines[0].size * 0.9, m.lines[0].size * 2];
        if (wide) { gaps.push(m.lines[0].size * 3.2, m.lines[0].size * 4.5); }
        gaps.forEach(function (gap, ring) {
            var x, y, far = ring * 4;
            function near(box) {
                var dx = (box.x1 + box.x2) / 2 - cx, dy = (box.y1 + box.y2) / 2 - cy;
                return 16 + far + Math.min(8, Math.sqrt(dx * dx + dy * dy) / 12);
            }
            for (y = anchor.y1 - m.h; y <= anchor.y2 + 0.01; y += step) {
                var bl = { x1: anchor.x2 + gap, y1: y, x2: anchor.x2 + gap + m.w, y2: y + m.h };
                test(bl, bl.x1, 'left', near(bl));
                var br = { x1: anchor.x1 - gap - m.w, y1: y, x2: anchor.x1 - gap, y2: y + m.h };
                test(br, br.x2, 'right', near(br));
            }
            for (x = anchor.x1 - m.w; x <= anchor.x2 + 0.01; x += step * 2) {
                var bt = { x1: x, y1: anchor.y1 - gap - m.h, x2: x + m.w, y2: anchor.y1 - gap };
                test(bt, x + m.w / 2, 'center', near(bt));
                var bb = { x1: x, y1: anchor.y2 + gap, x2: x + m.w, y2: anchor.y2 + gap + m.h };
                test(bb, x + m.w / 2, 'center', near(bb));
            }
        });
        return best;
    }

    /**
     * 排一塊路網（一組相連的生活圈，或全台灣）。座標是「版面座標 × scale」，還沒有位移。
     * 回傳要畫的東西與外框範圍。
     */
    function compose(ctx, sel, scale, opt) {
        var idx = index();
        var S = DATA.stations;
        var ps = scale * SPREAD, ms = scale * MARK;
        var k = Math.max(0.75, ms);
        var fz = opt.fontScale > 0 ? opt.fontScale : 1;
        var fk = Math.max(0.7, scale * 0.9) * fz;     // 字級的倍率
        var blk = { rings: [], pieces: [], links: [], dots: [], texts: [], names: [], hitLabels: [], hitRings: [], dropped: 0, droppedNames: [] };

        var nodes = DATA.nodes.filter(function (n) { return !sel || sel[n.r]; })
            .slice().sort(function (a, b) { return (a.lp ? 0 : 1) - (b.lp ? 0 : 1) || a.cls - b.cls; });   // 指定位置的站名先放
        var visible = {};

        // ---- 站點與大圈 ----
        var boxes = [];                     // 所有車站與站名的外框（算畫布範圍用）
        var env = { boxes: new Grid(72), segs: new Grid(72), halfLine: Math.max(2, 5 * ms) / 2, nearDot: 4 * k };
        function addBox(b) {
            boxes.push(b);
            env.boxes.add(b, b.x1, b.y1, b.x2, b.y2);
        }
        var shapes = nodes.map(function (n) {
            var ring = n.m.length > 1;
            var base = DOT_SIZE[n.cls] || 4;
            var dotR = ring ? Math.max(3.2, base * 0.72) * k : (n.small ? Math.max(3.4, base * 0.85) : base) * k;
            var pad = ring ? dotR + 3.2 * k : dotR;
            var dots = n.m.map(function (u) {
                visible[u] = true;
                var d = { x: S[u].x * ps, y: S[u].y * ps, r: dotR, color: S[u].c, uid: u };
                d.box = { x1: d.x - pad - 1, y1: d.y - pad - 1, x2: d.x + pad + 1, y2: d.y + pad + 1, dot: true };
                addBox(d.box);
                blk.dots.push(d);
                return d;
            });
            if (ring) {
                blk.rings.push({ pts: dots, rad: pad });
                blk.hitRings.push({ hull: convexHull(dots), rad: pad, uid: n.m[0] });
            }
            return { n: n, dots: dots, pad: pad };
        });

        // ---- 路線 ----
        idx.polys.forEach(function (poly) { trackPieces(poly, sel, ps, blk.pieces); });
        blk.pieces.forEach(function (pc) {
            for (var i = 2; i < pc.xy.length; i += 2) {
                var sg = [pc.xy[i - 2], pc.xy[i - 1], pc.xy[i], pc.xy[i + 1]];
                env.segs.add(sg, Math.min(sg[0], sg[2]), Math.min(sg[1], sg[3]), Math.max(sg[0], sg[2]), Math.max(sg[1], sg[3]));
            }
        });

        // ---- 站外轉乘連線 ----
        (DATA.links || []).forEach(function (l) {
            if (visible[l[0]] && visible[l[1]]) {
                blk.links.push([S[l[0]].x * ps, S[l[0]].y * ps, S[l[1]].x * ps, S[l[1]].y * ps]);
            }
        });

        // ---- 站名 ----
        function anchorOf(dots) {
            var a = { x1: Infinity, y1: Infinity, x2: -Infinity, y2: -Infinity };
            dots.forEach(function (d) {
                a.x1 = Math.min(a.x1, d.box.x1); a.y1 = Math.min(a.y1, d.box.y1);
                a.x2 = Math.max(a.x2, d.box.x2); a.y2 = Math.max(a.y2, d.box.y2);
            });
            return a;
        }
        function smallLines(name, en, cls) {
            var size = Math.min(17.25 * fz, Math.max(12.75 * fz, (FONT_SIZE[cls] || 12) * fk * 0.6));
            var lines = [{ text: name, size: size, font: fontOf(size, false), color: '#3d3d47', halo: 2.6, small: true }];
            if (en && opt.showEnglish !== false) {
                var es = Math.max(9.75 * fz, size * 0.78);
                lines.push({ text: en, size: es, font: fontOf(es, false, true), color: '#8a8a96', halo: 2.4 });
            }
            return lines;
        }
        /** 手調版面指定的站名位置 lp＝[x, y, 對齊]（版面座標；y 是整塊文字的垂直中心） */
        function fixedText(m, lp) {
            var s0 = m.lines[0].size;
            var mid = m.lines.length > 1 ? s0 * 0.66 + m.lines[1].size * 0.25 : s0 * 0.6;
            var align = lp[2] || 'left';
            var x = lp[0] * ps, y1 = lp[1] * ps - mid;
            var x1 = align === 'left' ? x : (align === 'right' ? x - m.w : x - m.w / 2);
            return { x: x, align: align, box: { x1: x1, y1: y1, x2: x1 + m.w, y2: y1 + m.h }, score: 0, hit: false };
        }
        function put(lines, dots, uid, optional, fixed) {
            var own = dots.map(function (d) { return d.box; });
            var anchor = anchorOf(dots);
            if (fixed && fixed[3] === 'zh') { lines = [lines[0]]; }      // 指定位置而且只放中文
            var m = measureText(ctx, lines);
            var lx = 0, ly = 0;
            dots.forEach(function (d) { var ln = idx.lean[d.uid]; if (ln) { lx += ln[0]; ly += ln[1]; } });
            var flat = lx > ly * 2.4;        // 經過這一站的線幾乎都是橫向
            var c = fixed ? fixedText(m, fixed) : placeText(m, anchor, own, env, false, flat);
            if (!fixed && lines.length > 1 && (c.hit || (c.score >= 10 && lines[0].small))) {
                // 中英文兩行放不下（會跟別的站名重疊或蓋到站點；小字則是連壓到線都算）：只留中文再找一次
                var m1 = measureText(ctx, [lines[0]]);
                var c1 = placeText(m1, anchor, own, env, false, flat);
                // 還是蓋到站點，或跟別的站名重疊太多：再往外找
                if (c1.onDot > 0.2 || c1.lap > 0.25) { c1 = placeText(m1, anchor, own, env, true, flat); }
                if (c1.score < c.score) { m = m1; c = c1; }
            }
            addBox(c.box);
            blk.texts.push({ x: c.x, align: c.align, box: c.box, lines: m.lines });
            var gx = Math.max(anchor.x1 - c.box.x2, c.box.x1 - anchor.x2, 0), gy = Math.max(anchor.y1 - c.box.y2, c.box.y1 - anchor.y2, 0);
            blk.hitLabels.push({ box: c.box, uid: uid, uids: dots.map(function (d) { return d.uid; }),
                name: lines[0].text, gap: Math.sqrt(gx * gx + gy * gy), cross: c.cross || 0, hit: !!c.hit, onDot: c.onDot || 0, lap: c.lap || 0,
                english: m.lines.length > 1 || lines.length < 2, fixed: !!fixed });
        }
        shapes.forEach(function (sh) {
            var n = sh.n;
            var mainDots = n.main ? sh.dots.filter(function (d) { return n.main.indexOf(d.uid) >= 0; }) : sh.dots;
            var lines;
            if (n.small) {
                lines = smallLines(n.name, n.en, n.cls);
            } else {
                var fs = (FONT_SIZE[n.cls] || 12) * fk;
                lines = [{ text: n.name, size: fs, font: fontOf(fs, n.cls <= 1), color: '#111', halo: 3 }];
                if (n.en && opt.showEnglish !== false) {
                    var es = Math.max(10.5 * fz, fs * 0.55);
                    lines.push({ text: n.en, size: es, font: fontOf(es, false, true), color: '#7a7a86', halo: 2.6 });
                }
            }
            put(lines, mainDots, n.m[0], n.cls >= 3 && !n.small && n.m.length < 2, n.lp);
            // 轉乘站裡站名不一樣的成員：各自的中英文小字，標在自己的站點旁邊
            (n.sub || []).forEach(function (sub) {
                var dots = sh.dots.filter(function (d) { return sub.m.indexOf(d.uid) >= 0; });
                if (dots.length) { put(smallLines(sub.name, sub.en, n.cls), dots, sub.m[0], false, sub.lp); }
            });
        });

        // ---- 生活圈名稱（淡淡地寫在底下）----
        var regionKeys = sel ? Object.keys(sel) : Object.keys(DATA.regions);
        if (opt.showRegionNames !== false && regionKeys.length > 1) {
            regionKeys.forEach(function (r) {
                var c = DATA.regions[r].center;
                blk.names.push({ text: DATA.regions[r].name, x: c[0] * ps, y: c[1] * ps });
            });
        }

        // ---- 範圍 ----
        var x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
        function grow(ax, ay, bx, by) {
            if (ax < x1) { x1 = ax; } if (ay < y1) { y1 = ay; }
            if (bx > x2) { x2 = bx; } if (by > y2) { y2 = by; }
        }
        boxes.forEach(function (b) { grow(b.x1, b.y1, b.x2, b.y2); });
        blk.pieces.forEach(function (pc) {
            var half = trackWidth(pc.line, ms, pc.conn) / 2;
            for (var i = 0; i < pc.xy.length; i += 2) { grow(pc.xy[i] - half, pc.xy[i + 1] - half, pc.xy[i] + half, pc.xy[i + 1] + half); }
        });
        if (!isFinite(x1)) { x1 = 0; y1 = 0; x2 = 10; y2 = 10; }
        blk.x1 = x1; blk.y1 = y1; blk.w = x2 - x1; blk.h = y2 - y1;
        blk.regions = sel ? Object.keys(sel) : null;
        return blk;
    }

    /**
     * 整張圖的排版：相連的生活圈各自一塊，由上往下排；畫布剛好容納（四邊留一個字的空白）。
     */
    function layout(ctx, opt, scale) {
        var keys = resolveRegions(opt.regions);
        var comps = keys ? components(keys) : [null];
        var blocks = comps.map(function (comp) {
            var sel = null;
            if (comp) { sel = {}; comp.forEach(function (r) { sel[r] = true; }); }
            return compose(ctx, sel, scale, opt);
        });
        var margin = Math.ceil(Math.max(20, FONT_SIZE[0] * Math.max(0.7, scale * 0.9) * (opt.fontScale > 0 ? opt.fontScale : 1)));
        var innerW = Math.max.apply(null, blocks.map(function (b) { return b.w; }));
        var y = margin;
        blocks.forEach(function (b, i) {
            b.dx = margin + (innerW - b.w) / 2 - b.x1;     // 版面×scale → 畫布座標的位移
            b.dy = y - b.y1;
            b.top = y;
            y += b.h + (i < blocks.length - 1 ? margin * 2 : margin);
        });
        return { blocks: blocks, scale: scale, margin: margin, width: Math.ceil(innerW + margin * 2), height: Math.ceil(y), regions: keys };
    }

    // ------------------------------------------------------------ 繪圖

    /** 轉乘站的大圈：把共構的站點圈起來的圓角外框（白底、深色邊），畫在線條底下 */
    function drawRing(ctx, pts, rad, k) {
        var hull = convexHull(pts);
        function trace() {
            ctx.beginPath();
            ctx.moveTo(hull[0].x, hull[0].y);
            for (var i = 1; i < hull.length; i++) { ctx.lineTo(hull[i].x, hull[i].y); }
            if (hull.length > 2) { ctx.closePath(); }
        }
        var edge = Math.max(1.2, 1.5 * k);
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        trace();
        ctx.strokeStyle = RING_EDGE;
        ctx.lineWidth = (rad + edge) * 2;
        ctx.stroke();
        if (hull.length > 2) { ctx.fillStyle = RING_EDGE; ctx.fill(); }
        trace();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = rad * 2;
        ctx.stroke();
        if (hull.length > 2) { ctx.fillStyle = '#fff'; ctx.fill(); }
    }

    /**
     * 畫一段軌道。
     *   鐵路（台鐵、高鐵）：路線色的粗線，中間疊上一格一格的底色 → 路線色與底色相間（鐵路圖例）
     *   捷運、輕軌：實線；連接軌道則是路線色外框＋底色
     * 底色：一般路段是白色，生活圈之間的連接軌道是 CONNECTOR_BG（淡灰）。
     */
    function drawTrack(ctx, pc, scale) {
        var line = pc.line, color = line.color || '#666', rail = isRail(line);
        ctx.beginPath();
        ctx.moveTo(pc.xy[0], pc.xy[1]);
        for (var i = 2; i < pc.xy.length; i += 2) { ctx.lineTo(pc.xy[i], pc.xy[i + 1]); }
        ctx.lineJoin = 'round';
        ctx.setLineDash([]);
        var w = trackWidth(line, scale, pc.conn);
        if (!rail && !pc.conn) {
            ctx.lineCap = 'round';
            ctx.strokeStyle = color;
            ctx.lineWidth = w;
            ctx.stroke();
            return;
        }
        var edge = rail ? Math.max(0.8, 1.1 * scale) : Math.max(0.9, 1.5 * scale);
        ctx.lineCap = pc.conn ? 'butt' : 'round';
        ctx.strokeStyle = color;
        ctx.lineWidth = w;
        ctx.stroke();
        ctx.lineCap = 'butt';
        ctx.strokeStyle = pc.conn ? CONNECTOR_BG : '#fff';
        ctx.lineWidth = w - edge * 2;
        if (rail) {
            var d = Math.max(5, 8 * scale);
            ctx.setLineDash([d, d]);
            ctx.lineDashOffset = d;      // 兩端從路線色的那一格開始
        }
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.lineDashOffset = 0;
    }

    function paintBlock(ctx, blk, scale) {
        var ms = scale * MARK;
        var k = Math.max(0.75, ms);
        var i;
        ctx.save();
        ctx.translate(blk.dx, blk.dy);

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.font = '600 ' + Math.max(16, 26 * scale).toFixed(1) + 'px ' + FONT;
        ctx.fillStyle = 'rgba(120,120,135,.18)';
        blk.names.forEach(function (nm) { ctx.fillText(nm.text, nm.x, nm.y); });

        blk.rings.forEach(function (r) { drawRing(ctx, r.pts, r.rad, k); });
        for (i = 0; i < blk.pieces.length; i++) { drawTrack(ctx, blk.pieces[i], ms); }

        ctx.lineCap = 'butt';
        ctx.strokeStyle = '#8a8a96';
        ctx.lineWidth = Math.max(1.2, 1.8 * ms);
        ctx.setLineDash([4 * k, 3 * k]);
        blk.links.forEach(function (l) {
            ctx.beginPath();
            ctx.moveTo(l[0], l[1]);
            ctx.lineTo(l[2], l[3]);
            ctx.stroke();
        });
        ctx.setLineDash([]);

        ctx.lineWidth = Math.max(1.2, 2.2 * ms);
        blk.dots.forEach(function (d) {
            ctx.beginPath();
            ctx.arc(d.x, d.y, d.r, 0, Math.PI * 2);
            ctx.fillStyle = '#fff';
            ctx.fill();
            ctx.strokeStyle = d.color;
            ctx.stroke();
        });

        ctx.textBaseline = 'middle';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = 'rgba(255,255,255,.92)';
        blk.texts.forEach(function (t) {
            ctx.textAlign = t.align;
            var y = t.box.y1 + t.lines[0].size * 0.6;
            t.lines.forEach(function (ln, n) {
                if (n === 1) { y += t.lines[0].size * 0.62; }
                if (n > 1) { y += t.lines[n - 1].size * 1.05; }
                ctx.font = ln.font;
                ctx.lineWidth = ln.halo;
                ctx.strokeText(ln.text, t.x, y);
                ctx.fillStyle = ln.color;
                ctx.fillText(ln.text, t.x, y);
            });
        });
        ctx.restore();
    }

    /** 點到線段的距離 */
    function segDist(px, py, ax, ay, bx, by) {
        var vx = bx - ax, vy = by - ay;
        var len2 = vx * vx + vy * vy;
        var t = len2 > 1e-9 ? ((px - ax) * vx + (py - ay) * vy) / len2 : 0;
        t = Math.max(0, Math.min(1, t));
        return Math.sqrt(Math.pow(px - ax - t * vx, 2) + Math.pow(py - ay - t * vy, 2));
    }

    function inHull(hull, rad, x, y) {
        var i, n = hull.length;
        if (n === 1) { return Math.sqrt(Math.pow(x - hull[0].x, 2) + Math.pow(y - hull[0].y, 2)) <= rad; }
        for (i = 0; i < n; i++) {
            var a = hull[i], b = hull[(i + 1) % n];
            if (segDist(x, y, a.x, a.y, b.x, b.y) <= rad) { return true; }
        }
        if (n < 3) { return false; }
        var inside = false;
        for (i = 0; i < n; i++) {
            var p = hull[i], q = hull[(i + n - 1) % n];
            if ((p.y > y) !== (q.y > y) && x < (q.x - p.x) * (y - p.y) / (q.y - p.y) + p.x) { inside = !inside; }
        }
        return inside;
    }

    /**
     * 把排好的圖畫到 canvas 上，回傳 view：
     *   width / height   畫布的 CSS 尺寸
     *   hitTest(x, y, slop)  → { target: 'station' | 'label' | 'group', uid } 或 null
     *   locate(uid)      → 車站在畫布上的位置 { x, y, r }（不在圖上回傳 null）
     *   toMap(x, y)      → 版面座標 { x, y }（點在兩塊之間的空白回傳 null）
     */
    function paint(canvas, L, opt) {
        var W = L.width, H = L.height;
        var dpr = opt.pixelRatio || global.devicePixelRatio || 1;
        var ratio = Math.min(dpr, 3, Math.sqrt((opt.maxPixels || DEFAULTS.maxPixels) / (W * H)), 8192 / Math.max(W, H));
        canvas.width = Math.max(1, Math.round(W * ratio));
        canvas.height = Math.max(1, Math.round(H * ratio));
        if (canvas.style) {
            canvas.style.width = W + 'px';
            canvas.style.height = H + 'px';
        }
        var ctx = canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, W, H);
        if (opt.background !== 'transparent') {
            ctx.fillStyle = opt.background || '#ffffff';
            ctx.fillRect(0, 0, W, H);
        }
        L.blocks.forEach(function (b, i) {
            if (i > 0) {      // 不相連的兩塊之間：一條淡淡的分隔線
                ctx.save();
                ctx.strokeStyle = '#e4e4ea';
                ctx.lineWidth = 1;
                ctx.setLineDash([6, 5]);
                ctx.beginPath();
                ctx.moveTo(L.margin, Math.round(b.top - L.margin) + 0.5);
                ctx.lineTo(W - L.margin, Math.round(b.top - L.margin) + 0.5);
                ctx.stroke();
                ctx.restore();
            }
            paintBlock(ctx, b, L.scale);
        });

        var where = {};
        L.blocks.forEach(function (b) {
            b.dots.forEach(function (d) { where[d.uid] = { x: d.x + b.dx, y: d.y + b.dy, r: d.r }; });
        });

        function hitTest(x, y, slop) {
            slop = slop == null ? 5 : slop;
            var b, i, j, best = null, bestD = Infinity;
            for (i = 0; i < L.blocks.length; i++) {
                b = L.blocks[i];
                var lx = x - b.dx, ly = y - b.dy;
                // 1. 站點的圓圈（最近的一個；slop 是手指的容許誤差）
                for (j = 0; j < b.dots.length; j++) {
                    var d = b.dots[j];
                    var over = Math.sqrt(Math.pow(lx - d.x, 2) + Math.pow(ly - d.y, 2)) - d.r;
                    if (over <= slop && over < bestD) { bestD = over; best = d.uid; }
                }
            }
            if (best !== null) { return { target: 'station', uid: best }; }      // 站點優先於站名
            for (i = 0; i < L.blocks.length; i++) {
                b = L.blocks[i];
                var mx = x - b.dx, my = y - b.dy;
                // 2. 站名
                for (j = b.hitLabels.length - 1; j >= 0; j--) {
                    var hb = b.hitLabels[j].box;
                    if (mx >= hb.x1 - 2 && mx <= hb.x2 + 2 && my >= hb.y1 - 2 && my <= hb.y2 + 2) {
                        return { target: 'label', uid: b.hitLabels[j].uid, uids: b.hitLabels[j].uids };
                    }
                }
            }
            if (best !== null) { return { target: 'station', uid: best }; }
            for (i = 0; i < L.blocks.length; i++) {
                b = L.blocks[i];
                // 3. 轉乘站的大圈（沒點在任何一個站點上）
                for (j = 0; j < b.hitRings.length; j++) {
                    if (inHull(b.hitRings[j].hull, b.hitRings[j].rad, x - b.dx, y - b.dy)) {
                        return { target: 'group', uid: b.hitRings[j].uid };
                    }
                }
            }
            return null;
        }

        return {
            width: W, height: H, scale: L.scale, pixelRatio: ratio,
            regions: L.regions,
            blocks: L.blocks.map(function (b) { return { regions: b.regions, x: b.x1 + b.dx, y: b.top, width: b.w, height: b.h }; }),
            dropped: L.blocks.reduce(function (n, b) { return n + b.dropped; }, 0),
            droppedNames: L.blocks.reduce(function (a, b) { return a.concat(b.droppedNames); }, []),   // 塞不下而沒畫的站名（檢查用）
            // 每個站名的排版結果（檢查用）：gap＝離站點多遠、cross＝壓到幾段線、onDot＝蓋到別站站點的程度、lap＝跟別的站名重疊的比例、english＝英文有沒有畫
            labels: L.blocks.reduce(function (a, b) {
                return a.concat(b.hitLabels.map(function (h) {
                    return { uid: h.uid, uids: h.uids, name: h.name, gap: h.gap, cross: h.cross, hit: h.hit, onDot: h.onDot, lap: h.lap, english: h.english, fixed: h.fixed,
                        x1: h.box.x1 + b.dx, y1: h.box.y1 + b.dy, x2: h.box.x2 + b.dx, y2: h.box.y2 + b.dy };
                }));
            }, []),
            hitTest: hitTest,
            locate: function (uid) {
                var s = DATA.stations[uid];
                return where[uid] || (s && s.p && where[s.p]) || null;
            },
            toMap: function (x, y) {
                for (var i = 0; i < L.blocks.length; i++) {
                    var b = L.blocks[i];
                    if (y >= b.top - L.margin && y <= b.top + b.h + L.margin) {
                        return { x: Math.round((x - b.dx) / (L.scale * SPREAD) * 10) / 10, y: Math.round((y - b.dy) / (L.scale * SPREAD) * 10) / 10 };
                    }
                }
                return null;
            }
        };
    }

    /** 'auto' 比例的下限：沒指定的話，觸控裝置用 1（手機上字要夠大），其他用 0.8 */
    function minScaleOf(opt) {
        if (typeof opt.minScale === 'number') { return opt.minScale; }
        var coarse = !!(global.matchMedia && global.matchMedia('(pointer: coarse)').matches);
        return Math.min(opt.maxScale, coarse ? 1 : 0.8);
    }

    function mergeOptions(a, b) {
        var o = {}, k;
        for (k in a) { if (Object.prototype.hasOwnProperty.call(a, k)) { o[k] = a[k]; } }
        for (k in (b || {})) {
            if (Object.prototype.hasOwnProperty.call(b, k) && b[k] !== undefined) { o[k === 'region' ? 'regions' : k] = b[k]; }
        }
        return o;
    }

    /**
     * 低階用法：直接畫到指定的 canvas（不建立 DOM、不處理事件），回傳 view。
     * scale 給數字；availableWidth 有給的話，'auto' / 'fit' 會配合這個寬度。
     */
    function draw(canvas, options, availableWidth) {
        var opt = mergeOptions(DEFAULTS, options);
        var ctx = canvas.getContext('2d');
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        var L;
        if (typeof opt.scale === 'number' && opt.scale > 0) {
            L = layout(ctx, opt, opt.scale);
        } else {
            var fit = opt.scale === 'fit';
            var s = fit ? 1 : opt.maxScale;
            L = layout(ctx, opt, s);
            // 圖的寬度不是剛好跟比例成正比（字級有下限、邊界固定），所以逼近幾次
            for (var pass = 0; pass < 4 && availableWidth > 0; pass++) {
                if (!(L.width > availableWidth || (fit && pass === 0))) { break; }
                var s2 = s * availableWidth / L.width;
                if (pass > 0) { s2 -= 0.004; }
                s2 = fit ? Math.max(0.2, s2) : Math.min(opt.maxScale, Math.max(minScaleOf(opt), s2));
                if (Math.abs(s2 - s) < 0.003) { break; }
                s = s2;
                L = layout(ctx, opt, s);
            }
        }
        return paint(canvas, L, opt);
    }

    // ------------------------------------------------------------ setup：在容器裡建立 canvas、處理點擊

    function resolveElement(target) {
        var doc = global.document;
        if (!target) { return null; }
        if (typeof target === 'string') { return doc.querySelector(target); }
        if (target.nodeType === 1) { return target; }
        if (target.value && target.value.nodeType === 1) { return target.value; }           // Vue ref
        if (target.value && target.value.$el) { return target.value.$el; }                  // ref 指到元件
        if (target.$el && target.$el.nodeType === 1) { return target.$el; }                 // Vue 元件實例
        return null;
    }

    function setup(target, options) {
        if (target && typeof target === 'object' && target.nodeType !== 1 && !('value' in target) && !target.$el &&
            (target.el || target.container)) {
            options = target;                       // setup({ el: div, ... }) 的寫法
            target = options.el || options.container;
        }
        var el = resolveElement(target);
        if (!el) { throw new Error('canvasTaiwanRailwayMap.setup：找不到要放路網圖的容器（請傳入 div、選擇器或 Vue 的 ref）'); }
        var doc = el.ownerDocument || global.document;
        var opt = mergeOptions(DEFAULTS, options);
        var handlers = { click: [] };
        if (typeof opt.onClick === 'function') { handlers.click.push(opt.onClick); }

        var wrap = doc.createElement('div');
        wrap.className = 'ctrm-wrap';
        wrap.style.cssText = 'position:relative;display:inline-block;vertical-align:top;line-height:0;';
        var canvas = doc.createElement('canvas');
        canvas.className = 'ctrm-canvas';
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', '台灣軌道路網圖');
        canvas.style.cssText = 'display:block;touch-action:pan-x pan-y;-webkit-tap-highlight-color:transparent;';
        var marker = doc.createElement('div');       // 標示選到的車站（疊在 canvas 上，換站不用重畫）
        marker.className = 'ctrm-selected';
        marker.style.cssText = 'position:absolute;display:none;box-sizing:border-box;border-radius:50%;pointer-events:none;' +
            'border:3px solid ' + SELECT_COLOR + ';box-shadow:0 0 0 4px rgba(230,25,75,.22);';
        wrap.appendChild(canvas);
        wrap.appendChild(marker);
        el.appendChild(wrap);

        var savedOverflow = null;
        if (opt.scroll !== false && global.getComputedStyle) {
            var cs = global.getComputedStyle(el);
            if (cs.overflowX === 'visible' && cs.overflowY === 'visible') {
                savedOverflow = el.style.overflow;
                el.style.overflow = 'auto';
                el.style.webkitOverflowScrolling = 'touch';
            }
        }

        var view = null;
        var selected = opt.selected || null;
        var lastLabel = null;      // 上一次點的站名與選到的車站 { key, uid }：再點同一個站名時換下一個
        var destroyed = false;
        var lastWidth = 0;

        function availWidth() {
            var w = el.clientWidth || 0;
            if (w && global.getComputedStyle) {
                var cs2 = global.getComputedStyle(el);
                w -= (parseFloat(cs2.paddingLeft) || 0) + (parseFloat(cs2.paddingRight) || 0);
            }
            return w;
        }

        function placeMarker() {
            var p = selected && view ? view.locate(selected) : null;
            if (!p) { marker.style.display = 'none'; return; }
            var r = p.r + 5;
            marker.style.left = (p.x - r) + 'px';
            marker.style.top = (p.y - r) + 'px';
            marker.style.width = marker.style.height = (r * 2) + 'px';
            marker.style.display = 'block';
        }

        function redraw() {
            if (destroyed) { return api; }
            lastWidth = availWidth();
            view = draw(canvas, opt, lastWidth);
            // 畫完之後容器冒出直向捲軸、可用寬度變窄：照新的寬度再排一次，免得多出橫向捲軸
            var after = availWidth();
            if (typeof opt.scale !== 'number' && after > 0 && after < lastWidth - 1 && view.width > after) {
                lastWidth = after;
                view = draw(canvas, opt, after);
            }
            placeMarker();
            return api;
        }

        function scrollTo(uid, smooth) {
            var p = view ? view.locate(uid) : null;
            if (!p) { return api; }
            if (el.scrollWidth > el.clientWidth + 1 || el.scrollHeight > el.clientHeight + 1) {
                var left = wrap.offsetLeft + p.x - el.clientWidth / 2;
                var top = wrap.offsetTop + p.y - el.clientHeight / 2;
                if (el.scrollTo && smooth) { el.scrollTo({ left: left, top: top, behavior: 'smooth' }); }
                else { el.scrollLeft = left; el.scrollTop = top; }
            } else if (marker.scrollIntoView && selected === uid) {
                marker.scrollIntoView({ block: 'center', inline: 'center', behavior: smooth ? 'smooth' : 'auto' });
            }
            return api;
        }

        function select(uid, o) {
            selected = uid && DATA.stations[uid] ? uid : null;
            if (lastLabel && lastLabel.uid !== selected) { lastLabel = null; }   // 外面改選了別站：站名的輪替重新開始
            placeMarker();
            if (selected && o && o.scroll) { scrollTo(selected, o.smooth); }
            return api;
        }

        function eventPoint(ev) {
            var rect = canvas.getBoundingClientRect();
            var fx = rect.width ? view.width / rect.width : 1;
            var fy = rect.height ? view.height / rect.height : 1;
            return { x: (ev.clientX - rect.left) * fx, y: (ev.clientY - rect.top) * fy };
        }

        function coarse(ev) {
            if (ev && ev.pointerType) { return ev.pointerType !== 'mouse'; }
            return !!(global.matchMedia && global.matchMedia('(pointer: coarse)').matches);
        }

        /**
         * 點站名：這個站名如果是好幾個系統共用的（沒有各自顯示站名，例：板橋＝高鐵、台鐵、板南線、環狀線），
         * 第一次點選到優先順序第一位，再點同一個站名就換下一個（輪完回到第一個）。
         * 「目前輪到誰」：先看現在標示的車站是不是這個站名底下的；不是的話看上一次點這個站名時選了誰。
         */
        function pickFromLabel(uids) {
            var key = uids.join('|');
            var cur = uids.indexOf(selected);
            if (cur < 0 && lastLabel && lastLabel.key === key) { cur = uids.indexOf(lastLabel.uid); }
            var uid = uids[(cur + 1) % uids.length];
            lastLabel = { key: key, uid: uid };
            return uid;
        }

        function onClick(ev) {
            if (!view) { return; }
            var pt = eventPoint(ev);
            var hit = view.hitTest(pt.x, pt.y, coarse(ev) ? 10 : 5);
            var shared = hit && hit.target === 'label' && hit.uids && hit.uids.length > 1 ? hit.uids : null;
            if (shared) { hit = { target: 'label', uid: pickFromLabel(shared), uids: shared }; } else { lastLabel = null; }
            var st = hit ? stationInfo(hit.uid) : undefined;
            var m = view.toMap(pt.x, pt.y);
            var e = {
                type: 'click',
                x: Math.round(pt.x * 10) / 10,           // 畫布座標（CSS px，左上角是原點）
                y: Math.round(pt.y * 10) / 10,
                mapX: m ? m.x : null,                    // 版面座標（跟縮放、範圍無關）
                mapY: m ? m.y : null,
                target: hit ? hit.target : null,         // 'station'（點在站點上）| 'label'（站名）| 'group'（轉乘站的大圈）| null
                station: st,
                group: st && st.TransferGroupID ? groupInfo(st.TransferGroupID) : undefined,
                candidates: shared ? shared.slice() : (st ? [st.StationUID] : []),   // 點站名時：共用這個站名的車站（再點一次會輪到下一個）
                originalEvent: ev
            };
            if (opt.selectOnClick !== false) { select(st ? st.StationUID : null); }
            handlers.click.slice().forEach(function (fn) { fn.call(api, e); });
        }

        var hoverPending = false;
        function onMove(ev) {
            if (hoverPending || !view || coarse(ev)) { return; }
            hoverPending = true;
            (global.requestAnimationFrame || global.setTimeout)(function () {
                hoverPending = false;
                if (destroyed || !view) { return; }
                var pt = eventPoint(ev);
                canvas.style.cursor = view.hitTest(pt.x, pt.y, 5) ? 'pointer' : '';
            });
        }

        canvas.addEventListener('click', onClick);
        canvas.addEventListener('mousemove', onMove);

        // 容器寬度改變（轉向、版面變動）而且比例是自動的 → 重排
        var observer = null, resizeTimer = null;
        if (global.ResizeObserver) {
            observer = new global.ResizeObserver(function () {
                if (typeof opt.scale === 'number') { return; }
                if (Math.abs(availWidth() - lastWidth) < 24) { return; }
                global.clearTimeout(resizeTimer);
                resizeTimer = global.setTimeout(function () {
                    var before = view ? view.scale : 0;
                    var w = availWidth();
                    if (!w || destroyed) { return; }
                    // 比例沒變就不用重畫（常見：已經是 minScale 或 maxScale）
                    var probe = opt.scale === 'fit' ? -1 : Math.min(opt.maxScale, Math.max(minScaleOf(opt), before * w / (view ? view.width : w)));
                    lastWidth = w;
                    if (probe < 0 || Math.abs(probe - before) > 0.01) { redraw(); }
                }, 150);
            });
            observer.observe(el);
        }

        var api = {
            canvas: canvas,
            element: el,
            /** 重畫（資料或容器大小改變之後） */
            redraw: redraw,
            /** 換要畫的範圍：'all' | 'taipei' | ['taipei', 'taoyuan'] */
            setRegions: function (regions) { opt.regions = regions; return redraw(); },
            /** 換比例：數字 | 'auto' | 'fit' */
            setScale: function (scale) { opt.scale = scale; return redraw(); },
            /** 一次改多個選項後重畫 */
            update: function (o) {
                opt = mergeOptions(opt, o);
                if (o && 'selected' in o) { selected = o.selected || null; }
                return redraw();
            },
            /** 標示某一站（null＝取消）；o.scroll＝順便捲到那一站 */
            select: select,
            getSelected: function () { return selected; },
            /** 把某一站捲到容器中間 */
            scrollTo: scrollTo,
            /** 目前畫面的資訊：{ width, height, scale, pixelRatio, regions, blocks } */
            getView: function () {
                return view ? { width: view.width, height: view.height, scale: view.scale, pixelRatio: view.pixelRatio,
                    regions: view.regions, blocks: view.blocks } : null;
            },
            /** 畫布座標上是哪一站：{ target, station, group } 或 null */
            hitTest: function (x, y) {
                var hit = view ? view.hitTest(x, y, 5) : null;
                if (!hit) { return null; }
                var st = stationInfo(hit.uid);
                return { target: hit.target, station: st, group: st.TransferGroupID ? groupInfo(st.TransferGroupID) : undefined };
            },
            /** 車站在畫布上的位置 { x, y, r }（不在目前的圖上回傳 null） */
            locate: function (uid) { return view ? view.locate(uid) : null; },
            on: function (type, fn) {
                if (handlers[type] && typeof fn === 'function') { handlers[type].push(fn); }
                return api;
            },
            off: function (type, fn) {
                if (handlers[type]) { handlers[type] = handlers[type].filter(function (f) { return fn && f !== fn; }); }
                return api;
            },
            toDataURL: function (type, quality) { return canvas.toDataURL(type || 'image/png', quality); },
            /** 釋放：移除 canvas 與事件（Vue 元件卸載時呼叫） */
            destroy: function () {
                if (destroyed) { return; }
                destroyed = true;
                global.clearTimeout(resizeTimer);
                if (observer) { observer.disconnect(); }
                canvas.removeEventListener('click', onClick);
                canvas.removeEventListener('mousemove', onMove);
                canvas.width = canvas.height = 0;          // 立刻釋放 canvas 的記憶體（手機）
                if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
                if (savedOverflow !== null) { el.style.overflow = savedOverflow; }
                handlers.click = [];
                view = null;
            }
        };

        redraw();
        if (selected && options && options.scrollToSelected !== false) { scrollTo(selected); }
        return api;
    }

    var api = {
        version: VERSION,
        setup: setup,
        draw: draw,
        /** 生活圈清單 [{ key, name, nameEn }] */
        regions: regionList,
        /** 路線清單 [{ uid, system, name, nameEn, color }] */
        lines: function () { return (DATA.lines || []).map(function (l) { return mergeOptions(l); }); },
        station: stationInfo,
        group: groupInfo,
        data: DATA
    };

    global.canvasTaiwanRailwayMap = api;
    if (typeof module === 'object' && module && module.exports) { module.exports = api; }
})(typeof window !== 'undefined' ? window : globalThis);
