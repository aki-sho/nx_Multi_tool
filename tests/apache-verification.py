"""Exercise actual Apache access rules and a real disposable MySQL-backed CMS."""
from pathlib import Path
import http.cookiejar, json, os, re, shutil, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request

root=Path(__file__).resolve().parent.parent
source=Path(os.environ.get('NXMT_CMS_SOURCE',str(root.parent/'nexapress')))
sitemap_source=Path(os.environ.get('NXMT_SITEMAP_SOURCE',str(root.parent/'nx_XML_HTML_sitemap')))
work=Path(tempfile.mkdtemp(prefix='nxmt-apache-'))
site=work/'site';shutil.copytree(source,site,ignore=shutil.ignore_patterns('.git','dist','build','*.zip','.runtime'))
for name in ['storage/cache','storage/logs','storage/backups','public/uploads','extensions','sessions']:(site/name).mkdir(parents=True,exist_ok=True)
state=json.loads((root/'.work/services.json').read_text())
env=os.environ.copy();env.update(NEXAPRESS_TEST_HOST=state['mysql_host'],NEXAPRESS_TEST_USER=state['mysql_user'],NEXAPRESS_TEST_PASSWORD=state['mysql_password'])
for name,src in [('nx_Multi_tool',root),('nx_XML_HTML_sitemap',sitemap_source)]:
    shutil.copytree(src,site/'extensions'/name,ignore=shutil.ignore_patterns('.git','.work','tests','dist','*.zip'))
log=work/'setup.log'
with log.open('w',encoding='utf-8') as output:
    subprocess.run(['php',str(source/'tests/fixture.php'),str(site)],env=env,stdout=output,stderr=subprocess.STDOUT,check=True)
    subprocess.run(['php',str(site/'app/Console/migrate.php')],env=env,stdout=output,stderr=subprocess.STDOUT,check=True)
# Mark both extensions enabled in this disposable database, preserving production data.
enable=work/'enable.php';enable.write_text("<?php define('BASE_PATH',"+json.dumps(site.as_posix())+");spl_autoload_register(function($c){$p=BASE_PATH.'/'.str_replace('\\\\','/',$c).'.php';if(is_file($p))require_once $p;});foreach(['nx_Multi_tool','nx_XML_HTML_sitemap'] as $id)app\\Models\\ExtensionSetting::enable($id);",encoding='utf-8')
subprocess.run(['php',str(enable)],env=env,check=True,capture_output=True)
for path,body in {'storage/logs/private.log':'PRIVATE_MARKER','storage/backups/private.zip':'PRIVATE_MARKER','config/private.txt':'PRIVATE_MARKER','.git/config':'PRIVATE_MARKER','public/uploads/payload.php':'<?php echo "EXECUTED_MARKER";','public/.env':'PRIVATE_MARKER','.well-known/acme-challenge/test-token':'challenge-ok'}.items():
    file=site/path;file.parent.mkdir(parents=True,exist_ok=True);file.write_text(body,encoding='utf-8')
ini=work/'php.ini';ini.write_text('extension_dir="D:/xampp/php/ext"\n'+''.join('extension='+name+'\n' for name in ['curl','mbstring','openssl','fileinfo','pdo_mysql','pdo_sqlite','zip'])+'date.timezone=Asia/Tokyo\nsession.save_path="'+(site/'sessions').as_posix()+'"\ndisplay_errors=Off\nlog_errors=On\nerror_log="'+(work/'php-errors.log').as_posix()+'"\n',encoding='utf-8')
modules=['authz_core','authz_host','dir','mime','rewrite','log_config']
conf=work/'httpd.conf'
conf.write_text('ServerRoot "D:/xampp/apache"\nListen 127.0.0.1:18742\nServerName 127.0.0.1\nPidFile "'+(work/'httpd.pid').as_posix()+'"\nErrorLog "'+(work/'apache-error.log').as_posix()+'"\n'+''.join(f'LoadModule {name}_module modules/mod_{name}.so\n' for name in modules)+'LoadFile "D:/xampp/php/php8ts.dll"\nLoadModule php_module "D:/xampp/php/php8apache2_4.dll"\nPHPIniDir "'+work.as_posix()+'"\nAddType application/x-httpd-php .php\nTypesConfig "D:/xampp/apache/conf/mime.types"\nDirectoryIndex index.php index.html\nDocumentRoot "'+site.as_posix()+'"\n<Directory />\n AllowOverride None\n Require all denied\n</Directory>\n<Directory "'+site.as_posix()+'">\n Options FollowSymLinks\n AllowOverride All\n Require all granted\n</Directory>\n',encoding='utf-8')
process=None;output=None;checks=0
base='http://127.0.0.1:18742'
def request(path,data=None,opener=None):
    req=urllib.request.Request(base+path,None if data is None else urllib.parse.urlencode(data).encode())
    try:r=(opener or urllib.request.build_opener()).open(req,timeout=10)
    except urllib.error.HTTPError as error:r=error
    return r.status,r.read().decode('utf-8',errors='replace'),dict(r.headers)
def check(value,label):
    global checks
    if not value:raise AssertionError(label)
    checks+=1;print('PASS:',label,flush=True)
try:
    configCheck=subprocess.run(['D:/xampp/apache/bin/httpd.exe','-t','-f',conf.as_posix()],capture_output=True)
    check(configCheck.returncode==0,'Apache configuration valid: '+configCheck.stderr.decode(errors='replace'))
    output=(work/'apache-console.log').open('w',encoding='utf-8');process=subprocess.Popen(['D:/xampp/apache/bin/httpd.exe','-f',conf.as_posix(),'-X'],stdout=output,stderr=output,env=env,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    for _ in range(100):
        if process.poll() is not None:raise RuntimeError((work/'apache-console.log').read_text(errors='replace')+(work/'apache-error.log').read_text(errors='replace'))
        try:
            with socket.create_connection(('127.0.0.1',18742),timeout=.2):break
        except OSError:time.sleep(.1)
    for path in ['/storage/logs/private.log','/storage/backups/private.zip','/config/private.txt','/.git/config','/app/Core/Database.php','/database/','/extensions/','/tests/','/update-package/','/public/uploads/payload.php','/public/uploads/payload.php/path-info','/public/uploads/payload.php.jpg','/public/.env','/public/themes/default/theme.php']:
        code,body,_=request(path);check(code==403 and 'PRIVATE_MARKER' not in body and 'EXECUTED_MARKER' not in body,'private or executable path denied '+path)
    code,body,_=request('/public/assets/css/style.css');check(code==200 and 'admin.css' not in body and 'login.css' not in body,'public CSS available without management imports')
    check(request('/.well-known/acme-challenge/test-token')[:2]==(200,'challenge-ok'),'ACME challenge stays available')
    code,body,_=request('/post/legacy-post');check(code==200 and 'admin.css' not in body and 'admin-preview.css' not in body and 'public-features.css' in body,'guest theme loads only public styles')
    code,body,_=request('/sitemap.xml');locations=re.findall(r'<loc>(.*?)</loc>',body);check(code==200 and bool(locations) and all(url.startswith(base+'/') for url in locations) and 'legacy-post' in body,'real MySQL-backed sitemap publishes absolute URLs')
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    code,body,_=request('/admin/login',{'username':'admin','password':'Test-Password-2026'},opener);check(code==200,'real administrator login')
    code,body,_=request('/admin/extensions/nx_Multi_tool/dashboard',opener=opener);check(code==200 and 'NX Multi Tool' in body and 'admin.css' in body,'Multi Tool installed and enabled through real CMS loader')
    code,body,_=request('/admin/extensions/nx_XML_HTML_sitemap/dashboard',opener=opener);check(code==200 and 'data-nx-sitemap-check' in body,'sitemap diagnostics in real CMS admin')
    check(request('/admin/login')[0]==200,'login page remains available')
    print(f'Apache and MySQL integration checks: {checks} passed')
    (root/'.work/apache-result.json').write_text(json.dumps({'checks':checks,'fixture':str(site),'log_directory':str(work)}),encoding='utf-8')
finally:
    if process and process.poll() is None:process.terminate();process.wait(timeout=10)
    if output:output.close()
    print('Apache fixture:',work,flush=True)
