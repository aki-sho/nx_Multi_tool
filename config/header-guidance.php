<?php

return [
    'hsts' => [
        'importance' => 'high',
        'impact' => '証明書の期限切れやHTTPSの障害が起きると、同じホストのページを閲覧できなくなることがあります。ブラウザーは解除後も、最後に受信した設定を最長5分間保持します。',
    ],
    'csp' => [
        'importance' => 'high',
        'impact' => '制限を適用すると、許可していない画像・CSS・JavaScriptや、動画・地図の埋め込みが読み込めなくなることがあります。ページ内に直接書いたスクリプトも制限され、アクセス解析やフォームが動かなくなる場合があります。',
    ],
    'frame' => [
        'importance' => 'high',
        'impact' => 'SAMEORIGINでは別オリジンから、DENYでは同じオリジンからも、このページをiframeに表示できなくなります。外部ポータルへの掲載や埋め込みプレビューに影響することがあります。',
    ],
    'nosniff' => [
        'importance' => 'medium',
        'impact' => 'CSSやJavaScriptの応答にも設定する場合、Content-Typeが誤っているファイルは読み込まれず、ページの表示や操作が崩れることがあります。',
    ],
    'referrer' => [
        'importance' => 'medium',
        'impact' => '参照元URLを使う広告・送客計測では、どのページから来たか分からなくなることがあります。strict-originは同一オリジン内でも参照元をオリジンまでに絞り、no-referrerは参照元情報の送信を止めます。',
    ],
    'permissions' => [
        'importance' => 'medium',
        'impact' => '制限した機能は公開ページやその埋め込み内で使えなくなります。カメラでのQRコード読み取り、マイクでの音声入力、地図での現在地表示などに影響します。',
    ],
    'cookie' => [
        'importance' => 'high',
        'impact' => 'Secureを有効にするとHTTPではCookieが送られず、HttpOnlyを有効にするとJavaScriptから読めなくなります。SameSiteを厳しくすると、外部ログインや決済から戻った際にセッションを引き継げない場合があります。',
        'where' => 'セッションCookieの属性は、NexaPress本体の app/Core/Auth.php のセッション開始処理と、PHPのセッション設定で確認できます。',
    ],
    'cache' => [
        'importance' => 'medium',
        'impact' => 'no-storeで応答の保存と再利用を止めると、ページを開き直すたびに配信が必要になり、表示が遅くなったりサーバー負荷が増えたりすることがあります。',
        'where' => '対象ページのコントローラー、または配信サーバーのキャッシュ設定を確認します。ログイン中や個人情報を含む応答は、公開キャッシュの対象から外します。',
    ],
    'cors' => [
        'importance' => 'medium',
        'impact' => '許可するオリジンを絞ると、外部サイトからAPIの応答を読めなくなり、連携しているウィジェットや別ドメインのフロントエンドが動かなくなる場合があります。',
        'where' => '外部からの読み取りを許可するAPI単位で設定します。対象の処理や配信サーバーで、必要なオリジンを指定してください。',
    ],
    'server' => [
        'importance' => 'low',
        'impact' => '製品名やバージョンの送信を止めると、その情報を使っている外部監視や障害調査でサーバーの種類を特定しづらくなります。',
        'where' => 'ServerはWebサーバーやCDN、X-Powered-ByはPHPの expose_php など、配信環境の設定で確認できます。',
    ],
    'Cross-Origin-Embedder-Policy' => [
        'importance' => 'low',
        'impact' => 'require-corpを適用すると、読み込みを許可していない外部の画像・スクリプト・埋め込みが表示されない場合があります。credentiallessでは一部の外部リソースにCookieが届かず、認証が必要な素材を取得できなくなることがあります。',
        'where' => '外部の画像やスクリプトの読み込みを確認したうえで、対象ページの応答に設定します。',
    ],
    'Cross-Origin-Opener-Policy' => [
        'importance' => 'low',
        'impact' => 'same-originを適用すると、別オリジンのポップアップと元のページの連携が切れ、外部ログインや決済の完了を元のページに通知できなくなる場合があります。',
        'where' => 'ログインや決済などのポップアップの連携を確認したうえで、対象ページの応答に設定します。',
    ],
    'Cross-Origin-Resource-Policy' => [
        'importance' => 'low',
        'impact' => 'same-originを適用すると、別オリジンからCORSを使わずに行う読み込みが制限されます。ほかのサイトに掲載している画像や、別ドメインから利用する配信素材が表示されなくなることがあります。',
        'where' => '埋め込み元を制限する場合は、画像やファイルなど、対象のリソース自体の応答に設定します。',
    ],
];
