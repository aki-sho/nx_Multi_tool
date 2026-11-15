<?php
use NxMultiTool\Security;

$public = $data['public'];
$redirect = $data['https_redirect'];
$score = $public['score'] === null ? '未採点' : $public['score'] . ' / 100点';
$formatPoints = static fn(int $tenths): string => ($tenths % 10 === 0)
    ? (string)intdiv($tenths, 10) : number_format($tenths / 10, 1);
$categoryTotal = $data['category_score'];
$scoreText = static fn(array $points): string => ($points['pending'] === $points['max'] ? '—' : $formatPoints($points['earned']))
    . ' / ' . $formatPoints($points['max']) . '点';
$pendingText = static fn(array $points): string => '未確認の配点 ' . $formatPoints($points['pending']) . '点';
$scope = [
    'cms' => ['CMS側', '確認先（CMS）'],
    'both' => ['両方', '確認先（CMS・サーバー）'],
    'server' => ['サーバー側', '確認先（サーバー）'],
    'versions' => ['CMS側で導入バージョンのみ確認可能', '確認先（CMS）'],
];
$badge = static function (string $state): void { ?>
    <span class="nxmt-security-badge" data-state="<?= e($state) ?>"><?= e(Security::STATES[$state]) ?></span>
<?php };
$guidance = require dirname(__DIR__, 2) . '/config/header-guidance.php';
$levels = ['high' => '高', 'medium' => '中', 'low' => '低'];
$weightLabels = [];
foreach ($data['category_points'] as $level => $points) {
    $weightLabels[] = $levels[$level] . $points . '点';
}
$importanceBadge = static function (string $level) use ($levels): void { ?>
    <span class="nxmt-security-badge nxmt-security-importance" data-importance="<?= e($level) ?>">重要度 <?= e($levels[$level]) ?></span>
<?php };
$headerRow = static function (array $row, string $id) use ($badge, $importanceBadge, $guidance, $data, $public): void {
    $guide = $guidance[$id];
    ?>
    <details class="nxmt-security-header" data-security-header="<?= e($id) ?>"<?php if (isset($row['state'])): ?> data-security-row="<?= e($row['state']) ?>"<?php endif; ?>>
        <summary>
            <span class="nxmt-security-row-summary">
                <span><code><?= e($row['name']) ?></code><small><?= e($row['purpose']) ?></small></span>
                <span class="nxmt-security-row-badges">
                    <?php if (isset($row['state'])) { $badge($row['state']); } ?>
                    <?php $importanceBadge($guide['importance']); ?>
                </span>
            </span>
        </summary>
        <div class="nxmt-security-header-detail">
            <p><?= e($row['detail']) ?></p>
            <div class="nxmt-security-impact" data-header-impact>
                <strong>設定したときの影響</strong>
                <p><?= e($guide['impact']) ?></p>
                <p data-header-mitigation><strong>※改善方法</strong> <?= e($guide['mitigation'] ?? 'ありません。') ?></p>
            </div>
            <?php if (isset($row['category'])): ?>
                <button type="button" class="secondary" data-security-jump="<?= (int)$row['category'] ?>"><?= (int)$row['category'] ?>番の分類を見る</button>
            <?php endif; ?>
            <?php if (isset($guide['where'])): ?>
                <details class="nxmt-security-header-settings"><summary>設定先を見る</summary><p><?= e($guide['where']) ?></p></details>
            <?php else: ?>
                <?php require __DIR__ . '/public-header-settings.php'; ?>
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
        <button type="button" class="secondary" data-security-tab="categories" aria-pressed="true" aria-controls="nxmt-security-categories">
            対策一覧（20分類） <span class="nxmt-security-badge" data-security-category-score><?= e($scoreText($categoryTotal)) ?></span>
        </button>
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
    <div class="nxmt-security-panel-heading">
        <h2>対策一覧</h2>
        <div class="nxmt-security-importance-key">
            <span>重要度（高・中・低）</span>
            <small>一般的な公開サイトで確認する優先度です。</small>
        </div>
    </div>
    <p class="nxmt-muted">確認できた対策の得点 <?= e($scoreText($categoryTotal)) ?>。<?= e($pendingText($categoryTotal)) ?>。</p>
    <details class="nxmt-security-breakdown" data-security-breakdown="categories">
        <summary>採点の内訳</summary>
        <p class="nxmt-muted">分類の配点は<?= e(implode('、', $weightLabels)) ?>です。分類内の採点対象に均等に割り振り、「確認あり」は加点、「設定なし」は0点、「未確認」は保留とします。</p>
        <p class="nxmt-muted">この機能が確認した対策の範囲で採点します。</p>
        <dl>
            <?php foreach ($data['items'] as $group): ?>
                <div>
                    <dt><?= (int)$group['number'] ?>. <?= e($group['name']) ?><small>重要度 <?= e($levels[$group['importance']]) ?></small></dt>
                    <dd><?= e($scoreText($group['score'])) ?>
                        <?php if ($group['score']['pending'] > 0): ?><small><?= e($pendingText($group['score'])) ?></small><?php endif; ?>
                    </dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </details>
    <?php foreach ($data['items'] as $group):
        [$areaLabel, $locationLabel] = $scope[$group['area']];
        $counts = array_count_values(array_column($group['rows'], 'state'));
    ?>
        <details class="nxmt-security-group" data-security-group="<?= (int)$group['number'] ?>" <?= $group['number'] === 1 ? 'open' : '' ?>>
            <summary>
                <span class="nxmt-security-row-summary">
                    <span>
                        <strong><?= (int)$group['number'] ?>. <?= e($group['name']) ?></strong>
                        <span class="nxmt-security-badge"><?= e($areaLabel) ?></span>
                        <small class="nxmt-security-counts nxmt-muted">
                            <?php foreach (Security::STATES as $state => $label): ?><span><?= e($label) ?> <?= $counts[$state] ?? 0 ?></span><?php endforeach; ?>
                        </small>
                    </span>
                    <span class="nxmt-security-row-badges">
                        <span class="nxmt-security-badge nxmt-security-points" data-category-points><?= e($scoreText($group['score'])) ?></span>
                        <?php $importanceBadge($group['importance']); ?>
                    </span>
                </span>
            </summary>
            <div class="nxmt-security-meter" aria-hidden="true">
                <?php foreach ($counts as $state => $count): ?><span data-state="<?= e($state) ?>" style="flex:<?= (int)$count ?>"></span><?php endforeach; ?>
            </div>
            <div class="nxmt-security-group-body">
                <div class="nxmt-security-check-head"><span><?= e($locationLabel) ?></span><span>判定</span></div>
                <?php foreach ($group['rows'] as $row):
                    $isRedirect = ($row['id'] ?? '') === 'https_redirect';
                ?>
                    <div class="nxmt-security-check-row" data-security-row="<?= e($row['state']) ?>"<?= $isRedirect ? ' data-https-redirect' : '' ?>>
                        <div>
                            <strong><?= e($row['name']) ?></strong>
                            <small><?= e($row['location']) ?></small>
                            <p><?= e($row['detail']) ?></p>
                        </div>
                        <div class="nxmt-security-row-badges">
                            <?php if (!$row['scored']): ?><span class="nxmt-security-badge">採点対象外</span><?php endif; ?>
                            <?php $badge($row['state']); ?>
                        </div>
                        <?php if ($isRedirect && ($row['state'] === 'absent' || $redirect['notice'] !== null)): ?>
                            <div class="nxmt-security-redirect">
                                <?php if ($row['state'] === 'absent'): ?>
                                    <details class="nxmt-security-header-settings" data-redirect-settings<?= $redirect['file'] === null ? ' open' : '' ?>>
                                        <summary>設定案を見る</summary>
                                        <?php if ($redirect['file'] !== null): ?>
                                            <p>適用先 <code><?= e($redirect['file']) ?></code></p>
                                            <p>適用前の設定を保存します。移行中は一時転送を使い、動作を確認してから恒久転送に切り替えます。</p>
                                            <form method="post" data-https-redirect-form>
                                                <button type="submit">この項目を適用</button>
                                            </form>
                                        <?php else: ?>
                                            <p><?= e($redirect['reason']) ?></p>
                                            <p>転送の設定先は、ホスティングの管理画面またはWebサーバーの設定を確認してください。</p>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                                <p data-redirect-status<?= !empty($redirect['notice']['error']) ? ' class="is-error" role="alert"' : ' role="status"' ?>><?= e($redirect['notice']['message'] ?? '') ?></p>
                            </div>
                        <?php endif; ?>
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
    <div class="nxmt-security-panel-heading">
        <h2>公開トップの応答</h2>
        <div class="nxmt-security-importance-key" data-importance-key>
            <span>重要度（高・中・低）</span>
            <small>一般的な公開サイトで確認する優先度です。</small>
        </div>
    </div>
    <div class="nxmt-security-meta">
        <?php if ($public['url'] !== ''): ?><code><?= e($public['url']) ?></code><?php endif; ?>
        <?php if ($public['http'] !== null): ?><span class="nxmt-security-badge">HTTP <?= (int)$public['http'] ?></span><?php endif; ?>
    </div>
    <p class="nxmt-muted"><?= e($public['message']) ?></p>
    <p class="nxmt-muted">適用先は公開HTMLです。既存のPHPヘッダーは優先されます。設定後に「再チェック」で実際の公開応答を確認できます。</p>
    <?php if ($data['policies'] === null): ?><p class="nxmt-notice is-error">公開ヘッダーの保存内容を読み取れませんでした。</p><?php endif; ?>
    <details class="nxmt-security-breakdown" data-security-breakdown="headers">
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
        <?php foreach ($public['rows'] as $id => $row) { $headerRow($row, $id); } ?>
    </div>
    <p class="nxmt-muted" data-security-empty hidden>該当する主要ヘッダーはありません。</p>
    <details class="nxmt-security-supplement" data-security-supplement>
        <summary>応答の補足</summary>
        <?php foreach ($public['supplement'] as $id => $row) { $headerRow($row, $id); } ?>
    </details>
    <details class="nxmt-security-supplement" data-security-unfiltered data-security-references>
        <summary>参考ヘッダー</summary>
        <?php foreach ($public['references'] as $row) { $headerRow($row, $row['name']); } ?>
    </details>
</section>
