<?php
use NxMultiTool\AdminController;
use NxMultiTool\Monitor;
use NxMultiTool\Registry;

$list = Monitor::listQuery($_GET);
$list['page'] = (string)$page;
$list['per_page'] = (string)$data['per_page'];
$list['ip_page'] = (string)$data['ip_summary']['page'];
$exportQuery = array_intersect_key($list, array_flip(['q', 'ip', 'from', 'to']));
$exportQuery['tool'] = 'access_log';
$exportQuery['_csrf_token'] = \app\Core\Csrf::token();
$filterQuery = ($list['q'] ?? '') !== '' ? $list['q'] : ($list['ip'] ?? '');
$allowList = array_flip($settings['allow_ips']);
$activeBlocks = array_diff_key($data['blocks'], $allowList);
$searchLabel = '検索（URL・参照元・UA・IP）';
$formAction = Registry::endpoint('action');
$returnFields = static function () use ($list): void {
    foreach ($list as $key => $value): ?>
        <input type="hidden" name="list[<?= e($key) ?>]" value="<?= e($value) ?>">
    <?php endforeach;
};
$blockStatus = static function (?array $block): void { ?>
    <span class="nxmt-log-badge<?= $block ? ' is-blocked' : '' ?>"><?= $block ? '拒否中' : '未拒否' ?></span>
<?php };
?>
<section data-access-log aria-label="アクセスログ">
    <div class="nxmt-log-toolbar">
        <form id="nxmt-log-bulk" method="post" action="<?= e($formAction) ?>">
            <?php AdminController::form('access_log', 'delete_selected'); $returnFields(); ?>
            <button type="submit" class="secondary" data-log-delete data-confirm="選択したログを削除しますか？">選択ログを削除</button>
        </form>
        <form class="nxmt-log-filter" method="get" action="<?= e(Registry::url()) ?>">
            <input type="hidden" name="tool" value="access_log">
            <input class="nxmt-log-search" type="search" name="q" value="<?= e($filterQuery) ?>"
                placeholder="<?= e($searchLabel) ?>" aria-label="<?= e($searchLabel) ?>" spellcheck="false">
            <label><span>開始日</span><input type="date" name="from" value="<?= e($list['from'] ?? '') ?>"></label>
            <label><span>終了日</span><input type="date" name="to" value="<?= e($list['to'] ?? '') ?>"></label>
            <label><span>表示件数</span><input type="number" name="per_page" min="1" max="<?= Monitor::MAX_PER_PAGE ?>"
                value="<?= (int)$data['per_page'] ?>" required></label>
            <button type="submit">適用</button>
        </form>
    </div>
    <?php if (isset($data['filter_error'])): ?><p class="nxmt-notice is-error" role="alert"><?= e($data['filter_error']) ?></p><?php endif; ?>
    <p class="nxmt-muted nxmt-log-note">日時はCMSのタイムゾーンです。管理画面・ログイン中のアクセス・静的ファイルは記録しません。URLと参照元のクエリ・認証情報は保存しません。キャッシュから直接返されたアクセスは記録されないことがあります。</p>
    <div class="nxmt-table-wrap">
        <table class="nxmt-log-table" data-log-table>
            <thead><tr>
                <th scope="col">選択</th>
                <th scope="col">日時</th>
                <th scope="col">HTTP</th>
                <th scope="col">IP</th>
                <th scope="col">拒否状態</th>
                <th scope="col">URL</th>
                <th scope="col">参照元</th>
                <th scope="col">UA</th>
                <th scope="col">メモ</th>
            </tr></thead>
            <tbody>
                <?php foreach ($data['rows'] as $row):
                    $ip = $row['ip'];
                    $block = $activeBlocks[$ip] ?? null;
                    $memo = $data['memos'][$ip] ?? '';
                ?>
                    <tr>
                        <td><input form="nxmt-log-bulk" type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>" aria-label="ログ<?= (int)$row['id'] ?>"></td>
                        <td class="nxmt-log-nowrap"><?= e(date('Y-m-d H:i:s', $row['created_at'])) ?></td>
                        <td class="nxmt-log-nowrap"><?= e($row['method']) ?> <?= (int)$row['status'] ?></td>
                        <td class="nxmt-log-nowrap"><?= e($ip ?: '—') ?></td>
                        <td class="nxmt-log-block">
                            <?php if ($ip !== ''): ?>
                                <?php $blockStatus($block); ?>
                                <?php if ($block): ?><small><?= e(date('Y-m-d H:i', $block['until'])) ?>まで</small><?php endif; ?>
                                <?php if (!isset($allowList[$ip])): ?>
                                    <form method="post" action="<?= e($formAction) ?>">
                                        <?php AdminController::form('access_log', $block ? 'unblock' : 'block'); $returnFields(); ?>
                                        <input type="hidden" name="ip" value="<?= e($ip) ?>">
                                        <button type="submit" class="secondary" data-confirm="このIPの拒否状態を変更しますか？"><?= $block ? '拒否解除' : '期限付き拒否' ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($settings['tools']['comment_manager'])): ?>
                                    <a href="<?= e(Registry::url('comment_manager') . '&q=' . rawurlencode($ip)) ?>">同じIPのコメント</a>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="nxmt-log-wrap"><?= e($row['url'] ?: '—') ?></td>
                        <td class="nxmt-log-wrap"><?= e($row['referrer'] ?: '—') ?></td>
                        <td class="nxmt-log-wrap"><?= e($row['ua'] ?: '—') ?></td>
                        <td>
                            <?php if ($ip !== ''): ?>
                                <form class="nxmt-log-memo" method="post" action="<?= e($formAction) ?>" data-memo-ip="<?= e($ip) ?>">
                                    <?php AdminController::form('access_log', 'save_ip_memo'); $returnFields(); ?>
                                    <input type="hidden" name="ip" value="<?= e($ip) ?>">
                                    <input type="text" name="memo" value="<?= e($memo) ?>" maxlength="<?= Monitor::MEMO_LIMIT ?>" aria-label="<?= e($ip . ' のメモ') ?>">
                                    <button type="submit" class="secondary">保存</button>
                                    <span class="nxmt-muted" role="status" data-memo-status hidden></span>
                                </form>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$data['rows']): ?><tr><td colspan="9">該当するログはありません。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="nxmt-log-footer">
        <?php AdminController::pagination('access_log', $page, $data['total'], $data['per_page'], 'page', 'アクセスログのページ送り'); ?>
        <a class="button secondary" href="<?= e(Registry::endpoint('export') . '?' . http_build_query($exportQuery)) ?>">CSV出力</a>
    </div>

    <section class="nxmt-log-ip-summary" aria-labelledby="nxmt-log-ip-title">
        <h2 id="nxmt-log-ip-title">IP一覧</h2>
        <p class="nxmt-muted nxmt-log-note">直近30日間の保存ログをIPごとに集計しています。上のアクセスログでメモを保存すると、ここにも反映されます。</p>
        <div class="nxmt-table-wrap">
            <table class="nxmt-log-ip-table" data-log-ip-table>
                <thead><tr>
                    <th scope="col">IP</th>
                    <th scope="col">回数（30日）</th>
                    <th scope="col">拒否状態</th>
                    <th scope="col">日時</th>
                    <th scope="col">メモ</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($data['ip_summary']['dates'] as $ip => $dates):
                        $times = array_map(static fn(int $time): string => '・' . date('Y-m-d H:i:s', $time), $dates);
                    ?>
                        <tr>
                            <td class="nxmt-log-nowrap"><?= e($ip) ?></td>
                            <td class="nxmt-log-nowrap"><?= count($dates) ?>回</td>
                            <td><?php $blockStatus($activeBlocks[$ip] ?? null); ?></td>
                            <td><textarea class="nxmt-log-times" rows="3" wrap="off" readonly aria-label="<?= e($ip . ' のアクセス日時') ?>"><?= e(implode("\n", $times)) ?></textarea></td>
                            <td class="nxmt-log-wrap" data-ip-memo="<?= e($ip) ?>"><?= e($data['memos'][$ip] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$data['ip_summary']['dates']): ?><tr><td colspan="5">直近30日間のログはありません。</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($data['ip_summary']['total'] > Monitor::DEFAULT_PER_PAGE): ?>
            <?php AdminController::pagination('access_log', $data['ip_summary']['page'], $data['ip_summary']['total'],
                Monitor::DEFAULT_PER_PAGE, 'ip_page', 'IP一覧のページ送り'); ?>
        <?php endif; ?>
    </section>
</section>

<section class="nxmt-panel">
    <h2>ログの整理</h2>
    <div class="nxmt-fields">
        <form method="post" action="<?= e($formAction) ?>">
            <?php AdminController::form('access_log', 'purge'); $returnFields(); ?>
            <label>指定日数より古いログを削除<input type="number" name="days" value="30" min="1" max="365"></label>
            <button class="secondary" data-confirm="古いログを削除しますか？">削除</button>
        </form>
        <form method="post" action="<?= e($formAction) ?>">
            <?php AdminController::form('access_log', 'truncate'); $returnFields(); ?>
            <label>全ログ削除の確認（DELETEと入力）<input name="confirm" required pattern="DELETE" autocomplete="off"></label>
            <button class="danger" data-confirm="全ログを削除します。取り消せません。">全ログを削除</button>
        </form>
    </div>
    <h3>現在の拒否リスト</h3>
    <?php foreach ($data['blocks'] as $ip => $block): ?>
        <form class="nxmt-inline" method="post" action="<?= e($formAction) ?>">
            <?php AdminController::form('access_log', 'unblock'); $returnFields(); ?>
            <input type="hidden" name="ip" value="<?= e($ip) ?>">
            <span><?= e($ip) ?> · <?= e(date('Y-m-d H:i', $block['until'])) ?>まで</span>
            <button class="secondary">拒否解除</button>
        </form>
    <?php endforeach; ?>
</section>
