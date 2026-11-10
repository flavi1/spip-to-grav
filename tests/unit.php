<?php
/** Tests unitaires : URL, langues, index, installation de Grav, runtime du thème. */
require __DIR__ . '/lib.php';
require __DIR__ . '/stubs.php';
include_spip('inc/sptg_export');
t_charger_runtime();

use Grav\Theme\Sptg\Runtime;

// ---------------------------------------------------------------- clés legacy
$cas = array(
	array('/spip.php', 'article10'),
	array('spip.php', 'article10&var_mode=calcul'),
	array('/Spip.php', 'ARTICLE10&utm_source=x&fbclid=1'),
	array('/spip.php', 'page=article&id_article=10&lang=en'),
	array('/spip.php', 'id_article=10&page=article'),
	array('/rubrique2.html', ''),
	array('./rubrique2.html/', ''),
	array('/Mon%20Article', ''),
	array('/', ''),
	array('', ''),
	array('/a/b/', 'x=1&amp;y=2'),
	array('/é', 'PHPSESSID=abc&a=%C3%A9'),
);
foreach ($cas as $i => $c) {
	t_eq(Runtime::cleLegacy($c[0], $c[1]), sptg_cle_legacy($c[0], $c[1]), 'cle legacy identique #' . $i);
}
t_eq(sptg_cle_legacy('spip.php', 'article10&var_mode=calcul'), '/spip.php?article10', 'cle : var_ ignorée');
t_eq(sptg_cle_legacy('/spip.php', 'id_article=10&page=article'), sptg_cle_legacy('/spip.php', 'page=article&id_article=10'), 'cle : ordre indifférent');
t_eq(sptg_cle_legacy('/Rubrique2.HTML/'), '/rubrique2.html', 'cle : casse et slash');
t_eq(sptg_cle_legacy('', ''), '/', 'cle : racine');
t_eq(Runtime::nomLegacy('/x'), sptg_nom_legacy('/x'), 'nom legacy identique');

// ---------------------------------------------------------------- routes
t_eq(sptg_route_depuis_chemin('rubrique2.html'), '/rubrique2', 'chemin : .html');
t_eq(sptg_route_depuis_chemin('/a//b/c.php/'), '/a/b/c', 'chemin : doubles slashs et .php');
t_eq(sptg_route_depuis_chemin(''), '', 'chemin vide');
t_eq(sptg_route_depuis_chemin('a.b/c'), '/a.b/c', 'chemin : point intermédiaire conservé');

t_eq(sptg_route_publique('spip', 'article', 'x', '/r/x', '/r', array('path' => 'r/x.html')), '/r/x', 'schéma spip : chemin SPIP');
t_eq(sptg_route_publique('spip', 'article', 'x', '/r/x', '/r', array('query' => 'spip.php?article1')), '/r/x', 'schéma spip : repli sur dossier');
t_eq(sptg_route_publique('spip', 'article', 'x', '/r/x', '/r', null), '/r/x', 'schéma spip : sans URL');
t_eq(sptg_route_publique('hierarchique', 'article', 'x', '/r/x', '/r', null), '/r/x', 'hiérarchique');
t_eq(sptg_route_publique('hierarchique_type', 'article', 'x', '/r/x', '/r', null), '/r/article/x', 'hiérarchique typé : article');
t_eq(sptg_route_publique('hierarchique_type', 'rubrique', 'r', '/r', '', null), '/r', 'hiérarchique typé : rubrique');
t_eq(sptg_route_publique('plat_type', 'breve', 'b', '/b', '', null), '/breve/b', 'plat typé');

// ---------------------------------------------------------------- anciennes URL
$a = sptg_anciennes_urls('article', 10, array('query' => 'spip.php?article10'), '/r/x');
t_eq(count($a), 1, 'anciennes : URL à paramètres');
t_eq(array($a[0]['chemin'], $a[0]['requete']), array('/spip.php', 'article10'), 'anciennes : découpage chemin/requête');
$a = sptg_anciennes_urls('article', 10, array('path' => 'r/x.html'), '/r/x');
t_eq(count($a), 1, 'anciennes : chemin SPIP différent de la route (.html)');
$a = sptg_anciennes_urls('article', 10, array('path' => 'r/x'), '/r/x');
t_eq($a, array(), 'anciennes : URL identique à la route → rien');
$a = sptg_anciennes_urls('article', 10, array('path' => 'r/nouveau.html'), '/r/nouveau', array('nouveau', 'ancien', 'ancien', ''));
t_eq(array_map(function ($x) {
	return $x['chemin'] . ($x['approx'] ? '~' : '');
}, $a), array('/r/nouveau.html', '/r/ancien.html~'), 'anciennes : historique arbo reconstruit, dédoublonné');
$a = sptg_anciennes_urls('article', 10, null, '/r/x', array('ancien'));
t_eq($a, array(), 'anciennes : sans URL courante pas de reconstruction');


// arbo : url avec préfixe de type, id_parent, déplacement d'objet, préfixe de langue
$hist = array(
	array('url' => 'article/nouveau', 'id_parent' => 2, 'segments' => 2, 'langue' => '', 'perma' => 0),
	array('url' => 'article/ancien', 'id_parent' => 2, 'segments' => 2, 'langue' => '', 'perma' => 0),
	array('url' => 'article/avant-deplacement', 'id_parent' => 7, 'segments' => 2, 'langue' => '', 'perma' => 0),
	array('url' => 'racine', 'id_parent' => 0, 'segments' => 1, 'langue' => '', 'perma' => 0),
);
$parents = array(2 => 'actu/sous', 7 => 'autre');
$a = sptg_anciennes_urls('article', 10, array('path' => 'actu/sous/article/nouveau.html'), '/actu/sous/article/nouveau', $hist, function ($i) use ($parents) {
	return $parents[$i] ?? null;
});
t_eq(array_map(function ($x) {
	return $x['chemin'] . ($x['approx'] ? '~' : '');
}, $a), array('/actu/sous/article/nouveau.html', '/actu/sous/article/ancien.html', '/autre/article/avant-deplacement.html', '/racine.html'), 'arbo : préfixe de type, id_parent, déplacement');
$a = sptg_anciennes_urls('article', 10, array('path' => 'actu/sous/article/nouveau.html'), '/actu/sous/article/nouveau', $hist);
t_eq(array_map(function ($x) {
	return $x['chemin'] . ($x['approx'] ? '~' : '');
}, $a), array('/actu/sous/article/nouveau.html', '/actu/sous/article/ancien.html~', '/actu/sous/article/avant-deplacement.html~', '/racine.html'), 'arbo : sans parent connu → approximation');
$a = sptg_anciennes_urls('article', 10, array('path' => 'en/actu/article/new'), '/en/actu/article/new', array(
	array('url' => 'article/new', 'id_parent' => 1, 'langue' => 'en'),
	array('url' => 'article/old', 'id_parent' => 1, 'langue' => 'en'),
	array('url' => 'article/ancien', 'id_parent' => 1, 'langue' => 'fr'),
), function ($i) {
	return $i == 1 ? 'actu' : null;
}, array('fr', 'en'));
t_eq(array_column($a, 'chemin'), array('/en/actu/article/old', '/fr/actu/article/ancien'), 'arbo multilingue : préfixe de langue par ligne');
t_eq(sptg_chemin_decouper('fr/a/b.html', array('fr', 'en')), array('.html', 'fr', 'a/b'), 'découpage chemin : langue + terminaison');
t_eq(sptg_chemin_decouper('a/b/', array('fr')), array('/', '', 'a/b'), 'découpage chemin : rubrique');
t_eq(sptg_chemin_decouper('fr', array('fr')), array('', '', 'fr'), 'découpage chemin : un seul segment = pas une langue');

sptg_scenario(array('spip_urls' => null));
t_eq(sptg_urls_historiques('article', 1), array(), 'historique : table absente');

// ---------------------------------------------------------------- langues
t_ok(sptg_contient_multi('a <multi>[fr]b[en]c</multi>'), 'multi détecté');
t_ok(!sptg_contient_multi('a <b>b</b>'), 'multi non détecté');
t_ok(!sptg_contient_multi(array()), 'multi : non-chaîne');
t_eq(sptg_langues_du_multi('<multi>[fr]a[en]b</multi>', array('fr', 'en', 'de')), array('fr', 'en'), 'langues du multi');
t_eq(sptg_langues_du_multi('<multi>[fr]a</multi> <multi>[de]x</multi>', array('fr', 'en', 'de')), array('fr', 'de'), 'langues : plusieurs blocs');
t_eq(sptg_langues_du_multi('sans', array('fr')), array(), 'langues : aucune');

$conf = array();
$g = sptg_groupes_trad(array(
	array('id_article' => 1, 'id_trad' => 1, 'lang' => 'fr'),
	array('id_article' => 2, 'id_trad' => 1, 'lang' => 'en'),
	array('id_article' => 3, 'id_trad' => 1, 'lang' => 'en'),
	array('id_article' => 4, 'id_trad' => 0, 'lang' => 'fr'),
	array('id_article' => 5, 'id_trad' => 5, 'lang' => 'de'),
), 'id_article', $conf);
t_eq($g[1], array('fr' => 1, 'en' => 2), 'trad : groupe fr/en (plus petit id gardé)');
t_eq($g[2], $g[1], 'trad : même groupe vu de la traduction');
t_eq(isset($g[4]), false, 'trad : id_trad 0 ignoré');
t_eq(isset($g[3]), false, 'trad : doublon de langue exclu');
t_eq($conf, array(array(2, 3, 'en')), 'trad : conflit signalé');
t_eq($g[5], array('de' => 5), 'trad : groupe d\'un seul');
t_eq(sptg_groupes_trad(array(array('id_article' => 1)), 'id_article'), array(), 'trad : colonnes absentes');

sptg_scenario(array('meta' => array('langue_site' => 'fr', 'langues_multilingue' => 'fr,en', 'multi_objets' => 'spip_articles,spip_rubriques', 'gerer_trad_objets' => 'spip_articles')));
$c = sptg_config_langues();
t_eq($c['langue_site'], 'fr', 'config langues : site');
t_eq($c['langues'], array('fr', 'en'), 'config langues : liste');
t_eq($c['multi_objets'], array('spip_articles', 'spip_rubriques'), 'config langues : multi_objets');

// ---------------------------------------------------------------- index
$racine = t_tmp('index');
sptg_index_ecrire($racine, array('type' => 'article', 'id' => 7, 'lang' => 'fr', 'trad' => array('fr' => 7, 'en' => 8), 'dossier' => '01.a/02.b', 'langues' => array()));
$e = t_json("$racine/user/data/spip-id/article/7.json");
t_eq($e['dossier'], '01.a/02.b', 'index : dossier');
t_eq($e['trad'], array('fr' => 7, 'en' => 8), 'index : trad');
t_ok(!isset($e['langues']), 'index : pas de langues si vide');
sptg_index_ecrire($racine, array('type' => 'rubrique', 'id' => 1, 'lang' => '', 'trad' => array(), 'dossier' => 'x'));
$e = t_json("$racine/user/data/spip-id/rubrique/1.json");
t_eq($e['lang'], null, 'index : lang null');
t_eq($e['trad'], array(), 'index : trad vide = objet');
sptg_index_legacy_url($racine, '/spip.php', 'article7&var_mode=x', 'article', 7);
$f = "$racine/user/data/spip-id/_legacy/urls/" . sptg_nom_legacy('/spip.php?article7');
t_eq(t_json($f), array('url' => '/spip.php?article7', 'type' => 'article', 'id' => 7), 'index : legacy url');
sptg_index_vider($racine);
t_ok(!is_file("$racine/user/data/spip-id/article/7.json"), 'index : vidé');

// ---------------------------------------------------------------- installation de Grav
t_eq(sptg_grav_version_depuis_defines("<?php\ndefine('GRAV_VERSION', '1.7.49');"), '1.7.49', 'version defines');
t_eq(sptg_grav_version_depuis_defines('define("GRAV_VERSION","2.0.0-rc.1");'), '2.0.0-rc.1', 'version defines rc');
t_eq(sptg_grav_version_depuis_defines('rien'), null, 'version defines absente');
t_eq(sptg_grav_version_depuis_tag('v2.0.15'), '2.0.15', 'tag v');
t_eq(sptg_grav_version_depuis_tag('1.7.49'), '1.7.49', 'tag nu');
t_eq(sptg_grav_version_depuis_tag('grav-2.0.0'), '2.0.0', 'tag préfixé');
t_eq(sptg_grav_majeure('2.0.15'), 2, 'majeure');
t_eq(sptg_grav_version_installee('/inexistant'), null, 'installée : absente');

function t_zip($fichier, array $entrees) {
	$z = new ZipArchive();
	$z->open($fichier, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	foreach ($entrees as $n => $c) {
		if (substr($n, -1) === '/') {
			$z->addEmptyDir(rtrim($n, '/'));
		} else {
			$z->addFromString($n, $c);
		}
	}
	$z->close();
}
$dir = t_tmp('zip');
t_zip("$dir/a.zip", array('grav-admin/' => '', 'grav-admin/index.php' => '<?php', 'grav-admin/user/' => '', 'grav-admin/user/pages/x.md' => 'x', 'grav-admin/system/defines.php' => "define('GRAV_VERSION', '9.9.9');"));
t_eq(sptg_zip_extraire_sans_racine("$dir/a.zip", "$dir/out"), 3, 'zip : fichiers extraits');
t_ok(is_file("$dir/out/index.php") and is_file("$dir/out/user/pages/x.md"), 'zip : racine retirée');
t_eq(sptg_grav_version_installee("$dir/out"), '9.9.9', 'zip : version lue');
t_ok(sptg_detecter_grav("$dir/out"), 'zip : Grav détecté');
t_zip("$dir/b.zip", array('index.php' => 'a', 'user/x' => 'b'));
t_eq(sptg_zip_extraire_sans_racine("$dir/b.zip", "$dir/out2"), 2, 'zip : sans racine commune');
t_ok(is_file("$dir/out2/index.php"), 'zip sans racine : fichier');
t_zip("$dir/c.zip", array('r/ok.txt' => 'a', 'r/../../evil.txt' => 'b'));
t_eq(sptg_zip_extraire_sans_racine("$dir/c.zip", "$dir/out3"), -1, 'zip : traversée refusée');
t_ok(!is_file("$dir/evil.txt"), 'zip : rien écrit hors du dossier');
t_eq(sptg_zip_extraire_sans_racine("$dir/inexistant.zip", "$dir/out4"), -1, 'zip : fichier absent');

// ---------------------------------------------------------------- runtime du thème
$racine = t_tmp('runtime');
$data = "$racine/user/data";
$pages = array(
	'01.actu' => array('type' => 'rubrique', 'id' => 1, 'lang' => 'fr', 'url' => '/actu', 'title' => 'Actu'),
	'01.actu/02.a' => array('type' => 'article', 'id' => 10, 'lang' => 'fr', 'url' => '/actu/a', 'title' => 'A'),
	'03.b' => array('type' => 'article', 'id' => 11, 'lang' => 'en', 'url' => '/b', 'title' => 'B'),
);
$rt = function () use (&$pages, $data) {
	return new Runtime($data, function ($d) use (&$pages) {
		return $pages[$d] ?? null;
	}, function () use (&$pages) {
		foreach ($pages as $d => $p) {
			yield array('dossier' => $d) + $p;
		}
	}, function ($p) {
		return array('fr' => $p['url'], 'en' => '/en' . $p['url']);
	});
};
$ecrire = function ($type, $id, array $d) use ($data) {
	@mkdir("$data/spip-id/$type", 0775, true);
	file_put_contents("$data/spip-id/$type/$id.json", json_encode(array('type' => $type, 'id' => $id) + $d));
};
$ecrire('article', 10, array('lang' => 'fr', 'trad' => array('fr' => 10, 'en' => 11), 'dossier' => '01.actu/02.a'));
$ecrire('article', 11, array('lang' => 'en', 'trad' => array('fr' => 10, 'en' => 11), 'dossier' => '03.b'));
$ecrire('rubrique', 1, array('lang' => 'fr', 'trad' => array(), 'dossier' => '01.actu', 'langues' => array('fr', 'en')));

$p = $rt()->resoudre('article', 10);
t_eq($p['url'] ?? null, '/actu/a', 'runtime : résolution directe');
t_ok(!is_dir("$data/spip-id/_legacy/article"), 'runtime : pas de réparation inutile');
t_eq($rt()->resoudre('article', 999), null, 'runtime : objet inconnu');

$trad = $rt()->traductions('article', 10, 'fr');
t_eq(array_map(function ($v) {
	return $v['lang'] . '=' . $v['url'] . ($v['courant'] ? '*' : '');
}, $trad), array('fr=/actu/a*', 'en=/b'), 'runtime : traductions id_trad');
$trad = $rt()->traductions('rubrique', 1, 'en');
t_eq(array_map(function ($v) {
	return $v['lang'] . '=' . $v['url'] . ($v['courant'] ? '*' : '');
}, $trad), array('fr=/actu', 'en=/en/actu*'), 'runtime : langues du dossier (<multi>)');

$h = $rt()->alternates('article', 10, 'fr', 'fr');
t_ok(strpos($h, 'hreflang="fr" href="/actu/a"') !== false and strpos($h, 'hreflang="en" href="/b"') !== false and strpos($h, 'hreflang="x-default" href="/actu/a"') !== false, 'runtime : alternates + x-default', $h);
$ecrire('article', 12, array('lang' => 'fr', 'trad' => array(), 'dossier' => 'x'));
$pages['x'] = array('type' => 'article', 'id' => 12, 'lang' => 'fr', 'url' => '/x', 'title' => 'X');
t_eq($rt()->alternates('article', 12, 'fr', 'fr'), '', 'runtime : pas d\'alternate pour une seule version');

// auto-réparation : le dossier a été renommé
$pages['01.actu/05.a-renomme'] = $pages['01.actu/02.a'];
unset($pages['01.actu/02.a']);
$p = $rt()->resoudre('article', 10);
t_eq($p['dossier'] ?? null, '01.actu/05.a-renomme', 'réparation : nouveau dossier trouvé');
t_eq(t_json("$data/spip-id/article/10.json")['dossier'], '01.actu/05.a-renomme', 'réparation : entrée réécrite');
t_eq(t_json("$data/spip-id/article/10.json")['trad'], array('fr' => 10, 'en' => 11), 'réparation : trad conservée');
t_eq(t_json("$data/spip-id/_legacy/article/10.json")['dossier'], '01.actu/02.a', 'réparation : ancienne entrée dans _legacy');

// auto-réparation : le dossier pointe vers une autre page
$ecrire('article', 11, array('lang' => 'en', 'trad' => array(), 'dossier' => '01.actu'));
$p = $rt()->resoudre('article', 11);
t_eq($p['dossier'] ?? null, '03.b', 'réparation : page d\'un autre (type,id)');
t_eq(t_json("$data/spip-id/_legacy/article/11.json")['dossier'], '01.actu', 'réparation : mauvaise entrée archivée');

// anciennes URL
sptg_index_legacy_url($racine, '/spip.php', 'article10', 'article', 10);
$p = $rt()->pourAncienneUrl('/spip.php', 'var_mode=calcul&article10');
t_eq($p['url'] ?? null, '/actu/a', 'legacy : ancienne URL résolue');
t_eq($rt()->pourAncienneUrl('/spip.php', 'article77'), null, 'legacy : inconnue');
t_eq($rt()->pourAncienneUrl('/rien', ''), null, 'legacy : chemin inconnu');

// formes toujours acceptées par SPIP (nettoyer_url_page), sans entrée d'index
$compat = array(
	array('/article10', '', 10), array('/x/y/article10.html', '', 10), array('/spip.php', 'article10', 10), array('/', 'article10=&var_mode=calcul', 10),
	array('/article.php3', 'id_article=10', 10), array('/spip.php', 'page=article&id_article=10', 10), array('/spip.php', 'id_article=10&page=article', 10),
);
foreach ($compat as $c) {
	$p = $rt()->pourAncienneUrl($c[0], $c[1]);
	t_eq($p['id'] ?? null, $c[2], 'compat SPIP : ' . $c[0] . '?' . $c[1]);
}
t_eq($rt()->pourAncienneUrl('/article99', ''), null, 'compat SPIP : id inconnu');
t_eq($rt()->pourAncienneUrl('/mot10', ''), null, 'compat SPIP : type non indexé');
t_eq($rt()->pourAncienneUrl('/spip.php', 'page=article&id_article=abc'), null, 'compat SPIP : id invalide');

// ---------------------------------------------------------------- gabarits (templates de templates)
include_spip('inc/sptg_theme');
t_eq(sptg_gabarit_texte("a @@X@@ b\n\t@@BLOC@@\nfin", array('X' => 'x', 'BLOC' => "l1\nl2\n")), "a x b\nl1\nl2\nfin", 'gabarit : en ligne et bloc');
t_eq(sptg_gabarit_texte("a\n\t@@BLOC@@\nb", array('BLOC' => '')), "a\nb", 'gabarit : bloc vide → ligne supprimée');
t_eq(sptg_gabarit_texte('@@A@@', array('A' => '@@B@@', 'B' => 'x')), '@@B@@', 'gabarit : valeurs non ré-interprétées');
t_eq(sptg_gabarit_restes('un @@RESTE@@ et {{ twig }} et @ seul'), 1, 'gabarit : restes détectés');
try {
	sptg_gabarit_texte('@@MANQUE@@', array());
	t_ok(false, 'gabarit : variable manquante → exception');
} catch (RuntimeException $e) {
	t_ok(strpos($e->getMessage(), 'MANQUE') !== false, 'gabarit : variable manquante → exception');
}
try {
	sptg_gabarit('inexistant/x.twig', array());
	t_ok(false, 'gabarit : introuvable → exception');
} catch (RuntimeException $e) {
	t_ok(true, 'gabarit : introuvable → exception');
}

// le socle complet se génère sans marqueur restant, PHP valide, balises Twig équilibrées
$dest = t_tmp('socle') . '/';
$ecrits = sptg_generer_socle($dest, 'mon-theme', array('CLASSE' => 'MonTheme', 'LANGUE_SITE' => 'fr'));
t_ok(in_array('mon-theme.php', $ecrits) and in_array('sptg_runtime.php', $ecrits), 'socle : fichiers PHP générés', implode(' ', $ecrits));
t_ok(in_array('templates/partials/base.html.twig', $ecrits) and in_array('templates/home.html.twig', $ecrits), 'socle : fichiers Twig générés', implode(' ', $ecrits));
t_ok(!preg_grep('/\.tpl$|__slug__/', $ecrits), 'socle : ni .tpl ni __slug__ dans les noms');
foreach ($ecrits as $rel) {
	$txt = file_get_contents($dest . $rel);
	t_eq(sptg_gabarit_restes($txt), 0, "socle : pas de marqueur restant dans $rel");
	if (substr($rel, -4) === '.php') {
		exec('php -l ' . escapeshellarg($dest . $rel) . ' 2>&1', $o, $code);
		t_eq($code, 0, "socle : syntaxe PHP de $rel");
	} else {
		foreach (array('if', 'for', 'block') as $bal) {
			$ouvre = preg_match_all('/\{%-?\s*' . $bal . '\b/', $txt);
			$ferme = preg_match_all('/\{%-?\s*end' . $bal . '\b/', $txt);
			t_eq($ouvre, $ferme, "socle : {% $bal %} équilibrés dans $rel");
		}
	}
}
t_ok(strpos(file_get_contents($dest . 'mon-theme.php'), 'class MonTheme extends Theme') !== false, 'socle : classe du thème');
t_ok(strpos(file_get_contents($dest . 'templates/partials/base.html.twig'), "default('fr')") !== false, 'socle : langue du site dans base.html.twig');

// template par type d'objet
$info = array('type' => 'article', 'id' => 'id_article', 'col_titre' => 'titre', 'date' => 'date', 'champs' => array(
	'id_article' => 'bigint', 'titre' => 'text', 'soustitre' => 'text', 'descriptif' => 'text', 'chapo' => 'mediumtext', 'texte' => 'longtext',
	'ps' => 'mediumtext', 'url_site' => 'text', 'nom_site' => 'tinytext', 'date' => 'datetime', 'statut' => 'varchar(10)', 'id_rubrique' => 'bigint', 'lang' => 'varchar(10)',
));
$tw = sptg_twig_objet($info, false);
t_eq(sptg_gabarit_restes($tw), 0, 'objet : pas de marqueur restant');
t_ok(strpos($tw, 'spip-article') !== false and strpos($tw, '{{ page.content|raw }}') !== false, 'objet : structure');
t_ok(strpos($tw, 'partials/enfants.html.twig') === false, 'objet : pas d\'enfants pour un article');
t_ok(strpos(sptg_twig_objet(array('type' => 'rubrique') + $info, false), 'partials/enfants.html.twig') !== false, 'objet : enfants pour une rubrique');
t_ok(strpos($tw, 's.soustitre') !== false and strpos($tw, 's.ps') !== false, 'objet : champs texte', $tw);
t_ok(!preg_match("/\n\n\n/", $tw), 'objet : pas de lignes vides en trop', $tw);

t_fin('unit');
