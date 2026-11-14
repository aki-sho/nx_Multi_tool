<?php
use NxMultiTool\PublicHeaders;

$saved = $data['policies'][$id] ?? null;
$value = $saved['value'] ?? PublicHeaders::DEFAULTS[$id];
$mode = $saved['mode'] ?? ($id === 'csp' ? 'report' : 'enforce');
$httpsOnly = $id === 'hsts' && parse_url($public['url'], PHP_URL_SCHEME) !== 'https';
?>
<details class="nxmt-security-header-settings" data-header-settings>
    <summary>設定案を見る</summary>
    <p>NX Multi Toolの設定 <strong><?= $saved === null ? '未適用' : ($mode === 'report' ? '適用済み（報告モード）' : '適用済み') ?></strong></p>
    <?php if (isset($public['observed'][$id])): ?><p><?= e($public['observed'][$id]) ?></p><?php endif; ?>
    <form data-header-form method="post" action="<?= e(\NxMultiTool\Registry::endpoint('api')) ?>">
        <input type="hidden" name="header" value="<?= e($id) ?>">
        <fieldset <?= $data['policies'] === null ? 'disabled' : '' ?>>
            <legend>送信する値</legend>
            <?php if ($id === 'csp'): ?>
                <label>CSPの値
                    <input name="value" value="<?= e($value) ?>" required maxlength="2048" spellcheck="false">
                </label>
                <label>適用方法
                    <select name="mode">
                        <option value="report" <?= $mode === 'report' ? 'selected' : '' ?>>報告モード</option>
                        <option value="enforce" <?= $mode === 'enforce' ? 'selected' : '' ?>>制限を適用</option>
                    </select>
                </label>
                <p class="nxmt-muted">報告モードではブラウザーの開発者ツールで違反を確認できます。制限を適用する前に、テーマや外部素材が使えることを確認してください。</p>
            <?php elseif ($id === 'permissions'): ?>
                <div class="nxmt-security-feature-options">
                    <?php foreach (PublicHeaders::FEATURES as $feature => $label): ?>
                        <label>
                            <input type="checkbox" name="features[]" value="<?= e($feature) ?>"
                                <?= in_array($feature . '=()', explode(', ', $value), true) ? 'checked' : '' ?>>
                            <?= e($label) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="nxmt-muted">公開ページで使っていない機能を選択してください。</p>
            <?php elseif (isset(PublicHeaders::OPTIONS[$id])): ?>
                <select name="value" aria-label="<?= e(PublicHeaders::NAMES[$id] . ' の値') ?>">
                    <?php foreach (PublicHeaders::OPTIONS[$id] as $option): ?>
                        <option value="<?= e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input type="hidden" name="value" value="<?= e($value) ?>">
            <?php endif; ?>
            <pre data-header-preview data-header-name="<?= e(PublicHeaders::NAMES[$id]) ?>"><?= e(PublicHeaders::name($id, $mode) . ': ' . $value) ?></pre>
            <?php if ($id === 'hsts'): ?>
                <p class="nxmt-muted">公開トップから送信し、ブラウザーはこのホストのHTTPSを5分間優先します。解除した後も、受信済みの有効期間は残ります。</p>
            <?php elseif ($id === 'frame'): ?>
                <p class="nxmt-muted">SAMEORIGINは同一オリジンからの埋め込みを許可します。DENYはすべての埋め込みを制限します。</p>
            <?php endif; ?>
            <button type="submit" name="op" value="apply_header" <?= $httpsOnly ? 'disabled' : '' ?>><?= $saved === null ? 'この項目を適用' : 'この項目を更新' ?></button>
        </fieldset>
        <?php if ($saved !== null): ?><button type="submit" name="op" value="remove_header" class="secondary" formnovalidate>この項目を解除</button><?php endif; ?>
        <?php if ($httpsOnly): ?><p class="nxmt-muted">HTTPSで管理画面を開くと、この項目を適用できます。</p><?php endif; ?>
        <p class="nxmt-muted" role="status" data-header-status></p>
    </form>
</details>
