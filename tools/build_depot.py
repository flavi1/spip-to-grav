#!/usr/bin/env python3
"""Construit le zip du plugin et le fichier archives.xml (dépôt SVP).

Version automatique : <base de paquet.xml>.<nombre de commits>, injectée uniquement
dans le zip (le dépôt git n'est jamais modifié).
Usage : build_depot.py <dossier_sortie> <url_publique_du_depot> <nb_commits> <timestamp_dernier_commit>
"""
import hashlib, os, re, sys, time, zipfile
from xml.sax.saxutils import escape

out, url, n, ts = sys.argv[1], sys.argv[2].rstrip('/') + '/', int(sys.argv[3]), int(sys.argv[4])
sha = sys.argv[5] if len(sys.argv) > 5 else 'local'
racine = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
os.makedirs(out, exist_ok=True)

paquet = open(os.path.join(racine, 'paquet.xml'), encoding='utf-8').read()
prefix_dir = 'spip_to_grav'
m = re.search(r'\bversion="(\d+)\.(\d+)\.\d+"', paquet)
version = f'{m.group(1)}.{m.group(2)}.{n}'
paquet = paquet.replace(m.group(0), f'version="{version}"', 1)

EXCLU = {'.git', '.github', 'tools', 'tests', 'README.md'}
nom_zip = f'{prefix_dir}-{version}.zip'
for f in os.listdir(out):  # ne garder que la dernière archive
    if f.endswith('.zip'):
        os.remove(os.path.join(out, f))
with zipfile.ZipFile(os.path.join(out, nom_zip), 'w', zipfile.ZIP_DEFLATED) as z:
    for dp, dn, fn in os.walk(racine):
        dn[:] = [d for d in dn if d not in EXCLU]
        for f in fn:
            p = os.path.join(dp, f)
            rel = os.path.relpath(p, racine)
            if rel in EXCLU:
                continue
            if rel == 'paquet.xml':
                z.writestr(f'{prefix_dir}/paquet.xml', paquet)
                z.writestr(f'{prefix_dir}/BUILD', f'{version} {sha[:7]} {time.strftime("%Y-%m-%d %H:%M", time.gmtime(ts))} UTC\n')
            else:
                z.write(p, f'{prefix_dir}/{rel}')
taille = os.path.getsize(os.path.join(out, nom_zip))

xml = f'''<depot>
	<titre>SPIP vers Grav</titre>
	<descriptif>Dépôt du plugin spip_to_grav</descriptif>
	<type>git</type>
	<url_serveur>https://github.com/</url_serveur>
	<url_archives>{url.rstrip('/')}</url_archives>
</depot>
<archives>
	<archive id="{prefix_dir}" dtd="paquet">
		<zip>
			<file>{nom_zip}</file>
			<size>{taille}</size>
			<date>{ts}</date>
			<last_commit>{ts}</last_commit>
			<source>spip_to_grav</source>
		</zip>
		{paquet.strip()}
	</archive>
</archives>
'''
open(os.path.join(out, 'archives.xml'), 'w', encoding='utf-8').write(xml)
open(os.path.join(out, 'index.html'), 'w').write(f'<p>Dépôt SVP : <a href="archives.xml">archives.xml</a> (version {version})</p>')
print(version, nom_zip, taille)
