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
$adminSession = [
    'user' => ['id' => 1, 'role' => 'administrator'],
    'auth_fingerprint' => hash('sha256', BASE_PATH . "\0" . \app\Models\User::passwordHash(1)),
];
$_SESSION = $adminSession;
$_SERVER['REMOTE_ADDR'] = '192.0.2.50'; $_SERVER['REQUEST_METHOD'] = 'GET';
check(count(NxMultiTool\Registry::all()) === 12, '12 tools');
$settings=Settings::all();check(count(array_filter($settings['tools']))===12,'all enabled');check($settings['comments_open']===false,'public comments opt in');
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
$settingsInput=['tools'=>array_keys(NxMultiTool\Registry::all()),'comments_open'=>'1'];Settings::save($settingsInput);
$id=Comments::submit('post',1,['name'=>'<script>alert(1)</script>','email'=>'user@example.test','body'=>'本文<script>test</script>'],'192.0.2.30');
check(Comments::list([])['rows'][0]['status']==='pending','new comment moderated');Comments::act('approved',[$id]);check(Comments::list(['status'=>'approved'])['total']===1,'approve');
rejects(fn()=>Comments::submit('post',2,['name'=>'Test','body'=>'draft'],'192.0.2.30'),'reject draft comments');
Comments::act('prepare_block',[$id]);$token=$_SESSION['nxmt_block_review']['token'];Comments::confirmBlock($token);check(Monitor::blocked('192.0.2.30'),'confirmed comment IP block');check(Comments::list(['status'=>'spam'])['total']===1,'confirmed spam');
rejects(fn()=>Comments::confirmBlock($token),'review token single use');Monitor::block('192.0.2.30',true);
Comments::act('delete',[$id]);check(Comments::list([])['total']===1,'only trash permanently deleted');Comments::act('trash',[$id]);Comments::act('delete',[$id]);check(Comments::list([])['total']===0,'trash deletion');
$_SESSION=[];http_response_code(200);$_SERVER['HTTP_REFERER']='https://example.test/a?secret=hidden';$_SERVER['HTTP_USER_AGENT']='fixture';
Monitor::record('/post/hello');check(Monitor::logs([])['total']===1,'access logging');check(Monitor::logs([])['rows'][0]['referrer']==='/a','referrer sanitized');
$now = time();
$ip = '192.0.2.50';
$insertLog = static function ($when, $address): void {
    Database::query('INSERT INTO ' . Database::table('logs') . ' (created_at,method,status,ip,url,referrer,ua) VALUES (?,?,?,?,?,?,?)',
        [$when, 'GET', 404, $address, '/fixture-log/', '/referrer', 'Test Browser']);
};
$insertLog($now - 90, $ip);
$insertLog($now - 31 * 86400, $ip);
$insertLog($now - 70, '192.0.2.51');
$insertLog($now - 70, '');
$searchPage = Monitor::logs(['q' => '192.0.2.'], 1, 1);
check($searchPage['total'] === 4 && count($searchPage['rows']) === 1
    && Monitor::logs(['q' => '::ffff:192.0.2.50'])['total'] === 3
    && Monitor::logs(['ip' => $ip])['total'] === 3, 'unified search matches IP and preserves exact legacy links');
$summary = Monitor::ipSummary(1);
check($summary['total'] === 2 && count($summary['dates'][$ip]) === 2
    && $summary['dates'][$ip][1] === $now - 90, '30-day IP counts include every retained timestamp in descending order');
for ($index = 1; $index <= 50; $index++) {
    $insertLog($now - 120, '198.51.100.' . $index);
}
$nextIps = Monitor::ipSummary(2);
check($nextIps['total'] === 52 && count($nextIps['dates']) === 2, 'IP summary pages independently');
check(Monitor::saveMemo($ip, ' 訪問確認 ') === '訪問確認' && Monitor::memos([$ip])[$ip] === '訪問確認',
    'memo is stored for the IP');
Monitor::saveMemo('::ffff:' . $ip, '更新');
check(Monitor::memos([$ip]) === [$ip => '更新'], 'memo update uses the normalized address');
rejects(fn() => Monitor::saveMemo($ip, str_repeat('文', Monitor::MEMO_LIMIT + 1)), 'overlong memo rejected');
Monitor::saveMemo($ip, '');
check(Monitor::memos([$ip]) === [], 'blank memo clears a previous note');
$otherIp = '192.0.2.51';
$otherLog = (string)Monitor::logs(['ip' => $otherIp])['rows'][0]['id'];
$ownLog = (string)Monitor::logs(['ip' => $ip])['rows'][0]['id'];
rejects(fn() => Monitor::blockSelected([$otherLog, $ownLog]), 'bulk block rejects the administrator IP');
rejects(fn() => Monitor::blockSelected([$otherLog, '999999']), 'bulk block rejects missing log IDs');
check(!Monitor::blocked($otherIp), 'invalid selections leave other IPs unaffected');
Storage::transaction('settings', static fn($saved) => [array_replace($saved, ['allow_ips' => [$otherIp]]), null]);
rejects(fn() => Monitor::blockSelected([$otherLog]), 'bulk block respects allow-listed IPs');
Settings::save($settingsInput);
Database::query('DELETE FROM ' . Database::table('logs'));
$security = \NxMultiTool\Security::inspect();
check(array_column($security['items'], 'area') === ['both', 'cms', 'cms', 'both', 'server', 'cms', 'both', 'both', 'versions', 'cms', 'both', 'both', 'cms', 'server', 'server', 'both', 'cms', 'cms', 'both', 'server'], '20 security categories with approved confirmation sources');
check($security['items'][2]['rows'][0]['state'] === 'present' && $security['items'][12]['rows'][0]['state'] === 'present', 'checks find scoped evidence in the installed CMS and extension');
check($security['items'][4]['rows'][0]['state'] === 'unknown' && array_column($security['items'][8]['rows'], 'state') === ['present', 'unknown'], 'unavailable server rules and vulnerability matches stay unconfirmed');
check($security['category_score']['max'] === 1000
    && array_count_values(array_column($security['items'], 'importance')) === ['high' => 12, 'medium' => 6, 'low' => 2],
    '20 weighted categories total 100 points');
check($security['items'][2]['score'] === ['max' => 60, 'earned' => 60, 'pending' => 0]
    && $security['items'][3]['score'] === ['max' => 60, 'earned' => 30, 'pending' => 30],
    'confirmed protections earn points and unread public checks stay pending');
check($security['items'][8]['score'] === ['max' => 60, 'earned' => 0, 'pending' => 60]
    && $security['items'][18]['score'] === ['max' => 40, 'earned' => 0, 'pending' => 40],
    'version information and inactive optional cache do not count as protection or a penalty');
$security = \NxMultiTool\Security::inspect([]);
check($security['category_score'] === ['max' => 1000, 'earned' => 0, 'pending' => 1000], 'all points remain pending when every source is omitted');
foreach ($security['items'] as $item) {
    check(array_unique(array_column($item['rows'], 'state')) === ['unknown'], 'excluded sources are unconfirmed for group ' . $item['number']);
}
check(\NxMultiTool\PublicHeaders::settings() === [], 'public headers start off when upgrading');
\NxMultiTool\PublicHeaders::set('permissions', ['features' => ['geolocation', 'camera']]);
\NxMultiTool\PublicHeaders::set('csp', ['mode' => 'report', 'value' => "default-src 'self'; style-src 'self'"]);
rejects(fn() => \NxMultiTool\PublicHeaders::set('csp', ['value' => "default-src 'self'\n"]), 'CSP must occupy one header line');
rejects(fn() => \NxMultiTool\PublicHeaders::set('permissions', ['features' => []]), 'permissions must include a selected feature');
$publicHeaders = \NxMultiTool\PublicHeaders::settings();
check($publicHeaders['csp']['value'] === "default-src 'self'; style-src 'self'" && $publicHeaders['csp']['mode'] === 'report'
    && $publicHeaders['permissions']['value'] === 'camera=(), geolocation=()', 'configured features and CSP are persisted');
\NxMultiTool\PublicHeaders::set('csp', null);
check(array_keys(\NxMultiTool\PublicHeaders::settings()) === ['permissions'], 'removing one setting preserves other headers');
\NxMultiTool\PublicHeaders::set('permissions', null);
Settings::save(['tools'=>['qr']]);check(!Settings::enabled('password')&&Settings::enabled('qr'),'per-tool disable state');
// Reset fixture data for browser tests, preserving extension-owned tables.
Settings::save($settingsInput);
foreach (['198.51.100.11', '198.51.100.11', '198.51.100.11', '198.51.100.12'] as $index => $address) {
    $insertLog(time() - $index * 60, $address);
}
$commentIp = '198.51.100.11';
$noCommentIp = '198.51.100.12';
Comments::submit('post', 1, ['name' => '確認用', 'body' => '以前の確認用コメント'], $commentIp);
$latestComment = Comments::submit('post', 1, ['name' => '確認用', 'body' => '最新の確認用コメント'], $commentIp);
$counts = Comments::countsByIp([$commentIp, $noCommentIp, $commentIp]);
check(count($counts) === 1 && (int)$counts[$commentIp]['comment_count'] === 2
    && (int)$counts[$commentIp]['latest_id'] === $latestComment, 'per-IP comment counts and newest ID');
check((int)Comments::list(['ip' => $commentIp])['rows'][0]['id'] === $latestComment
    && Comments::list(['ip' => $noCommentIp])['total'] === 0, 'comment manager uses the exact IP');
check(Monitor::logs(['sort' => 'comments', 'dir' => 'asc'], 1)['rows'][0]['ip'] === $noCommentIp
    && Monitor::logs(['sort' => 'comments', 'dir' => 'desc'], 1)['rows'][0]['ip'] === $commentIp,
    'comment count ordering includes zero counts and precedes pagination');
$commentIpRows = Monitor::logs(['ip' => $commentIp])['rows'];
$selectedIds = array_map('strval', array_column($commentIpRows, 'id'));
Monitor::block($commentIp, false);
check(isset(Monitor::blocks()[$commentIp]['until']), 'existing comment block can be temporary');
check(Monitor::blockSelected($selectedIds) === 1 && Monitor::blocks()[$commentIp] === ['permanent' => true],
    'selected logs are deduplicated by IP and remove the expiry for that IP');
Monitor::block($commentIp, false);
check(Monitor::blocks()[$commentIp] === ['permanent' => true], 'comment block does not downgrade permanent access refusal');
check(Monitor::logs(['sort' => 'blocked', 'dir' => 'desc'], 1)['rows'][0]['ip'] === $commentIp
    && array_keys(Monitor::ipSummary(1, ['ip_sort' => 'blocked', 'ip_dir' => 'asc', 'ip_per_page' => '1'])['dates']) === [$noCommentIp],
    'effective block status sorts the full results in both tables');
Monitor::block($commentIp, true);
check(!Monitor::blocked($commentIp), 'permanent block can be released');
Monitor::saveMemo($commentIp, 'memo_100%');
check(Monitor::ipSummary(1, ['ip_q' => '100%'])['total'] === 1
    && Monitor::ipSummary(1, ['ip_q' => '%_'])['total'] === 0
    && Monitor::ipSummary(1, ['ip_q' => '198.51.100.'])['total'] === 2, 'IP summary searches IP and memo literally');
check(Monitor::logs(['sort' => 'memo', 'dir' => 'desc'], 1)['rows'][0]['ip'] === $commentIp
    && array_keys(Monitor::ipSummary(2, ['ip_sort' => 'memo', 'ip_dir' => 'asc', 'ip_per_page' => '1'])['dates']) === [$commentIp],
    'memo order and page size apply across the search results');
Monitor::saveMemo($commentIp, '');
$_SESSION = $adminSession;
Storage::transaction('settings', static fn($saved) => [array_replace($saved, ['site_url'=>'https://old.example.test']), null]);
$_SERVER['HTTP_HOST'] = 'current.example.test';
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_X_FORWARDED_HOST'] = 'forwarded.example.test';
$inventoryRouter = new \app\Core\Router();
$inventoryRouter->get('/', 'app\Controllers\HomeController@index');
$inventoryRouter->get('/posts', 'app\Controllers\ArchiveController@index');
$inventoryRouter->get('/search', 'app\Controllers\ArchiveController@search');
$inventoryRouter->get('/category/{slug}', 'app\Controllers\ArchiveController@category');
$inventoryRouter->get('/sitemap', 'NexaPressXmlHtmlSitemap\Controller\HtmlSitemapController@index');
$inventoryRouter->get('/admin', 'app\Controllers\Admin\DashboardController@index');
$inventoryRouter->get('/admin/settings/general', 'app\Controllers\Admin\SettingController@general');
$inventoryRouter->post('/admin/settings/general', 'app\Controllers\Admin\SettingController@updateGeneral');
$inventoryRouter->get('/admin/posts/edit/{id}', 'app\Controllers\Admin\PostController@edit');
$inventoryRouter->get('/install/database', 'app\Controllers\InstallController@database');
$inventoryRouter->get('/admin/extensions/{extensionKey}/dashboard', 'app\Controllers\Admin\ExtensionController@dashboard');
\NxMultiTool\RouteInventory::capture($inventoryRouter);
$inventoryRouter->post('/admin/extensions/nx_Multi_tool/action', \NxMultiTool\AdminController::class . '@action');
$crawl=\NxMultiTool\Crawler::start();
check(count(array_filter($crawl['rows'], static fn($row) => $row['method'] === '開始URL' && $row['url'] === 'https://current.example.test/')) === 1, 'crawler starts from the current CMS even with a legacy URL stored');
// The standard login route is known to the CMS even on a site without any links to it.
$loginUrl = 'https://current.example.test' . url('admin/login');
$loginRows = array_values(array_filter($crawl['rows'], static fn($row) => $row['url'] === $loginUrl));
check(count($loginRows) === 1 && $loginRows[0]['method'] === 'CMS設定（ログイン）'
    && $loginRows[0]['http'] === 0 && $loginRows[0]['access'] === '未確認', 'standard login URL is listed without an HTTP result');
check(in_array('admin', $loginRows[0]['tags'], true)
    && HttpClient::isAdminUrl('https://example.test/blog/admin', 'https://example.test/blog')
    && HttpClient::isAdminUrl('https://example.test/blog/public/admin/login', 'https://example.test/blog')
    && !HttpClient::isAdminUrl('https://example.test/blog/admin-news', 'https://example.test/blog')
    && !HttpClient::isAdminUrl('https://example.test/blog/guide/admin', 'https://example.test/blog'),
    'admin filter matches the actual administration prefix including subdirectory installs');
check(!in_array($loginUrl, Storage::read('crawl-1-' . $crawl['run'])['queue'], true), 'standard login URL is not queued for crawling');
$routeRows = array_column($crawl['rows'], null, 'url');
foreach (['/', '/posts', '/search', '/category/{slug}', '/sitemap'] as $viewerPath) {
    check(in_array('viewer_page', $routeRows['https://current.example.test' . url($viewerPath)]['tags'], true),
        'known visitor page can be filtered before HTTP: ' . $viewerPath);
}
foreach (['post', 'page'] as $contentType) {
    $contentItem = Content::find($contentType, 1);
    $contentUrl = 'https://current.example.test' . Content::url($contentType, $contentItem);
    check(isset($routeRows[$contentUrl]) && in_array('viewer_page', $routeRows[$contentUrl]['tags'], true),
        'published ' . $contentType . ' is included instead of being unconditionally hidden');
    check($routeRows[$contentUrl]['title'] === $contentItem['title'] && $routeRows[$contentUrl]['content_status'] === '公開',
        'published ' . $contentType . ' title and status come from the CMS');
    $draft = Content::find($contentType, 2);
    $draftUrl = 'https://current.example.test' . Content::url($contentType, $draft);
    check($routeRows[$draftUrl]['title'] === $draft['title'] && $routeRows[$draftUrl]['content_status'] === '下書き'
        && $routeRows[$draftUrl]['http'] === 0 && in_array('viewer_page', $routeRows[$draftUrl]['tags'], true)
        && !in_array($draftUrl, Storage::read('crawl-1-' . $crawl['run'])['queue'], true),
        'draft ' . $contentType . ' is metadata-only and is never queued');
}
check(!isset($routeRows['https://current.example.test/draft-only-link'])
    && !isset($routeRows['https://current.example.test/draft-page-only-link']), 'draft bodies do not supply links for the public crawl');
check($routeRows['https://current.example.test' . url('admin/settings/general')]['title'] === ''
    && $routeRows['https://current.example.test' . url('admin/settings/general')]['content_status'] === ''
    && $routeRows['https://current.example.test/']['content_status'] === '', 'inapplicable or unavailable metadata stays empty');
check(!in_array('viewer_page', $loginRows[0]['tags'], true)
    && !in_array('viewer_page', $routeRows['https://current.example.test' . url('install/database')]['tags'], true)
    && !in_array('viewer_page', $routeRows['https://current.example.test' . url('admin/settings/general')]['tags'], true)
    && !in_array('viewer_page', $routeRows['https://current.example.test/sitemap.xml']['tags'], true),
    'login, administration, installation and XML sitemap are outside visitor pages');
$settingsUrl = 'https://current.example.test' . url('admin/settings/general');
check(isset($routeRows[$settingsUrl]) && $routeRows[$settingsUrl]['registered_methods'] === ['GET', 'POST']
    && $routeRows[$settingsUrl]['method'] === 'CMS登録ルート'
    && $routeRows[$settingsUrl]['http'] === 0 && $routeRows[$settingsUrl]['access'] === '未確認',
    'unlinked administration routes are inventoried with their real methods and untested HTTP status');
$templateUrl = 'https://current.example.test' . url('admin/posts/edit/{id}');
check($routeRows[$templateUrl]['kind'] === 'route' && str_contains($routeRows[$templateUrl]['status'], 'URLパターン'),
    'parameterized routes stay recognizable as templates instead of fabricated record URLs');
check(isset($routeRows['https://current.example.test' . url('install/database')])
    && isset($routeRows['https://current.example.test' . url('admin/extensions/nx_Multi_tool/action')])
    && isset($routeRows['https://current.example.test' . url('admin/extensions/nx_Multi_tool/dashboard')]),
    'internal routes, later extension registrations and enabled dashboards are included');
$queued = Storage::read('crawl-1-' . $crawl['run'])['queue'];
check(!in_array($settingsUrl, $queued, true) && !in_array($templateUrl, $queued, true)
    && !array_intersect($queued, array_keys(array_filter($routeRows, static fn($row) => !empty($row['registered_only'])))),
    'inventorying registration records does not queue management actions or URL templates');
check(!isset(Settings::all()['site_url']) && Settings::all()['comments_open'], 'legacy URL ignored while other settings are read normally');
check(count(array_filter($crawl['rows'],static fn($row)=>str_ends_with($row['url'],'/featured-only.png')&&$row['method']==='アイキャッチ'))===1,'CMS featured media reference collected');
check(count(array_filter($crawl['rows'],static fn($row)=>str_ends_with($row['url'],'/sitemap.xml')&&$row['candidate']===true))===1,'guessed sitemap identified separately');
$_SERVER['HTTP_HOST'] = 'moved.example.test';
rejects(fn() => \NxMultiTool\Crawler::step($crawl['run']), 'a crawl from the former host cannot resume');
\NxMultiTool\Crawler::clear($crawl['run']);
Settings::save($settingsInput + ['site_url' => 'https://posted.example.test']);
check(!isset(Storage::read('settings')['site_url']), 'settings form cannot change the crawl destination');
echo "PHP regression checks: $checks passed\n";
