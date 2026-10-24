#!/usr/bin/env python3
"""Package only source/config/docs/tools and the exact tested PHAR files."""
from pathlib import Path
import hashlib,zipfile
r=Path(__file__).resolve().parent.parent
out=r/'release';out.mkdir(exist_ok=True)
for name in ['AmyxClans','AmyxEconomy','ChatPerms','AmyBuilderGuard','AmyxCommandAccess','FormAPI']:
    data=(r/f'build/{name}.phar').read_bytes();(out/f'{name}.phar').write_bytes(data)
source=out/'AmyxClans-v2.0.0-source.zip'
files=[]
for directory in ['src','resources','extras','docs','tools','tests','.github']:
    files.extend(p for p in (r/directory).rglob('*') if p.is_file() and '__pycache__' not in p.parts)
files.extend(r/f for f in ['plugin.yml','README.md','LICENSE.txt','THIRD_PARTY.md','.gitignore'])
with zipfile.ZipFile(source,'w',zipfile.ZIP_DEFLATED) as z:
    for p in sorted(files):z.write(p,'AmyxClans/'+str(p.relative_to(r)))
with zipfile.ZipFile(out/'AmyxClans-v2.0.0-install.zip','w',zipfile.ZIP_DEFLATED) as z:
    for name in ['AmyxClans','AmyxEconomy','ChatPerms','FormAPI']:z.write(out/f'{name}.phar',f'plugins/{name}.phar')
    for name in ['AmyBuilderGuard','AmyxCommandAccess']:z.write(out/f'{name}.phar',f'optional/{name}.phar')
    for name in ['README.md','THIRD_PARTY.md','LICENSE.txt']:z.write(r/name,name)
    for name in ['ARENAS.md','TESTING.md','AUDIT.md']:z.write(r/f'docs/{name}',f'docs/{name}')
    z.write(r/'extras/FormAPI/LICENSE','licenses/FormAPI-LGPL-3.0.txt')
assets=sorted(p for p in out.iterdir() if p.is_file() and p.name!='SHA256SUMS')
(out/'SHA256SUMS').write_text(''.join(hashlib.sha256(p.read_bytes()).hexdigest()+'  '+p.name+'\n' for p in assets))
print('\n'.join(f'{p.name}: {p.stat().st_size}' for p in assets))
