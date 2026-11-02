<?php
namespace NxMultiTool;

final class Security
{
    public static function inspect(): array
    {
        $facts = ['PHP' => PHP_VERSION, 'NexaPress' => (require BASE_PATH . '/config/version.php')['version'] ?? '不明',
            'HTTPS' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '使用中' : '未確認／HTTP',
            'ログ機能' => Settings::enabled('access_log') ? '有効' : '無効', 'アップロード受信' => ini_get('file_uploads') ? '有効' : '無効'];
        $names = ['ブルートフォース', 'クレデンシャルスタッフィング', 'SQLインジェクション', 'XSS', 'DDoS', 'CSRF', 'RCE', 'ファイルアップロード', '脆弱性悪用', 'ディレクトリトラバーサル'];
        $reasons = ['ログイン試行の成功・失敗と制限設定を横断確認するAPIがありません。', 'パスワードの使い回し・漏えい・全ユーザーの追加認証を確認していません。',
            '全拡張・テーマのSQL組み立てと入力経路は監査していません。', '全拡張・テーマの出力処理は監査していません。', 'CDN・WAF・回線負荷と、保存されないアクセスは確認できません。',
            'CMSにはCSRF APIがあります。各拡張・テーマが全操作で使用しているかは未確認です。', 'PHP ' . PHP_VERSION . '。サーバーと拡張全体の実行経路・サポート状況は未確認です。',
            '独自の保存経路・Webサーバーの実行禁止設定は未確認です。', '本体・拡張・テーマのCVE照合やオンラインの最新版確認は行っていません。', 'ファイル参照処理全体と不正な読み取りの成立は未確認です。'];
        $observations = 'アクセスログは無効です。';
        if (Settings::enabled('access_log')) {
            $rows = Database::query('SELECT url,status FROM ' . Database::table('logs') . ' WHERE created_at >= ? ORDER BY id DESC LIMIT 2000', [time() - 86400])->fetchAll();
            $errors = count(array_filter($rows, static fn($row) => (int)$row['status'] >= 500));
            $observations = '直近24時間の保存ログから最大2000件中' . count($rows) . '件を参照。HTTP 5xx: ' . $errors . '件。記録上限・キャッシュの影響を受けます。';
        }
        $items = [];
        foreach ($names as $index => $name) { $items[] = ['name' => $name, 'level' => 'unknown', 'label' => '未確認', 'reason' => $reasons[$index]]; }
        if (!ini_get('file_uploads')) { $items[7]['level'] = 'low'; $items[7]['label'] = '低（PHP標準受信のみ）'; $items[7]['reason'] = 'PHP標準のアップロード受信は無効です。URL取込みなど独自の保存経路は未確認です。'; }
        $debug = BASE_PATH . '/config/debug.php';
        if (is_file($debug)) { $config = require $debug; $facts['デバッグ出力'] = !empty($config['enabled']) ? '有効（公開環境では設定を確認）' : '無効'; }
        return ['items' => $items, 'facts' => $facts, 'observations' => $observations, 'checked_at' => time()];
    }
}
