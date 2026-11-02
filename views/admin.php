<?php
use NxMultiTool\Registry;
use NxMultiTool\AdminController;
use app\Core\Csrf;
$groups = ['content' => 'コンテンツ', 'management' => 'サイト管理', 'utility' => 'ユーティリティ'];
?>
<div class="nxmt" data-nxmt data-api="<?= e(Registry::endpoint('api')) ?>" data-csrf="<?= e(Csrf::token()) ?>" style="--nxmt-font:<?= (int)($settings['font_size'] ?? 3) + 12 ?>px">
    <header class="nxmt-header"><div><a class="nxmt-eyebrow" href="<?= e(Registry::url()) ?>">NEXAPRESS / TOOLBOX</a><h1><?= e($registry[$tool][0] ?? 'NX Multi Tool') ?></h1><p><?= e($registry[$tool][1] ?? 'サイトの運営と、毎日の作業をひとつの場所で。') ?></p></div><span class="nxmt-version">v<?= e(\NxMultiTool\Registry::VERSION) ?> · 13 tools</span></header>
    <nav class="nxmt-nav" aria-label="Multi Tool"><a href="<?= e(Registry::url()) ?>" <?= $tool === '' ? 'aria-current="page"' : '' ?>>ダッシュボード</a><?php foreach ($registry as $id => $definition): ?><?php if (!empty($settings['tools'][$id])): ?><a href="<?= e(Registry::url($id)) ?>" <?= $tool === $id ? 'aria-current="page"' : '' ?>><?= e($definition[0]) ?></a><?php endif; ?><?php endforeach; ?></nav>
    <?php if ($notice): ?><p class="nxmt-notice <?= $notice[0] === 'error' ? 'is-error' : '' ?>" role="status"><?= e($notice[1]) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="nxmt-notice is-error" role="alert"><?= e($error) ?></p><?php else: ?>
    <main class="nxmt-body">
    <?php if ($tool === ''): ?>
        <form method="post" action="<?= e(Registry::endpoint('settings')) ?>" class="nxmt-settings">
            <input type="hidden" name="_csrf_token" value="<?= e(Csrf::token()) ?>">
            <div class="nxmt-section-heading"><h2>使うツールを選ぶ</h2><p>オフにしたツールの画面と専用処理は読み込みません。</p></div>
            <?php foreach ($groups as $group => $label): ?><h3><?= e($label) ?></h3><div class="nxmt-cards"><?php foreach ($registry as $id => $definition): ?><?php if ($definition[2] === $group): ?><section class="nxmt-card"><label class="nxmt-switch"><input type="checkbox" name="tools[]" value="<?= e($id) ?>" <?= $settings['tools'][$id] ? 'checked' : '' ?>><span><?= e($definition[0]) ?></span></label><p><?= e($definition[1]) ?></p><?php if ($settings['tools'][$id]): ?><a href="<?= e(Registry::url($id)) ?>">ツールを開く →</a><?php else: ?><span class="nxmt-muted">無効</span><?php endif; ?></section><?php endif; ?><?php endforeach; ?></div><?php endforeach; ?>
            <section class="nxmt-panel"><h2>共通設定</h2><div class="nxmt-fields">
                <label>文字サイズ<select name="font_size"><?php foreach ([1 => '小', 2 => 'やや小', 3 => '標準', 4 => 'やや大', 5 => '大'] as $value => $label): ?><option value="<?= $value ?>" <?= $settings['font_size'] == $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="nxmt-wide">公開サイトURL<input type="url" name="site_url" value="<?= e($settings['site_url']) ?>" placeholder="https://example.com/blog" maxlength="2048"><small>サイト内URL収集の起点。設置先のパスまで指定します。</small></label>
                <label>ログ保持日数<input type="number" name="log_days" min="1" max="365" value="<?= (int)$settings['log_days'] ?>"></label>
                <label>ログ保存上限<input type="number" name="log_max" min="100" max="100000" value="<?= (int)$settings['log_max'] ?>"></label>
                <label>ログ記録上限／分<input type="number" name="log_per_minute" min="1" max="600" value="<?= (int)$settings['log_per_minute'] ?>"></label>
                <label>同一IPの記録上限／分<input type="number" name="log_per_ip" min="1" max="60" value="<?= (int)$settings['log_per_ip'] ?>"></label>
                <label>IP拒否の有効時間<input type="number" name="block_hours" min="1" max="720" value="<?= (int)$settings['block_hours'] ?>"></label>
                <label class="nxmt-wide">許可IP（1行に1件）<textarea name="allow_ips" rows="3"><?= e(implode("\n", $settings['allow_ips'])) ?></textarea></label>
            </div><label class="nxmt-check"><input type="checkbox" name="comments_open" value="1" <?= $settings['comments_open'] ? 'checked' : '' ?>>公開記事・固定ページでコメントを受け付ける（全件承認制）</label><p class="nxmt-muted">初期状態では受付停止。コメント管理ツールが有効な場合に、公開ページへフォームを追加します。</p></section>
            <div class="nxmt-save"><button type="submit">設定を保存</button><span>設定は拡張機能の更新後も保持されます。</span></div>
        </form>
    <?php else: ?><?php require __DIR__ . '/tools/' . $tool . '.php'; ?><?php endif; ?>
    </main><?php endif; ?>
    <footer class="nxmt-footer">NX Multi Tool · NexaPress 2.4+ <span>サイトと作業の、小さな道具箱。</span></footer>
</div>
