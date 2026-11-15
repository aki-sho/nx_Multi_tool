/* Real Chromium over CDP, using an isolated profile and a fixture CMS. No production sessions. */
const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const root = path.resolve(__dirname, '..');
const base = process.env.NXMT_TEST_URL || 'http://127.0.0.1:18741';
const chromePath = process.env.NXMT_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const delay = ms => new Promise(resolve=>setTimeout(resolve,ms));
let chrome, socket, checks = 0;
const pending = new Map(); let seq=0;
const command = (method,params={})=>new Promise((resolve,reject)=>{const id=++seq;const timeout=setTimeout(()=>{pending.delete(id);reject(new Error('CDP timeout: '+method));},15000);pending.set(id,{resolve,reject,timeout});socket.send(JSON.stringify({id,method,params}));});
const evaluate = async expression => {
  const reply = await command('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});
  if(reply.exceptionDetails)throw new Error(reply.exceptionDetails.exception?.description||reply.exceptionDetails.text);
  return reply.result.value;
};
const waitFor = async expression=>{for(let i=0;i<100;i++){if(await evaluate(expression))return;await delay(50);}const detail=await evaluate('JSON.stringify({url:location.pathname,status:document.querySelector("[data-status]")?.textContent,body:document.querySelector(".classic-editor-content")?.innerHTML})');throw new Error('Timed out: '+expression+' '+detail);};
const go = async route=>{await command('Page.navigate',{url:base+route});await waitFor('document.readyState==="complete"');await delay(100);};
const check = (value,label)=>{assert.ok(value,label);checks++;};
const api = (tool,fields={},csrf=true)=>evaluate(`(async()=>{const form=new FormData();form.set('tool',${JSON.stringify(tool)});${csrf ? "form.set('_csrf_token',document.querySelector('[data-nxmt]').dataset.csrf);" : ''}for(const [key,value]of Object.entries(${JSON.stringify(fields)}))form.set(key,value);const response=await fetch('/admin/extensions/nx_Multi_tool/api',{method:'POST',body:form});const text=await response.text();try{return {status:response.status,json:JSON.parse(text)}}catch{return {status:response.status,text}}})()`);
(async()=>{
  try {
    chrome=spawn(chromePath,['--headless=new','--no-sandbox','--disable-gpu','--no-first-run','--disable-default-apps','--disable-background-networking','--remote-debugging-port=0','--user-data-dir='+(process.env.NXMT_BROWSER_PROFILE||path.join(__dirname,'.runtime/chrome')),'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
    const endpoint=await new Promise((resolve,reject)=>{let output='';const timeout=setTimeout(()=>reject(new Error('Chrome startup timeout')),15000);chrome.on('error',reject);chrome.stderr.on('data',chunk=>{output+=chunk;const hit=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(hit){clearTimeout(timeout);resolve(hit[1]);}});});
    const url=new URL(endpoint);const target=await (await fetch(`http://${url.host}/json/new?about:blank`,{method:'PUT'})).json();
    socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
    socket.addEventListener('message',event=>{const data=JSON.parse(event.data);if(process.env.NXMT_CDP_DEBUG)console.log('CDP',JSON.stringify(data).slice(0,200));if(data.method==='Page.javascriptDialogOpening')command('Page.handleJavaScriptDialog',{accept:true}).catch(()=>{});if(data.id&&pending.has(data.id)){const entry=pending.get(data.id);pending.delete(data.id);clearTimeout(entry.timeout);data.error?entry.reject(new Error(data.error.message)):entry.resolve(data.result||{});}});
    if(process.env.NXMT_CDP_DEBUG)console.log('Browser version',await command('Browser.getVersion'));
    await command('Page.enable');await command('Runtime.enable');
    await command('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});
    await go('/fixture/login');check(await evaluate('document.querySelectorAll(".nxmt-card input[type=checkbox]").length===13'),'dashboard tool switches');
    check(await evaluate('!document.querySelector("input[name=site_url]") && document.querySelector(".nxmt-site-info").textContent.includes("サイト名") && document.querySelector(".nxmt-site-info a").href === location.origin + "/"'),'dashboard shows CMS site information without a URL input');
    const tools=['site_links','qr','calc','bcrypt','password','timer','link_inspector','access_log','security_check','post_data','site_urls','search_replace','comment_manager'];
    for(const tool of tools){await go('/admin/extensions/nx_Multi_tool/dashboard?tool='+tool);check(await evaluate('!!document.querySelector("[data-nxmt]")&&!document.querySelector(".nxmt-notice.is-error")'),'render '+tool);}
    const logRoute = '/admin/extensions/nx_Multi_tool/dashboard?tool=access_log';
    await go(logRoute);
    check(await evaluate(`(() => {
      const accessLog = document.querySelector('[data-access-log]');
      const search = accessLog.querySelector('input[type="search"]');
      const deleteButton = accessLog.querySelector('[data-log-delete]');
      const blockButton = accessLog.querySelector('[data-log-block-selected]');
      return search.placeholder === '検索（URL・参照元・UA・IP）'
        && !search.form.querySelector('[name=ip]')
        && blockButton.previousElementSibling === deleteButton && deleteButton.disabled && blockButton.disabled
        && search.getBoundingClientRect().left > blockButton.getBoundingClientRect().right
        && [...accessLog.querySelectorAll('.nxmt-log-filter label')].every(label =>
          label.firstElementChild.getBoundingClientRect().right < label.lastElementChild.getBoundingClientRect().left)
        && [...accessLog.querySelectorAll('[data-log-table] th .nxmt-log-sort')]
          .map(control => control.firstChild.textContent.trim()).join(',') === '選択,日時,HTTP,IP,拒否状態,URL,参照元,UA,コメント,メモ'
        && accessLog.querySelectorAll('[data-log-ip-table] th .nxmt-log-sort').length === 5
        && !accessLog.textContent.includes('期限付き拒否') && !accessLog.textContent.includes('同じIPのコメント');
    })()`), 'bulk toolbar, sortable columns and no row actions');
    await evaluate(`const form = document.querySelector('.nxmt-log-filter');
      form.elements.q.value = '198.51.100.11'; form.elements.per_page.value = '2';
      form.querySelector('button[type="submit"]').click(); void 0`);
    await waitFor(`document.readyState === 'complete' && new URLSearchParams(location.search).get('per_page') === '2'
      && document.querySelectorAll('[data-log-table] tbody input[name="memo"]').length === 2`);
    check(await evaluate(`(() => {
      const ipRows = [...document.querySelectorAll('[data-log-ip-table] tbody tr')];
      const row = ipRows.find(item => item.cells[0].textContent === '198.51.100.11');
      const dates = row.querySelector('textarea');
      return ipRows.some(item => item.cells[0].textContent === '198.51.100.12')
        && row.cells[1].textContent === '3回' && dates.readOnly && getComputedStyle(dates).resize === 'vertical'
        && dates.value.split('\\n').length === 3 && !row.cells[4].querySelector('input, textarea');
    })()`), '30-day IP summary spans all matching visits and shows the memo read-only');
    await evaluate(`const memo = document.querySelector('[data-memo-ip]'); memo.elements.memo.value = '確認済み <メモ>'; memo.requestSubmit(); void 0`);
    await waitFor(`document.querySelector('[data-ip-memo="198.51.100.11"]').textContent === '確認済み <メモ>'`);
    check(await evaluate(`[...document.querySelectorAll('[data-log-table] input[name="memo"]')].every(input => input.value === '確認済み <メモ>')`),
      'saving one memo updates the entries for the same IP');
    await go(logRoute + '&q=198.51.100.11&per_page=2&page=2');
    check(await evaluate(`document.querySelectorAll('[data-log-table] input[name="memo"]').length === 1
      && document.querySelector('[data-log-table] input[name="memo"]').value === '確認済み <メモ>'
      && document.querySelector('[data-access-log] .nxmt-pagination').textContent.includes('2 / 2')`), 'page size and memo persist on the next page');
    check((await api('access_log', {op:'save_ip_memo', ip:'198.51.100.11', memo:'未保存'}, false)).status === 419, 'memo changes require CSRF');
    await evaluate(`const form = document.querySelector('[data-memo-ip]'); form.elements.memo.value = ''; form.requestSubmit(); void 0`);
    await waitFor(`document.querySelector('[data-ip-memo="198.51.100.11"]').textContent === '—'`);
    check(await evaluate(`new URLSearchParams(location.search).get('page') === '2'`), 'clearing memo leaves current view intact');
    await go(logRoute + '&per_page=1&sort=comments&dir=asc');
    check(await evaluate(`(() => {
      const cell = document.querySelector('[data-log-table] tbody tr').cells[8];
      return cell.textContent.trim() === '0件' && !cell.querySelector('a');
    })()`), 'ascending comment order starts at zero, without a link');
    await evaluate(`document.querySelector('[data-log-table] th[aria-sort="ascending"] a').click()`);
    await waitFor(`document.readyState === 'complete' && new URLSearchParams(location.search).get('dir') === 'desc'`);
    const latestHref = await evaluate(`(() => {
      const link = document.querySelector('[data-log-table] tbody tr').cells[8].querySelector('a');
      return link?.textContent === '2件' ? link.href : '';
    })()`);
    check(latestHref.includes('ip=198.51.100.11#nxmt-comment-'), 'descending comment order links to the latest comment');
    await evaluate(`document.querySelector('[data-log-table] tbody tr').cells[8].querySelector('a').click()`);
    await waitFor(`document.readyState === 'complete' && document.querySelector('.nxmt-comment-row:target')?.textContent.includes('最新の確認用コメント')`);
    check(await evaluate(`document.querySelectorAll('.nxmt-comment-row').length === 2`), 'comment link opens the matching IP and targets its newest comment');
    await go(logRoute + '&q=198.51.100.11');
    await evaluate(`const form = document.querySelector('.nxmt-log-ip-filter');
      form.elements.ip_q.value = '198.51.100.'; form.elements.ip_per_page.value = '1';
      form.querySelector('button[type="submit"]').click(); void 0`);
    await waitFor(`document.readyState === 'complete' && new URLSearchParams(location.search).get('ip_per_page') === '1'`);
    check(await evaluate(`document.querySelectorAll('[data-log-table] tbody input[type="checkbox"]').length === 3
      && document.querySelectorAll('[data-log-ip-table] tbody textarea').length === 1
      && document.querySelector('[aria-label="IP一覧のページ送り"]').textContent.includes('1 / 2')`), 'IP search and display count are independent of the top filter');
    await evaluate(`document.querySelector('[data-log-ip-table] th[aria-sort="descending"] a').click()`);
    await waitFor(`document.readyState === 'complete' && new URLSearchParams(location.search).get('ip_dir') === 'asc'`);
    check(await evaluate(`document.querySelector('[data-log-ip-table] tbody td').textContent === '198.51.100.12'`), 'IP list sort changes the results before splitting into pages');
    await evaluate(`const search = document.querySelector('.nxmt-log-ip-filter input[name="ip_q"]'); search.value = '198.51.100.11'; search.focus()`);
    await command('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r'});
    await command('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13});
    await waitFor(`document.readyState === 'complete' && new URLSearchParams(location.search).get('ip_q') === '198.51.100.11'`);
    check(await evaluate(`document.querySelector('[data-log-ip-table] tbody td').textContent === '198.51.100.11'`), 'typing Enter still submits the search');
    await go(logRoute + '&q=198.51.100.11');
    await evaluate(`(() => {
      const boxes = [...document.querySelectorAll('[data-log-table] tbody input[type="checkbox"]')];
      boxes.slice(1).forEach(box => box.click());
      document.querySelector('[data-sort-selection]').click();
    })()`);
    check(await evaluate(`document.querySelector('[data-log-table] tbody input[type="checkbox"]').checked
      && !document.querySelector('[data-log-block-selected]').disabled`), 'selected rows can be sorted and blocked');
    check(await evaluate(`(async () => {
      const form = document.getElementById('nxmt-log-bulk'); const body = new FormData(form);
      body.delete('_csrf_token'); body.set('op', 'block_selected');
      return (await fetch(form.action, {method: 'POST', body})).status === 419;
    })()`), 'bulk block requires CSRF');
    await evaluate(`document.querySelector('[data-log-block-selected]').click()`);
    await waitFor(`document.readyState === 'complete' && document.querySelector('.nxmt-notice')?.textContent.includes('1件のIPを拒否')`);
    check(await evaluate(`document.querySelectorAll('[data-log-table] .nxmt-log-badge.is-blocked').length === 3
      && document.querySelectorAll('[data-log-ip-table] .nxmt-log-badge.is-blocked').length === 1`), 'both tables reflect the permanent block for one selected IP');
    await evaluate(`document.querySelector('form:has([name=op][value=unblock]) button').click()`);
    await waitFor(`document.readyState === 'complete' && document.querySelector('[data-log-table] .nxmt-log-badge')?.textContent === '未拒否'`);
    check(await evaluate(`!document.querySelector('[data-log-ip-table] .nxmt-log-badge.is-blocked')`), 'both tables reflect unblocking');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=security_check');
    check(await evaluate(`Array.from(document.querySelectorAll('.nxmt-security-sources input')).every(box =>
      box.getBoundingClientRect().width <= 24 && box.nextElementSibling.getBoundingClientRect().left - box.getBoundingClientRect().right <= 12)
      && getComputedStyle(document.querySelector('[data-security-filter]').parentElement).whiteSpace === 'nowrap'`), 'security source boxes sit beside their labels');
    check(await evaluate(`document.querySelectorAll('[data-security-group]').length === 20
      && document.querySelector('[data-security-group="1"]').open
      && document.querySelector('[data-security-group="1"] .nxmt-security-check-head').textContent.includes('確認先（CMS・サーバー）')
      && document.querySelector('[data-security-group="2"] .nxmt-security-check-head').textContent.includes('確認先（CMS）')
      && document.querySelector('[data-security-group="5"] .nxmt-security-check-head').textContent.includes('確認先（サーバー）')`), '20 security categories and all three source labels');
    check(await evaluate(`document.querySelector('[data-security-group="5"] .nxmt-security-check-row [data-state]').textContent === '未確認'
      && document.querySelector('[data-security-group="9"] summary').textContent.includes('CMS側で導入バージョンのみ確認可能')`), 'unavailable server rules and local version coverage');
    check(await evaluate(`(() => {
      const panel = document.querySelector('[data-security-panel="categories"]');
      const rows = Array.from(panel.querySelectorAll('[data-security-group]'));
      const points = rows.map(row => row.querySelector('[data-category-points]').textContent.trim());
      const levels = rows.map(row => row.querySelector('[data-importance]').dataset.importance);
      return document.querySelector('[data-security-category-score]').textContent.includes('/ 100点')
        && panel.querySelectorAll('[data-security-breakdown="categories"] dd').length === 20
        && points.every((point, index) => point.endsWith('/ ' + ({high:6, medium:4, low:2})[levels[index]] + '点'))
        && points[4] === '— / 4点'
        && panel.textContent.includes('未確認の配点') && rows[8].textContent.includes('採点対象外');
    })()`), 'category scores, published weights, and all three priority levels');
    await evaluate(`document.querySelector('[data-security-breakdown="categories"] summary').click()`);
    await evaluate(`document.querySelector('[data-security-tab="headers"]').click();
      document.querySelector('[data-security-breakdown="headers"] summary').click()`);
    check(await evaluate(`!document.querySelector('[data-security-panel="headers"]').hidden
      && document.querySelector('[data-security-score]').textContent === '未採点'
      && document.querySelector('[data-security-breakdown="headers"]').open
      && document.querySelectorAll('[data-security-breakdown="headers"] dd').length === 6`), 'header tab and point breakdown');
    check(await evaluate(`(() => {
      const key = document.querySelector('[data-importance-key]');
      const title = key.parentElement.querySelector('h2');
      const rows = Array.from(document.querySelectorAll('[data-security-header]'));
      const levels = new Set(rows.map(row => row.querySelector('[data-importance]').dataset.importance));
      return key.textContent.includes('重要度（高・中・低）')
        && key.getBoundingClientRect().left > title.getBoundingClientRect().right
        && rows.length === 13 && levels.size === 3
        && rows.every(row => {
          const impact = row.querySelector('[data-header-impact] p');
          const mitigation = impact?.nextElementSibling;
          return impact?.textContent.trim().length > 0
            && mitigation?.matches('p[data-header-mitigation]')
            && mitigation.textContent.trim().startsWith('※改善方法 ');
        });
    })()`), 'importance legend and explanations cover every public response row');
    check((await api('security_check', {op:'inspect'}, false)).status === 419, 'rechecking requires CSRF');
    check(await evaluate(`(() => {
      const rows = [...document.querySelectorAll('[data-security-group="15"] .nxmt-security-check-row')];
      return rows.length === 3 && rows[1].matches('[data-https-redirect]')
        && rows[1].dataset.securityRow === 'unknown' && !rows[1].querySelector('[data-https-redirect-form]');
    })()`), 'HTTPS redirect is shown after the HTTPS response and stays unconfirmed without evidence');
    check((await api('security_check', {op:'apply_https_redirect'}, false)).status === 419, 'HTTPS redirect settings require CSRF');
    await evaluate(`document.querySelector('[data-security-form] button').click()`);
    await waitFor(`document.querySelector('[data-security-status]').textContent === '結果を更新しました。'`);
    check(await evaluate(`document.querySelector('[data-security-score]').textContent === '未採点'
      && document.querySelector('[data-security-breakdown="headers"]').open
      && document.querySelector('[data-security-breakdown="categories"]').open
      && document.querySelector('[data-security-panel="headers"]').textContent.includes('応答を取得できませんでした')`), 'unreachable fixture public host remains unscored after recheck');
    check(await evaluate(`(() => {
      const csp = document.querySelector('[data-security-header="csp"]');
      const badges = csp.querySelector('.nxmt-security-row-badges');
      return csp.querySelector('[data-importance="high"]').textContent.trim() === '重要度 高'
        && csp.querySelector('[data-header-impact]').textContent.includes('フォームが動かなくなる')
        && csp.querySelector('[data-header-mitigation]').textContent.includes('報告モード')
        && badges.getBoundingClientRect().right > csp.querySelector('code').getBoundingClientRect().right;
    })()`), 'rechecking retains priority, impact and mitigation');
    await evaluate(`document.querySelector('.nxmt-security-header summary').click();
      document.querySelector('[data-security-jump="15"]').click()`);
    check(await evaluate(`!document.querySelector('[data-security-panel="categories"]').hidden
      && document.querySelector('[data-security-group="15"]').open`), 'go from public header to its category');
    await evaluate(`const state = document.querySelector('[data-security-filter]');
      state.value = 'present'; state.dispatchEvent(new Event('change', {bubbles:true}))`);
    check(await evaluate(`document.querySelector('[data-security-group="5"]').hidden
      && !document.querySelector('[data-security-group="9"]').hidden
      && document.querySelector('[data-security-group="9"] [data-security-row="unknown"]').hidden`), 'state filter hides unconfirmed categories and rows');
    check((await api('security_check', {op:'apply_header', header:'referrer'}, false)).status === 419, 'header settings require CSRF');
    check((await api('security_check', {op:'apply_header', header:'hsts'})).status === 400, 'HSTS needs an HTTPS connection');
    check((await api('security_check', {op:'apply_header', header:'cookie'})).status === 400, 'supplement rows do not write unrelated headers');
    const readHeader = (route, name) => evaluate(`fetch(${JSON.stringify(route)}, {cache:'no-store'}).then(response => response.headers.get(${JSON.stringify(name)}))`);
    check(await readHeader('/', 'Referrer-Policy') === null, 'header does not change until opted in');
    await evaluate(`document.querySelector('[data-security-filter]').value='all';
      document.querySelector('[data-security-filter]').dispatchEvent(new Event('change', {bubbles:true}));
      document.querySelector('[data-security-tab="headers"]').click();
      const row = document.querySelector('[data-security-header="referrer"]');
      row.open = true; row.querySelector('[data-header-settings]').open = true;
      row.querySelector('select').value = 'strict-origin';
      row.querySelector('select').dispatchEvent(new Event('input', {bubbles:true}));`);
    check(await evaluate(`document.querySelector('[data-security-header="referrer"] [data-header-preview]').textContent === 'Referrer-Policy: strict-origin'`), 'live preview shows the exact header');
    await evaluate(`document.querySelector('[data-security-header="referrer"] button[value="apply_header"]').click(); void 0`);
    await waitFor(`Array.from(document.querySelectorAll('[data-header-status]')).some(row => row.textContent.includes('設定を保存しました'))`);
    for (const route of ['/', '/post/hello', '/page/about']) {
      check(await readHeader(route, 'Referrer-Policy') === 'strict-origin', 'saved header reaches ' + route);
    }
    check(await readHeader('/admin/extensions/nx_Multi_tool/dashboard', 'Referrer-Policy') === null, 'header leaves CMS administration alone');
    check(await readHeader('/nx-multi-tool/comments/post/1', 'Referrer-Policy') === 'no-referrer', 'comment page keeps its existing policy');
    check(await readHeader('/public/themes/default/style.css', 'Referrer-Policy') === null, 'header setting applies to HTML responses');
    check(await readHeader('/does-not-exist', 'Referrer-Policy') === null, 'header settings skip error responses');
    check(await evaluate(`document.querySelector('[data-security-header="referrer"] [data-header-settings]').open
      && document.querySelector('[data-security-score]').textContent === '未採点'`), 'saved state is distinct from the observed score');
    await evaluate(`document.querySelector('[data-security-header="referrer"] button[value="remove_header"]').click(); void 0`);
    await waitFor(`Array.from(document.querySelectorAll('[data-header-status]')).some(row => row.textContent.includes('設定を解除しました'))`);
    check(await readHeader('/', 'Referrer-Policy') === null, 'clear a single header through the settings form');
    const csp = await api('security_check', {op:'apply_header',header:'csp'});
    check(csp.status === 200 && await readHeader('/', 'Content-Security-Policy-Report-Only') === "default-src 'self'"
      && await readHeader('/', 'Content-Security-Policy') === null, 'default CSP mode sends a report-only policy');
    const enforcing = await api('security_check', {op:'apply_header',header:'csp',mode:'enforce',value:"frame-ancestors 'self'"});
    check(enforcing.status === 200 && await readHeader('/', 'Content-Security-Policy') === "frame-ancestors 'self'"
      && await readHeader('/', 'Content-Security-Policy-Report-Only') === null, 'explicit CSP mode replaces the plugin’s previous policy');
    check((await api('security_check', {op:'remove_header',header:'csp'})).status === 200, 'remove CSP from test site');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=password');
    await evaluate(`document.querySelector('[data-password-preset]').value='strong';document.querySelector('[data-password-preset]').dispatchEvent(new Event('change'));document.querySelector('[data-nxmt-api] button[type=submit]').click()`);
    await waitFor('document.querySelector("[data-api-output]").value.length>0');
    check(await evaluate('document.querySelector("[data-api-output]").value.split("\\n").every(p=>p.length===32)'),'password UI preset and API');
    check((await api('password',{upper:'1'} ,false)).status===419,'CSRF required');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=qr');await evaluate(`document.querySelector('#nxmt-qr-text').value='日本語 https://example.com';document.querySelector('#nxmt-qr-generate').click()`);await waitFor('!document.querySelector("#nxmt-qr-result").hidden');check(await evaluate('document.querySelector("#nxmt-qr-download").href.startsWith("blob:")'),'local QR output');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=calc');await evaluate(`for(const key of ['7','+','3','='])document.querySelector('[data-nxmt-calc] button[data-key="'+key+'"]').click()`);check(await evaluate('document.querySelector("[data-nxmt-calc-display]").textContent==="10"'),'calculator');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=timer');await evaluate('document.querySelector("#cd-reset").click();document.querySelector("#cd-start").click()');await waitFor('!document.querySelector("#cd-pause").disabled');check(true,'countdown start');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=site_links');await evaluate(`const input=document.querySelector('[data-link-search]');input.value='配色';input.dispatchEvent(new Event('input'));`);check(await evaluate('document.querySelectorAll("[data-link-item]:not([hidden])").length>0&&document.querySelectorAll("[data-link-item][hidden]").length>0'),'catalog filtering');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=post_data');check(await evaluate('document.querySelectorAll("[data-post-table] tbody tr").length===2'),'post data core model');
    const csv = await evaluate(`(async()=>{const response=await fetch(document.querySelector('[data-post-csv]').href);return {status:response.status,type:response.headers.get('content-type'),text:await response.text()}})()`);check(csv.status===200&&csv.type.includes('csv')&&csv.text.includes('日本語テスト記事'),'post CSV');
    await go('/admin/extensions/nx_Multi_tool/dashboard?tool=site_urls');
    check(await evaluate('document.querySelector("[data-crawl-site]").href === location.origin + "/"'),'URL list shows the same destination as the dashboard');
    const crawl=await api('site_urls',{op:'start',site_url:'https://different.example.test/'});
    const {origin, port} = new URL(base);
    if (port) {
      check(crawl.status===400 && crawl.json.error.includes('ポート80'),'unsupported ports are reported before crawling');
    } else {
      check(crawl.json.ok && crawl.json.data.rows.some(row=>row.method==='開始URL' && row.url===origin+'/'),'crawler uses the current CMS address');
      let step;
      for(let i=0;i<3;i++) {
        step=await api('site_urls',{op:'step',run:crawl.json.data.run});
        if(step.json.data.rows.some(row=>row.status.includes('内部'))) break;
      }
      check(step.json.ok && step.json.data.requests===0 && step.json.data.rows.some(row=>row.status.includes('内部')),'crawler refuses private IP before HTTP');

      const collectLoginUrl = async (loginPath, label) => {
        const expected = JSON.stringify(origin + loginPath);
        const loginRows = 'Array.from(document.querySelectorAll("[data-crawl-table] tbody tr")).filter(row => row.cells[2].textContent === "CMS設定（ログイン）")';
        await evaluate('document.querySelector("[data-crawl-start]").click()');
        await waitFor('document.querySelector("[data-crawl-status]").textContent.includes("完了") && '
          + loginRows + '.some(row => row.cells[0].textContent === ' + expected + ')');
        check(await evaluate('(' + loginRows + ').length === 1 && (' + loginRows + ')[0].cells[5].textContent === "—"'), label);
      };
      const setLoginUrl = async (slug, enabled) => {
        check(await evaluate('(async () => {'
          + 'const data = new URLSearchParams({_csrf_token:document.querySelector("[data-nxmt]").dataset.csrf,slug:' + JSON.stringify(slug) + '});'
          + (enabled ? 'data.set("enabled","1");' : '')
          + 'const response = await fetch("/admin/extensions/access-guard/settings/login-url",{method:"POST",body:data});'
          + 'return response.ok && (await response.text()).includes("ログインページ変更設定を保存しました");})()'), 'Access Guard saved login settings');
      };
      await collectLoginUrl('/admin/login','the initial inventory lists the standard login URL without an HTTP result');
      await setLoginUrl('inventory-login-test', true);
      await collectLoginUrl('/inventory-login-test','recollect reflects the changed login URL');
      await setLoginUrl('inventory-login-next', true);
      await collectLoginUrl('/inventory-login-next','recollect replaces the previous login URL');
      await setLoginUrl('inventory-login-next', false);
      await collectLoginUrl('/admin/login','turning off the URL change restores the standard login URL');
    }
    check((await api('site_urls',{op:'list',run:'../escape'})).status===400,'invalid run ID rejected');
    check(await evaluate('document.querySelectorAll("[data-crawl-table] th").length===9&&!!document.querySelector("[data-crawl-type]")'),'crawler classifications and redirect columns');
    await go('/admin/posts/edit/1');await waitFor('!!document.querySelector("[data-nxmt-editor]")');
    check(await evaluate('!!document.querySelector(".classic-editor-content")'),'real Classic Editor active');
    await evaluate(`const panel=document.querySelector('[data-nxmt-editor]');panel.open=true;panel.querySelector('[data-find]').value='旧名称';panel.querySelector('[data-replace]').value='新名称';panel.querySelector('[data-scan]').click()`);await waitFor('document.querySelector("[data-status]").textContent.includes("3件")');
    await evaluate(`document.querySelector('[data-all]').click()`);check(await evaluate('document.querySelector("textarea#content").value.includes("新名称")&&document.querySelector("textarea#content").value.includes("https://old.example.com/path")'),'editor replacement preserves link attributes');
    await evaluate(`document.querySelector('[data-undo]').click()`);check(await evaluate('document.querySelector(".classic-editor-content").innerHTML.includes("旧名称 と")'),'editor undo');
    await evaluate(`document.querySelector('[data-editor-mode=html]').click();const input=document.querySelector('textarea#content');input.value='<p>旧名称 と <strong>旧名称</strong></p><pre>旧名称は変更しない</pre>';input.dispatchEvent(new Event('input',{bubbles:true}));document.querySelector('[data-scan]').click()`);await waitFor('document.querySelector("[data-status]").textContent.includes("2件")');
    await evaluate(`document.querySelector('[data-all]').click()`);check(await evaluate('document.querySelector("textarea#content").value.includes("<pre>旧名称は変更しない</pre>")&&document.querySelector("textarea#content").value.includes("新名称")'),'HTML mode code protection');
    await evaluate(`document.querySelector('[data-undo]').click()`);
    await evaluate(`document.querySelector('[data-find]').value='旧.称';document.querySelector('[data-regex]').checked=true;document.querySelector('[data-scan]').click()`);await waitFor('document.querySelector("[data-status]").textContent.includes("2件")');check(true,'regex worker');
    await command('Page.handleJavaScriptDialog',{accept:true}).catch(()=>{});
    await go('/admin/pages/edit/1');check(await evaluate('!!document.querySelector("[data-nxmt-editor]")'),'page editor integration');
    await go('/admin/extensions/nx_Multi_tool/dashboard');
    const screenshot=await command('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(process.env.NXMT_BROWSER_OUTPUT||path.join(__dirname,'.runtime/dashboard.png'),Buffer.from(screenshot.data,'base64'));
    await evaluate(`document.querySelector('input[name="tools[]"][value=password]').checked=false;document.querySelector('.nxmt-settings').requestSubmit()`);await waitFor('document.readyState==="complete"');await delay(100);
    check((await api('password',{upper:'1'})).status===403,'disabled tool API denied');
    await evaluate(`document.querySelector('input[name="tools[]"][value=password]').checked=true;document.querySelector('.nxmt-settings').requestSubmit()`);await delay(250);
    await go('/fixture/login?role=editor');check(await evaluate('document.body.textContent.includes("権限")'),'editor role denied dashboard');
    const denied=await evaluate(`(async()=>{const form=new FormData();form.set('tool','password');const response=await fetch('/admin/extensions/nx_Multi_tool/api',{method:'POST',body:form});return response.status})()`);check(denied===403,'editor role denied API');
    await go('/fixture/logout');await go('/nx-multi-tool/comments/post/1');check(await evaluate('!!document.querySelector("form textarea[name=body]")'),'public comment form');
    await delay(2100);await evaluate(`document.querySelector('[name=name]').value='テスト投稿者';document.querySelector('[name=body]').value='<script>alert(1)</script> コメント';document.querySelector('form button').click()`);await waitFor('document.body.textContent.includes("承認後に表示")');check(true,'public comment submit');
    await go('/fixture/login');await go('/admin/extensions/nx_Multi_tool/dashboard?tool=comment_manager');check(await evaluate('document.querySelector("table").textContent.includes("<script>alert(1)</script>")'),'comment escaped in admin');
    await go('/post/hello');check(await evaluate('!!document.querySelector("iframe#nxmt-comments-frame")'),'comment embedding via real theme');
    await go('/fixture/logout');await go('/post/hello');
    check(await evaluate('!!document.querySelector("link[rel=sitemap]")'),'enabled sitemap advertised in public HTML');
    check(await evaluate('!Array.from(document.querySelectorAll("link[rel=stylesheet]")).some(link=>/admin(?:-media|-preview)?\\.css|login\\.css/.test(link.href))'),'guest theme excludes admin styles');
    const xml=await evaluate(`(async()=>{const response=await fetch('/sitemap.xml');return {status:response.status,body:await response.text()}})()`);
    check(xml.status===200&&xml.body.includes('<urlset')&&xml.body.includes(base+'/post/hello'),'real sitemap returns absolute published URLs');
    await go('/fixture/login');await go('/admin/extensions/nx_XML_HTML_sitemap/dashboard');
    check(await evaluate('!!document.querySelector("[data-nx-sitemap-check]")'),'sitemap public-state diagnostics');
    await evaluate('document.querySelector("[data-nx-sitemap-check]").click()');await waitFor('document.querySelector("[data-nx-sitemap-result]").textContent.includes("公開中")');check(true,'sitemap browser probe validates XML');
    console.log(`Browser checks: ${checks} passed`);
  } finally {
    for(const item of pending.values()){clearTimeout(item.timeout);item.reject(new Error('Browser closing'));}pending.clear();
    if(socket)socket.close();if(chrome)chrome.kill();
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
