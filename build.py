"""Gera o .zip instalável de um plugin WL e atualiza o manifest.json.

Uso: python -I build.py wl-login-guard
Saída: dist/<slug>-<versão>.zip, com a pasta <slug>/ na raiz do zip.

O manifest.json é lido pelo WL Updater nos sites. Depois de gerar, faça
commit e push do zip e do manifest para a versão chegar aos sites.
"""
import hashlib
import json
import os
import re
import sys
import zipfile

REPO_RAW = 'https://raw.githubusercontent.com/Wev-at2/wl-plugins/main/'
REPO_URL = 'https://github.com/Wev-at2/wl-plugins'

base = os.path.dirname(os.path.abspath(__file__))
slug = sys.argv[1].strip('/\\')
src = os.path.join(base, slug)
main = os.path.join(src, slug + '.php')


def header(text, field):
    m = re.search(r'^\s*\*?\s*' + re.escape(field) + r':\s*(.+?)\s*$', text, re.M)
    return m.group(1) if m else ''


php = open(main, encoding='utf-8').read()
readme = open(os.path.join(src, 'readme.txt'), encoding='utf-8').read()
version = header(php, 'Version')

os.makedirs(os.path.join(base, 'dist'), exist_ok=True)
name = '%s-%s.zip' % (slug, version)
out = os.path.join(base, 'dist', name)
tmp = out + '.tmp'

with zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(src):
        dirs[:] = sorted(d for d in dirs if not d.startswith('.') and d != '__pycache__')
        for fname in sorted(files):
            full = os.path.join(root, fname)
            rel = os.path.relpath(full, src).replace(os.sep, '/')
            # Data fixa: o mesmo código gera sempre o mesmo zip e o mesmo SHA-256.
            info = zipfile.ZipInfo(slug + '/' + rel, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            with open(full, 'rb') as fh:
                z.writestr(info, fh.read())

sha = hashlib.sha256(open(tmp, 'rb').read()).hexdigest()

# Um zip publicado nunca muda de conteúdo: o CDN do GitHub guarda cópias e o
# WL Updater recusaria o pacote pelo checksum. Código novo pede versão nova.
manifest_path = os.path.join(base, 'manifest.json')
manifest = json.load(open(manifest_path, encoding='utf-8')) if os.path.exists(manifest_path) else {}
published = manifest.get(slug, {})
if published.get('version') == version and published.get('sha256') != sha and '--force' not in sys.argv:
    os.remove(tmp)
    sys.exit('%s %s já está publicado com outro conteúdo. Aumente a Version (ou use --force antes do push).' % (slug, version))
os.replace(tmp, out)

# Descrição curta: primeira linha de texto depois do cabeçalho do readme.
short = ''
for block in readme.split('\n\n')[1:]:
    block = block.strip()
    if block and not block.startswith('=='):
        short = block
        break

manifest[slug] = {
    'name': header(php, 'Plugin Name'),
    'version': version,
    'package': REPO_RAW + 'dist/' + name,
    'sha256': sha,
    'requires': header(php, 'Requires at least'),
    'requires_php': header(php, 'Requires PHP'),
    'tested': header(readme, 'Tested up to'),
    'homepage': REPO_URL,
    'description': short,
}
with open(manifest_path, 'w', encoding='utf-8', newline='\n') as fh:
    json.dump(dict(sorted(manifest.items())), fh, indent=2, ensure_ascii=False)
    fh.write('\n')

print(out)
print('sha256', sha)
