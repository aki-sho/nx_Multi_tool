<?php
namespace NxMultiTool;

final class ResponseClassifier
{
    public static function classify(array $response, bool $candidate = false): array
    {
        $status = (int)$response['status']; $mime = strtolower($response['headers']['content-type'] ?? '');
        $kind = Discovery::kind($response['url'], $mime);
        $result = ['http' => $status, 'original_http' => $response['history'][0]['status'] ?? $status,
            'final_url' => $response['url'], 'mime' => $mime, 'kind' => $kind, 'issue' => false,
            'status' => '取得済み', 'access' => '判定対象外'];
        if (!empty($response['blocked'])) {
            $result['status'] = '転送先は取得対象外'; $result['access'] = '転送先の認証要否は未確認';
            $result['final_url'] = $response['blocked_to'] ?? $response['url']; $result['issue'] = true; return $result;
        }
        if ($status === 404 || $status === 410) {
            $result['status'] = $candidate ? '自動確認した候補は見つかりません' : 'ページ・ファイルなし（' . $status . '）';
            $result['issue'] = !$candidate; return $result;
        }
        if ($status === 401 || $status === 403) {
            $result['status'] = $status === 401 ? '認証が必要' : 'アクセス拒否';
            $result['access'] = $status === 401 ? 'HTTP認証が必要' : '未ログインでは取得不可'; return $result;
        }
        if ($status >= 500) { $result['status'] = 'サーバーエラー（' . $status . '）'; $result['issue'] = true; return $result; }
        if ($status >= 300 && $status < 400) { $result['status'] = '転送先は未確認'; $result['access'] = '未確認'; return $result; }
        if ($status < 200 || $status >= 300) { $result['status'] = '取得結果を確認してください'; $result['issue'] = true; return $result; }
        if (str_contains($mime, 'html') && preg_match('/<input\b[^>]*\btype\s*=\s*["\']?password\b/i', $response['body'])) {
            $result['status'] = '認証フォームを取得'; $result['access'] = '元ページの閲覧可否は未確認'; $result['issue'] = true; return $result;
        }
        if (preg_match('/\.xml$/i', (string)parse_url($response['url'], PHP_URL_PATH)) && str_contains($mime, 'html')) {
            $result['status'] = $candidate ? 'サイトマップ候補はXMLではありません' : 'XMLのURLがHTMLを返しています';
            $result['access'] = '未確認'; $result['issue'] = !$candidate; return $result;
        }
        $result['access'] = in_array($kind, ['css', 'js', 'image', 'font', 'file'], true) ? '公開ファイルとして取得可' : '未ログインで取得可';
        return $result;
    }
}
