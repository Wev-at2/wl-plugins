"""Atualiza as traduções de um plugin WL.

Uso: python -I i18n.py wl-login-guard

1. Lê os textos traduzíveis do código PHP (__, _e, esc_html__, esc_html_e,
   esc_attr__, esc_attr_e com o text domain do plugin) e a Description do
   cabeçalho.
2. Gera languages/<slug>.pot.
3. Junta com languages/<slug>-pt_BR.po: textos novos entram vazios, textos que
   saíram do código são removidos.
4. Compila languages/<slug>-pt_BR.mo.

Sai com código 1 se algum texto ficar sem tradução.
"""
import os
import re
import struct
import sys
from datetime import datetime, timezone

LOCALE = 'pt_BR'
PLURAL = 'nplurals=2; plural=(n > 1);'

base = os.path.dirname(os.path.abspath(__file__))
slug = sys.argv[1].strip('/\\')
src = os.path.join(base, slug)
lang_dir = os.path.join(src, 'languages')
main = os.path.join(src, slug + '.php')

CALL = re.compile(
    r"(?:/\*\s*translators:(?P<tc>.*?)\*/\s*)?"
    r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*"
    r"'(?P<s>(?:[^'\\]|\\.)*)'\s*,\s*'" + re.escape(slug) + r"'\s*\)",
    re.S,
)


def unescape_php(s):
    return s.replace("\\'", "'").replace('\\\\', '\\')


def po_quote(s):
    return '"' + s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n') + '"'


def extract():
    entries = {}  # msgid -> {'refs': [...], 'tc': str|None}
    for root, _, files in os.walk(src):
        for name in sorted(files):
            if not name.endswith('.php'):
                continue
            full = os.path.join(root, name)
            rel = os.path.relpath(full, src).replace(os.sep, '/')
            text = open(full, encoding='utf-8').read()
            for m in CALL.finditer(text):
                msgid = unescape_php(m.group('s'))
                line = text.count('\n', 0, m.start('s')) + 1
                e = entries.setdefault(msgid, {'refs': [], 'tc': None})
                e['refs'].append('%s:%d' % (rel, line))
                if m.group('tc'):
                    e['tc'] = m.group('tc').strip()
    header = open(main, encoding='utf-8').read()
    for field in ('Plugin Name', 'Description'):
        m = re.search(r'^\s*\*\s*' + field + r':\s*(.+)$', header, re.M)
        if m:
            e = entries.setdefault(m.group(1).strip(), {'refs': [], 'tc': None})
            e['refs'].append('%s.php (%s)' % (slug, field))
    return entries


def read_po(path):
    out = {}
    if not os.path.exists(path):
        return out
    msgid = msgstr = None
    cur = None
    for raw in open(path, encoding='utf-8'):
        line = raw.strip()
        if line.startswith('msgid '):
            if msgid is not None:
                out[msgid] = msgstr
            msgid, msgstr, cur = parse(line[6:]), '', 'id'
        elif line.startswith('msgstr '):
            msgstr, cur = parse(line[7:]), 'str'
        elif line.startswith('"') and cur:
            if cur == 'id':
                msgid += parse(line)
            else:
                msgstr += parse(line)
    if msgid is not None:
        out[msgid] = msgstr
    out.pop('', None)
    return out


def parse(q):
    q = q.strip()[1:-1]
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t'}.get(m.group(1), m.group(1)), q)


def version():
    with open(main, encoding='utf-8') as fh:
        return re.search(r'^\s*\*\s*Version:\s*(\S+)', fh.read(), re.M).group(1)


def write_po(path, entries, translations, locale):
    now = datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M+0000')
    lines = [
        '# Copyright (C) %d Wisionary Lab' % datetime.now().year,
        '# This file is distributed under the GPL-2.0-or-later.',
        'msgid ""',
        'msgstr ""',
        po_quote('Project-Id-Version: %s %s\n' % (slug, version())),
        po_quote('Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/%s\n' % slug),
        po_quote('POT-Creation-Date: %s\n' % now),
        po_quote('MIME-Version: 1.0\n'),
        po_quote('Content-Type: text/plain; charset=UTF-8\n'),
        po_quote('Content-Transfer-Encoding: 8bit\n'),
    ]
    if locale:
        lines += [po_quote('Language: %s\n' % locale), po_quote('Plural-Forms: %s\n' % PLURAL)]
    lines.append(po_quote('X-Domain: %s\n' % slug))
    lines.append('')
    for msgid, e in entries.items():
        if e['tc']:
            lines.append('#. translators: ' + e['tc'])
        lines.append('#: ' + ' '.join(e['refs']))
        if '%' in msgid:
            lines.append('#, php-format')
        lines.append('msgid ' + po_quote(msgid))
        lines.append('msgstr ' + po_quote(translations.get(msgid, '') if locale else ''))
        lines.append('')
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(lines))


def write_mo(path, translations):
    header = (
        'Project-Id-Version: %s %s\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n'
        'Content-Transfer-Encoding: 8bit\nLanguage: %s\nPlural-Forms: %s\nX-Domain: %s\n'
    ) % (slug, version(), LOCALE, PLURAL, slug)
    items = sorted([('', header)] + [(k, v) for k, v in translations.items() if v])
    ids = b''
    strs = b''
    offsets = []
    for k, v in items:
        kb, vb = k.encode('utf-8'), v.encode('utf-8')
        offsets.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\0'
        strs += vb + b'\0'
    n = len(items)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    table = b''
    for o1, l1, o2, l2 in offsets:
        table += struct.pack('<2I', l1, keystart + o1)
    for o1, l1, o2, l2 in offsets:
        table += struct.pack('<2I', l2, valuestart + o2)
    out = struct.pack('<7I', 0x950412DE, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0) + table + ids + strs
    with open(path, 'wb') as fh:
        fh.write(out)


entries = extract()
os.makedirs(lang_dir, exist_ok=True)
po_path = os.path.join(lang_dir, '%s-%s.po' % (slug, LOCALE))
old = read_po(po_path)
translations = {k: old.get(k, '') for k in entries}

write_po(os.path.join(lang_dir, slug + '.pot'), entries, {}, None)
write_po(po_path, entries, translations, LOCALE)
write_mo(os.path.join(lang_dir, '%s-%s.mo' % (slug, LOCALE)), translations)

missing = [k for k, v in translations.items() if not v]
removed = [k for k in old if k not in entries]
print('%s: %d textos, %d traduzidos' % (slug, len(entries), len(entries) - len(missing)))
for k in removed:
    print('  removido do .po: ' + k)
for k in missing:
    print('  sem tradução: ' + k)
sys.exit(1 if missing else 0)
