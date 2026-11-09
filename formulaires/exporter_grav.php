<?php
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

function formulaires_exporter_grav_charger_dist() {
	include_spip('inc/sptg_introspection');
	include_spip('inc/filtres');

	$objets = array();
	foreach (sptg_introspecter(true) as $type => $i) {
		$objets[$type] = array('label' => $i['label'] . ' (' . $i['nb'] . ')');
	}
	$objets['mot'] = array('label' => _T('sptg:objet_mot'));
	$objets['document'] = array('label' => _T('sptg:objet_document'));

	$secteurs = array();
	$rows = sql_allfetsel('id_rubrique, titre', 'spip_rubriques', 'id_parent=0', '', '0+titre, titre');
	foreach ($rows as $r) {
		$secteurs[$r['id_rubrique']] = supprimer_numero(textebrut(typo($r['titre']))) . ' (#' . $r['id_rubrique'] . ')';
	}

	$statuts = array();
	foreach (array('publie', 'prop', 'prepa', 'refuse', 'poubelle') as $s) {
		$statuts[$s] = _T('sptg:statut_' . $s);
	}

	return array(
		'_objets' => $objets,
		'_secteurs' => $secteurs,
		'_statuts' => $statuts,
		'objets' => array_keys($objets),
		'secteurs' => array_keys($secteurs),
		'statuts' => array('publie'),
		'generer_theme' => 'oui',
		'nom_theme' => 'spip2grav',
		'conserver_img' => 'oui',
		'copier_documents' => '',
		'conserver_source' => '',
		'inclure_emails' => '',
	);
}

function formulaires_exporter_grav_verifier_dist() {
	$erreurs = array();
	$objets = (array) _request('objets');
	if (!$objets) {
		$erreurs['objets'] = _T('sptg:erreur_aucun_objet');
	}
	if (in_array('rubrique', $objets) and !_request('secteurs')) {
		$erreurs['secteurs'] = _T('sptg:erreur_aucun_secteur');
	}
	if (!_request('statuts')) {
		$erreurs['statuts'] = _T('sptg:erreur_aucun_statut');
	}
	if (_request('generer_theme') == 'oui' and !preg_match('/^[a-z0-9][a-z0-9-]*$/', (string) _request('nom_theme'))) {
		$erreurs['nom_theme'] = _T('sptg:erreur_nom_theme');
	}
	return $erreurs;
}

function formulaires_exporter_grav_traiter_dist() {
	include_spip('inc/sptg_export');
	include_spip('inc/actions');

	$opts = array(
		'objets' => (array) _request('objets'),
		'secteurs' => array_map('intval', (array) _request('secteurs')),
		'statuts' => array_map('strval', (array) _request('statuts')),
		'generer_theme' => _request('generer_theme') == 'oui',
		'nom_theme' => (string) _request('nom_theme') ?: 'spip2grav',
		'conserver_img' => _request('conserver_img') == 'oui',
		'copier_documents' => _request('copier_documents') == 'oui',
		'conserver_source' => _request('conserver_source') == 'oui',
		'inclure_emails' => _request('inclure_emails') == 'oui',
	);

	$r = sptg_exporter($opts);

	$url = generer_action_auteur('sptg_telecharger', $r['zip']);
	$msg = _T('sptg:resultat_ok', array('pages' => $r['pages']));
	if ($r['ignores']) {
		$msg .= ' ' . _T('sptg:resultat_ignores', array('nb' => $r['ignores']));
	}
	if (!empty($r['details'])) {
		$msg .= '<br />' . implode(', ', array_map('entites_html', $r['details']));
	}
	$msg .= '<br /><a href="' . entites_html($url) . '">' . _T('sptg:resultat_telecharger') . '</a>';

	return array('message_ok' => $msg, 'editable' => true);
}
