<?php
require __DIR__ . '/fixture.php';
use NxMultiTool\Access;
use NxMultiTool\Settings;
use NxMultiTool\Storage;
use NxMultiTool\Utility;
use NxMultiTool\Monitor;
use NxMultiTool\Database;
use NxMultiTool\Content;
use NxMultiTool\Comments;
use NxMultiTool\HttpClient;
use NxMultiTool\Inspector;
$checks = 0;
function check(bool $condition, string $message): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
function rejects(callable $callback, string $message): void { try { $callback(); } catch (Throwable $e) { check(true, $message); return; } check(false, $message); }
$_SESSION = ['user' => ['id' => 1, 'role' => 'administrator']];
$_SERVER['REMOTE_ADDR'] = '192.0.2.50'; $_SERVER['REQUEST_METHOD'] = 'GET';
check(count(NxMultiTool\Registry::all()) === 13, '13 tools');
$settings=Settings::all();check(count(array_filter($settings['tools']))===13,'all enabled');check($settings['comments_open']===false,'public comments opt in');
rejects(fn()=>Access::text(['a'=>[]],'a',10),'reject array');rejects(fn()=>Access::integer(['a'=>'1x'],'a',0,10,1),'reject malformed integer');
ob_start();Access::csv('fixture.csv',['式','文字'],[['=HYPERLINK("x")','日本語']]);$csv=ob_get_clean();check(str_starts_with($csv,"\xEF\xBB\xBF")&&str_contains($csv,"'=HYPERLINK"),'CSV formula protection and BOM');
$settingsFile=Storage::directory().'/settings.json';$original=is_file($settingsFile)?file_get_contents($settingsFile):null;
file_put_contents($settingsFile,'{"tools":[');rejects(fn()=>Settings::all(),'corrupt settings rejected');check(file_get_contents($settingsFile)==='{"tools":[','corrupt settings not overwritten');if($original===null){unlink($settingsFile);}else{file_put_contents($settingsFile,$original);}
rejects(fn()=>Utility::passwords(['upper'=>'0']),'require character pool');
$input=['upper'=>'1','lower'=>'1','digit'=>'1','symbols'=>'1','length'=>'32','count'=>'8'];
$passwords=Utility::passwords($input);check(count($passwords)===8,'password count');
foreach($passwords as $pw){check(strlen($pw)===32&&preg_match('/[A-Z]/',$pw)&&preg_match('/[a-z]/',$pw)&&preg_match('/[0-9]/',$pw)&&preg_match('/[^a-zA-Z0-9]/',$pw),'required pools');}
$hash=Utility::bcrypt(['plain'=>'引用"と\\文字','cost'=>'10'])['hash'];check(Utility::bcrypt(['mode'=>'verify','plain'=>'引用"と\\文字','hash'=>$hash])['match'],'bcrypt unicode quotes');
check(!Utility::bcrypt(['mode'=>'verify','plain'=>'違う','hash'=>$hash])['match'],'bcrypt mismatch');rejects(fn()=>Utility::bcrypt(['plain'=>str_repeat('a',73)]),'bcrypt 72 bytes');
rejects(fn()=>Utility::bcrypt(['plain'=>'abc','cost'=>'4']),'low cost opt in');
rejects(fn()=>Utility::bcrypt(['plain'=>'abc','mode'=>'verify','hash'=>str_replace('$10$','$13$',$hash)]),'verify cost bound');
foreach(['127.0.0.1','10.1.2.3','169.254.169.254','192.168.1.1','100.64.0.1','192.0.2.1','198.18.0.1','203.0.113.2','224.0.0.1','::1','::ffff:127.0.0.1','fe80::1','2001:db8::1','2002:7f00:1::1'] as $ip){check(!HttpClient::publicIp($ip),'block private/reserved '.$ip);}
check(HttpClient::publicIp('8.8.8.8'),'public IP');
foreach(['file:///etc/passwd','https://user:pass@example.com','http://example.com:8080','https://example.com/?token=x'] as $url){rejects(fn()=>HttpClient::base($url),'reject base '.$url);}
check(HttpClient::resolve('../img.png','https://example.com/blog/post/hello','https://example.com/blog')==='https://example.com/blog/img.png','relative URL');
check(HttpClient::resolve('https://evil.test/','https://example.com/blog/','https://example.com/blog')===null,'same origin');
check(HttpClient::resolve('/outside','https://example.com/blog/','https://example.com/blog')===null,'subdirectory restriction');
check(HttpClient::resolve('/blog/%2e%2e/private','https://example.com/blog/','https://example.com/blog')===null,'encoded traversal');
check(!str_contains(HttpClient::resolve('/blog/x?token=secret','https://example.com/blog/','https://example.com/blog'),'secret'),'redacted query');
check(Monitor::safeUrl('https://user:pass@example.com/a?token=secret#x')==='/a','private URL removal');
check(Monitor::normalizeIp('::ffff:192.0.2.10')==='192.0.2.10','mapped normalization');
rejects(fn()=>Monitor::block('192.0.2.50',false),'self block protection');Monitor::block('192.0.2.20',false);check(Monitor::blocked('192.0.2.20'),'IP blocking');Monitor::block('192.0.2.20',true);check(!Monitor::blocked('192.0.2.20'),'IP unblock');
$page=Content::rows(['type'=>'post']);check($page['total']===2,'content rows');$metric=Content::metrics($page['rows'][1],'post');check($metric[7]==='あり'&&$metric[8]===1&&$metric[9]===1,'content metrics');
$scan=Inspector::scan(['patterns'=>'old.example.com','types'=>['a'],'distance'=>'0']);check(count($scan['rows'])===2,'link inspector finds both posts');
$settingsInput=['tools'=>array_keys(NxMultiTool\Registry::all()),'site_url'=>'https://example.com','comments_open'=>'1'];Settings::save($settingsInput);
$id=Comments::submit('post',1,['name'=>'<script>alert(1)</script>','email'=>'user@example.test','body'=>'本文<script>test</script>'],'192.0.2.30');
check(Comments::list([])['rows'][0]['status']==='pending','new comment moderated');Comments::act('approved',[$id]);check(Comments::list(['status'=>'approved'])['total']===1,'approve');
rejects(fn()=>Comments::submit('post',2,['name'=>'Test','body'=>'draft'],'192.0.2.30'),'reject draft comments');
Comments::act('prepare_block',[$id]);$token=$_SESSION['nxmt_block_review']['token'];Comments::confirmBlock($token);check(Monitor::blocked('192.0.2.30'),'confirmed comment IP block');check(Comments::list(['status'=>'spam'])['total']===1,'confirmed spam');
rejects(fn()=>Comments::confirmBlock($token),'review token single use');Monitor::block('192.0.2.30',true);
Comments::act('delete',[$id]);check(Comments::list([])['total']===1,'only trash permanently deleted');Comments::act('trash',[$id]);Comments::act('delete',[$id]);check(Comments::list([])['total']===0,'trash deletion');
$_SESSION=[];http_response_code(200);$_SERVER['HTTP_REFERER']='https://example.test/a?secret=hidden';$_SERVER['HTTP_USER_AGENT']='fixture';
Monitor::record('/post/hello');check(Monitor::logs([])['total']===1,'access logging');check(Monitor::logs([])['rows'][0]['referrer']==='/a','referrer sanitized');
check(count(NxMultiTool\Security::inspect()['items'])===10,'10 security checks');
Settings::save(['tools'=>['qr']]);check(!Settings::enabled('password')&&Settings::enabled('qr'),'per-tool disable state');
// Reset fixture data for browser tests, preserving extension-owned tables.
Database::query('DELETE FROM '.Database::table('logs'));Settings::save(['tools'=>array_keys(NxMultiTool\Registry::all()),'site_url'=>'https://127.0.0.1','comments_open'=>'1']);
$_SESSION=['user'=>['id'=>1,'role'=>'administrator']];
$crawl=\NxMultiTool\Crawler::start();
check(count(array_filter($crawl['rows'],static fn($row)=>str_ends_with($row['url'],'/featured-only.png')&&$row['method']==='アイキャッチ'))===1,'CMS featured media reference collected');
check(count(array_filter($crawl['rows'],static fn($row)=>str_ends_with($row['url'],'/sitemap.xml')&&$row['candidate']===true))===1,'guessed sitemap identified separately');
\NxMultiTool\Crawler::clear($crawl['run']);
echo "PHP regression checks: $checks passed\n";
