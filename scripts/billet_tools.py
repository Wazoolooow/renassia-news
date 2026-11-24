#!/usr/bin/env python3
"""Outils du workflow GitHub « Billet du jour » (bibliothèque standard uniquement).

  billet_tools.py today                 -> date du jour en Israël (YYYY-MM-DD)
  billet_tools.py live                  -> date du billet actuellement en ligne
  billet_tools.py validate [fichier]    -> contrôle billet.json pour la date du jour
  billet_tools.py post [--dry-run]      -> publie billet.json via billet.php (HTTPS) puis vérifie
  billet_tools.py ftp                   -> secours: envoi FTP (variable FTP_CREDENTIALS "user:mot_de_passe")

Variables: BILLET_CODE (code du POST de billet.php), FTP_CREDENTIALS (secours FTP).
"""
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from collections import Counter
from datetime import datetime
from zoneinfo import ZoneInfo

SITE = 'https://renassia.com/news/billet.php'
FILE = 'billet.json'


def today():
    return datetime.now(ZoneInfo('Asia/Jerusalem')).strftime('%Y-%m-%d')


def fetch_live():
    req = urllib.request.Request(SITE + '?x=%d' % time.time(), headers={'User-Agent': 'billet-ci/1.0', 'Cache-Control': 'no-cache'})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode('utf-8'))


def paras(h):
    return len(re.findall(r'<p>', h))


def digits(h):
    t = re.sub(r'<[^>]+>', ' ', h).replace('&nbsp;', ' ')
    t = re.sub(r'(?<=\d)[ ,](?=\d{3}\b)', '', t)
    t = re.sub(r'(?<=\d),(?=\d)', '.', t)
    return Counter(re.findall(r'\d+(?:\.\d+)?', t))


def validate(path=FILE):
    errors, warnings = [], []
    try:
        d = json.load(open(path, encoding='utf-8'))
    except Exception as e:
        return ['JSON illisible: %s' % e], []
    want = 'hal_billet_v3_' + today()
    if d.get('date') != want:
        errors.append("date=%r, attendu %r" % (d.get('date'), want))
    for k in ('html', 'html_he', 'html_en'):
        if not d.get(k) or len(d[k]) < 1500:
            errors.append('%s vide ou trop court' % k)
    for k in ('html', 'html_he', 'html_en'):
        h = d.get(k, '')
        if 'editorial-closing' not in h or 'editorial-header' not in h:
            errors.append('%s: structure editorial-header/closing manquante' % k)
        if re.search(r'TODO|XXX|\[\.\.\.\]|lorem', h, re.I):
            errors.append('%s: texte provisoire détecté' % k)
    pf, ph, pe = (paras(d.get(k, '')) for k in ('html', 'html_he', 'html_en'))
    if not (pf == ph == pe):
        errors.append('nombre de paragraphes différent: fr=%d he=%d en=%d' % (pf, ph, pe))
    if pf < 3:
        errors.append('moins de 3 paragraphes (%d)' % pf)
    # chiffres FR vs EN: avertissement seulement (mots/chiffres peuvent varier)
    a, b = digits(d.get('html', '')), digits(d.get('html_en', ''))
    diff = (a - b) + (b - a)
    if sum(diff.values()) > 6:
        warnings.append('écart de chiffres FR/EN: %s' % dict(diff))
    return errors, warnings


def cmd_validate(args):
    errors, warnings = validate(args[0] if args else FILE)
    for w in warnings:
        print('AVERTISSEMENT:', w)
    for e in errors:
        print('ERREUR:', e)
    if errors:
        sys.exit(1)
    print('OK: billet valide pour', today())


def cmd_post(args):
    dry = '--dry-run' in args
    errors, _ = validate()
    if errors:
        print('Refus de publier: billet invalide:', errors)
        sys.exit(1)
    d = json.load(open(FILE, encoding='utf-8'))
    code = os.environ.get('BILLET_CODE', '')
    if not code and not dry:
        print('BILLET_CODE absent')
        sys.exit(2)
    payload = dict(d)
    payload['password'] = code
    body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
    if dry:
        print('dry-run: %d octets seraient envoyés à %s' % (len(body), SITE))
        return
    last = None
    for attempt in range(1, 4):
        try:
            req = urllib.request.Request(SITE, data=body, method='POST',
                                         headers={'Content-Type': 'application/json; charset=utf-8', 'User-Agent': 'billet-ci/1.0'})
            with urllib.request.urlopen(req, timeout=60) as r:
                resp = r.read().decode('utf-8', 'replace')
            print('POST ->', resp[:200])
            time.sleep(2)
            live = fetch_live()
            if live.get('date') == d['date']:
                print('Vérifié en ligne:', live['date'])
                return
            last = 'date en ligne = %r' % live.get('date')
        except Exception as e:
            last = repr(e)
        print('tentative %d échouée: %s' % (attempt, last))
        time.sleep(20)
    print('Échec de la publication HTTPS:', last)
    sys.exit(1)


def cmd_ftp(_args):
    cred = os.environ.get('FTP_CREDENTIALS', '')
    if not cred:
        print('FTP_CREDENTIALS absent: secours FTP ignoré')
        sys.exit(2)
    for attempt in range(1, 4):
        r = subprocess.run(['curl', '--ftp-pasv', '-T', FILE, 'ftp://ftp.caesarea.tv/news/billet.json',
                            '--user', cred, '--connect-timeout', '30', '--silent', '--show-error'],
                           capture_output=True, text=True)
        if r.returncode == 0:
            time.sleep(2)
            live = fetch_live()
            want = json.load(open(FILE, encoding='utf-8'))['date']
            if live.get('date') == want:
                print('FTP OK, vérifié en ligne:', want)
                return
        print('tentative FTP %d échouée (code %s)' % (attempt, r.returncode), r.stderr[:200])
        time.sleep(20)
    sys.exit(1)


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    rest = sys.argv[2:]
    if cmd == 'today':
        print(today())
    elif cmd == 'live':
        print(fetch_live().get('date', ''))
    elif cmd == 'validate':
        cmd_validate(rest)
    elif cmd == 'post':
        cmd_post(rest)
    elif cmd == 'ftp':
        cmd_ftp(rest)
    else:
        print(__doc__)
        sys.exit(64)
