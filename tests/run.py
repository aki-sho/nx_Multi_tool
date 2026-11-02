"""Build an isolated CMS fixture, lint source, then run PHP regression checks.
Never connects to a real CMS database or changes the sibling projects.
"""
from pathlib import Path
import shutil
import os
import sqlite3
import subprocess
import sys

root = Path(__file__).resolve().parent.parent
cms = Path(os.environ.get('NXMT_CMS_SOURCE', str(root.parent / 'nexapress')))
site = Path(os.environ.get('NXMT_TEST_SITE', str(root / 'tests/.runtime/site')))
site.mkdir(parents=True, exist_ok=True)
assert site.resolve().is_relative_to(root.resolve())
for name in ['app', 'public/themes', 'public/assets']:
    shutil.copytree(cms / name, site / name, dirs_exist_ok=True)
(site / 'config').mkdir(exist_ok=True)
for name in ['version', 'theme', 'url', 'general', 'debug']:
    shutil.copyfile(cms / f'config/{name}.php', site / f'config/{name}.php')
(site / 'config/url.php').write_text("<?php return ['site_url_mode'=>'root','post_url_type'=>'post_slug','page_url_type'=>'page_slug'];", encoding='utf-8')
(site / 'storage').mkdir(exist_ok=True)
(site / 'storage/logs').mkdir(exist_ok=True)
(site / 'storage/installed.lock').write_text('fixture only')
extension = site / 'extensions/nx_Multi_tool'
for name in ['src','views','admin','assets','config']:
    shutil.copytree(root/name,extension/name,dirs_exist_ok=True)
for name in ['bootstrap.php','autoload.php','manifest.json']:
    shutil.copyfile(root/name,extension/name)
classic = root.parent / 'nexapress-classic-editor'
for name in ['assets','includes']:
    shutil.copytree(classic/name,site/'extensions/classic-editor'/name,dirs_exist_ok=True)
for name in ['bootstrap.php','manifest.json']:
    shutil.copyfile(classic/name,site/'extensions/classic-editor'/name)
sitemap=Path(os.environ.get('NXMT_SITEMAP_SOURCE', str(root.parent/'nx_XML_HTML_sitemap')))
for name in ['assets','src','admin','config','routes','views']:
    shutil.copytree(sitemap/name,site/'extensions/nx_XML_HTML_sitemap'/name,dirs_exist_ok=True)
for name in ['bootstrap.php','autoload.php','hooks.php','manifest.json']:
    shutil.copyfile(sitemap/name,site/'extensions/nx_XML_HTML_sitemap'/name)
db_path=site/'fixture.sqlite'
if db_path.exists(): db_path.unlink()
schema_path=site/'storage/extensions/nx_Multi_tool/schema.json'
if schema_path.exists(): schema_path.unlink()
for name in ['settings','blocks']:
    path=site/f'storage/extensions/nx_Multi_tool/{name}.json'
    if path.exists(): path.unlink()
db=sqlite3.connect(db_path)
db.executescript('''
CREATE TABLE test_users(id INTEGER PRIMARY KEY,username TEXT,name TEXT,email TEXT,role TEXT,password_hash TEXT,created_at TEXT);
INSERT INTO test_users VALUES(1,'admin','管理者','admin@example.test','administrator','','2026-11-03');
INSERT INTO test_users VALUES(2,'editor','編集者','editor@example.test','editor','','2026-11-03');
CREATE TABLE test_categories(id INTEGER PRIMARY KEY,name TEXT,slug TEXT,created_at TEXT);
INSERT INTO test_categories VALUES(1,'テスト','test','2026-11-03');
CREATE TABLE test_posts(id INTEGER PRIMARY KEY,title TEXT,slug TEXT,content TEXT,excerpt TEXT,status TEXT,user_id INTEGER,category_id INTEGER,featured_media_id INTEGER,created_at TEXT,updated_at TEXT,published_at TEXT,deleted_at TEXT);
CREATE TABLE test_pages(id INTEGER PRIMARY KEY,title TEXT,slug TEXT,content TEXT,status TEXT,user_id INTEGER,created_at TEXT,updated_at TEXT,published_at TEXT,deleted_at TEXT);
CREATE TABLE test_media(id INTEGER PRIMARY KEY,file_path TEXT,title TEXT,description TEXT,original_name TEXT,file_name TEXT,mime_type TEXT,file_size INTEGER,file_type TEXT,user_id INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE test_extension_settings(extension_key TEXT PRIMARY KEY,is_enabled INTEGER);
INSERT INTO test_extension_settings VALUES('nx_Multi_tool',1);
INSERT INTO test_extension_settings VALUES('classic-editor',1);
INSERT INTO test_extension_settings VALUES('nx_XML_HTML_sitemap',1);
CREATE TABLE test_navigation_items(id INTEGER PRIMARY KEY,label TEXT,url TEXT,reference_id INTEGER,type TEXT,position INTEGER);
''')
body='<p>旧名称 と <strong>旧名称</strong>。日本語の本文です。</p><p><a href="https://old.example.com/path">リンク</a><img src="/public/uploads/test.png" alt="test"></p><pre>旧名称は変更しない</pre>'
db.execute('INSERT INTO test_posts VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',(1,'日本語テスト記事','hello',body,'説明文','published',1,1,1,'2026-11-03 10:00:00','2026-11-03 11:00:00','2026-11-03 10:00:00',None))
db.execute('INSERT INTO test_posts VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',(2,'下書き記事','draft',body,'下書き','draft',1,1,None,'2026-11-03','2026-11-03',None,None))
db.execute('INSERT INTO test_pages VALUES(?,?,?,?,?,?,?,?,?,?)',(1,'固定ページ','about',body,'published',1,'2026-11-03','2026-11-03','2026-11-03',None))
db.execute('INSERT INTO test_media VALUES(1,?,?,?,?,?,?,?,?,?,?,?)',('public/uploads/featured-only.png','画像','説明','featured-only.png','featured-only.png','image/png',100,'image',1,'2026-11-03','2026-11-03'))
db.commit();db.close()
for path in root.rglob('*.php'):
    if '.runtime' in path.parts or '.work' in path.parts: continue
    result=subprocess.run(['php','-n','-l',str(path)],capture_output=True,text=True)
    if result.returncode: print(result.stdout,result.stderr);sys.exit(1)
for path in (root/'assets').rglob('*.js'):
    subprocess.run(['node','--check',str(path)],check=True,capture_output=True)
subprocess.run(['php','-d','display_errors=1',str(root/'tests/run.php')],check=True)
subprocess.run(['php',str(root/'tests/http-client.php')],check=True)
print('Fixture:',site)
