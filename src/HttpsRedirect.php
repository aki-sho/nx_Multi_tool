<?php
namespace NxMultiTool;

final class HttpsRedirect
{
    private const BACKUP = 'https-redirect.htaccess.backup';
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    public static function inspect(?string $site, bool $requested, array $public, ?array $change = null): array
    {
        $result = $change['result'] ?? ($requested
            ? self::observe($site, $public)
            : self::unconfirmed('「公開応答」を選択して再チェックすると、HTTPからの転送を確認できます。'));
        $result['notice'] = $change['notice'] ?? null;
        $result['file'] = null;
        $result['reason'] = null;
        if ($result['enabled'] !== false) { return $result; }
        try {
            // The public-response result and the ability to edit this installation are independent.
            $target = HttpsRedirectConfig::prepare($site ?? '');
            if (!$public['collected'] || parse_url($public['url'], PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('HTTPSで公開トップが正常に開くことを確認できません。');
            }
            $result['file'] = $target['relative'];
        } catch (\RuntimeException $exception) {
            $result['reason'] = $exception->getMessage();
        }
        return $result;
    }

    private static function unconfirmed(string $detail): array
    {
        return ['enabled' => null, 'detail' => $detail, 'redirect_code' => null, 'target' => null];
    }

    private static function addresses(?string $site): array
    {
        $parts = parse_url(HttpClient::base($site ?? ''));
        if (isset($parts['port']) && $parts['port'] !== ($parts['scheme'] === 'https' ? 443 : 80)) {
            throw new \InvalidArgumentException('HTTPS強制転送の確認は、HTTPとHTTPSの標準ポートに対応しています。');
        }
        $address = $parts['host'] . ($parts['path'] ?? '') . '/';
        return ['http://' . $address, 'https://' . $address];
    }

    private static function normalPage(array $response): bool
    {
        $mime = strtolower(trim(explode(';', $response['headers']['content-type'] ?? '')[0]));
        return $response['status'] === 200 && in_array($mime, ['text/html', 'application/xhtml+xml'], true)
            && !ResponseClassifier::classify($response)['issue'];
    }

    private static function observe(?string $site, array $knownHttps = [], bool $captureResponse = false): array
    {
        try {
            [$http, $https] = self::addresses($site);
        } catch (\InvalidArgumentException $exception) {
            return self::unconfirmed($exception->getMessage());
        }
        $deadline = microtime(true) + 6;
        try {
            $response = HttpClient::fetch($http, $http, uncached: true, deadline: $deadline);
        } catch (\RuntimeException $exception) {
            return self::unconfirmed('公開トップのHTTP応答を取得できませんでした。' . $exception->getMessage());
        }
        $status = $response['status'];
        $original = $response['history'][0]['status'];
        $detail = 'HTTP ' . $original . ($status === $original ? '' : ' → HTTP ' . $status);
        if (self::normalPage($response)) {
            return ['enabled' => false, 'detail' => $detail . '。公開トップがHTTPのまま表示されます。',
                'redirect_code' => null, 'target' => null];
        }
        if (!in_array($status, self::REDIRECT_CODES, true)) {
            return self::unconfirmed($detail . '。転送の有無を判定できる応答を確認できませんでした。');
        }
        // The current HTTP scope stops at the scheme change. Follow only a validated HTTPS
        // URL at the same host and within the CMS's public installation path.
        $location = $response['headers']['location'] ?? '';
        $target = preg_match('~^https://~i', $location) ? HttpClient::resolve($location, $https, $https) : null;
        if ($target === null || str_contains($target, '?') || HttpClient::protectedUrl($target, $https)) {
            return self::unconfirmed($detail . '。対象サイトの公開HTTPSへの転送先を確認できませんでした。');
        }
        $detail .= ' → ' . $target;
        if (!empty($knownHttps['collected']) && $knownHttps['url'] === $target) {
            $finalStatus = $knownHttps['http'];
        } else {
            try {
                $final = HttpClient::fetch($target, $https, includeHeaderValues: $captureResponse, uncached: true, deadline: $deadline);
            } catch (\RuntimeException $exception) {
                return self::unconfirmed($detail . '。転送先のHTTPS応答を取得できませんでした。');
            }
            $finalStatus = $final['status'];
            if (!self::normalPage($final)) {
                return self::unconfirmed($detail . '（転送後 HTTP ' . $finalStatus . '）。正常な公開ページを確認できませんでした。');
            }
        }
        $result = ['enabled' => true, 'detail' => $detail . '（転送後 HTTP ' . $finalStatus . '）',
            'redirect_code' => $status, 'target' => $target];
        if ($captureResponse) { $result['response'] = $final ?? null; }
        return $result;
    }

    public static function apply(?string $site): array
    {
        [, $https] = self::addresses($site);
        return Storage::transaction('https-redirect', static function () use ($site, $https): array {
            $target = HttpsRedirectConfig::prepare($site ?? '');
            $before = self::observe($site);
            if ($before['enabled'] !== false) {
                throw new \RuntimeException('設定前のHTTP応答を確認してください。' . $before['detail']);
            }
            $secure = HttpClient::fetch($https, $https, includeHeaderValues: true, uncached: true, deadline: microtime(true) + 6);
            if (!self::normalPage($secure)) {
                throw new \RuntimeException('HTTPSで公開トップが正常に開くことを確認できません。');
            }
            $original = $target['contents'];
            $trial = HttpsRedirectConfig::withRule($target, 302);
            $permanent = HttpsRedirectConfig::withRule($target, 301);
            self::backup($original);

            $restore = static fn() => HttpsRedirectConfig::replace($target, [$original, $trial, $permanent], $original);
            $pending = true;
            register_shutdown_function(static function () use (&$pending, $restore): void {
                if ($pending) {
                    try { $restore(); } catch (\Throwable $exception) {
                        error_log('[NX Multi Tool] HTTPS強制転送の復元に失敗しました。保存済みのバックアップを確認してください。');
                    }
                }
            });
            $oldAbort = ignore_user_abort(true);
            $publicResponse = $secure;
            try {
                HttpsRedirectConfig::replace($target, [$original], $trial);
                self::verify($site, $https, 302);
                HttpsRedirectConfig::replace($target, [$trial], $permanent);
                $result = self::verify($site, $https, 301, true);
                $publicResponse = $result['response'];
                unset($result['response']);
                $notice = ['error' => false, 'message' => '設定を適用し、公開トップのHTTPS強制転送を確認しました。'];
            } catch (\Throwable $exception) {
                try {
                    $restore();
                    $result = $before;
                    $notice = ['error' => true, 'message' => $exception->getMessage() . ' 適用前の .htaccess に戻しました。'];
                } catch (\Throwable $restoreException) {
                    $result = self::unconfirmed('現在の転送状態は「再チェック」で確認してください。');
                    $publicResponse = null;
                    $notice = ['error' => true, 'message' => '自動復元に失敗しました。'
                        . ' storage/extensions/nx_Multi_tool/' . self::BACKUP . ' に保存した内容で '
                        . $target['relative'] . ' を復元してください。'];
                }
            } finally {
                $pending = false;
                ignore_user_abort((bool)$oldAbort);
            }
            return [null, compact('result', 'notice', 'publicResponse')];
        });
    }

    private static function backup(string $contents): void
    {
        $directory = Storage::directory();
        $backup = $directory . '/' . self::BACKUP;
        $temporary = @tempnam($directory, '.nxmt-');
        try {
            if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)
                || @file_put_contents($temporary, $contents) !== strlen($contents)
                || is_link($backup) || !@rename($temporary, $backup)) {
                throw new \RuntimeException('適用前の .htaccess をバックアップできません。');
            }
        } finally {
            if (is_string($temporary) && is_file($temporary)) { @unlink($temporary); }
        }
    }

    private static function verify(?string $site, string $https, int $status, bool $captureResponse = false): array
    {
        $result = self::observe($site, [], $captureResponse);
        if ($result['enabled'] !== true || $result['redirect_code'] !== $status || $result['target'] !== $https) {
            throw new \RuntimeException('設定後にHTTP ' . $status . 'でのHTTPS転送を確認できませんでした。' . $result['detail']);
        }
        return $result;
    }
}
