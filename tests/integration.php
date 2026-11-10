<?php
/** Tests d'intégration : export complet avec SPIP simulé, par scénario. */
require __DIR__ . '/lib.php';
require __DIR__ . '/stubs.php';
include_spip('inc/sptg_export');

$F_ART = array('id_article' => 'bigint', 'titre' => 'text', 'chapo' => 'mediumtext', 'texte' => 'longtext', 'date' => 'datetime',
	'statut' => 'varchar(10)', 'id_rubrique' => 'bigint', 'id_secteur' => 'bigint', 'maj' => 'timestamp', 'lang' => 'varchar(10)', 'id_trad' => 'bigint');
$F_RUB = array('id_rubrique' => 'bigint', 'id_parent' => 'bigint', 'titre' => 'text', 'descriptif' => 'text', 'texte' => 'longtext',
	'statut' => 'varchar(10)', 'date' => 'datetime', 'lang' => 'varchar(10)', 'id_trad' => 'bigint');

function rub($id, $parent, $titre, $lang = 'fr', $trad = 0) {
	return array('id_rubrique' => $id, 'id_parent' => $parent, 'titre' => $titre, 'descriptif' => '', 'texte' => '', 'statut' => 'publie',
		'date' => '2020-01-01 00:00:00', 'lang' => $lang, 'id_trad' => $trad);
}
function art($id, $rub, $titre, $texte = 'Texte.', $lang = 'fr', $trad = 0, $sect = 1) {
	return array('id_article' => $id, 'titre' => $titre, 'chapo' => '', 'texte' => $texte, 'date' => '2021-05-02 10:00:00', 'statut' => 'publie',
		'id_rubrique' => $rub, 'id_secteur' => $sect, 'maj' => 'x', 'lang' => $lang, 'id_trad' => $trad);
}
function lancer($nom, array $opts = array(), $prepare = null) {
	$dest = t_tmp($nom);
	$r = sptg_exporter($opts + array(
		'export_name' => 'demo', 'dossier_destination' => $dest, 'vider' => true, 'objets' => array('article', 'rubrique'),
		'secteurs' => array(1, 2, 3), 'statuts' => array('publie'), 'creer_zip' => false, 'mode_images' => 'partage',
	));
	return array($r, rtrim($r['racine'] ?? $dest, '/'));
}
function md_de($dest, $glob) {
	$g = glob("$dest/user/pages/$glob");
	return $g ? file_get_contents($g[0]) : null;
}
function index($dest, $type, $id) {
	return t_json("$dest/user/data/spip-id/$type/$id.json");
}

// ------------------------------------------------ 1. monolingue, URL à paramètres, schéma hiérarchique
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, '1. Actualités'), rub(2, 1, 'Sous rubrique')),
		'spip_articles' => array(art(10, 2, 'Mon article', "{{{Intertitre}}}\n\nVoir [lien->art11] et [la rubrique->rub2]."), art(11, 1, 'Autre article')),
	),
));
list($r, $dest) = lancer('mono', array('schema_urls' => 'hierarchique'));
t_ok(empty($r['erreur']), 'mono : pas d\'erreur', json_encode($r['erreur'] ?? null));
$md = md_de($dest, '01.*/*/*/article.md') ?? md_de($dest, '*/*/*/article.md');
$tous = glob("$dest/user/pages/*/*/*/*.md");
t_ok(count(glob("$dest/user/pages/*/*.md")) >= 1, 'mono : pages exportées', implode(' ', glob("$dest/user/pages/*")));
$e10 = index($dest, 'article', 10);
t_ok($e10 !== null, 'mono : index de l\'article 10');
t_eq($e10['type'] ?? null, 'article', 'mono : index type');
t_eq($e10['lang'] ?? null, 'fr', 'mono : index lang');
t_ok(is_dir("$dest/user/pages/" . ($e10['dossier'] ?? 'inexistant')), 'mono : le dossier de l\'index existe');
t_ok(index($dest, 'rubrique', 2) !== null, 'mono : index de la rubrique 2');
$page10 = file_get_contents("$dest/user/pages/{$e10['dossier']}/article.md");
t_ok(strpos($page10, 'spip_id: 10') !== false, 'mono : spip_id dans l\'entête', $page10);
t_ok(strpos($page10, 'spip_type: "article"') !== false, 'mono : spip_type dans l\'entête');
t_ok(preg_match('~\]\(/[a-z0-9/_-]+\)~', $page10) === 1, 'mono : liens internes en routes absolues', $page10);
t_ok(strpos($page10, 'art11') === false, 'mono : plus de référence art11', $page10);
// URL à paramètres : anciennes URL gérées par le résolveur
$leg = "$dest/user/data/spip-id/_legacy/urls/" . sptg_nom_legacy('/spip.php?article10');
t_eq(t_json($leg)['id'] ?? null, 10, 'mono : ancienne URL spip.php?article10 dans _legacy');
t_ok(is_file("$dest/user/themes/demo-theme/demo.php") or glob("$dest/user/themes/demo-theme/*.php"), 'mono : classe du thème générée');
foreach (glob("$dest/user/themes/demo-theme/*.php") as $f) {
	exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
	t_eq($code, 0, 'mono : syntaxe PHP du thème ' . basename($f));
}
$yaml = glob("$dest/user/config/*.yaml");
t_ok(!is_file("$dest/user/config/system.yaml") or strpos(file_get_contents("$dest/user/config/system.yaml"), 'languages:') === false, 'mono : pas de bloc languages');


// ------------------------------------------------ 1b. liens stables (idx:type/id)
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, '1. Actualités'), rub(2, 1, 'Sous rubrique')),
		'spip_articles' => array(art(10, 2, 'Mon article', "Voir [lien->art11] et [la rubrique->rub2#haut]."), art(11, 1, 'Autre article')),
	),
));
list($r, $dest) = lancer('stables', array('schema_urls' => 'hierarchique', 'liens_stables' => true));
$e10 = index($dest, 'article', 10);
$page10 = file_get_contents("$dest/user/pages/{$e10['dossier']}/article.md");
t_ok(strpos($page10, '](idx:article/11)') !== false, 'stables : lien vers un article', $page10);
t_ok(strpos($page10, '](idx:rubrique/2#haut)') !== false, 'stables : lien vers une rubrique avec ancre', $page10);
t_eq($r['ecarts'], array(), 'stables : aucun écart de vérification');
t_eq($r['liens_stables'], 'actifs', 'stables : rapport');
$thdir = "$dest/user/themes/demo-theme";
t_eq(sptg_theme_helpers($thdir)['etat'], 'ok', 'stables : le thème généré porte les helpers');

// sans régénérer le thème et sans helpers : routes absolues, avertissement, redirections par site.yaml
list($r, $dest) = lancer('stables-sans', array('schema_urls' => 'hierarchique', 'liens_stables' => true, 'generer_theme' => false));
$e10 = index($dest, 'article', 10);
$page10 = file_get_contents("$dest/user/pages/{$e10['dossier']}/article.md");
t_ok(strpos($page10, '](idx:') === false and preg_match('~\]\(/[a-z0-9/_-]+\)~', $page10), 'stables : repli sur les routes absolues', $page10);
t_ok(strpos($r['liens_stables'], 'non activés') !== false, 'stables : avertissement dans le rapport', $r['liens_stables']);
t_eq($r['moteur_redirections'], 'site', 'stables : redirections par site.yaml sans helpers');
t_ok(strpos(sptg_rapport_texte($r, array('export_name' => 'demo'), ''), 'non activés') !== false, 'stables : avertissement dans le rapport texte');

// thème réutilisé qui comporte déjà les helpers (généré une première fois, puis contenu seul)
$d2 = t_tmp('stables-reuse');
sptg_generer_socle("$d2/user/themes/demo-theme/", 'demo-theme', array('CLASSE' => 'DemoTheme', 'LANGUE_SITE' => 'fr'));
$ouvert = sptg_exporter(array(
	'export_name' => 'demo', 'dossier_destination' => $d2, 'vider' => true, 'objets' => array('article', 'rubrique'), 'secteurs' => array(1, 2, 3),
	'statuts' => array('publie'), 'creer_zip' => false, 'schema_urls' => 'hierarchique', 'liens_stables' => true, 'generer_theme' => false,
));
$e10 = t_json("$d2/user/data/spip-id/article/10.json");
$page10 = file_get_contents("$d2/user/pages/{$e10['dossier']}/article.md");
t_ok(strpos($page10, '](idx:article/11)') !== false, 'stables : thème réutilisé compatible → idx:', $page10);
t_eq($ouvert['moteur_redirections'], 'resolveur', 'stables : thème réutilisé → résolveur d\'anciennes URL');
t_ok(is_file("$d2/user/themes/demo-theme/demo-theme.php"), 'stables : thème réutilisé non supprimé par « vider »');

// option désactivée : routes absolues
list($r, $dest) = lancer('stables-non', array('schema_urls' => 'hierarchique'));
$e10 = index($dest, 'article', 10);
t_ok(strpos(file_get_contents("$dest/user/pages/{$e10['dossier']}/article.md"), '](idx:') === false, 'stables : désactivé par défaut');
t_eq($r['liens_stables'], '', 'stables : rien dans le rapport si non demandé');


// ------------------------------------------------ 1c. nom d'export et destination indépendants
list($r, $dest) = lancer('nom-dest', array('export_name' => 'autre-nom', 'schema_urls' => 'hierarchique'));
t_ok(is_dir("$dest/user/pages") and rtrim($r['racine'], '/') === rtrim($dest, '/'), 'destination : l\'export est écrit directement dans le dossier de destination');
t_ok(!is_dir("$dest/autre-nom"), 'destination : pas de sous-dossier du nom d\'export');
t_ok(is_dir("$dest/user/themes/autre-nom-theme"), 'nom d\'export : nomme le thème');
t_eq($r['rapport'], 'autre-nom-rapport.md', 'nom d\'export : nomme le rapport');
// destination par défaut si aucune n'est donnée
$def = sptg_exporter(array('export_name' => 'essai-defaut', 'objets' => array('rubrique'), 'secteurs' => array(1), 'statuts' => array('publie'), 'creer_zip' => false, 'vider' => true));
t_eq(rtrim($def['racine'], '/'), sptg_destination_defaut('essai-defaut'), 'destination par défaut : <tmp>/grav/<nom>');
t_ok(is_dir($def['racine'] . 'user/pages'), 'destination par défaut : export écrit');

// ------------------------------------------------ 2. schéma « spip » avec URL arborescentes
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'url' => function ($id, $type) {
		return $type == 'rubrique' ? "actualites/rubrique$id.html" : "actualites/article$id.html";
	},
	'spip_urls' => array(array('type' => 'article', 'id_objet' => 10, 'url' => 'ancien-titre'), array('type' => 'article', 'id_objet' => 10, 'url' => 'article10')),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'Actualités')),
		'spip_articles' => array(art(10, 1, 'Mon article')),
	),
));
list($r, $dest) = lancer('spip', array('schema_urls' => 'spip'));
$e10 = index($dest, 'article', 10);
t_ok($e10 !== null, 'spip : index');
$page10 = md_de($dest, '*/*/article.md') ?? md_de($dest, '*/article.md');
$tout = shell_exec('grep -rl "actualites/article10" ' . escapeshellarg("$dest/user/pages") . ' 2>/dev/null');
t_ok($tout !== null and trim($tout) !== '', 'spip : route publique actualites/article10 posée', shell_exec('find ' . escapeshellarg("$dest/user/pages") . ' -name "*.md" -exec head -12 {} \;'));
t_ok(is_file("$dest/user/data/spip-id/_legacy/urls/" . sptg_nom_legacy('/actualites/article10.html')), 'spip : ancienne URL .html redirigée');
t_ok(is_file("$dest/user/data/spip-id/_legacy/urls/" . sptg_nom_legacy('/actualites/ancien-titre.html')), 'spip : historique spip_urls reconstruit');
t_ok(strpos(file_get_contents("$dest/user/config/system.yaml"), 'redirect_default_route: 301') !== false, 'spip : redirect_default_route 301');


// ------------------------------------------------ 2b. le runtime du thème relit l'export du scénario 2
t_charger_runtime();
list($r2, $d2) = lancer('spip-rt', array('schema_urls' => 'spip'));
$charger = function ($dossier) use ($d2) {
	foreach (glob("$d2/user/pages/$dossier/*.md") as $f) {
		$t = file_get_contents($f);
		if (preg_match('/^spip_id: (\d+)/m', $t, $i) and preg_match('/^spip_type: "(\w+)"/m', $t, $ty)) {
			return array('type' => $ty[1], 'id' => (int) $i[1], 'lang' => 'fr', 'url' => '/' . $dossier, 'title' => $dossier);
		}
	}
	return null;
};
$rt = new Grav\Theme\Sptg\Runtime("$d2/user/data", $charger, function () { return array(); });
foreach (array(array('/actualites/article10.html', ''), array('/actualites/ancien-titre.html', ''), array('/Actualites/Article10.html/', '')) as $u) {
	$p = $rt->pourAncienneUrl($u[0], $u[1]);
	t_eq($p['id'] ?? null, 10, 'runtime sur export : ' . $u[0]);
}

// ------------------------------------------------ 3. traductions id_trad par secteur
sptg_scenario(array(
	'meta' => array('langue_site' => 'fr', 'langues_multilingue' => 'fr,en', 'multi_objets' => '', 'gerer_trad_objets' => 'spip_articles', 'nom_site' => 'S', 'descriptif_site' => ''),
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'Accueil fr', 'fr'), rub(2, 0, 'Home en', 'en')),
		'spip_articles' => array(art(10, 1, 'Bonjour', 'Salut.', 'fr', 10, 1), art(11, 2, 'Hello', 'Hi.', 'en', 10, 2)),
	),
));
list($r, $dest) = lancer('trad', array('schema_urls' => 'hierarchique', 'secteurs' => array(1, 2)));
$a = index($dest, 'article', 10);
$b = index($dest, 'article', 11);
t_eq($a['trad'] ?? null, array('fr' => 10, 'en' => 11), 'trad : liste des traductions dans l\'index fr');
t_eq($b['trad'] ?? null, array('fr' => 10, 'en' => 11), 'trad : liste des traductions dans l\'index en');
t_ok(($a['dossier'] ?? 'a') !== ($b['dossier'] ?? 'a'), 'trad : un dossier par objet');
t_ok(strpos(file_get_contents("$dest/user/pages/{$b['dossier']}/article.md"), 'spip_lang: "en"') !== false, 'trad : spip_lang en');
// traductions filtrées sur les objets exportés
list($r, $dest) = lancer('trad2', array('schema_urls' => 'hierarchique', 'secteurs' => array(1)));
$a = index($dest, 'article', 10);
t_eq($a['trad'] ?? null, array('fr' => 10), 'trad : traduction hors export retirée de l\'index');

// ------------------------------------------------ 4. <multi>
sptg_scenario(array(
	'meta' => array('langue_site' => 'fr', 'langues_multilingue' => 'fr,en', 'multi_objets' => 'spip_articles,spip_rubriques', 'nom_site' => 'S', 'descriptif_site' => ''),
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, '<multi>[fr]Accueil[en]Home</multi>', '')),
		'spip_articles' => array(art(10, 1, '<multi>[fr]Bonjour[en]Hello</multi>', "<multi>[fr]Salut le monde.[en]Hi world.</multi>", ''),
			art(11, 1, 'Lien', "<multi>[fr]Voir [ici->art10].[en]See [here->art10].</multi>", '')),
	),
));
list($r, $dest) = lancer('multi', array('schema_urls' => 'hierarchique'));
$e = index($dest, 'article', 10);
t_eq($e['langues'] ?? null, array('fr', 'en'), 'multi : langues dans l\'index');
$fr = file_get_contents("$dest/user/pages/{$e['dossier']}/article.fr.md");
$en = file_get_contents("$dest/user/pages/{$e['dossier']}/article.en.md");
t_ok(strpos($fr, 'Salut le monde') !== false and strpos($fr, 'Hi world') === false, 'multi : fichier fr', $fr);
t_ok(strpos($en, 'Hi world') !== false and strpos($en, 'Salut') === false, 'multi : fichier en', $en);
t_ok(strpos($fr, 'title: "Bonjour"') !== false, 'multi : titre fr', $fr);
t_ok(strpos($en, 'title: "Hello"') !== false, 'multi : titre en', $en);
$sys = file_get_contents("$dest/user/config/system.yaml");
$e11 = index($dest, 'article', 11);
$l_fr = file_get_contents("$dest/user/pages/{$e11['dossier']}/article.fr.md");
$l_en = file_get_contents("$dest/user/pages/{$e11['dossier']}/article.en.md");
t_ok(preg_match('~\]\(/[^/)]+[^)]*\)~', $l_fr) and strpos($l_fr, '](/en/') === false, 'multi : lien fr sans préfixe de langue', $l_fr);
t_ok(strpos($l_en, '](/en/') !== false, 'multi : lien en avec préfixe /en', $l_en);
list($r, $dest_s) = lancer('multi-stable', array('schema_urls' => 'hierarchique', 'liens_stables' => true));
$e11 = index($dest_s, 'article', 11);
t_ok(strpos(file_get_contents("$dest_s/user/pages/{$e11['dossier']}/article.en.md"), '](idx:article/10)') !== false, 'multi : lien stable dans la version en (préfixe retiré)');
t_ok(strpos($sys, 'languages:') !== false and strpos($sys, 'include_default_lang: false') !== false, 'multi : config languages', $sys);
t_ok(strpos($sys, 'default_lang: "fr"') !== false, 'multi : default_lang fr', $sys);
t_ok(strpos($sys, '- "en"') !== false, 'multi : en supporté', $sys);

// ------------------------------------------------ 5. routes en double
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'url' => function ($id, $type) {
		return 'meme-url';
	},
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'R')),
		'spip_articles' => array(art(10, 1, 'Un'), art(11, 1, 'Deux')),
	),
));
list($r, $dest) = lancer('doublons', array('schema_urls' => 'spip', 'objets' => array('article')));
t_ok(!empty($r['rapport']['doublons']) or !empty($r['doublons']), 'doublons : signalés dans le rapport', json_encode(array_diff_key($r, array('snippet' => 1)), JSON_UNESCAPED_UNICODE));
$all = shell_exec('grep -rh "default:" ' . escapeshellarg("$dest/user/pages") . ' 2>/dev/null');
t_ok(strpos((string) $all, '/meme-url-2') !== false, 'doublons : suffixe -2', (string) $all);

// ------------------------------------------------ 12. comptes des auteurs
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'Actu')),
		'spip_articles' => array(art(10, 1, 'Un article')),
		'spip_auteurs' => array(
			array('id_auteur' => 1, 'nom' => 'Marie Curie', 'login' => 'marie', 'email' => 'marie@exemple.org', 'statut' => '1comite'),
			array('id_auteur' => 2, 'nom' => 'Sans Mail', 'login' => 'sm', 'email' => '', 'statut' => '1comite'),
		),
	),
));
$dest_c = t_tmp('comptes');
mkdir("$dest_c/user/accounts", 0775, true);
mkdir("$dest_c/system");
file_put_contents("$dest_c/system/defines.php", "<?php define('GRAV_VERSION', '2.0.0');");
file_put_contents("$dest_c/index.php", '<?php');
file_put_contents("$dest_c/user/accounts/admin.yaml", "email: admin@site.org\nhashed_password: SECRET\n");
$r12 = sptg_exporter(array(
	'export_name' => 'demo', 'dossier_destination' => $dest_c, 'objets' => array('article', 'rubrique'),
	'secteurs' => array(1), 'statuts' => array('publie'), 'mode_images' => 'partage', 'creer_comptes' => true, 'creer_zip' => true,
));
$cm = @file_get_contents("$dest_c/user/accounts/marie.yaml");
t_ok($cm !== false and strpos($cm, 'marie@exemple.org') !== false, 'comptes export : fichier écrit avec l\'email', (string) $cm);
t_ok(strpos((string) $cm, 'password') === false, 'comptes export : pas de mot de passe');
t_ok(strpos((string) $cm, 'state: "enabled"') !== false or strpos((string) $cm, 'state: enabled') !== false, 'comptes export : compte activé', (string) $cm);
t_ok(is_file("$dest_c/user/accounts/sm2.yaml") and strpos(file_get_contents("$dest_c/user/accounts/sm2.yaml"), 'state: "disabled"') !== false or strpos(file_get_contents("$dest_c/user/accounts/sm2.yaml"), 'state: disabled') !== false, 'comptes export : auteur sans email -> compte désactivé');
t_ok(strpos(file_get_contents("$dest_c/user/accounts/sm2.yaml"), 'email') === false, 'comptes export : pas d\'email pour le compte désactivé');
t_eq(file_get_contents("$dest_c/user/accounts/admin.yaml"), "email: admin@site.org\nhashed_password: SECRET\n", 'comptes export : compte existant intact');
t_eq($r12['comptes']['crees'], 2, 'comptes export : 2 comptes créés (1 actif, 1 désactivé)');
$rap = file_get_contents(_DIR_TMP . 'sptg/' . $r12['rapport']);
t_ok(strpos($rap, 'Sans Mail') !== false and strpos($rap, 'désactivé') !== false and strpos($rap, 'Mot de passe oublié') !== false, 'comptes export : rapport (désactivés + prérequis)', $rap);
$page_c = glob("$dest_c/user/pages/*/*.md");
$tout_pages = '';
foreach (glob("$dest_c/user/pages/*/*.md") as $f) { $tout_pages .= file_get_contents($f); }
t_ok(strpos($tout_pages, 'marie@exemple.org') === false, 'comptes export : email absent des pages');
if (!empty($r12['zip']) and class_exists('ZipArchive')) {
	$z = new ZipArchive();
	$z->open(_DIR_TMP . 'sptg/' . $r12['zip']);
	t_ok($z->locateName('user/accounts/marie.yaml') !== false and $z->locateName('user/accounts/sm2.yaml') !== false and $z->locateName('user/accounts/admin.yaml') === false, 'comptes export : zip avec les comptes créés, sans l\'admin existant');
}
$r12b = sptg_exporter(array(
	'export_name' => 'demo', 'dossier_destination' => $dest_c, 'objets' => array('article', 'rubrique'),
	'secteurs' => array(1), 'statuts' => array('publie'), 'mode_images' => 'partage', 'creer_comptes' => false,
));
t_ok(empty($r12b['comptes']), 'comptes export : option décochée → rien');

// ------------------------------------------------ 13. Twig jamais actif dans le contenu (Grav 2.x l'ouvre par défaut)
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'Actu')),
		'spip_articles' => array(art(10, 1, 'Accolades', "Texte {{}} puis {{gras}}.")),
	),
));
list($r, $dest) = lancer('twig');
$fichiers_md = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dest/user/pages", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	if (substr($f->getFilename(), -3) === '.md') {
		$fichiers_md[] = $f->getPathname();
	}
}
t_ok(count($fichiers_md) >= 3, 'twig : pages trouvées (accueil, rubrique, article)', implode(' ', $fichiers_md));
$sans = array();
foreach ($fichiers_md as $f) {
	$c = file_get_contents($f);
	if (!preg_match('/^---\n(.*?)\n---\n/s', $c, $m) or !preg_match('/^process:\n(?:[ ]+.*\n)*?[ ]+twig: false/m', $m[1] . "\n")) {
		$sans[] = $f;
	}
}
t_eq($sans, array(), 'twig : process.twig=false dans toutes les pages');

// ------------------------------------------------ 14. logos (documents SPIP 4.x), liens espace privé, rapport
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'La revue'), rub(2, 0, 'Sans fichier'), rub(3, 0, 'Sans logo')),
		'spip_articles' => array(
			art(10, 1, 'Ancien logo', 'Voir <a href="../ecrire/?exec=rubrique&amp;id_rubrique=3">la rubrique</a>, <a href="https://x.org/ecrire/?exec=definition&amp;id_definition=9">?</a> et <a href="https://exemple.org/autre-page">ailleurs</a>.'),
		),
	),
	'logos' => array(
		'logoon:rubrique:1' => array('fichier' => 'png/rubon1.png', 'extension' => 'png', 'distant' => 'non'),
		'logoon:rubrique:2' => array('fichier' => 'png/absent.png', 'extension' => 'png', 'distant' => 'non'),
	),
	'meta' => array('nom_site' => 'Mon site', 'descriptif_site' => 'Bienvenue', 'langue_site' => 'fr', 'adresse_site' => 'https://exemple.org'),
));
@mkdir(_DIR_IMG . 'png', 0775, true);
file_put_contents(_DIR_IMG . 'png/rubon1.png', 'PNG');
file_put_contents(_DIR_IMG . 'arton10.jpg', 'JPG');
list($r14, $dest14) = lancer('logos');
$e1 = index($dest14, 'rubrique', 1);
$p1 = file_get_contents("$dest14/user/pages/{$e1['dossier']}/rubrique.md");
t_ok(strpos($p1, 'logo_url: "/images/png/rubon1.png"') !== false or strpos($p1, 'logo_url: /images/png/rubon1.png') !== false, 'logos : logo-document de la rubrique 1 référencé', $p1);
t_ok(is_file("$dest14/user/pages/images/png/rubon1.png"), 'logos : fichier copié dans le dossier partagé');
$e10 = index($dest14, 'article', 10);
$p10 = file_get_contents("$dest14/user/pages/{$e10['dossier']}/article.md");
t_ok(strpos($p10, '/images/arton10.jpg') !== false, 'logos : repli sur l\'ancien fichier arton10.jpg', $p10);
t_ok(strpos($p10, 'id_rubrique=3') === false, 'liens : plus de lien ecrire/ vers la rubrique exportée', $p10);
$e3 = index($dest14, 'rubrique', 3);
t_ok(strpos($p10, '[la rubrique](/sans-logo)') !== false and $e3 !== null, 'liens : lien espace privé remplacé par la route de la rubrique 3', $p10);
$noms_logos = array();
foreach ($r14['logos'] as $l) { $noms_logos[] = $l['type'] . $l['id'] . ($l['present'] ? '+' : '-'); }
sort($noms_logos);
t_eq($noms_logos, array('article10+', 'rubrique1+', 'rubrique2-'), 'logos : rapport (source, présence disque)');
t_eq($r14['logos_nb']['rubrique'], array('objets' => 3, 'logos' => 2), 'logos : comptage par type');
t_eq(count($r14['liens_prives']), 1, 'liens : 1 lien d\'espace privé non résolu (définition 9 non exportée)');
t_eq(count($r14['liens_site']), 1, 'liens : 1 lien absolu vers le site actuel');
$rap14 = file_get_contents(_DIR_TMP . 'sptg/' . $r14['rapport']);
t_ok(strpos($rap14, '## Logos') !== false and strpos($rap14, '**ABSENT**') !== false and strpos($rap14, 'png/rubon1.png') !== false, 'rapport : section Logos', $rap14);
t_ok(strpos($rap14, "## Liens vers l'espace privé") !== false and strpos($rap14, '## Liens absolus vers le site SPIP actuel') !== false, 'rapport : liens privés et liens absolus', $rap14);

// ------------------------------------------------ 15. Champs Extras
$F_ART_X = $F_ART + array('langue_choisie' => 'varchar(3)', 'ville' => "varchar(255) NOT NULL DEFAULT ''", 'themes' => 'text', 'actif' => 'varchar(3)', 'note' => 'text');
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART_X, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'Actu')),
		'spip_articles' => array(array_merge(art(10, 1, 'Avec extras'), array('langue_choisie' => 'non', 'ville' => 'Paris', 'themes' => 'a,b', 'actif' => 'on', 'note' => 'Une {{note}} ici'))),
	),
	'pipelines' => array('declarer_champs_extras' => array('spip_articles' => array(
		array('saisie' => 'fieldset', 'options' => array('nom' => 'fs', 'label' => 'Infos'), 'saisies' => array(
			array('saisie' => 'input', 'options' => array('nom' => 'ville', 'label' => 'Ville', 'obligatoire' => 'on', 'sql' => "varchar(255) NOT NULL DEFAULT ''")),
			array('saisie' => 'checkbox', 'options' => array('nom' => 'themes', 'label' => 'Thèmes', 'datas' => "a|Alpha\nb|Bêta", 'sql' => 'text')),
		)),
		array('saisie' => 'oui_non', 'options' => array('nom' => 'actif', 'label' => 'Actif', 'sql' => 'varchar(3)')),
		array('saisie' => 'textarea', 'options' => array('nom' => 'note', 'label' => 'Note', 'sql' => 'text')),
	))),
));
sptg_extras_definitions(true);
list($r15, $dest15) = lancer('extras', array('generer_theme' => true));
$e15 = index($dest15, 'article', 10);
$p15 = file_get_contents("$dest15/user/pages/{$e15['dossier']}/article.md");
t_ok(preg_match('/^extras:\n(?:[ ]+.*\n)+/m', $p15) === 1, 'extras : bloc extras: dans l\'entête', $p15);
t_ok(strpos($p15, 'ville: "Paris"') !== false or strpos($p15, 'ville: Paris') !== false, 'extras : valeur texte', $p15);
t_ok(preg_match('/themes:\n\s+- "?a"?\n\s+- "?b"?/', $p15) === 1, 'extras : liste typée', $p15);
t_ok(preg_match('/actif: true/', $p15) === 1, 'extras : booléen typé', $p15);
$spip_blk = preg_match('/^spip:\n((?:[ ]+.*\n)+)/m', $p15, $mm) ? $mm[1] : '';
t_ok($spip_blk !== '' and strpos($spip_blk, 'ville') === false and strpos($spip_blk, 'themes') === false, 'extras : absents du bloc spip:', $spip_blk);
$bpf = glob("$dest15/user/themes/*/blueprints/article.yaml");
$bp15 = $bpf ? file_get_contents($bpf[0]) : '';
t_ok(strpos($bp15, 'header.extras.ville') !== false and strpos($bp15, 'Champs extras') !== false, 'extras : onglet blueprint', $bp15);
t_ok(strpos($bp15, 'checkboxes') !== false and strpos($bp15, 'Alpha') !== false and strpos($bp15, 'required') !== false, 'extras : blueprint reprend type, options et obligatoire', $bp15);
$twf = glob("$dest15/user/themes/*/templates/article.html.twig");
$tw15 = $twf ? file_get_contents($twf[0]) : '';
t_ok(strpos($tw15, '<!-- langue_choisie :') !== false and strpos($tw15, 'Langue choisie') === false, 'gabarits : langue_choisie en commentaire HTML (pas en liste visible)', $tw15);
t_ok(strpos($tw15, 'page.content|trim is not empty') !== false, 'gabarits : corps vide toléré', $tw15);
t_ok(strpos($tw15, 'spip-extras') !== false and strpos($tw15, 'e.ville') !== false and strpos($tw15, 'markdown') !== false, 'extras : template affiche les extras', $tw15);
$rap15 = file_get_contents(_DIR_TMP . 'sptg/' . $r15['rapport']);
t_ok(strpos($rap15, '## Champs extras') !== false and strpos($rap15, '`ville`') !== false, 'extras : rapport', $rap15);
sptg_scenario(array());
sptg_extras_definitions(true);

// ------------------------------------------------ 16. pages rattachées aux comptes Grav ; page d'auteur accrochée à son compte
$F_AUT = array('id_auteur' => 'bigint', 'nom' => 'text', 'bio' => 'text', 'email' => 'tinytext', 'login' => 'varchar(255)', 'statut' => 'varchar(10)', 'lang' => 'varchar(10)');
$auteurs16 = array(
	array('id_auteur' => 1, 'nom' => 'Marie Curie', 'login' => 'marie', 'email' => 'marie@exemple.org', 'statut' => '1comite', 'bio' => 'Physicienne', 'lang' => 'fr'),
	array('id_auteur' => 2, 'nom' => 'Sans Mail', 'login' => 'sm', 'email' => '', 'statut' => '1comite', 'bio' => '', 'lang' => 'fr'),
	array('id_auteur' => 3, 'nom' => 'Visiteur', 'login' => 'vis', 'email' => 'v@exemple.org', 'statut' => '6forum', 'bio' => '', 'lang' => 'fr'),
);
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB, 'spip_auteurs' => $F_AUT),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'Actu')),
		'spip_articles' => array(art(10, 1, 'Un article')),
		'spip_auteurs' => $auteurs16,
	),
	'auteurs_join' => array(
		array('objet' => 'article', 'id_objet' => 10, 'id_auteur' => 1, 'nom' => 'Marie Curie'),
		array('objet' => 'article', 'id_objet' => 10, 'id_auteur' => 2, 'nom' => 'Sans Mail'),
		array('objet' => 'article', 'id_objet' => 10, 'id_auteur' => 3, 'nom' => 'Visiteur'),
	),
));
list($r16, $dest16) = lancer('auteurs', array('objets' => array('article', 'rubrique', 'auteur'), 'creer_comptes' => true, 'inclure_emails' => true));
$e16 = index($dest16, 'article', 10);
$p16 = file_get_contents("$dest16/user/pages/{$e16['dossier']}/article.md");
t_ok(preg_match('/^auteurs:\n\s+- "?marie"?\n\s+- "?sm2"?\n/m', $p16) === 1, 'auteurs : articles rattachés aux identifiants de comptes', $p16);
t_ok(strpos($p16, 'auteurs_sans_compte') !== false and strpos($p16, 'Visiteur') !== false, 'auteurs : auteur sans compte -> nom', $p16);
t_ok(preg_match('/taxonomy:(?:\n[ ]+.*)*\n[ ]+auteur:\n\s+- "?marie"?/', $p16) === 1, 'auteurs : taxonomie auteur = identifiants de comptes', $p16);
t_ok(strpos($p16, 'Marie Curie') === false, 'auteurs : le nom n\'est pas dupliqué dans la page de l\'article', $p16);
$ea = index($dest16, 'auteur', 1);
$pa = file_get_contents("$dest16/user/pages/{$ea['dossier']}/auteur.md");
t_ok(strpos($pa, 'account: "marie"') !== false or strpos($pa, 'account: marie') !== false, 'auteurs : page d\'auteur accrochée à son compte', $pa);
t_ok(strpos($pa, 'afficher_email: true') !== false, 'auteurs : afficher_email demandé', $pa);
t_ok(strpos($pa, 'marie@exemple.org') === false, 'auteurs : email jamais écrit dans la page (lu dans le compte)', $pa);
$twa = glob("$dest16/user/themes/*/templates/auteur.html.twig");
t_ok($twa and strpos(file_get_contents($twa[0]), 'sptg_compte(page.header.account)') !== false, 'auteurs : template d\'auteur lit le compte');
$tws = glob("$dest16/user/themes/*/templates/partials/auteurs.html.twig");
t_ok($tws and strpos(file_get_contents($tws[0]), 'sptg_compte(a)') !== false, 'auteurs : partial des auteurs d\'une page');
$php = glob("$dest16/user/themes/*/*.php");
$php_all = '';
foreach ($php as $f) { $php_all .= file_get_contents($f); }
t_ok(strpos($php_all, "'sptg_compte'") !== false, 'auteurs : fonction Twig sptg_compte dans le thème');
// re-export sur la même destination : rattachement par id, aucun compte dupliqué
$nb_avant = count(glob("$dest16/user/accounts/*.yaml"));
$r16b = sptg_exporter(array(
	'export_name' => 'demo', 'dossier_destination' => $dest16, 'objets' => array('article', 'rubrique'),
	'secteurs' => array(1, 2, 3), 'statuts' => array('publie'), 'mode_images' => 'partage', 'creer_comptes' => true, 'creer_zip' => false,
));
t_eq(count(glob("$dest16/user/accounts/*.yaml")), $nb_avant, 'auteurs : re-export sans duplication de comptes');
$p16b = file_get_contents("$dest16/user/pages/{$e16['dossier']}/article.md");
t_ok(preg_match('/^auteurs:\n\s+- "?marie"?\n\s+- "?sm2"?\n/m', $p16b) === 1, 'auteurs : re-export : mêmes comptes dans les pages', $p16b);
sptg_scenario(array());

// ------------------------------------------------ 17. liens « à l'ancienne » résolus, autres sites ignorés, page=… notifié
sptg_scenario(array(
	'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
	'rows' => array(
		'spip_rubriques' => array(rub(1, 0, 'Actu')),
		'spip_articles' => array(
			art(10, 1, 'Source', 'Voir <a href="spip.php?article11#haut">un</a>, <a href="/spip.php?page=article&amp;id_article=11">deux</a>, <a href="http://autre.org/spip.php?article11">autre site</a> et <a href="/spip.php?page=contact">contact</a>.'),
			art(11, 1, 'Cible'),
		),
	),
	'meta' => array('nom_site' => 'Mon site', 'descriptif_site' => 'Bienvenue', 'langue_site' => 'fr', 'adresse_site' => 'https://exemple.org'),
));
list($r17, $dest17) = lancer('anciens-liens');
$e17 = index($dest17, 'article', 10);
$p17 = file_get_contents("$dest17/user/pages/{$e17['dossier']}/article.md");
t_ok(strpos($p17, '[un](/actu/cible#haut)') !== false and strpos($p17, '[deux](/actu/cible)') !== false, 'liens spip : résolus vers la route de l\'objet exporté', $p17);
t_ok(strpos($p17, 'http://autre.org/spip.php?article11') !== false, 'liens spip : lien vers un autre site laissé intact', $p17);
t_ok(strpos($p17, '(/spip.php?page=contact)') !== false, 'liens spip : page=contact laissé tel quel', $p17);
$urls17 = array_column($r17['liens_bruts'], 'url');
t_eq($urls17, array('/spip.php?page=contact'), 'liens spip : seul page=contact est notifié (autre site ignoré)');
t_ok(empty($r17['erreur']), 'liens spip : non bloquant');

// ------------------------------------------------ 18. publication différée (publish_date)
foreach (array('non' => true, 'oui' => false) as $post_dates => $differe) {
	$fut = date('Y-m-d H:i:s', time() + 86400 * 30);
	$a_fut = art(10, 1, 'Futur'); $a_fut['date'] = $fut;
	$a_pas = art(11, 1, 'Passe');
	sptg_scenario(array(
		'fields' => array('spip_articles' => $F_ART, 'spip_rubriques' => $F_RUB),
		'meta' => array('nom_site' => 'S', 'descriptif_site' => '', 'langue_site' => 'fr', 'post_dates' => $post_dates),
		'rows' => array('spip_rubriques' => array(rub(1, 0, 'Actu')), 'spip_articles' => array($a_fut, $a_pas)),
	));
	$dest18 = sys_get_temp_dir() . '/sptg_t18_' . $post_dates . getmypid();
	$r18 = sptg_exporter(array(
		'export_name' => 'demo', 'dossier_destination' => $dest18, 'objets' => array('article', 'rubrique'),
		'secteurs' => array(1), 'statuts' => array('publie'), 'creer_comptes' => false, 'creer_zip' => false, 'vider' => true,
	));
	$mds = glob("$dest18/user/pages/*/*/*.md") ?: array();
	$futur = $passe = '';
	foreach ($mds as $f) {
		$c = file_get_contents($f);
		if (preg_match('/^title: ["\']?Futur/m', $c)) { $futur = $c; }
		if (preg_match('/^title: ["\']?Passe/m', $c)) { $passe = $c; }
	}
	t_ok($futur !== '' and $passe !== '', "publish_date ($post_dates) : pages trouvées", implode(',', $mds));
	t_eq(strpos($futur, 'publish_date:') !== false, $differe, "publish_date ($post_dates) : article futur");
	t_ok(strpos($passe, 'publish_date:') === false, "publish_date ($post_dates) : article passé sans publish_date");
	t_eq(isset($r18['alertes']['Publication différée (publish_date, la page apparaîtra à cette date)']), $differe, "publish_date ($post_dates) : rapport");
	sptg_scenario(array());
}

t_fin('integration');
