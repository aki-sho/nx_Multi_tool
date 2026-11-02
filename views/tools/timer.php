<link rel="stylesheet" href="<?= e(\NxMultiTool\Registry::asset('timer.css')) ?>"><section class="nxmt-panel nxmt-timer">
    <div class="nxmt-card">
      <div class="nxmt-note">
        <strong>使い方：</strong>
        「カウントダウン / ストップウォッチ / アラーム」をタブで切替。終了時に <code>timer-finish.mp3</code> を鳴らし、操作音は <code>ui-click.mp3</code> を使います。
      </div>

      <div class="nxmt-toolbar">
        <div class="nxmt-tabs" role="tablist" aria-label="Timer Tabs">
          <button type="button" class="nxmt-tab is-active" data-tab="countdown" role="tab" aria-selected="true">カウントダウン</button>
          <button type="button" class="nxmt-tab" data-tab="stopwatch" role="tab" aria-selected="false">ストップウォッチ</button>
          <button type="button" class="nxmt-tab" data-tab="alarm" role="tab" aria-selected="false">アラーム</button>
        </div>

        <div class="nxmt-settings">
          <label class="nxmt-toggle">
            <input type="checkbox" id="nxmt-sound-enabled" checked>
            <span>終了音</span>
          </label>

          <label class="nxmt-toggle">
            <input type="checkbox" id="nxmt-click-enabled" checked>
            <span>操作音</span>
          </label>

          <label class="nxmt-toggle">
            <input type="checkbox" id="nxmt-notify-enabled">
            <span>通知</span>
          </label>

          <label class="nxmt-volume">
            <span>音量</span>
            <input type="range" id="nxmt-volume" min="0" max="100" value="80">
          </label>
        </div>
      </div>

      <!-- ===== カウントダウン ===== -->
      <section class="nxmt-panel is-active" data-panel="countdown" role="tabpanel">
        <div class="nxmt-grid">
          <div>
            <div class="nxmt-label">時間設定</div>
            <div class="nxmt-time-inputs">
              <label>時 <input type="number" id="cd-h" min="0" max="99" value="0"></label>
              <label>分 <input type="number" id="cd-m" min="0" max="59" value="10"></label>
              <label>秒 <input type="number" id="cd-s" min="0" max="59" value="0"></label>
              <button type="button" class="button" id="cd-apply">反映</button>
            </div>

            <div class="nxmt-label" style="margin-top:10px;">プリセット</div>
            <div class="nxmt-presets">
              <button type="button" class="button" data-preset-sec="300">5分</button>
              <button type="button" class="button" data-preset-sec="600">10分</button>
              <button type="button" class="button" data-preset-sec="900">15分</button>
              <button type="button" class="button" data-preset-sec="1800">30分</button>
              <button type="button" class="button" data-preset-sec="3600">60分</button>
            </div>

            <div class="nxmt-actions">
              <button type="button" class="button button-primary" id="cd-start">開始</button>
              <button type="button" class="button" id="cd-pause" disabled>一時停止</button>
              <button type="button" class="button" id="cd-resume" disabled>再開</button>
              <button type="button" class="button" id="cd-reset">リセット</button>
            </div>

            <div class="nxmt-mini-actions">
              <button type="button" class="button" id="cd-copy-remaining">残りをコピー</button>
              <button type="button" class="button" id="cd-copy-end">終了予定をコピー</button>
            </div>
          </div>

          <div>
            <div class="nxmt-display">
              <div class="nxmt-display-title">残り</div>
              <div class="nxmt-display-time" id="cd-display">00:10:00</div>
              <div class="nxmt-sub" id="cd-sub">未開始</div>
              <div class="nxmt-progress">
                <div class="nxmt-progress-bar" id="cd-bar" style="width:0%"></div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ===== ストップウォッチ ===== -->
      <section class="nxmt-panel" data-panel="stopwatch" role="tabpanel">
        <div class="nxmt-grid">
          <div>
            <div class="nxmt-actions">
              <button type="button" class="button button-primary" id="sw-start">開始</button>
              <button type="button" class="button" id="sw-stop" disabled>停止</button>
              <button type="button" class="button" id="sw-reset">リセット</button>
              <button type="button" class="button" id="sw-lap" disabled>ラップ</button>
            </div>

            <div class="nxmt-mini-actions">
              <button type="button" class="button" id="sw-copy">記録をコピー</button>
              <button type="button" class="button" id="sw-clear-laps">ラップ消去</button>
            </div>

            <div class="nxmt-laps">
              <div class="nxmt-label">ラップ</div>
              <ol id="sw-laps"></ol>
            </div>
          </div>

          <div>
            <div class="nxmt-display">
              <div class="nxmt-display-title">経過</div>
              <div class="nxmt-display-time" id="sw-display">00:00:00.0</div>
              <div class="nxmt-sub" id="sw-sub">未開始</div>
            </div>
          </div>
        </div>
      </section>

      <!-- ===== アラーム ===== -->
      <section class="nxmt-panel" data-panel="alarm" role="tabpanel">
        <div class="nxmt-grid">
          <div>
            <div class="nxmt-label">指定時刻（HH:MM）</div>
            <div class="nxmt-alarm-inputs">
              <input type="time" id="al-time" value="14:30">
              <button type="button" class="button button-primary" id="al-set">セット</button>
              <button type="button" class="button" id="al-clear" disabled>解除</button>
            </div>

            <div class="nxmt-mini-actions">
              <button type="button" class="button" id="al-copy">設定内容をコピー</button>
            </div>
          </div>

          <div>
            <div class="nxmt-display">
              <div class="nxmt-display-title">あと</div>
              <div class="nxmt-display-time" id="al-display">--:--:--</div>
              <div class="nxmt-sub" id="al-sub">未設定</div>
            </div>
          </div>
        </div>
      </section>
    </div>




</section><script>window.NXMT_TIMER=<?= json_encode(['finishSoundUrl'=>\NxMultiTool\Registry::asset('finish.mp3'),'clickSoundUrl'=>\NxMultiTool\Registry::asset('click.mp3')], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="<?= e(\NxMultiTool\Registry::asset('timer.js')) ?>" defer></script>
