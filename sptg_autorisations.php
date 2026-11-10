<?php
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

function sptg_autoriser() {
}

/** Exporter : réservé aux webmestres */
function autoriser_sptg_exporter_dist($faire, $type, $id, $qui, $opt) {
	return autoriser('webmestre', '', 0, $qui);
}

/** Visibilité de l'entrée de menu (paquet.xml : menu exporter_grav ; SPIP appelle autoriser(menu, _exporter_grav)) */
function autoriser_exporter_grav_menu_dist($faire, $type, $id, $qui, $opt) {
	return autoriser('exporter', '_sptg', 0, $qui);
}
