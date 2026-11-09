<?php
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Télécharge une archive d'export générée précédemment */
function action_sptg_telecharger_dist($arg = null) {
	if (is_null($arg)) {
		$securiser_action = charger_fonction('securiser_action', 'inc');
		$arg = $securiser_action();
	}
	include_spip('inc/autoriser');
	if (!autoriser('exporter', '_sptg') or !preg_match('/^sptg_export_[0-9_]+\.zip$/', $arg)) {
		include_spip('inc/minipres');
		echo minipres();
		exit;
	}
	$f = _DIR_TMP . 'sptg/' . $arg;
	if (!is_file($f)) {
		http_response_code(404);
		exit;
	}
	header('Content-Type: application/zip');
	header('Content-Disposition: attachment; filename="' . $arg . '"');
	header('Content-Length: ' . filesize($f));
	readfile($f);
	exit;
}
