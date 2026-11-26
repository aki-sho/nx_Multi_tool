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
  const accessLog = root.querySelector('[data-access-log]');
  if (accessLog) {
    const logTable = accessLog.querySelector('[data-log-table]');
    const logActions = accessLog.querySelectorAll('[data-log-action]');
    const selectionSort = logTable.querySelector('[data-sort-selection]');
    const selections = [...logTable.querySelectorAll('tbody input[type="checkbox"]')];
    function updateLogSelection() {
      const hasSelection = selections.some(box => box.checked);
      logActions.forEach(button => { button.disabled = !hasSelection; });
    }
    updateLogSelection();
    logTable.addEventListener('change', updateLogSelection);
    selectionSort.disabled = selections.length === 0;
    let selectedFirst = true;
    selectionSort.addEventListener('click', () => {
      const compare = (left, right) => Number(right.checked) - Number(left.checked);
      const ordered = [...selections].sort((left, right) => selectedFirst ? compare(left, right) : compare(right, left));
      ordered.forEach(box => logTable.tBodies[0].append(box.closest('tr')));
      logTable.querySelector('th[aria-sort]')?.removeAttribute('aria-sort');
      selectionSort.closest('th').setAttribute('aria-sort', selectedFirst ? 'descending' : 'ascending');
      selectionSort.querySelector('span').textContent = selectedFirst ? '↓' : '↑';
      selectedFirst = !selectedFirst;
      selectionSort.setAttribute('aria-label', selectedFirst ? 'チェックしたログを上に並べ替え' : 'チェックしていないログを上に並べ替え');
    });
    logTable.addEventListener('submit', async event => {
      const form = event.target;
      if (!form.matches('[data-memo-ip]')) return;
      event.preventDefault();
      const ip = form.dataset.memoIp;
      const editors = [...logTable.querySelectorAll('[data-memo-ip]')].filter(editor => editor.dataset.memoIp === ip);
      const button = form.querySelector('button[type="submit"]');
      if (button.disabled) return;
      const payload = new FormData(form);
      const status = form.querySelector('[data-memo-status]');
      const controls = editors.flatMap(editor => [editor.elements.memo, editor.querySelector('button[type="submit"]')]);
      controls.forEach(control => { control.disabled = true; });
      status.hidden = false;
      status.classList.remove('is-error');
      status.textContent = '保存中…';
      try {
        const saved = await api('access_log', payload);
        for (const editor of editors) {
          editor.elements.memo.value = saved.memo;
          if (editor !== form) editor.querySelector('[data-memo-status]').hidden = true;
        }
        const summary = [...accessLog.querySelectorAll('[data-ip-memo]')].find(cell => cell.dataset.ipMemo === ip);
        if (summary) summary.textContent = saved.memo || '—';
        status.textContent = '保存済み';
      } catch (error) {
        status.textContent = error.message;
        status.classList.add('is-error');
      } finally {
        controls.forEach(control => { control.disabled = false; });
      }
    });
    logTable.addEventListener('input', event => {
      if (event.target.matches('[data-memo-ip] input[name="memo"]')) {
        event.target.form.querySelector('[data-memo-status]').hidden = true;
      }
    });
  }
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
  const security = root.querySelector('[data-security-check]');
  if (security) {
    const form = security.querySelector('[data-security-form]');
    const results = security.querySelector('[data-security-results]');
    const message = security.querySelector('[data-security-status]');
    const submit = form.querySelector('[type="submit"]');
    let active = 'categories';
    let filter = 'all';
    let pending = false;

    function updateView() {
      const select = results.querySelector('[data-security-filter]');
      select.value = filter;
      results.querySelectorAll('[data-security-tab]').forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.securityTab === active));
      });
      results.querySelectorAll('[data-security-row]').forEach(row => {
        row.hidden = filter !== 'all' && row.dataset.securityRow !== filter;
      });
      results.querySelectorAll('[data-security-group], [data-security-supplement]').forEach(group => {
        group.hidden = !group.querySelector('[data-security-row]:not([hidden])');
      });
      results.querySelectorAll('[data-security-unfiltered]').forEach(item => { item.hidden = filter !== 'all'; });
      results.querySelectorAll('[data-security-panel]').forEach(panel => {
        panel.hidden = panel.dataset.securityPanel !== active;
        const mainRows = panel.querySelector('[data-security-main-headers]') || panel;
        panel.querySelector('[data-security-empty]').hidden = !!mainRows.querySelector('[data-security-row]:not([hidden])');
      });
    }

    async function refresh(fields, status, success, busy) {
      pending = true;
      submit.disabled = true;
      form.querySelector('fieldset').disabled = true;
      results.setAttribute('aria-busy', 'true');
      status.textContent = busy;
      const groups = Array.from(results.querySelectorAll('[data-security-group][open]'), item => item.dataset.securityGroup);
      const openHeaders = Array.from(results.querySelectorAll('[data-security-header][open]'), item => item.dataset.securityHeader);
      const supplements = Array.from(results.querySelectorAll('[data-security-supplement], [data-security-references]'), item => item.open);
      const openBreakdowns = Array.from(results.querySelectorAll('[data-security-breakdown][open]'), item => item.dataset.securityBreakdown);
      const openSettings = Array.from(results.querySelectorAll('[data-security-header] [data-header-settings][open]'),
        item => item.closest('[data-security-header]').dataset.securityHeader);
      const redirectSettingsOpen = results.querySelector('[data-redirect-settings]')?.open;
      const applyingRedirect = fields.get('op') === 'apply_https_redirect';
      const header = fields.get('header');
      try {
        const data = await api('security_check', fields);
        results.innerHTML = data.html;
        results.querySelectorAll('[data-security-group]').forEach(item => {
          item.open = groups.includes(item.dataset.securityGroup);
        });
        results.querySelectorAll('[data-security-header]').forEach(item => {
          item.open = openHeaders.includes(item.dataset.securityHeader);
        });
        results.querySelectorAll('[data-security-supplement], [data-security-references]').forEach((item, index) => {
          item.open = supplements[index];
        });
        openSettings.forEach(id => {
          results.querySelector('[data-security-header="' + id + '"] [data-header-settings]').open = true;
        });
        const redirectSettings = results.querySelector('[data-redirect-settings]');
        if (redirectSettingsOpen && redirectSettings) redirectSettings.open = true;
        results.querySelectorAll('[data-security-breakdown]').forEach(item => {
          item.open = openBreakdowns.includes(item.dataset.securityBreakdown);
        });
        if (header) {
          active = 'headers';
          filter = 'all';
        } else if (applyingRedirect) {
          active = 'categories';
          filter = 'all';
        }
        updateView();
        if (header) {
          const item = results.querySelector('[data-security-header="' + header + '"]');
          item.querySelector('[data-header-status]').textContent = success;
          item.querySelector('[data-header-settings] > summary').focus({preventScroll: true});
        } else {
          message.textContent = success;
        }
      } catch (error) {
        status.textContent = error.message;
      } finally {
        pending = false;
        results.removeAttribute('aria-busy');
        form.querySelector('fieldset').disabled = false;
        submit.disabled = false;
      }
    }

    form.addEventListener('change', () => { message.textContent = '「再チェック」で選択を反映します。'; });
    form.addEventListener('submit', event => {
      event.preventDefault();
      if (pending) return;
      const fields = new FormData(form);
      fields.set('op', 'inspect');
      refresh(fields, message, '結果を更新しました。', '確認中…');
    });
    results.addEventListener('submit', async event => {
      const editor = event.target;
      if (editor.matches('[data-https-redirect-form]')) {
        event.preventDefault();
        if (pending) return;
        const fields = new FormData(form);
        fields.set('op', 'apply_https_redirect');
        const button = editor.querySelector('button');
        button.disabled = true;
        await refresh(fields, editor.closest('[data-https-redirect]').querySelector('[data-redirect-status]'), '', '適用と公開応答を確認中…');
        button.disabled = false;
        return;
      }
      if (!editor.matches('[data-header-form]')) return;
      event.preventDefault();
      const action = event.submitter;
      if (pending || !action) return;
      const removing = action.value === 'remove_header';
      const preview = editor.querySelector('[data-header-preview]').textContent;
      if (!confirm(removing ? 'この項目の設定を解除しますか？' : '公開HTMLに適用しますか？\n\n' + preview)) return;
      const fields = new FormData(form);
      new FormData(editor).forEach((value, key) => fields.append(key, value));
      fields.set('op', action.value);
      const buttons = Array.from(editor.querySelectorAll('button:enabled'));
      buttons.forEach(button => { button.disabled = true; });
      const success = removing ? '設定を解除しました。「再チェック」で公開応答を確認できます。'
        : '設定を保存しました。「再チェック」で公開応答を確認できます。';
      await refresh(fields, editor.querySelector('[data-header-status]'), success, '保存中…');
      buttons.forEach(button => { button.disabled = false; });
    });
    results.addEventListener('input', event => {
      const editor = event.target.closest('[data-header-form]');
      if (!editor) return;
      const preview = editor.querySelector('[data-header-preview]');
      const fields = new FormData(editor);
      const name = preview.dataset.headerName + (fields.get('mode') === 'report' ? '-Report-Only' : '');
      const value = fields.get('value') ?? fields.getAll('features[]').map(feature => feature + '=()').join(', ');
      preview.textContent = name + ': ' + value.trim();
    });

    results.addEventListener('change', event => {
      if (event.target.matches('[data-security-filter]')) {
        filter = event.target.value;
        updateView();
      }
    });
    results.addEventListener('click', event => {
      const tab = event.target.closest('[data-security-tab]');
      if (tab) {
        active = tab.dataset.securityTab;
        updateView();
        return;
      }
      const jump = event.target.closest('[data-security-jump]');
      if (jump) {
        const group = results.querySelector('[data-security-group="' + jump.dataset.securityJump + '"]');
        if (!group) return;
        if (group.hidden) filter = 'all';
        active = 'categories';
        group.open = true;
        updateView();
        const summary = group.querySelector('summary');
        summary.focus();
        summary.scrollIntoView({block: 'nearest'});
      }
    });
  }
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
    const columns = ['url','title','content_status','source','method','kind','original_http','http','final_url','status','access'];
    const selected = () => {
      const q = crawler.querySelector('[data-crawl-search]').value.toLocaleLowerCase();
      const kinds = Array.from(crawler.querySelectorAll('[data-crawl-kind]:checked')).map(box => box.dataset.crawlKind);
      const only = crawler.querySelector('[data-crawl-mode]').value === 'only';
      const type=crawler.querySelector('[data-crawl-type]').value, issues=crawler.querySelector('[data-crawl-issues]').checked;
      return rows.filter(row => (!q || [row.url,row.title,row.content_status,row.source].join(' ').toLocaleLowerCase().includes(q)) && (!type||row.kind===type) && (!issues||row.issue)
        && (only ? kinds.some(kind=>(row.tags||[row.kind]).includes(kind)) : !kinds.some(kind=>(row.tags||[row.kind]).includes(kind))))
        .sort((a,b) => direction * (['http','original_http'].includes(sort) ? a[sort]-b[sort] : String(a[sort]||'').localeCompare(String(b[sort]||''), 'ja', { numeric:true })));
    };
    const render = () => {
      const visible = selected(); tbody.replaceChildren();
      visible.slice(0, 500).forEach(row => {
        const labels={page:'ページ',route:'URLパターン',css:'CSS',js:'JavaScript',image:'画像',font:'フォント',sitemap:'XML・サイトマップ',file:'ファイル'};
        const tr = document.createElement('tr'); columns.forEach(key => { const td = document.createElement('td'); td.textContent = key==='kind' ? labels[row.kind]||row.kind : key==='final_url'&&row[key]===row.url?'—':row[key] || (['http','original_http'].includes(key) ? '—' : ''); tr.append(td); }); tbody.append(tr);
      });
      root.querySelector('[data-crawl-count]').textContent = `${visible.length}件（画面は先頭500件。CSVは絞り込み後の全件）`;
    };
    const accept = data => {
      run = data.run;
      if (data.delta) {
        const map = new Map(rows.map(row => [row.url, row]));
        data.rows.forEach(row => map.set(row.url, row));
        rows = [...map.values()];
      } else {
        rows = data.rows;
      }
      finished = data.done;
      status.textContent = `${data.candidates}候補 · 通信${data.requests}回${data.done ? ' · 完了' : ''}${data.limited ? '（上限に達しました）' : ''}`;
      const warning = crawler.querySelector('[data-crawl-warning]');
      warning.textContent = (data.warnings || []).join(' ');
      warning.hidden = !warning.textContent;
      warning.classList.toggle('is-error', !warning.hidden);
      render();
    };
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
      const data = [['URL','ページタイトル','ステータス','発見元','発見方法','種類','HTTP（元）','HTTP（最終取得）','転送先','確認結果','アクセス条件'], ...selected().map(row=>columns.map(key=>row[key]))];
      const url = URL.createObjectURL(new Blob(['\ufeff' + data.map(row=>row.map(safe).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));
      const link = document.createElement('a'); link.href=url; link.download='nx-multi-tool-site-urls.csv'; link.click(); setTimeout(()=>URL.revokeObjectURL(url),1000);
    });
    window.addEventListener('pagehide', () => { pause = true; if (!run) return; const data = new FormData(); data.set('tool','site_urls'); data.set('op','clear'); data.set('run',run); data.set('_csrf_token',root.dataset.csrf); navigator.sendBeacon(root.dataset.api,data); });
  }
})();
