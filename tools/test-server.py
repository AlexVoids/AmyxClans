#!/usr/bin/env python3
import argparse,json,os,shutil,subprocess,tempfile
from pathlib import Path
p=argparse.ArgumentParser();p.add_argument('--php',required=True,type=Path);p.add_argument('--pmmp',required=True,type=Path);p.add_argument('--port',default=19440,type=int);p.add_argument('--integrations',action='store_true');p.add_argument('--formapi',type=Path);p.add_argument('--hologram',type=Path);a=p.parse_args()
r=Path(__file__).resolve().parent.parent;php=str(a.php.resolve());pm=str(a.pmmp.resolve())
subprocess.run([php,'-n','-d','phar.readonly=0',str(r/'tools/build.php')],check=True)
(r/'work').mkdir(exist_ok=True)
out=r/'work/AmyxClansTests.phar';out.unlink(missing_ok=True)
subprocess.run([php,'-n','-d','phar.readonly=0','-r','$p=new Phar($argv[1]);$p->buildFromDirectory($argv[2]);$p->setStub("<?php __HALT_COMPILER();");',str(out),str(r/'tests/server')],check=True)
s=Path(tempfile.mkdtemp(prefix='server-',dir=r/'work'));plugins=s/'plugins';plugins.mkdir()
for f in [r/'build/AmyxClans.phar',r/'build/AmyxEconomy.phar',r/'build/ChatPerms.phar',out]:shutil.copy2(f,plugins/f.name)
shutil.copy2(a.formapi.resolve() if a.formapi else r/'build/FormAPI.phar',plugins/'FormAPI.phar')
shutil.copy2(a.hologram.resolve() if a.hologram else r/'work/input-Hologram.phar',plugins/'AmyxHologram.phar')
if a.integrations:
    for name in ['AmyBuilderGuard','AmyxCommandAccess']:shutil.copy2(r/f'build/{name}.phar',plugins/f'{name}.phar')
    data=s/'plugin_data/AmyxCommandAccess';data.mkdir(parents=True);(data/'config.yml').write_text('require-authentication: false\nallowed-commands: [clan, gm]\ngrant-permissions: [amyxclans.command]\n')
data=s/'plugin_data/AmyXClans';data.mkdir(parents=True,exist_ok=True);(data/'config.yml').write_text('max-channels: 3\n')
(s/'server.properties').write_text(f'server-ip=127.0.0.1\nserver-port={a.port}\nserver-portv6={a.port+1}\nenable-query=off\nlevel-type=FLAT\n')
env=os.environ.copy();env['AMYXCLANS_TESTS']='1'
with (s/'test.log').open('w') as log:
    try:run=subprocess.run([php,'-n','-d','zend.assertions=-1',pm,'--no-wizard','--disable-ansi',f'--data={s}',f'--plugins={plugins}'],cwd=s,env=env,stdout=log,stderr=subprocess.STDOUT,timeout=90)
    except subprocess.TimeoutExpired:raise SystemExit(f'Timeout: {s}/test.log')
result=s/'results.json'
if run.returncode or not result.exists():raise SystemExit(f'Failed: {s}/test.log')
data=json.loads(result.read_text());print(json.dumps(data,ensure_ascii=False));print(f'Log: {s}/test.log')
if data['failures']:raise SystemExit(1)
