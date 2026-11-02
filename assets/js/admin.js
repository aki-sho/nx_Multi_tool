(() => {
  'use strict';
  const root = document.querySelector('[data-nxmt]'); if (!root) return;
  const api = async (tool, fields = {}) => {
    const body = fields instanceof FormData ? fields : new FormData();
    if (!(fields instanceof FormData)) Object.entries(fields).forEach(([key, value]) => body.set(key, String(value)));
    body.set('tool', tool); body.set('_csrf_token', root.dataset.csrf);
    const response = await fetch(root.dataset.api, { method: 'POST', credentials: 'same-origin', body });
    let json; try { json = await response.json(); } catch (_) { throw new Error('通信に失敗しました。ログイン状態を確認してください。'); }
    if (!response.ok || !json.ok) throw new Error(json.error || '処理に失敗しました。');
    return json.data;
  };
  root.querySelectorAll('[data-confirm]').forEach(button => button.addEventListener('click', event => {
    if (!confirm(button.dataset.confirm)) event.preventDefault();
  }));
  root.querySelectorAll('[data-nxmt-api]').forEach(form => {
    form.addEventListener('submit', async event => {
      event.preventDefault(); const button = form.querySelector('[type=submit]'), status = form.querySelector('[data-api-status]'), output = form.querySelector('[data-api-output]');
      button.disabled = true; status.textContent = '処理中…'; output.value = '';
      try {
        const data = await api(form.dataset.nxmtApi, new FormData(form));
        output.value = Array.isArray(data) ? data.join('\n') : data.hash || (data.match ? '一致しました。' : '一致しません。'); status.textContent = '完了しました。';
      } catch (error) { status.textContent = error.message; }
      finally { button.disabled = false; }
    });
    form.querySelector('[data-copy-output]')?.addEventListener('click', async () => {
      const output = form.querySelector('[data-api-output]');
      try { await navigator.clipboard.writeText(output.value); form.querySelector('[data-api-status]').textContent = 'コピーしました。'; }
      catch (_) { output.focus(); output.select(); form.querySelector('[data-api-status]').textContent = '選択した結果をコピーしてください。'; }
    });
    form.querySelector('[data-password-preset]')?.addEventListener('change', event => {
      const presets = { simple: [12, false], normal: [20, true], strong: [32, true], maximum: [64, true] };
      const preset = presets[event.target.value]; if (!preset) return;
      form.elements.namedItem('length').value = preset[0]; ['upper', 'lower', 'digit'].forEach(name => form.elements.namedItem(name).checked = true); form.elements.namedItem('symbols').checked = preset[1];
    });
  });
  const links = root.querySelector('[data-link-search]');
  links?.addEventListener('input', () => {
    const q = links.value.trim().toLocaleLowerCase(); let visible = 0;
    root.querySelectorAll('[data-link-item]').forEach(item => { item.hidden = !item.textContent.toLocaleLowerCase().includes(q); if (!item.hidden) visible++; });
    root.querySelectorAll('[data-link-section]').forEach(section => { section.hidden = !section.querySelector('[data-link-item]:not([hidden])'); });
    root.querySelector('[data-link-empty]').hidden = visible > 0;
  });
  root.querySelectorAll('[data-post-column]').forEach(box => box.addEventListener('change', () => {
    const index = Number(box.dataset.postColumn);
    root.querySelectorAll('[data-post-table] tr').forEach(row => { if (row.children[index]) row.children[index].hidden = !box.checked; });
    const link = root.querySelector('[data-post-csv]'), url = new URL(link.href);
    url.searchParams.delete('cols[]'); url.searchParams.delete('cols');
    const checkedColumns=root.querySelectorAll('[data-post-column]:checked');
    checkedColumns.forEach(checked => url.searchParams.append('cols[]', checked.dataset.postColumn));
    if(!checkedColumns.length)url.searchParams.append('cols[]','-1');link.href = url.href;
  }));
  const inspector = root.querySelector('[data-nxmt-inspector]');
  if (inspector) {
    let stop = false, busy = false;
    inspector.querySelector('[data-stop]').addEventListener('click', () => { stop = true; });
    inspector.addEventListener('submit', async event => {
      event.preventDefault(); if (busy) return; busy = true; stop = false;
      const tbody = root.querySelector('[data-inspector-results]'), status = inspector.querySelector('[data-api-status]'); tbody.replaceChildren();
      const submit = inspector.querySelector('[type=submit]'); submit.disabled = true; let offset = 0, count = 0;
      const body = new FormData(inspector);
      try {
        do {
          body.set('offset', String(offset)); const data = await api('link_inspector', body);
          for (const row of data.rows) {
            const tr = document.createElement('tr');
            [row.title + ' #' + row.id, row.url, row.type, row.reason].forEach(value => { const td = document.createElement('td'); td.textContent = value; tr.append(td); }); tbody.append(tr); count++;
          }
          offset = data.processed; status.textContent = `${offset} / ${data.total}記事 · 検出${count}件`;
          if (data.done || data.limited || count >= 5000) { status.textContent += data.limited || count >= 5000 ? '（結果上限）' : ' · 完了'; break; }
        } while (!stop);
        if (stop) status.textContent += ' · 停止しました';
      } catch (error) { status.textContent = error.message; }
      finally { busy = false; submit.disabled = false; }
    });
  }
  const crawler = root.querySelector('[data-nxmt-crawler]');
  if (crawler) {
    let run = '', rows = [], pause = true, pending = false, generation = 0, finished = false, sort = 'url', direction = 1;
    const status = crawler.querySelector('[data-crawl-status]'), tbody = root.querySelector('[data-crawl-table] tbody');
    const selected = () => {
      const q = crawler.querySelector('[data-crawl-search]').value.toLocaleLowerCase();
      const kinds = Array.from(crawler.querySelectorAll('[data-crawl-kind]:checked')).map(box => box.dataset.crawlKind);
      const only = crawler.querySelector('[data-crawl-mode]').value === 'only';
      const type=crawler.querySelector('[data-crawl-type]').value, issues=crawler.querySelector('[data-crawl-issues]').checked;
      return rows.filter(row => (!q || (row.url + row.source).toLocaleLowerCase().includes(q)) && (!type||row.kind===type) && (!issues||row.issue)
        && (only ? kinds.some(kind=>(row.tags||[row.kind]).includes(kind)) : !kinds.some(kind=>(row.tags||[row.kind]).includes(kind))))
        .sort((a,b) => direction * (['http','original_http'].includes(sort) ? a[sort]-b[sort] : String(a[sort]||'').localeCompare(String(b[sort]||''), 'ja', { numeric:true })));
    };
    const render = () => {
      const visible = selected(); tbody.replaceChildren();
      visible.slice(0, 500).forEach(row => {
        const labels={page:'ページ',css:'CSS',js:'JavaScript',image:'画像',font:'フォント',sitemap:'XML・サイトマップ',file:'ファイル'};
        const tr = document.createElement('tr'); ['url','source','method','kind','original_http','http','final_url','status','access'].forEach(key => { const td = document.createElement('td'); td.textContent = key==='kind' ? labels[row.kind]||row.kind : key==='final_url'&&row[key]===row.url?'—':row[key] || (['http','original_http'].includes(key) ? '—' : ''); tr.append(td); }); tbody.append(tr);
      });
      root.querySelector('[data-crawl-count]').textContent = `${visible.length}件（画面は先頭500件。CSVは絞り込み後の全件）`;
    };
    const accept = data => { run = data.run; if(data.delta){const map=new Map(rows.map(row=>[row.url,row]));data.rows.forEach(row=>map.set(row.url,row));rows=[...map.values()];}else rows=data.rows;finished = data.done; status.textContent = `${data.candidates}候補 · 通信${data.requests}回${data.done ? ' · 完了' : ''}${data.limited ? '（上限に達しました）' : ''}`; render(); };
    const loop = async expected => {
      if (pending || pause || finished || expected !== generation) return;
      pending = true;
      try { const data = await api('site_urls', { op:'step', run }); if (expected === generation) accept(data); }
      catch (error) { status.textContent = error.message; pause = true; }
      finally { pending = false; if (!pause && !finished && expected === generation) setTimeout(() => loop(expected), 250); }
    };
    crawler.querySelector('[data-crawl-start]').addEventListener('click', async event => {
      if (pending) { status.textContent = '現在の通信が終わってから再収集してください。'; return; }
      pause = true; generation++; event.target.disabled = true;
      try { if (run) await api('site_urls', { op:'clear', run }); accept(await api('site_urls', { op:'start' })); pause = false; loop(generation); }
      catch (error) { status.textContent = error.message; } finally { event.target.disabled = false; }
    });
    crawler.querySelector('[data-crawl-pause]').addEventListener('click', () => { pause = true; status.textContent += ' · 一時停止（処理中の1件は完了します）'; });
    crawler.querySelector('[data-crawl-resume]').addEventListener('click', () => { if (!run) return; pause = false; loop(generation); });
    let filterKey = 'nxmt-crawl-filters:' + location.pathname;
    try { const saved = JSON.parse(localStorage.getItem(filterKey) || '{}'); crawler.querySelector('[data-crawl-mode]').value = saved.mode === 'only' ? 'only' : 'exclude'; crawler.querySelectorAll('[data-crawl-kind]').forEach(box => box.checked = Array.isArray(saved.kinds) && saved.kinds.includes(box.dataset.crawlKind)); } catch (_) {}
    crawler.querySelectorAll('[data-crawl-search],[data-crawl-mode],[data-crawl-kind],[data-crawl-type],[data-crawl-issues]').forEach(field => field.addEventListener('input', () => {
      render(); try { localStorage.setItem(filterKey, JSON.stringify({ mode:crawler.querySelector('[data-crawl-mode]').value, kinds:Array.from(crawler.querySelectorAll('[data-crawl-kind]:checked')).map(box=>box.dataset.crawlKind) })); } catch (_) {}
    }));
    root.querySelectorAll('[data-crawl-sort]').forEach(button => button.addEventListener('click', () => { direction = sort === button.dataset.crawlSort ? -direction : 1; sort = button.dataset.crawlSort; render(); }));
    crawler.querySelector('[data-crawl-csv]').addEventListener('click', () => {
      const safe = value => { value = String(value ?? ''); if (/^[\s\x00-\x1f]*[=+@-]/.test(value)) value = "'" + value; return '"' + value.replaceAll('"','""') + '"'; };
      const data = [['URL','発見元','発見方法','種類','HTTP（元）','HTTP（最終取得）','転送先','確認結果','アクセス条件'], ...selected().map(row=>['url','source','method','kind','original_http','http','final_url','status','access'].map(key=>row[key]))];
      const url = URL.createObjectURL(new Blob(['\ufeff' + data.map(row=>row.map(safe).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));
      const link = document.createElement('a'); link.href=url; link.download='nx-multi-tool-site-urls.csv'; link.click(); setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    window.addEventListener('pagehide', () => { pause = true; if (!run) return; const data = new FormData(); data.set('tool','site_urls'); data.set('op','clear'); data.set('run',run); data.set('_csrf_token',root.dataset.csrf); navigator.sendBeacon(root.dataset.api,data); });
  }
})();
