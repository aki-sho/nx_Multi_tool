<?php
use NxMultiTool\Security;

$public = $data['public'];
$score = $public['score'] === null ? '未採点' : $public['score'] . ' / 100点';
$scope = [
    'cms' => ['CMS側', '確認先（CMS）'],
    'both' => ['両方', '確認先（CMS・サーバー）'],
    'server' => ['サーバー側', '確認先（サーバー）'],
    'versions' => ['CMS側で導入バージョンのみ確認可能', '確認先（CMS）'],
];
$badge = static function (string $state): void { ?>
    <span class="nxmt-security-badge" data-state="<?= e($state) ?>"><?= e(Security::STATES[$state]) ?></span>
<?php };
$headerRow = static function (array $row) use ($badge): void { ?>
    <details class="nxmt-security-header" data-security-row="<?= e($row['state']) ?>">
        <summary>
            <span><code><?= e($row['name']) ?></code><small><?= e($row['purpose']) ?></small></span>
            <?php $badge($row['state']); ?>
        </summary>
        <div class="nxmt-security-header-detail">
            <p><?= e($row['detail']) ?></p>
            <?php if ($row['category'] !== null): ?>
                <button type="button" class="secondary" data-security-jump="<?= (int)$row['category'] ?>"><?= (int)$row['category'] ?>番の分類を見る</button>
            <?php endif; ?>
        </div>
    </details>
<?php };
?>
<div class="nxmt-security-meta nxmt-muted">
    <time datetime="<?= e(date(DATE_ATOM, $data['checked_at'])) ?>">確認日時 <?= e(date('Y-m-d H:i:s', $data['checked_at'])) ?></time>
    <?php foreach ($data['facts'] as $name => $value): ?><span><?= e($name . ' ' . $value) ?></span><?php endforeach; ?>
</div>
<div class="nxmt-security-toolbar">
    <div class="nxmt-security-tabs" role="group" aria-label="結果の表示">
        <button type="button" class="secondary" data-security-tab="categories" aria-pressed="true" aria-controls="nxmt-security-categories">対策一覧（20分類）</button>
        <button type="button" class="secondary" data-security-tab="headers" aria-pressed="false" aria-controls="nxmt-security-headers">
            公開ヘッダー <span class="nxmt-security-badge" data-security-score><?= e($score) ?></span>
        </button>
    </div>
    <label>状態
        <select data-security-filter>
            <option value="all">すべて</option>
            <?php foreach (Security::STATES as $state => $label): ?><option value="<?= e($state) ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </label>
</div>
<section class="nxmt-panel nxmt-security-results" id="nxmt-security-categories" data-security-panel="categories" aria-label="対策一覧（20分類）">
    <p class="nxmt-muted">確認できた範囲を、対策ごとに表示します。</p>
    <?php foreach ($data['items'] as $group):
        [$areaLabel, $locationLabel] = $scope[$group['area']];
        $counts = array_count_values(array_column($group['rows'], 'state'));
    ?>
        <details class="nxmt-security-group" data-security-group="<?= (int)$group['number'] ?>" <?= $group['number'] === 1 ? 'open' : '' ?>>
            <summary>
                <span class="nxmt-security-group-title">
                    <strong><?= (int)$group['number'] ?>. <?= e($group['name']) ?></strong>
                    <span class="nxmt-security-badge"><?= e($areaLabel) ?></span>
                </span>
                <span class="nxmt-security-counts nxmt-muted">
                    <?php foreach (Security::STATES as $state => $label): ?><span><?= e($label) ?> <?= $counts[$state] ?? 0 ?></span><?php endforeach; ?>
                </span>
            </summary>
            <div class="nxmt-security-meter" aria-hidden="true">
                <?php foreach ($counts as $state => $count): ?><span data-state="<?= e($state) ?>" style="flex:<?= (int)$count ?>"></span><?php endforeach; ?>
            </div>
            <div class="nxmt-security-group-body">
                <div class="nxmt-security-check-head"><span><?= e($locationLabel) ?></span><span>判定</span></div>
                <?php foreach ($group['rows'] as $row): ?>
                    <div class="nxmt-security-check-row" data-security-row="<?= e($row['state']) ?>">
                        <div>
                            <strong><?= e($row['name']) ?></strong>
                            <small><?= e($row['location']) ?></small>
                            <p><?= e($row['detail']) ?></p>
                        </div>
                        <?php $badge($row['state']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
    <p class="nxmt-muted" data-security-empty hidden>該当する項目はありません。</p>
    <?php if ($data['observations'] !== null): ?>
        <details class="nxmt-security-supplement" data-security-unfiltered>
            <summary>アクセスログの集計</summary>
            <p><?= e($data['observations']) ?></p>
        </details>
    <?php endif; ?>
</section>
<section class="nxmt-panel nxmt-security-results" id="nxmt-security-headers" data-security-panel="headers" aria-label="公開ヘッダー" hidden>
    <h2>公開トップの応答</h2>
    <div class="nxmt-security-meta">
        <?php if ($public['url'] !== ''): ?><code><?= e($public['url']) ?></code><?php endif; ?>
        <?php if ($public['http'] !== null): ?><span class="nxmt-security-badge">HTTP <?= (int)$public['http'] ?></span><?php endif; ?>
    </div>
    <p class="nxmt-muted"><?= e($public['message']) ?></p>
    <details class="nxmt-security-breakdown">
        <summary>採点の内訳 <span class="nxmt-security-badge"><?= e($score) ?></span></summary>
        <p class="nxmt-muted">公開トップの主要6項目に対する独自の配点です。</p>
        <dl>
            <?php foreach ($public['rows'] as $row): ?>
                <div>
                    <dt><code><?= e($row['name']) ?></code><small><?= e($row['rule']) ?></small></dt>
                    <dd><?= $row['points'] === null ? '—' : (int)$row['points'] ?> / <?= (int)$row['max'] ?>点</dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </details>
    <div data-security-main-headers>
        <?php foreach ($public['rows'] as $row) { $headerRow($row); } ?>
    </div>
    <p class="nxmt-muted" data-security-empty hidden>該当する主要ヘッダーはありません。</p>
    <details class="nxmt-security-supplement" data-security-supplement>
        <summary>応答の補足</summary>
        <?php foreach ($public['supplement'] as $row) { $headerRow($row); } ?>
    </details>
    <details class="nxmt-security-supplement" data-security-unfiltered>
        <summary>参考ヘッダー</summary>
        <?php foreach ($public['references'] as $row): ?>
            <div class="nxmt-security-check-row">
                <div><strong><code><?= e($row['name']) ?></code></strong><small><?= e($row['purpose']) ?></small><p><?= e($row['detail']) ?></p></div>
            </div>
        <?php endforeach; ?>
    </details>
</section>
