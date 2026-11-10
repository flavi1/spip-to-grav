<?php
/**
 * Index des identifiants SPIP : user/data/spip-id/
 *
 *   <type>/<id>.json        {type, id, lang, trad: {lang: id}, dossier, langues}
 *   _legacy/urls/<sha1>.json   ancienne URL -> {type, id, url}
 *   _legacy/<type>/<id>.json   entrée d'index déplacée par l'auto-réparation (côté thème)
 *
 * `dossier` est le chemin du dossier de la page relatif à user/pages/ ; aucune route n'est stockée :
 * elle est relue dans la page à la demande, donc exacte après un déplacement dans l'admin Grav.
 * Le thème (grav_theme/sptg_runtime.php) lit et répare cet index.
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/sptg_urls');

function sptg_index_dir($racine) {
	return rtrim($racine, '/') . '/user/data/spip-id';
}

function sptg_index_json($data) {
	return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
}

/** Repart d'un index vide (l'index est entièrement régénéré par l'export) */
function sptg_index_vider($racine) {
	$d = sptg_index_dir($racine);
	if (is_dir($d)) {
		sptg_rm_contenu($d);
	}
}

/**
 * @param array $f type, id, lang (ou ''), trad (lang => id), dossier (relatif à user/pages/, sans slash final), langues
 */
function sptg_index_ecrire($racine, array $f) {
	$data = array(
		'type' => $f['type'],
		'id' => (int) $f['id'],
		'lang' => $f['lang'] !== '' ? $f['lang'] : null,
		'trad' => $f['trad'] ? $f['trad'] : new stdClass(),
		'dossier' => $f['dossier'],
	);
	if (!empty($f['langues'])) {
		$data['langues'] = array_values($f['langues']);
	}
	sptg_ecrire(sptg_index_dir($racine) . '/' . $f['type'] . '/' . (int) $f['id'] . '.json', sptg_index_json($data));
}

function sptg_index_legacy_url($racine, $chemin, $requete, $type, $id) {
	$cle = sptg_cle_legacy($chemin, $requete);
	sptg_ecrire(
		sptg_index_dir($racine) . '/_legacy/urls/' . sptg_nom_legacy($cle),
		sptg_index_json(array('url' => $cle, 'type' => $type, 'id' => (int) $id))
	);
}
