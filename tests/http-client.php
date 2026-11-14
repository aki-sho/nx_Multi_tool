<?php
// Namespace-local test doubles: no real HTTP or DNS calls occur in these cases.
namespace NxMultiTool {
    function gethostbynamel(string $host) { return $host === 'private.test' ? ['127.0.0.1'] : ['8.8.8.8']; }
    function curl_init(string $url) { return (object)['url'=>$url,'options'=>[],'status'=>0]; }
    function curl_setopt_array($handle,array $options) { $handle->options=$options; return true; }
    function curl_setopt($handle,$option,$value) { $handle->options[$option]=$value; return true; }
    function curl_exec($handle) {
        $GLOBALS['nxmt_http_calls'][]=$handle;
        $responses=&$GLOBALS['nxmt_http_responses'];
        if (!$responses) { throw new \RuntimeException('Unexpected HTTP request'); }
        $response=array_shift($responses); $handle->status=$response['status'];
        $header=$handle->options[CURLOPT_HEADERFUNCTION];$header($handle,'HTTP/1.1 '.$response['status']." Test\r\n");
        foreach ($response['headers'] ?? [] as $key => $values) {
            foreach ((array)$values as $value) { $header($handle, $key . ': ' . $value . "\r\n"); }
        }
        $body=$response['body']??'';
        if(empty($handle->options[CURLOPT_NOBODY]) && $body!=='') {
            if($handle->options[CURLOPT_WRITEFUNCTION]($handle,$body)!==strlen($body))return false;
        }
        return true;
    }
    function curl_getinfo($handle,$option){return $handle->status;}
    function curl_close($handle){}
}
namespace {
    putenv('NXMT_TEST_BASE_PATH=/blog/public');
    require __DIR__.'/fixture.php';
    use NxMultiTool\HttpClient;
    use NxMultiTool\ResponseClassifier;
    use NxMultiTool\Discovery;
    use NxMultiTool\SecurityHeaders;
    use NxMultiTool\SiteInfo;
    $checks=0;
    function expect(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new \RuntimeException('FAIL '.$label);}
    function fetchCase(array $responses,bool $head=false,string $url='https://example.test/asset'):array {
        $GLOBALS['nxmt_http_responses']=$responses;$GLOBALS['nxmt_http_calls']=[];$requests=0;
        return HttpClient::fetch($url,'https://example.test',$head,0,false,$requests);
    }
    $_SERVER['HTTP_HOST'] = 'instance.example.test';
    $_SERVER['REQUEST_SCHEME'] = 'https';
    expect(SiteInfo::publicUrl() === 'https://instance.example.test/blog/', 'CMS root URL retains its installation directory');
    $_SERVER['HTTP_HOST'] = 'instance.example.test/invalid';
    expect(SiteInfo::publicUrl() === null, 'malformed host cannot become a crawl URL');
    $response=fetchCase([['status'=>200,'headers'=>['Content-Type'=>'text/css'],'body'=>'body{}']]);
    $classified=ResponseClassifier::classify($response);
    expect($classified['kind']==='css'&&$classified['access']==='公開ファイルとして取得可','public CSS is expected');
    $options=$GLOBALS['nxmt_http_calls'][0]->options;
    expect($options[CURLOPT_FOLLOWLOCATION]===false && str_contains($options[CURLOPT_RESOLVE][0],'8.8.8.8'),'redirects manual and DNS pinned');
    $response=fetchCase([['status'=>302,'headers'=>['Location'=>'/admin/login']]]);
    $classified=ResponseClassifier::classify($response);
    expect($response['blocked']&&count($GLOBALS['nxmt_http_calls'])===1,'protected login redirect never fetched');
    expect($classified['original_http']===302&&$classified['access']!=='未ログインで取得可','protected redirect not reported publicly accessible');
    $response=fetchCase([['status'=>302,'headers'=>['Location'=>'/signin']],['status'=>200,'headers'=>['Content-Type'=>'text/html'],'body'=>'<form><input type="password"></form>']]);
    $classified=ResponseClassifier::classify($response);
    expect($classified['original_http']===302&&$classified['http']===200&&$classified['final_url']==='https://example.test/signin','original status and final URL retained');
    expect($classified['status']==='認証フォームを取得'&&$classified['issue'],'login HTML 200 not accepted as content');
    $response=fetchCase([['status'=>302,'headers'=>['Location'=>'https://outside.test/login']]]);
    expect($response['blocked']&&count($GLOBALS['nxmt_http_calls'])===1,'external redirect not followed');
    $response=fetchCase([['status'=>404,'headers'=>['Content-Type'=>'text/html']]]);
    expect(!ResponseClassifier::classify($response,true)['issue'],'missing guessed sitemap is not a broken link');
    expect(ResponseClassifier::classify($response,false)['issue'],'missing actual link is actionable');
    $response=fetchCase([['status'=>200,'headers'=>['Content-Type'=>'text/html'],'body'=>'<html>not found</html>']],false,'https://example.test/sitemap.xml');
    expect(ResponseClassifier::classify($response,true)['status']==='サイトマップ候補はXMLではありません','HTML response not mistaken for valid XML');
    foreach([401,403,500] as $code){$response=fetchCase([['status'=>$code]]);expect(ResponseClassifier::classify($response)['access']!=='未ログインで取得可','non-success '.$code);}
    $response=fetchCase([['status'=>405],['status'=>206,'headers'=>['Content-Type'=>'image/png'],'body'=>'PNG']],true);
    expect($response['requests']===2&&$GLOBALS['nxmt_http_calls'][1]->options[CURLOPT_RANGE]==='0-511','bounded HEAD fallback');
    $failed=false;try{fetchCase([],false,'https://private.test/');}catch(\RuntimeException $e){$failed=true;}
    expect($failed&&count($GLOBALS['nxmt_http_calls'])===0,'private DNS blocked before HTTP');
    foreach(['/storage/logs/debug.log','/config/config.php','/public/admin/login','/%61pp/Core/Database.php','/.git/config'] as $path){expect(HttpClient::protectedUrl('https://example.test'.$path,'https://example.test'),'protected path '.$path);}
    $refs=Discovery::html('<img src="main.png" srcset="one.png 1x, two.png 2x"><video poster="poster.png"></video><style>@import "extra.css";</style>');
    $urls=array_column($refs,'url');expect(in_array('one.png',$urls)&&in_array('two.png',$urls)&&in_array('poster.png',$urls)&&in_array('extra.css',$urls),'srcset poster and inline style discovery');
    expect(Discovery::css('@import "first.css"; @import url("second.css"); a{background:url(img.png)}')===['first.css','second.css','img.png'],'all CSS import forms');
    expect(Discovery::json('{"url":"https:\/\/example.test\/image.png"}')===['https://example.test/image.png'],'escaped JSON reference');
    foreach (['JsonStorage', 'Settings', 'LoginUrl'] as $class) {
        require_once BASE_PATH . '/extensions/access-guard/includes/' . $class . '.php';
    }
    $loginSettings = ['enabled' => true, 'slug' => 'inventory-login-test'];
    \NexaPressAccessGuard\Settings::saveSection('login_url', $loginSettings);
    $response = fetchCase([['status'=>302, 'headers'=>['Location'=>'/inventory-login-test']]]);
    expect($response['blocked'] && count($GLOBALS['nxmt_http_calls']) === 1, 'redirect to the current custom login URL is not followed');
    \NexaPressAccessGuard\Settings::saveSection('login_url', array_replace($loginSettings, ['enabled'=>false]));

    $siteUrl = 'https://instance.example.test/blog/';
    $GLOBALS['nxmt_http_calls'] = [];
    $initial = SecurityHeaders::inspect($siteUrl, false);
    expect($initial['score'] === null && !$GLOBALS['nxmt_http_calls'], 'initial screen waits for a public response check');
    $headerBag = [
        'strict-transport-security' => ['max-age=31536000'],
        'content-security-policy' => ["default-src 'self'; frame-ancestors 'none'"],
        'x-content-type-options' => ['nosniff'],
        'referrer-policy' => ['unsafe-url, strict-origin-when-cross-origin'],
        'permissions-policy' => ['camera=(), microphone=(self)'],
        'set-cookie' => [session_name() . '=fixture-session-value; Path=/; Secure; HttpOnly; SameSite=Lax', 'theme=fixture-theme-value'],
        'cache-control' => ['no-store'],
    ];
    $_SERVER['HTTP_COOKIE'] = session_name() . '=fixture-admin-session';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fixture-admin-authorization';
    $GLOBALS['nxmt_http_responses'] = [[
        'status' => 200,
        'headers' => $headerBag + ['Content-Type' => 'text/html; charset=UTF-8'],
        'body' => '<html><h1>Public fixture</h1></html>',
    ]];
    $public = SecurityHeaders::inspect($siteUrl, true);
    expect($public['collected'] && $public['http'] === 200 && $public['score'] === 100, 'six effective header checks score 100');
    expect(array_column($public['rows'], 'points') === [25, 30, 20, 10, 10, 5], 'approved distribution of header points');
    expect($public['supplement']['cookie']['state'] === 'present' && str_contains($public['supplement']['cookie']['detail'], 'HttpOnly あり'),
        'session attributes retained when another Set-Cookie follows');
    expect(!str_contains(json_encode($public, JSON_THROW_ON_ERROR), 'fixture-'), 'report excludes cookie values and response body');
    expect(count($GLOBALS['nxmt_http_calls']) === 1 && $GLOBALS['nxmt_http_calls'][0]->url === $siteUrl
        && !str_contains(json_encode($GLOBALS['nxmt_http_calls'][0]->options, JSON_PARTIAL_OUTPUT_ON_ERROR), 'fixture-admin-'),
        'public header check fetches only this site and never sends credentials');
    expect($public['supplement']['cors']['state'] === 'unknown', 'ordinary public GET cannot determine origin-specific CORS rules');
    $GLOBALS['nxmt_http_responses'] = [[
        'status' => 200,
        'headers' => $headerBag + ['Content-Type' => 'text/html; charset=UTF-8'],
        'body' => '<html><h1>Public fixture</h1></html>',
    ]];
    $public = SecurityHeaders::inspect($siteUrl, true, [
        'hsts' => ['value' => 'max-age=300', 'mode' => 'enforce'],
        'nosniff' => ['value' => 'nosniff', 'mode' => 'enforce'],
        'csp' => ['value' => "default-src 'self'", 'mode' => 'report'],
    ]);
    expect(str_contains($public['observed']['hsts'], '異なります') && str_contains($public['observed']['nosniff'], '確認しました。')
        && str_contains($public['observed']['csp'], '送信を確認できません'), 'public observations distinguish matching, different, and missing settings');
    $headersOnly = SecurityHeaders::evaluate(array_intersect_key($headerBag, array_flip(['strict-transport-security', 'content-security-policy'])), true, session_name());
    expect($headersOnly['score'] === 75 && $headersOnly['rows']['frame']['points'] === 20, 'CSP frame-ancestors earns embedding points without an XFO field');
    $limited = SecurityHeaders::evaluate([
        'strict-transport-security'=>['max-age=0'], 'content-security-policy-report-only'=>["default-src 'self'"],
        'x-frame-options'=>['SAMEORIGIN'], 'x-content-type-options'=>['nosniff'], 'permissions-policy'=>['camera=*'],
    ], true, session_name());
    expect($limited['score'] === 30 && $limited['rows']['hsts']['points'] === 0 && $limited['rows']['csp']['points'] === 0,
        'disabled HSTS and report-only CSP earn no points');
    expect(SecurityHeaders::evaluate([], true, session_name())['score'] === 0, 'response with no applicable headers scores zero');
    expect(SecurityHeaders::evaluate(['strict-transport-security'=>['unrecognized']], true, session_name())['score'] === null, 'unresolved header prevents a misleading total');
    $GLOBALS['nxmt_http_responses'] = [['status' => 503, 'headers' => ['Content-Type' => 'text/html']]];
    expect(SecurityHeaders::inspect($siteUrl, true)['score'] === null, 'server error response remains unscored');
    echo "HTTP and URL-discovery checks: $checks passed\n";
}
