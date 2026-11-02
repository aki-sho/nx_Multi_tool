<section class="nxmt-panel" data-nxmt-crawler>
    <h2>公開サイトのURLを収集</h2><p>投稿・固定ページ以外の参照先を表示します。CSS・画像などの公開ファイルを取得できることは通常の動作です。</p>
    <div class="nxmt-inline"><button type="button" data-crawl-start>収集開始・再収集</button><button type="button" class="secondary" data-crawl-pause>一時停止</button><button type="button" class="secondary" data-crawl-resume>再開</button><button type="button" class="secondary" data-crawl-csv>表示結果をCSV出力</button></div>
    <p role="status" data-crawl-status>未開始</p>
    <div class="nxmt-fields"><label>検索<input type="search" data-crawl-search placeholder="URL・発見元"></label>
    <label>ファイルの種類<select data-crawl-type><option value="">すべて</option><?php foreach (['page'=>'ページ','css'=>'CSS','js'=>'JavaScript','image'=>'画像','font'=>'フォント','sitemap'=>'XML・サイトマップ','file'=>'その他のファイル'] as $key=>$label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <label>対象の扱い<select data-crawl-mode><option value="exclude">チェックした種類を除外</option><option value="only">チェックした種類だけ表示</option></select></label></div>
    <div class="nxmt-inline"><label><input type="checkbox" data-crawl-kind="upload">アップロード配下</label><label><input type="checkbox" data-crawl-kind="image_redirect">画像への転送</label><label><input type="checkbox" data-crawl-kind="attachment">添付ページ形式</label><label><input type="checkbox" data-crawl-issues>要確認の結果だけ表示</label></div>
    <p class="nxmt-muted">robots.txt・sitemap.xmlは探索用の候補も確認します。候補の404は「未検出」とし、実際のリンク切れと区別します。アクセス条件は未ログインでの取得結果で、安全性の判定ではありません。</p>
    <p class="nxmt-muted">最大10,000候補・2,000回の通信。同一サイトの公開IPだけを取得し、管理・認証・内部領域とクエリ付きURLは巡回しません。画面を離れると結果を削除します（通信できない場合も1時間で期限切れ）。</p>
</section>
<div class="nxmt-table-wrap"><table data-crawl-table><thead><tr><?php foreach (['url'=>'URL','source'=>'発見元','method'=>'発見方法','kind'=>'種類','original_http'=>'HTTP（元）','http'=>'HTTP（最終取得）','final_url'=>'転送先','status'=>'確認結果','access'=>'アクセス条件'] as $key=>$label): ?><th><button type="button" class="nxmt-sort" data-crawl-sort="<?= e($key) ?>"><?= e($label) ?> ↕</button></th><?php endforeach; ?></tr></thead><tbody></tbody></table></div><p class="nxmt-muted" data-crawl-count></p>
