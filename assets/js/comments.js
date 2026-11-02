(() => {
  'use strict';
  const script = document.querySelector('script[data-nxmt-comments]');
  if (!script || document.getElementById('nxmt-comments-frame')) return;
  const container = document.querySelector('main article') || document.querySelector('article') || document.querySelector('main');
  if (!container) return;
  const frame = document.createElement('iframe'); frame.id = 'nxmt-comments-frame'; frame.src = script.dataset.nxmtComments;
  frame.title = 'この記事のコメント'; frame.loading = 'lazy'; frame.style.cssText = 'width:100%;height:650px;border:0;display:block;margin:24px 0';
  container.append(frame);
  window.addEventListener('message', event => {
    if (event.origin !== location.origin || event.source !== frame.contentWindow || event.data?.type !== 'nxmt-comments-height') return;
    const height = Number(event.data.height); if (Number.isFinite(height)) frame.style.height = Math.min(15000, Math.max(200, height + 24)) + 'px';
  });
})();
