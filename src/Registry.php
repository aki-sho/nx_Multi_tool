<?php
namespace NxMultiTool;

final class Registry
{
    public const VERSION = '1.0.1';
    public static function all(): array
    {
        return [
            'site_links' => ['サイトリンク', '制作・運用・集客に役立つサービスへのリンク集。', 'utility'],
            'qr' => ['QRコード', 'URLや文章からQRコードを生成し、PNGで保存。', 'utility'],
            'calc' => ['電卓', 'キーボードにも対応した四則演算。', 'utility'],
            'bcrypt' => ['bcrypt', 'パスワードのハッシュ生成・一致確認。', 'utility'],
            'password' => ['パスワード生成', '長さ・文字種・個数を指定して生成。', 'utility'],
            'timer' => ['タイマー', 'カウントダウン・ストップウォッチ・アラーム。', 'utility'],
            'link_inspector' => ['リンク点検', '記事内の指定URL・ドメインの誤記候補を検出。', 'management'],
            'access_log' => ['アクセスログ', '訪問履歴・検索・CSV・期限付きIP拒否。', 'management'],
            'security_check' => ['セキュリティチェック', '設定・環境・保存ログから10項目の確認結果を表示。', 'management'],
            'post_data' => ['投稿データ', '投稿・固定ページの情報を一覧・並び替え・CSV出力。', 'content'],
            'site_urls' => ['サイト内URL一覧', '公開サイトのURLを収集し、記事以外の参照先を確認。', 'management'],
            'search_replace' => ['記事検索・置換', '編集画面で未保存の本文を検索・置換。', 'content'],
            'comment_manager' => ['コメント管理', '承認制コメント・スパム管理・IP拒否。', 'content'],
        ];
    }
    public static function url(string $tool = ''): string
    {
        return \url('admin/extensions/nx_Multi_tool/dashboard') . ($tool !== '' ? '?tool=' . rawurlencode($tool) : '');
    }
    public static function endpoint(string $operation): string { return \url('admin/extensions/nx_Multi_tool/' . $operation); }
    public static function asset(string $name): string { return \url('nx-multi-tool/assets/' . rawurlencode($name)) . '?v=' . self::VERSION; }
}
