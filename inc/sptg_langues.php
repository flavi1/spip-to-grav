<?php
/**
 * Langues : lecture de la configuration multilingue de SPIP, groupes de traduction (id_trad),
 * détection des blocs <multi>.
 *
 * Trois modèles SPIP, combinables :
 *  - <multi>[fr]…[en]…</multi> dans les champs     -> une page Grav, un fichier par langue (i18n Grav)
 *  - articles traduits (id_trad + lang)             -> un dossier par objet, route SPIP exacte ; les
 *                                                      traductions sont reliées par l'index
 *  - langue par secteur (lang des rubriques/objets) -> idem
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

function sptg_liste_meta($nom) {
	$v = isset($GLOBALS['meta'][$nom]) ? $GLOBALS['meta'][$nom] : '';
	return array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));
}

/**
 * Configuration multilingue du site.
 *
 * @return array langue_site, langues (toutes, langue du site en premier), multi_objets, gerer_trad_objets
 */
function sptg_config_langues() {
	$site = !empty($GLOBALS['meta']['langue_site']) ? $GLOBALS['meta']['langue_site'] : 'fr';
	$langues = sptg_liste_meta('langues_multilingue');
	$langues = array_values(array_unique(array_merge(array($site), $langues)));
	return array(
		'langue_site' => $site,
		'langues' => $langues,
		'multi_objets' => sptg_liste_meta('multi_objets'),
		'gerer_trad_objets' => sptg_liste_meta('gerer_trad_objets'),
	);
}

/** Le texte contient-il un bloc <multi> ? */
function sptg_contient_multi($texte) {
	return is_string($texte) and stripos($texte, '<multi>') !== false;
}

/** Langues pour lesquelles un texte contient un bloc explicite : [en], [fr]… dans un <multi> */
function sptg_langues_du_multi($texte, array $langues) {
	$out = array();
	if (!sptg_contient_multi($texte)) {
		return $out;
	}
	if (preg_match_all('#<multi>(.*?)</multi>#is', $texte, $blocs)) {
		foreach ($blocs[1] as $b) {
			foreach ($langues as $l) {
				if (preg_match('/\[' . preg_quote($l, '/') . '\]/', $b)) {
					$out[$l] = $l;
				}
			}
		}
	}
	return array_values($out);
}

/**
 * Groupes de traduction d'un lot de lignes : id => [langue => id].
 *
 * Un groupe est l'ensemble des lignes de même id_trad (> 0). Une ligne sans traduction
 * (id_trad = 0) est un groupe d'elle-même. Si deux lignes du groupe ont la même langue, la
 * première (id le plus petit) est conservée et l'autre signalée dans $conflits.
 *
 * @param array  $lignes   lignes SQL
 * @param string $cle_id   nom de la colonne identifiant (id_article…)
 * @param array  $conflits (sortie) [[id, id_en_conflit, langue]]
 * @return array
 */
function sptg_groupes_trad(array $lignes, $cle_id, array &$conflits = array()) {
	$groupes = array();
	foreach ($lignes as $r) {
		if (!isset($r['id_trad']) or !isset($r['lang']) or (int) $r['id_trad'] <= 0 or $r['lang'] === '') {
			continue;
		}
		$groupes[(int) $r['id_trad']][] = array('id' => (int) $r[$cle_id], 'lang' => $r['lang']);
	}
	$map = array();
	foreach ($groupes as $membres) {
		usort($membres, function ($a, $b) {
			return $a['id'] <=> $b['id'];
		});
		$g = array();
		foreach ($membres as $m) {
			if (isset($g[$m['lang']])) {
				$conflits[] = array($g[$m['lang']], $m['id'], $m['lang']);
				continue;
			}
			$g[$m['lang']] = $m['id'];
		}
		foreach ($g as $id) {
			$map[$id] = $g;
		}
	}
	return $map;
}
