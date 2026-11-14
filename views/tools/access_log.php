<?php
use NxMultiTool\AdminController;
use NxMultiTool\Monitor;
use NxMultiTool\Registry;

$list = Monitor::listQuery($_GET);
$list['page'] = (string)$page;
$list['per_page'] = (string)$data['per_page'];
$list['ip_page'] = (string)$data['ip_summary']['page'];
$list['ip_per_page'] = (string)$data['ip_summary']['per_page'];
$exportQuery = array_intersect_key($list, array_flip(['q', 'ip', 'from', 'to', 'sort', 'dir']));
$exportQuery['tool'] = 'access_log';
$exportQuery['_csrf_token'] = \app\Core\Csrf::token();
$filterQuery = ($list['q'] ?? '') !== '' ? $list['q'] : ($list['ip'] ?? '');
$activeBlocks = $data['active_blocks'];
$canOpenComments = !empty($settings['tools']['comment_manager']);
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
$keepFields = static function (array $names) use ($list): void {
    foreach (array_intersect_key($list, array_flip($names)) as $key => $value): ?>
        <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
    <?php endforeach;
};
$logSort = Monitor::sort($list);
$ipSort = Monitor::sort($list, true);
$sortHeading = static function (string $column, string $label, bool $summary = false) use ($list, $logSort, $ipSort): void {
    $current = $summary ? $ipSort : $logSort;
    $active = $current['column'] === $column;
    $direction = $active && $current['direction'] === 'asc' ? 'desc' : 'asc';
    $query = array_replace($list, [
        ($summary ? 'ip_sort' : 'sort') => $column,
        ($summary ? 'ip_dir' : 'dir') => $direction,
        ($summary ? 'ip_page' : 'page') => 1,
    ]);
    $href = Registry::url('access_log') . '&' . http_build_query($query) . ($summary ? '#nxmt-log-ip-title' : '');
    $indicator = $active ? ($current['direction'] === 'asc' ? '↑' : '↓') : '↕';
    ?>
    <th scope="col"<?= $active ? ' aria-sort="' . ($current['direction'] === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>>
        <a href="<?= e($href) ?>" class="nxmt-log-sort" aria-label="<?= e($label . ($direction === 'asc' ? 'を昇順に並べ替え' : 'を降順に並べ替え')) ?>">
            <?= e($label) ?> <span aria-hidden="true"><?= $indicator ?></span>
        </a>
    </th>
    <?php
};
?>
<section data-access-log aria-label="アクセスログ">
    <div class="nxmt-log-toolbar">
        <form id="nxmt-log-bulk" method="post" action="<?= e($formAction) ?>">
            <?php AdminController::form('access_log', ''); $returnFields(); ?>
            <button type="submit" name="op" value="delete_selected" class="secondary" data-log-delete data-log-action
                data-confirm="選択したログを削除しますか？">選択ログを削除</button>
            <button type="submit" name="op" value="block_selected" class="secondary" data-log-block-selected data-log-action
                data-confirm="選択したログのIPを拒否しますか？ 拒否リストで解除できます。">選択IPを拒否</button>
        </form>
        <form class="nxmt-log-filter" method="get" action="<?= e(Registry::url()) ?>">
            <input type="hidden" name="tool" value="access_log">
            <?php $keepFields(['sort', 'dir', 'ip_q', 'ip_per_page', 'ip_page', 'ip_sort', 'ip_dir']); ?>
            <input class="nxmt-log-search" type="search" name="q" value="<?= e($filterQuery) ?>"
                placeholder="<?= e($searchLabel) ?>" aria-label="<?= e($searchLabel) ?>" spellcheck="false">
            <label><span>開始日</span><input type="date" name="from" value="<?= e($list['from'] ?? '') ?>"></label>
            <label><span>終了日</span><input type="date" name="to" value="<?= e($list['to'] ?? '') ?>"></label>
            <label><span>表示件数</span><input type="number" name="per_page" min="1" max="<?= Monitor::MAX_PER_PAGE ?>"
                value="<?= (int)$data['per_page'] ?>" required></label>
            <button type="submit">検索</button>
        </form>
    </div>
    <?php if (isset($data['filter_error'])): ?><p class="nxmt-notice is-error" role="alert"><?= e($data['filter_error']) ?></p><?php endif; ?>
    <p class="nxmt-muted nxmt-log-note">日時はCMSのタイムゾーンです。管理画面・ログイン中のアクセス・静的ファイルは記録しません。URLと参照元のクエリ・認証情報は保存しません。キャッシュから直接返されたアクセスは記録されないことがあります。</p>
    <div class="nxmt-table-wrap">
        <table class="nxmt-log-table" data-log-table>
            <thead><tr>
                <th scope="col">
                    <button type="button" class="nxmt-log-sort" data-sort-selection aria-label="チェックしたログを上に並べ替え">
                        選択 <span aria-hidden="true">↕</span>
                    </button>
                </th>
                <?php foreach (['date' => '日時', 'http' => 'HTTP', 'ip' => 'IP', 'blocked' => '拒否状態',
                    'url' => 'URL', 'referrer' => '参照元', 'ua' => 'UA', 'comments' => 'コメント', 'memo' => 'メモ'] as $column => $label) {
                    $sortHeading($column, $label);
                } ?>
            </tr></thead>
            <tbody>
                <?php foreach ($data['rows'] as $row):
                    $ip = $row['ip'];
                    $block = $activeBlocks[$ip] ?? null;
                    $memo = $data['memos'][$ip] ?? '';
                    $comment = $data['comments'][$ip] ?? null;
                    $commentCount = (int)($comment['comment_count'] ?? 0);
                ?>
                    <tr>
                        <td><input form="nxmt-log-bulk" type="checkbox" name="ids[]" value="<?= (int)$row['id'] ?>" aria-label="ログ<?= (int)$row['id'] ?>"></td>
                        <td class="nxmt-log-nowrap"><?= e(date('Y-m-d H:i:s', $row['created_at'])) ?></td>
                        <td class="nxmt-log-nowrap"><?= e($row['method']) ?> <?= (int)$row['status'] ?></td>
                        <td class="nxmt-log-nowrap"><?= e($ip ?: '—') ?></td>
                        <td>
                            <?php if ($ip !== ''): ?>
                                <?php $blockStatus($block); ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="nxmt-log-wrap"><?= e($row['url'] ?: '—') ?></td>
                        <td class="nxmt-log-wrap"><?= e($row['referrer'] ?: '—') ?></td>
                        <td class="nxmt-log-wrap"><?= e($row['ua'] ?: '—') ?></td>
                        <td class="nxmt-log-nowrap">
                            <?php if ($commentCount > 0 && $canOpenComments): ?>
                                <a href="<?= e(Registry::url('comment_manager') . '&ip=' . rawurlencode($ip) . '#nxmt-comment-' . (int)$comment['latest_id']) ?>"
                                    aria-label="<?= e($ip . ' の最新コメントを表示（' . $commentCount . '件）') ?>"><?= $commentCount ?>件</a>
                            <?php else: ?>
                                <?= $commentCount ?>件
                            <?php endif; ?>
                        </td>
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
                <?php if (!$data['rows']): ?><tr><td colspan="10">該当するログはありません。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="nxmt-log-footer">
        <?php AdminController::pagination('access_log', $page, $data['total'], $data['per_page'], 'page', 'アクセスログのページ送り'); ?>
        <a class="button secondary" href="<?= e(Registry::endpoint('export') . '?' . http_build_query($exportQuery)) ?>">CSV出力</a>
    </div>

    <section class="nxmt-log-ip-summary" aria-labelledby="nxmt-log-ip-title">
        <div class="nxmt-log-ip-heading">
            <h2 id="nxmt-log-ip-title">IP一覧</h2>
            <form class="nxmt-log-filter nxmt-log-ip-filter" method="get" action="<?= e(Registry::url() . '#nxmt-log-ip-title') ?>">
                <input type="hidden" name="tool" value="access_log">
                <?php $keepFields(['q', 'ip', 'from', 'to', 'per_page', 'page', 'sort', 'dir', 'ip_sort', 'ip_dir']); ?>
                <input type="search" name="ip_q" class="nxmt-log-search" value="<?= e($list['ip_q'] ?? '') ?>"
                    placeholder="検索（IP・メモ）" aria-label="IP一覧を検索（IP・メモ）" spellcheck="false">
                <label><span>表示件数</span><input type="number" name="ip_per_page" min="1" max="<?= Monitor::MAX_PER_PAGE ?>"
                    value="<?= (int)$data['ip_summary']['per_page'] ?>" required></label>
                <button type="submit">検索</button>
            </form>
        </div>
        <p class="nxmt-muted nxmt-log-note">直近30日間の保存ログをIPごとに集計しています。上のアクセスログでメモを保存すると、ここにも反映されます。</p>
        <div class="nxmt-table-wrap">
            <table class="nxmt-log-ip-table" data-log-ip-table>
                <thead><tr>
                    <?php foreach (['ip' => 'IP', 'count' => '回数（30日）', 'blocked' => '拒否状態', 'date' => '日時', 'memo' => 'メモ'] as $column => $label) {
                        $sortHeading($column, $label, true);
                    } ?>
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
                    <?php if (!$data['ip_summary']['dates']): ?><tr><td colspan="5">該当するIPはありません。</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($data['ip_summary']['total'] > $data['ip_summary']['per_page']): ?>
            <?php AdminController::pagination('access_log', $data['ip_summary']['page'], $data['ip_summary']['total'],
                $data['ip_summary']['per_page'], 'ip_page', 'IP一覧のページ送り', '#nxmt-log-ip-title'); ?>
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
    <?php foreach (array_keys($data['blocks']) as $ip): ?>
        <form class="nxmt-inline" method="post" action="<?= e($formAction) ?>">
            <?php AdminController::form('access_log', 'unblock'); $returnFields(); ?>
            <input type="hidden" name="ip" value="<?= e($ip) ?>">
            <span><?= e($ip) ?></span>
            <button class="secondary">拒否解除</button>
        </form>
    <?php endforeach; ?>
</section>
