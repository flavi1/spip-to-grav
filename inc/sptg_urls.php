<?php
/**
 * URL : schémas de routes Grav, anciennes URL SPIP (redirections), clés de recherche.
 *
 * Principe : l'arborescence des dossiers (admin Grav) est découplée des URL publiques.
 * La route publique de chaque page est posée dans `routes.default` quand elle diffère de
 * la route « brute » (celle qui découle des dossiers).
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Schémas de structure proposés : code => [libellé, recommandé] */
function sptg_schemas_urls() {
	return array(
		'spip' => array('Identique à SPIP quand c\'est possible (sinon hiérarchique)', false),
		'hierarchique' => array('Hiérarchique : /rubrique/sous-rubrique/article', true),
		'hierarchique_type' => array('Hiérarchique typé : /rubrique/sous-rubrique/article/mon-article', false),
		'plat_type' => array('Plat par type : /article/mon-article', false),
	);
}

/**
 * Clé de recherche d'une ancienne URL (chemin + requête), identique côté thème
 * (grav_theme/sptg_runtime.php : Sptg\Runtime::cleLegacy). Tout changement ici doit être répercuté là-bas ;
 * tests/run.php vérifie que les deux implémentations donnent le même résultat.
 *
 * Règles : chemin décodé, sans « ./ », sans slash final (sauf la racine), en minuscules ;
 * requête découpée sur « & », variables ignorées retirées (var_*, PHPSESSID, fbclid, utm_*, lang),
 * morceaux triés, en minuscules.
 */
function sptg_cle_legacy($chemin, $requete = '') {
	$chemin = rawurldecode((string) $chemin);
	$chemin = preg_replace('~^(\./)+~', '', $chemin);
	$chemin = '/' . trim($chemin, '/');
	$chemin = mb_strtolower($chemin);
	$morceaux = array();
	foreach (explode('&', (string) $requete) as $m) {
		$m = trim(str_replace('&amp;', '', $m));
		if ($m === '') {
			continue;
		}
		$nom = mb_strtolower(rawurldecode(strtok($m, '=')));
		if (preg_match('/^(var_|phpsessid$|fbclid$|utm_|lang$)/', $nom)) {
			continue;
		}
		$morceaux[] = mb_strtolower(rawurldecode($m));
	}
	sort($morceaux);
	return $chemin . ($morceaux ? '?' . implode('&', $morceaux) : '');
}

/** Nom de fichier de l'index legacy pour une clé */
function sptg_nom_legacy($cle) {
	return sha1($cle) . '.json';
}

/** Sépare « chemin?requête » d'une URL SPIP relative */
function sptg_url_decouper($u) {
	$u = (string) $u;
	$q = '';
	if (strpos($u, '?') !== false) {
		list($u, $q) = explode('?', $u, 2);
	}
	return array($u, $q);
}

/**
 * Route « à la SPIP » d'après une URL de type chemin : sans extension ni slash final.
 *
 * @param string $chemin chemin relatif (sans slash initial)
 * @return string route Grav (avec slash initial), '' si elle serait vide
 */
function sptg_route_depuis_chemin($chemin) {
	$chemin = preg_replace('/\.(html?|php)$/i', '', trim((string) $chemin, '/'));
	$chemin = preg_replace('~/{2,}~', '/', $chemin);
	return $chemin === '' ? '' : '/' . $chemin;
}

/**
 * Route publique d'un objet selon le schéma choisi.
 *
 * @param string $schema   spip|hierarchique|hierarchique_type|plat_type
 * @param string $type     type d'objet
 * @param string $slug     dernier segment
 * @param string $route_dossier route brute (issue des dossiers) de la page
 * @param string $route_parent  route publique du parent (pour les schémas hiérarchiques)
 * @param array|null $url_spip  résultat de sptg_url_spip()
 * @return string route publique
 */
function sptg_route_publique($schema, $type, $slug, $route_dossier, $route_parent, $url_spip) {
	switch ($schema) {
		case 'spip':
			if ($url_spip and isset($url_spip['path'])) {
				$r = sptg_route_depuis_chemin($url_spip['path']);
				if ($r !== '') {
					return $r;
				}
			}
			return $route_dossier;
		case 'hierarchique_type':
			return $type == 'rubrique' ? $route_dossier : $route_parent . '/' . $type . '/' . $slug;
		case 'plat_type':
			return '/' . $type . '/' . $slug;
		default:
			return $route_dossier;
	}
}

/**
 * Anciennes URL d'un objet : l'URL « actuelle » SPIP si elle diffère de la nouvelle route,
 * puis les URL historiques de spip_urls (gestion avancée des URL : anciennes URL conservées).
 *
 * Pour les URL arborescentes, spip_urls ne stocke que le dernier segment : l'ancien chemin est
 * reconstitué avec les parents actuels. C'est une approximation, signalée dans le rapport.
 *
 * @return array liste de ['chemin' => ..., 'requete' => ..., 'approx' => bool]
 */
function sptg_anciennes_urls($type, $id, $url_spip, $route_publique, array $historiques = array()) {
	$out = array();
	$vus = array();
	$ajout = function ($chemin, $requete, $approx) use (&$out, &$vus, $route_publique) {
		$cle = sptg_cle_legacy($chemin, $requete);
		if ($requete === '' and $cle === mb_strtolower($route_publique)) {
			return;
		}
		if (isset($vus[$cle])) {
			return;
		}
		$vus[$cle] = 1;
		$out[] = array('chemin' => '/' . ltrim($chemin, '/'), 'requete' => $requete, 'approx' => $approx);
	};
	if ($url_spip) {
		if (isset($url_spip['path'])) {
			$ajout($url_spip['path'], '', false);
		} elseif (isset($url_spip['query'])) {
			list($c, $q) = sptg_url_decouper($url_spip['query']);
			$ajout($c, $q, false);
		}
	}
	$courant = ($url_spip and isset($url_spip['path'])) ? $url_spip['path'] : '';
	foreach ($historiques as $seg) {
		$seg = trim((string) $seg);
		if ($seg === '' or $courant === '') {
			continue;
		}
		$dir = strpos($courant, '/') !== false ? substr($courant, 0, strrpos($courant, '/') + 1) : '';
		$ext = preg_match('/(\.[a-z0-9]{2,5})$/i', $courant, $m) ? $m[1] : '';
		$ancien = $dir . $seg . (substr($seg, -strlen($ext)) === $ext ? '' : $ext);
		$ajout($ancien, '', true);
	}
	return $out;
}

/**
 * Lit les segments d'URL (principale et historiques) d'un objet dans spip_urls.
 * Retourne array() si la table n'existe pas ou si ses colonnes ne sont pas celles attendues.
 */
function sptg_urls_historiques($type, $id) {
	// sql_showtable() met déjà ses résultats en cache côté SPIP
	$table_ok = false;
	if (function_exists('sql_showtable')) {
		$d = sql_showtable('spip_urls', true);
		$table_ok = is_array($d) and isset($d['field']['url'], $d['field']['id_objet'], $d['field']['type']);
		$table_ok = (bool) $table_ok;
	}
	if (!$table_ok) {
		return array();
	}
	// Toutes les lignes : l'URL principale (celle qui a priorité : perma puis date récente) est
	// l'URL actuelle, déjà connue ; sptg_anciennes_urls() dédoublonne.
	$rows = sql_allfetsel('url', 'spip_urls', 'type=' . sql_quote($type) . ' AND id_objet=' . (int) $id) ?: array();
	$out = array();
	foreach ($rows as $r) {
		$out[] = $r['url'];
	}
	return $out;
}
