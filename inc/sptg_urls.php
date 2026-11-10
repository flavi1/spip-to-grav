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
 * (gabarits/theme/sptg_runtime.php.tpl : Sptg\Runtime::cleLegacy). Tout changement ici doit être répercuté là-bas ;
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
 * Décompose le chemin d'une URL SPIP de type chemin : [terminaison, langue, base].
 *
 * - terminaison : « .html », « / »… (non stockée dans spip_urls) ;
 * - langue : premier segment quand il est un code de langue (_url_arbo_multilang = true) ;
 * - base : le reste, sans terminaison.
 */
function sptg_chemin_decouper($chemin, array $langues = array()) {
	$chemin = trim((string) $chemin);
	$t = '';
	if (preg_match('~(\.[a-z0-9]{2,5}|/)$~i', $chemin, $m)) {
		$t = $m[1];
		$chemin = substr($chemin, 0, -strlen($t));
	}
	$chemin = trim($chemin, '/');
	$lang = '';
	$segs = explode('/', $chemin);
	if (count($segs) > 1 and in_array($segs[0], $langues, true)) {
		$lang = array_shift($segs);
	}
	return array($t, $lang, implode('/', $segs));
}

/**
 * Anciennes URL d'un objet : l'URL « actuelle » SPIP si elle diffère de la nouvelle route,
 * puis les URL historiques de spip_urls (gestion avancée des URL : anciennes URL conservées).
 *
 * Dans spip_urls (plugin urls_etendues), une ligne = (id_parent, url, langue, segments…) :
 * `url` peut contenir un préfixe de type (« article/titre », segments = 2), `id_parent` est le
 * parent dans la hiérarchie des URL (0 = racine). Le chemin d'une ancienne URL est donc
 * « chemin du parent + url + terminaison » ; le chemin du parent est fourni par $chemin_parent
 * (callable id_parent => base du chemin SPIP actuel du parent, ou null si inconnu). Sans lui,
 * le préfixe est déduit du chemin actuel de l'objet, et l'URL est comptée comme approximative.
 *
 * @param array         $historiques   lignes de spip_urls (ou simples chaînes « url »)
 * @param callable|null $chemin_parent id_parent => base du chemin du parent ou null
 * @param array         $langues       langues du site (pour reconnaître le préfixe de langue)
 * @return array liste de ['chemin' => ..., 'requete' => ..., 'approx' => bool]
 */
function sptg_anciennes_urls($type, $id, $url_spip, $route_publique, array $historiques = array(), $chemin_parent = null, array $langues = array()) {
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
	if (!$url_spip or !isset($url_spip['path'])) {
		return $out;
	}
	list($terminaison, $lang_courante, $base) = sptg_chemin_decouper($url_spip['path'], $langues);

	$rows = array();
	foreach ($historiques as $h) {
		$h = is_array($h) ? $h : array('url' => $h);
		$u = trim((string) ($h['url'] ?? ''), "/ \t");
		if ($u !== '') {
			$h['url'] = $u;
			$rows[] = $h;
		}
	}
	// ligne de l'URL actuelle : celle dont l'url termine la base actuelle (la plus longue)
	$prefixe_courant = null;
	$meilleure = -1;
	foreach ($rows as $h) {
		$u = $h['url'];
		if (($base === $u or substr($base, -strlen($u) - 1) === '/' . $u) and strlen($u) > $meilleure) {
			$meilleure = strlen($u);
			$prefixe_courant = substr($base, 0, strlen($base) - strlen($u));
		}
	}
	if ($prefixe_courant === null) {
		$prefixe_courant = strpos($base, '/') !== false ? substr($base, 0, strrpos($base, '/') + 1) : '';
	}

	foreach ($rows as $h) {
		$prefixe = $prefixe_courant;
		$approx = true;
		if (isset($h['id_parent']) and (int) $h['id_parent'] === 0) {
			$prefixe = '';
			$approx = false;
		} elseif (isset($h['id_parent']) and $chemin_parent) {
			$pp = call_user_func($chemin_parent, (int) $h['id_parent']);
			if ($pp !== null and $pp !== '') {
				$prefixe = rtrim($pp, '/') . '/';
				$approx = false;
			}
		}
		$l = '';
		if ($lang_courante !== '') {
			$l = (isset($h['langue']) and $h['langue'] !== '') ? $h['langue'] : $lang_courante;
			$l .= '/';
		}
		$ajout($l . $prefixe . $h['url'] . $terminaison, '', $approx);
	}
	return $out;
}

/**
 * Lit les lignes d'URL (principale et historiques) d'un objet dans spip_urls.
 * Retourne array() si la table n'existe pas ou si ses colonnes ne sont pas celles attendues
 * (spip_urls : id_parent, url, type, id_objet, date, segments, perma, langue — plugin urls_etendues).
 *
 * @return array lignes ['url', 'id_parent', 'segments', 'langue', 'perma'] (colonnes présentes)
 */
function sptg_urls_historiques($type, $id) {
	$champs = array();
	if (function_exists('sql_showtable')) {
		$d = sql_showtable('spip_urls', true);
		if (is_array($d) and isset($d['field']['url'], $d['field']['id_objet'], $d['field']['type'])) {
			$champs = array_values(array_intersect(array('url', 'id_parent', 'segments', 'langue', 'perma', 'date'), array_keys($d['field'])));
		}
	}
	if (!$champs) {
		return array();
	}
	// Toutes les lignes : l'URL principale (celle qui a priorité : perma puis date récente) est
	// l'URL actuelle, déjà connue ; sptg_anciennes_urls() dédoublonne.
	return sql_allfetsel(implode(',', $champs), 'spip_urls', 'type=' . sql_quote($type) . ' AND id_objet=' . (int) $id) ?: array();
}
