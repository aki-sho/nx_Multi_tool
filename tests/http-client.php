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
        foreach($response['headers']??[] as $key=>$value){$header($handle,$key.': '.$value."\r\n");}
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
    require __DIR__.'/fixture.php';
    use NxMultiTool\HttpClient;
    use NxMultiTool\ResponseClassifier;
    use NxMultiTool\Discovery;
    $checks=0;
    function expect(bool $ok,string $label):void {global $checks;$checks++;if(!$ok)throw new \RuntimeException('FAIL '.$label);}
    function fetchCase(array $responses,bool $head=false,string $url='https://example.test/asset'):array {
        $GLOBALS['nxmt_http_responses']=$responses;$GLOBALS['nxmt_http_calls']=[];$requests=0;
        return HttpClient::fetch($url,'https://example.test',$head,0,false,$requests);
    }
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
    echo "HTTP and URL-discovery checks: $checks passed\n";
}
