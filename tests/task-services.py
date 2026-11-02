"""Private local services for this maintenance run; all data is disposable."""
from pathlib import Path
import json, os, socket, subprocess, tempfile, time

root=Path(__file__).resolve().parent.parent
work=root/'.work';work.mkdir(exist_ok=True)
stop=work/'services.stop'
if stop.exists():stop.unlink()
data=Path(tempfile.mkdtemp(prefix='nxmt-mariadb-'))
mysql_bin=Path('D:/xampp/mysql/bin'); password='Local-Verification-Only-2026!'
children=[]; handles=[]
def spawn(args,name,cwd=None):
    log=open(work/(name+'.log'),'w',encoding='utf-8');handles.append(log)
    proc=subprocess.Popen(args,cwd=cwd,stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0));children.append(proc);return proc
try:
    result=subprocess.run([str(mysql_bin/'mysql_install_db.exe'),'--datadir='+str(data),'--port=13371','--password='+password,'--silent'],capture_output=True,text=True)
    if result.returncode:raise RuntimeError(result.stdout+result.stderr)
    mysql=spawn([str(mysql_bin/'mysqld.exe'),'--no-defaults','--basedir=D:/xampp/mysql','--datadir='+str(data),'--port=13371','--bind-address=127.0.0.1','--skip-log-bin','--innodb-buffer-pool-size=32M'],'mariadb')
    for _ in range(100):
        if mysql.poll() is not None:raise RuntimeError((work/'mariadb.log').read_text(errors='replace'))
        try:
            with socket.create_connection(('127.0.0.1',13371),timeout=.2):break
        except OSError:time.sleep(.2)
    else:raise RuntimeError('MariaDB did not become ready')
    php=spawn(['php','-d','display_errors=0','-S','127.0.0.1:18741',str(root/'tests/router.php')],'php-tools',root)
    (work/'services.json').write_text(json.dumps({'mysql_host':'127.0.0.1;port=13371','mysql_port':13371,'mysql_user':'root','mysql_password':password,'mysql_data':str(data)}),encoding='utf-8')
    print('READY: isolated MariaDB 13371 and PHP test server 18741',flush=True)
    while not stop.exists():
        if mysql.poll() is not None or php.poll() is not None:raise RuntimeError('A private test service stopped')
        time.sleep(.5)
finally:
    for proc in reversed(children):
        if proc.poll() is None:
            proc.terminate()
            try:proc.wait(timeout=10)
            except subprocess.TimeoutExpired:proc.kill();proc.wait()
    for handle in handles:handle.close()
    print('Private test services stopped.',flush=True)
