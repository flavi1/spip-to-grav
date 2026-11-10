<?php
/**
 * Logos des objets SPIP.
 *
 * Depuis SPIP 4.0, un logo est un document de la base (spip_documents.mode = 'logoon' / 'logooff', lié par
 * spip_documents_liens, fichier sous IMG/<extension>/). L'ancien format (IMG/rubon3.png, arton12.jpg…) n'est plus
 * qu'un repli de compatibilité dans inc/chercher_logo (« deprecated »).
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Préfixe historique du nom de fichier : « rub » pour les rubriques (rubon3.png), « art » pour les articles… */
function sptg_prefixe_logo($type) {
	if (function_exists('type_du_logo') and function_exists('id_table_objet')) {
		return type_du_logo(id_table_objet($type));
	}
	$m = array('article' => 'art', 'rubrique' => 'rub', 'auteur' => 'aut', 'mot' => 'mot', 'breve' => 'breve', 'site' => 'site');
	return isset($m[$type]) ? $m[$type] : $type;
}

/**
 * Cherche le logo d'un objet (mode 'on' ou 'off' pour le survol).
 *
 * @return array|null ['abs' => chemin disque, 'rel' => 'IMG/…', 'source' => 'base'|'fichier']
 */
function sptg_logo_trouver($type, $id, $mode = 'on') {
	$doc = sql_fetsel(
		'D.fichier, D.extension, D.distant',
		'spip_documents AS D JOIN spip_documents_liens AS L ON L.id_document=D.id_document',
		array(
			'D.mode=' . sql_quote('logo' . $mode),
			'L.objet=' . sql_quote($type),
			'L.id_objet=' . (int) $id,
		)
	);
	if ($doc and !empty($doc['fichier']) and ($doc['distant'] ?? 'non') !== 'oui') {
		return array('abs' => _DIR_IMG . $doc['fichier'], 'rel' => 'IMG/' . $doc['fichier'], 'source' => 'base');
	}
	// repli : ancien format de fichier, par ex. IMG/rubon3.png
	$g = glob(_DIR_IMG . sptg_prefixe_logo($type) . $mode . (int) $id . '.*');
	if ($g) {
		return array('abs' => $g[0], 'rel' => 'IMG/' . basename($g[0]), 'source' => 'fichier');
	}
	return null;
}

/** Nombre de logos de survol (non exportés) : documents de mode logooff. */
function sptg_logos_survol_nb() {
	return (int) sql_countsel('spip_documents', "mode='logooff'");
}
