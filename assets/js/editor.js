(() => {
  'use strict';
  const initialize = () => {
    const textarea = document.querySelector('.post-form textarea[name="content"]'), engine = window.NXMTEditorSearchEngine;
    if (!textarea || !engine || document.querySelector('[data-nxmt-editor]')) return;
    const panel = document.createElement('details'); panel.className = 'nxmt-editor'; panel.dataset.nxmtEditor = '';
    panel.innerHTML = '<summary>本文を検索・置換</summary><label>検索する文章<textarea data-find rows="2"></textarea></label><label>置換後の文章<textarea data-replace rows="2"></textarea></label><div class="nxmt-inline"><label><input type="checkbox" data-regex>正規表現（.*）</label><label><input type="checkbox" data-case>大文字小文字を区別</label><button type="button" data-scan>検索</button><button type="button" class="secondary" data-prev>前へ</button><button type="button" class="secondary" data-next>次へ</button></div><div class="nxmt-inline"><button type="button" data-one>この箇所を置換</button><button type="button" data-all>すべて置換</button><button type="button" class="secondary" data-undo>元に戻す</button></div><p role="status" data-status>検索対象は本文です。タグ・属性・コード部分は変更しません。</p><div class="nxmt-editor-results" data-results></div>';
    textarea.closest('.classic-editor')?.before(panel) || (!panel.isConnected && textarea.before(panel));
    const find = panel.querySelector('[data-find]'), replacement = panel.querySelector('[data-replace]'), regular = panel.querySelector('[data-regex]'), sensitive = panel.querySelector('[data-case]'), status = panel.querySelector('[data-status]'), results = panel.querySelector('[data-results]');
    let matches = [], snapshot = '', current = 0, limited = false, job = null, sequence = 0, searching = false, history = [], expectedUndo = null;
    const script = Array.from(document.scripts).find(item => /\/editor\.js(?:\?|$)/.test(item.src));
    const workerUrl = script ? script.src.replace(/editor\.js(?=\?|$)/,'editor-worker.js') : '';
    const visual = () => { const wrapper = textarea.closest('.classic-editor'); return wrapper && !wrapper.classList.contains('is-html-mode') ? wrapper.querySelector('.classic-editor-content') : null; };
    const read = () => visual()?.innerHTML ?? textarea.value;
    const stopWorker = () => { if (job) { const old=job; job=null; clearTimeout(old.timeout);old.worker.terminate();old.reject(new Error('検索を中止しました。')); } searching = false; };
    const clearHighlight = () => { if (window.CSS?.highlights) { CSS.highlights.delete('nxmt-search'); CSS.highlights.delete('nxmt-current'); } };
    const invalidate = () => { sequence++; stopWorker(); matches = []; results.replaceChildren(); clearHighlight(); };
    const buttons = () => { panel.querySelector('[data-one]').disabled = searching || !matches.length; panel.querySelector('[data-all]').disabled = searching || !matches.length || limited; panel.querySelector('[data-undo]').disabled = history.length === 0; };
    const display = () => {
      results.replaceChildren(); clearHighlight();
      matches.slice(0, 100).forEach((match, index) => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'nxmt-editor-hit'; button.setAttribute('aria-current', String(index === current));
        button.append(document.createTextNode(match.before)); const mark = document.createElement('mark'); mark.textContent = match.text; button.append(mark,document.createTextNode(match.after));
        button.addEventListener('click', () => { current=index; focusMatch(); }); results.append(button);
      });
      status.textContent = `${matches.length}件${limited ? '（上限のため一括置換はできません）' : ''}${matches.length ? ` · ${current+1}件目` : ''}`;
      const content = visual();
      if (content && window.CSS?.highlights && typeof Highlight !== 'undefined') {
        const ranges = engine.domRangesForMatches(content,matches).filter(Boolean); CSS.highlights.set('nxmt-search',new Highlight(...ranges));
        const range = matches.length ? engine.domRangesForMatches(content,[matches[current]]).filter(Boolean)[0] : null; if (range) CSS.highlights.set('nxmt-current',new Highlight(range));
      }
      buttons();
    };
    const focusMatch = () => {
      if (!matches.length || read() !== snapshot) { status.textContent='本文が変更されました。再検索してください。'; return; }
      const content = visual(), match = matches[current];
      if (content) { const range=engine.domRangesForMatches(content,[match])[0]; range?.startContainer.parentElement?.scrollIntoView({block:'nearest'}); }
      else if (match.ranges?.length) { textarea.focus(); textarea.setSelectionRange(match.ranges[0][0],match.ranges.at(-1)[1]); }
      display();
    };
    const scan = async () => {
      invalidate(); snapshot=read(); limited=false; current=0;
      if (!find.value) { status.textContent='検索する文章を入力してください。'; buttons(); return; }
      if (snapshot.length>2000000 || find.value.length>10000) { status.textContent='本文または検索語が上限を超えています。'; buttons(); return; }
      const expected=sequence; searching=true; buttons(); status.textContent='検索中…';
      try {
        if (regular.checked) {
          if (!window.Worker || !workerUrl) throw new Error('この環境では正規表現Workerを利用できません。');
          const runs=engine.htmlRuns(snapshot);
          const response=await new Promise((resolve,reject)=>{
            const worker=new Worker(workerUrl);
            const finish=()=>{if(job?.worker===worker){clearTimeout(job.timeout);job=null;}worker.terminate();};
            job={worker,reject,timeout:setTimeout(()=>{finish();reject(new Error('正規表現の処理時間を超えました。式を絞ってください。'));},1500)};
            worker.onmessage=event=>{if(event.data.ready)return;finish();if(event.data.error)reject(new Error(event.data.error));else resolve(event.data);};
            worker.onerror=()=>{finish();reject(new Error('正規表現を実行できません。'));};
            worker.postMessage({sources:[runs.map(run=>run.text)],needle:find.value,caseSensitive:sensitive.checked});
          });
          const found=engine.attachRanges(runs,response.results[0]||{matches:[],limited:false}); matches=found.matches; limited=response.limited;
        } else { const found=engine.scanHTML(snapshot,find.value,sensitive.checked); matches=found.matches; limited=found.limited; }
        if(expected!==sequence)return;
        searching=false; display();
      } catch(error) { if(expected===sequence){searching=false;status.textContent=error.message;buttons();} }
    };
    const write = value => {
      const content=visual();
      if(content){content.innerHTML=value;content.dispatchEvent(new Event('input',{bubbles:true}));textarea.value=content.innerHTML;}
      else textarea.value=value;
      textarea.dispatchEvent(new Event('input',{bubbles:true})); textarea.dispatchEvent(new Event('change',{bubbles:true}));
    };
    const replace = all => {
      if(searching||!matches.length||all&&limited)return;
      if(read()!==snapshot){invalidate();status.textContent='本文が変わりました。再検索してください。';buttons();return;}
      try {
        const chosen=all?matches:[matches[current]], size=engine.measureReplacementHTML(snapshot,chosen,replacement.value,regular.checked);
        if(size>2000000)throw new Error('置換後の本文が200万文字を超えます。');
        const next=engine.replaceHTML(snapshot,chosen,replacement.value,regular.checked);
        history.push(snapshot);if(history.length>20)history.shift();write(next);expectedUndo=read();scan();
      }catch(error){status.textContent=error.message;}
    };
    panel.querySelector('[data-scan]').addEventListener('click',scan);
    panel.querySelector('[data-one]').addEventListener('click',()=>replace(false));panel.querySelector('[data-all]').addEventListener('click',()=>replace(true));
    panel.querySelector('[data-prev]').addEventListener('click',()=>{if(matches.length){current=(current+matches.length-1)%matches.length;focusMatch();}});
    panel.querySelector('[data-next]').addEventListener('click',()=>{if(matches.length){current=(current+1)%matches.length;focusMatch();}});
    panel.querySelector('[data-undo]').addEventListener('click',()=>{
      if(!history.length)return;if(expectedUndo!==read()){status.textContent='置換後に本文が編集されています。CMSの編集履歴をご利用ください。';return;}
      write(history.pop());expectedUndo=read();scan();
    });
    [find,regular,sensitive].forEach(field=>field.addEventListener('input',()=>{invalidate();buttons();}));
    textarea.form?.addEventListener('input',event=>{if(!panel.contains(event.target)){invalidate();status.textContent='本文を変更しました。再検索してください。';buttons();}});
    document.addEventListener('keydown',event=>{
      if((event.ctrlKey||event.metaKey)&&['f','h'].includes(event.key.toLowerCase())&&(event.target===textarea||visual()?.contains(event.target))){event.preventDefault();panel.open=true;find.focus();}
      if(event.key==='Escape'&&panel.contains(event.target)){panel.open=false;clearHighlight();}
    });
    find.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.altKey){event.preventDefault();if(event.shiftKey)panel.querySelector('[data-prev]').click();else if(matches.length)panel.querySelector('[data-next]').click();else scan();}});
    panel.addEventListener('toggle',()=>{if(!panel.open){clearHighlight();stopWorker();}});buttons();
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initialize);else initialize();
})();
