<?php use NxMultiTool\Security; ?>
<section data-security-check>
    <form class="nxmt-panel nxmt-security-scope" data-security-form>
        <fieldset>
            <legend>読み取り元</legend>
            <div class="nxmt-security-sources">
                <?php foreach (['cms' => '本体・拡張の設定', 'server' => 'PHPの実行設定', 'http' => 'HTTPS転送・ヘッダー・Cookie属性'] as $source => $detail): ?>
                    <label>
                        <input type="checkbox" name="sources[]" value="<?= e($source) ?>" checked>
                        <span><strong><?= e(Security::SOURCES[$source]) ?></strong><small><?= e($detail) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="nxmt-inline nxmt-security-actions">
            <button type="submit">再チェック</button>
            <span class="nxmt-muted" role="status" data-security-status></span>
        </div>
    </form>
    <div data-security-results>
        <?php require dirname(__DIR__) . '/partials/security-results.php'; ?>
    </div>
</section>
