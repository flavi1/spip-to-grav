<?php
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

function formulaires_exporter_grav_charger_dist() {
	include_spip('inc/sptg_introspection');
	include_spip('inc/sptg_export');
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

	$nom_site = isset($GLOBALS['meta']['nom_site']) ? $GLOBALS['meta']['nom_site'] : '';
	$export_name = _request('export_name') ?: (sptg_slug($nom_site) ?: 'spip2grav');
	$dest = _request('dossier_destination') ?: '/tmp/grav';

	return array(
		'_objets' => $objets,
		'_secteurs' => $secteurs,
		'_statuts' => $statuts,
		'_urls_params' => sptg_urls_a_parametres(),
		'_detection' => sptg_chemin_valide($dest) ? sptg_detecter_grav($dest) : false,
		'objets' => array_keys($objets),
		'secteurs' => array_keys($secteurs),
		'statuts' => array('publie'),
		'export_name' => $export_name,
		'dossier_destination' => $dest,
		'vider' => '',
		'generer_theme' => 'oui',
		'nom_theme' => '',
		'markdown' => 'oui',
		'verifier' => 'oui',
		'urls_spip' => 'oui',
		'mode_images' => 'partage',
		'conserver_source' => '',
		'inclure_emails' => '',
		'creer_zip' => '',
	);
}

function formulaires_exporter_grav_verifier_dist() {
	include_spip('inc/sptg_export');
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

	if (_request('urls_spip') == 'oui' and sptg_urls_a_parametres()) {
		$erreurs['urls_spip'] = _T('sptg:erreur_urls_params');
	}
	if (!in_array(_request('mode_images'), array('partage', 'img'))) {
		$erreurs['mode_images'] = _T('sptg:erreur_slug');
	}

	$name = (string) _request('export_name');
	if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
		$erreurs['export_name'] = _T('sptg:erreur_slug');
	}
	$theme = (string) _request('nom_theme');
	if ($theme !== '' and !preg_match('/^[a-z0-9][a-z0-9-]*$/', $theme)) {
		$erreurs['nom_theme'] = _T('sptg:erreur_slug');
	}

	$dest = (string) _request('dossier_destination');
	if (!sptg_chemin_valide($dest)) {
		$erreurs['dossier_destination'] = _T('sptg:erreur_destination');
	} elseif (!isset($erreurs['export_name'])) {
		list($racine, $install) = sptg_cible($dest, $name);
		$pages = $racine . 'user/pages';
		$slug_theme = $theme !== '' ? $theme : $name . '-theme';
		$conflits = array();
		if (sptg_dossier_non_vide($pages)) {
			$conflits[] = $pages;
		}
		if (_request('generer_theme') == 'oui' and is_dir($racine . 'user/themes/' . $slug_theme)) {
			$conflits[] = $racine . 'user/themes/' . $slug_theme;
		}
		if ($conflits and !_request('vider')) {
			$erreurs['vider'] = _T('sptg:erreur_non_vide', array('liste' => implode(', ', $conflits)))
				. ($install ? ' ' . _T('sptg:info_installation') : '');
		}
		$existant = is_dir($dest) ? $dest : dirname($dest);
		while ($existant and !is_dir($existant) and dirname($existant) !== $existant) {
			$existant = dirname($existant);
		}
		if (!is_writable($existant)) {
			$erreurs['dossier_destination'] = _T('sptg:erreur_ecriture');
		}
	}
	return $erreurs;
}

function formulaires_exporter_grav_traiter_dist() {
	include_spip('inc/sptg_export');
	include_spip('inc/actions');

	$opts = array(
		'export_name' => (string) _request('export_name'),
		'dossier_destination' => (string) _request('dossier_destination'),
		'vider' => (bool) _request('vider'),
		'nom_theme' => (string) _request('nom_theme'),
		'objets' => (array) _request('objets'),
		'secteurs' => array_map('intval', (array) _request('secteurs')),
		'statuts' => array_map('strval', (array) _request('statuts')),
		'generer_theme' => _request('generer_theme') == 'oui',
		'markdown' => _request('markdown') == 'oui',
		'verifier' => _request('verifier') == 'oui',
		'urls_spip' => _request('urls_spip') == 'oui',
		'mode_images' => _request('mode_images') == 'img' ? 'img' : 'partage',
		'conserver_source' => _request('conserver_source') == 'oui',
		'inclure_emails' => _request('inclure_emails') == 'oui',
		'creer_zip' => _request('creer_zip') == 'oui',
	);

	try {
		$r = sptg_exporter($opts);
	} catch (\Throwable $e) {
		return array('message_erreur' => entites_html($e->getMessage()), 'editable' => true);
	}

	$msg = _T('sptg:resultat_ok', array('pages' => $r['pages'], 'dossier' => entites_html($r['racine'])));
	if ($r['ignores']) {
		$msg .= ' ' . _T('sptg:resultat_ignores', array('nb' => $r['ignores']));
	}
	$msg .= '<br />' . implode(', ', array_map('entites_html', $r['details']));
	$msg .= '<br />' . _T('sptg:resultat_verif', array(
		'ecarts' => count($r['ecarts']), 'html' => $r['fmt_html'], 'liens' => count($r['liens_bruts']),
	));
	$msg .= '<br />' . _T('sptg:resultat_urls', array('redir' => $r['redirections'], 'non' => $r['non_redirigeables']));

	if ($r['ecarts']) {
		$msg .= '<details><summary>' . _T('sptg:resultat_echantillons') . '</summary>';
		foreach (array_slice($r['ecarts'], 0, 8) as $e) {
			$msg .= '<pre style="white-space:pre-wrap;text-align:left">' . entites_html(
				$e['page'] . ' / ' . $e['champ'] . "\nATTENDU : " . $e['attendu'] . "\nOBTENU  : " . $e['obtenu']
				. "\nMARKDOWN :\n" . $e['markdown'] . "\nSOURCE SPIP :\n" . $e['source']
			) . '</pre>';
		}
		$msg .= '</details>';
	}
	if ($r['snippet']) {
		$msg .= '<details><summary>' . _T('sptg:resultat_config') . '</summary><pre style="text-align:left">'
			. entites_html($r['snippet']) . '</pre></details>';
	}
	$msg .= '<br /><a href="' . entites_html(generer_action_auteur('sptg_telecharger', $r['rapport'])) . '">'
		. _T('sptg:resultat_rapport') . '</a>';
	if ($r['zip']) {
		$msg .= ' | <a href="' . entites_html(generer_action_auteur('sptg_telecharger', $r['zip'])) . '">'
			. _T('sptg:resultat_telecharger') . '</a>';
	} elseif ($r['zip_refuse']) {
		$msg .= '<br />' . _T('sptg:resultat_zip_refuse', array('raison' => $r['zip_refuse']));
	}

	return array('message_ok' => $msg, 'editable' => true);
}
