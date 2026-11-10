<?php
/**
 * Doublures minimales de SPIP pour tester le plugin hors SPIP.
 *
 * Le scénario courant est dans $GLOBALS['SC'] :
 *   meta      => $GLOBALS['meta']
 *   rows      => [table => [lignes]]
 *   fields    => [table => [colonne => type]]
 *   url       => callable($id, $type) : URL « à la SPIP » (défaut : spip.php?articleN)
 *   spip_urls => lignes de spip_urls (type, id_objet, url) ou null si la table n'existe pas
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
define('_ECRIRE_INC_VERSION', 1);
define('SPTG_RACINE', realpath(__DIR__ . '/..') . '/');
define('SPTG_TMP_TESTS', sys_get_temp_dir() . '/sptg-tests-' . getmypid() . '/');
@mkdir(SPTG_TMP_TESTS, 0775, true);
define('_DIR_TMP', SPTG_TMP_TESTS . 'tmp/');
define('_DIR_IMG', SPTG_TMP_TESTS . 'img/');
define('_DIR_RACINE', SPTG_TMP_TESTS . 'racine/');
define('_DIR_VAR', SPTG_TMP_TESTS . 'var/');
foreach (array(_DIR_TMP, _DIR_IMG, _DIR_RACINE, _DIR_VAR) as $d) {
	@mkdir($d, 0775, true);
}
register_shutdown_function(function () {
	exec('rm -rf ' . escapeshellarg(SPTG_TMP_TESTS));
});

$GLOBALS['SC'] = array();
$GLOBALS['spip_lang'] = 'fr';

function sptg_scenario(array $sc) {
	$defaut = array(
		'meta' => array('nom_site' => 'Mon site', 'descriptif_site' => 'Bienvenue', 'langue_site' => 'fr'),
		'rows' => array(),
		'fields' => array(),
		'url' => null,
		'spip_urls' => null,
	);
	$sc = array_replace($defaut, $sc);
	$GLOBALS['SC'] = $sc;
	$GLOBALS['meta'] = $sc['meta'];
	$GLOBALS['spip_lang'] = $sc['meta']['langue_site'] ?? 'fr';
}

function include_spip($f) {
	$p = SPTG_RACINE . $f . '.php';
	if (strpos($f, 'inc/sptg_') === 0 and file_exists($p)) {
		require_once $p;
	}
}
function _T($k, $a = array()) {
	return $k;
}
function charger_fonction($n, $d) {
	return 'sptg_stub_' . $n;
}
function sptg_stub_trouver_table($t) {
	$f = $GLOBALS['SC']['fields'][$t] ?? null;
	return $f ? array('table' => $t, 'field' => $f) : false;
}
function sql_showtable($t, $serveur = true) {
	if ($t === 'spip_urls') {
		return $GLOBALS['SC']['spip_urls'] === null ? false : array('field' => array(
			'url' => 'varchar(255)', 'type' => 'varchar(25)', 'id_objet' => 'bigint', 'perma' => 'tinyint', 'date' => 'datetime', 'segments' => 'smallint',
		));
	}
	return false;
}
function lister_tables_objets_sql() {
	return array(
		'spip_articles' => array('titre' => 'titre, lang', 'date' => 'date', 'statut' => array(array('champ' => 'statut'))),
		'spip_rubriques' => array('titre' => 'titre, lang', 'date' => 'date', 'statut' => array(array('champ' => 'statut'))),
		'spip_breves' => array('titre' => 'titre', 'date' => 'date_heure', 'statut' => array(array('champ' => 'statut'))),
	);
}
function objet_type($t) {
	return array('spip_articles' => 'article', 'spip_rubriques' => 'rubrique', 'spip_breves' => 'breve')[$t] ?? rtrim(substr($t, 5), 's');
}
function id_table_objet($t) {
	return 'id_' . $t;
}
function table_objet($t) {
	return $t . 's';
}
function sql_countsel($t) {
	return count($GLOBALS['SC']['rows'][$t] ?? array());
}
function sql_in($c, $v) {
	return "$c IN (" . implode(',', array_map('sql_quote', (array) $v)) . ')';
}
function sql_quote($v) {
	return "'" . addslashes($v) . "'";
}
function sql_allfetsel($sel, $from, $where = array()) {
	$rows = $GLOBALS['SC']['rows'][$from] ?? array();
	if ($from === 'spip_urls') {
		$rows = $GLOBALS['SC']['spip_urls'] ?? array();
		$w = is_array($where) ? implode(' AND ', $where) : (string) $where;
		if (preg_match("/type='([^']*)'/", $w, $t) and preg_match('/id_objet=(\d+)/', $w, $i)) {
			$rows = array_values(array_filter($rows, function ($r) use ($t, $i) {
				return $r['type'] === $t[1] and (int) $r['id_objet'] === (int) $i[1];
			}));
		}
	}
	return $rows;
}
function sql_fetsel($s, $f, $w = '') {
	if ($f === 'spip_documents') {
		return array('id_document' => 5, 'titre' => 'La photo', 'fichier' => 'jpg/photo.jpg', 'extension' => 'jpg', 'distant' => 'non',
			'descriptif' => '', 'mode' => 'image', 'largeur' => 10, 'hauteur' => 10, 'taille' => 3, 'credits' => '');
	}
	$r = $GLOBALS['SC']['rows'][$f] ?? array();
	return $r ? $r[0] : false;
}
function typo($t) {
	return preg_replace('/\{([^{}]+)\}/', '<i>$1</i>', $t);
}
function textebrut($t) {
	return trim(strip_tags(html_entity_decode($t)));
}
function supprimer_numero($t) {
	return preg_replace('/^\d+\.\s*/', '', $t);
}
function recuperer_numero($t) {
	return preg_match('/^(\d+)\./', $t, $m) ? $m[1] : '';
}
function translitteration($t) {
	return strtr(iconv('UTF-8', 'ASCII//TRANSLIT', $t), array("'" => ''));
}
function entites_html($t) {
	return htmlspecialchars($t);
}
function sous_repertoire($d, $n) {
	@mkdir($d . $n, 0775, true);
	return $d . $n . '/';
}
function generer_objet_url($id, $type, $args = '', $ancre = '', $public = null) {
	$f = $GLOBALS['SC']['url'] ?? null;
	if ($f) {
		return $f($id, $type);
	}
	return $type == 'rubrique' ? "spip.php?rubrique$id" : "spip.php?$type$id";
}
function calculer_notes() {
	return '';
}
function changer_langue($l) {
	$GLOBALS['spip_lang'] = $l;
	return true;
}
/** Comme SPIP : le bloc de la langue demandée, sinon le premier bloc ; texte hors <multi> conservé */
function extraire_multi($t, $lang = null) {
	$lang = $lang ?: $GLOBALS['spip_lang'];
	return preg_replace_callback('#<multi>(.*?)</multi>#is', function ($m) use ($lang) {
		$blocs = array();
		if (preg_match_all('/\[([a-z_-]+)\](.*?)(?=\[[a-z_-]+\]|$)/s', $m[1], $b, PREG_SET_ORDER)) {
			foreach ($b as $x) {
				$blocs[$x[1]] = trim($x[2]);
			}
		}
		if (!$blocs) {
			return trim($m[1]);
		}
		return $blocs[$lang] ?? reset($blocs);
	}, $t);
}
function propre($t) {
	$t = preg_replace('/\{\{\{(.*?)\}\}\}/', "\n\n<h3 class=\"spip\">$1</h3>\n\n", $t);
	$t = preg_replace('/\{\{(.*?)\}\}/', '<strong class="spip">$1</strong>', $t);
	$t = preg_replace('/\[(.*?)->(.*?)\]/', '<a href="$2" class="spip_in">$1</a>', $t);
	$out = '';
	foreach (preg_split("/\n\n+/", trim($t)) as $b) {
		$b = trim($b);
		if ($b === '') {
			continue;
		}
		if (preg_match('/^- /', $b)) {
			$out .= '<ul class="spip"><li>' . implode("</li>\n<li>", array_map(function ($l) {
				return substr($l, 2);
			}, explode("\n", $b))) . '</li></ul>';
		} elseif (strpos($b, '<h3') === 0) {
			$out .= $b;
		} else {
			$out .= '<p class="spip">' . $b . "</p>\n";
		}
	}
	return $out;
}
