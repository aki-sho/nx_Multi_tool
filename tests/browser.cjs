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
    await evaluate(`document.querySelector('[data-security-tab="headers"]').click();
      document.querySelector('.nxmt-security-breakdown summary').click()`);
    check(await evaluate(`!document.querySelector('[data-security-panel="headers"]').hidden
      && document.querySelector('[data-security-score]').textContent === '未採点'
      && document.querySelector('.nxmt-security-breakdown').open
      && document.querySelectorAll('.nxmt-security-breakdown dd').length === 6`), 'header tab and point breakdown');
    check((await api('security_check', {op:'inspect'}, false)).status === 419, 'rechecking requires CSRF');
    await evaluate(`document.querySelector('[data-security-form] button').click()`);
    await waitFor(`document.querySelector('[data-security-status]').textContent === '結果を更新しました。'`);
    check(await evaluate(`document.querySelector('[data-security-score]').textContent === '未採点'
      && document.querySelector('.nxmt-security-breakdown').open
      && document.querySelector('[data-security-panel="headers"]').textContent.includes('応答を取得できませんでした')`), 'unreachable fixture public host remains unscored after recheck');
    await evaluate(`document.querySelector('.nxmt-security-header summary').click();
      document.querySelector('[data-security-jump="15"]').click()`);
    check(await evaluate(`!document.querySelector('[data-security-panel="categories"]').hidden
      && document.querySelector('[data-security-group="15"]').open`), 'go from public header to its category');
    await evaluate(`const state = document.querySelector('[data-security-filter]');
      state.value = 'present'; state.dispatchEvent(new Event('change', {bubbles:true}))`);
    check(await evaluate(`document.querySelector('[data-security-group="5"]').hidden
      && !document.querySelector('[data-security-group="9"]').hidden
      && document.querySelector('[data-security-group="9"] [data-security-row="unknown"]').hidden`), 'state filter hides unconfirmed categories and rows');
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
