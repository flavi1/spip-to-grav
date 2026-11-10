<?php
/**
 * Champs Extras (plugin SPIP « cextras » + interface « iextras »).
 *
 * Fonctionnement du plugin (v4.x, lu dans ses sources) :
 *  - chaque champ extra est une colonne SQL ajoutée à la table de l'objet (pipeline declarer_tables_objets_sql) ;
 *    le champ est donc déjà vu par l'introspection comme un champ ordinaire ;
 *  - sa définition (« saisie » du plugin Saisies : type, label, explication, obligatoire, défaut, datas…) vient du
 *    pipeline `declarer_champs_extras` : table SQL => liste de saisies, éventuellement imbriquées dans des fieldsets.
 *    L'interface (iextras) y ajoute celles stockées dans les métas `champs_extras_<table>` ;
 *  - les valeurs multiples (cases à cocher, sélection multiple) sont stockées en texte, séparées par des virgules.
 *
 * Ici : chaque champ extra est écrit dans l'entête de la page sous `extras:` (et non plus mêlé aux champs SPIP de
 * `spip:`), avec une valeur typée, et le blueprint reprend sa configuration (label, type de champ, options,
 * obligatoire, valeur par défaut, aide).
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/sptg_introspection');

/** Types de saisies qui ne portent pas de valeur */
function sptg_extras_saisies_sans_valeur() {
	return array('fieldset', 'explication', 'alerte', 'hidden', 'case_informative', 'message');
}

/** Types de saisies dont la valeur est une liste (stockée « a,b,c ») */
function sptg_extras_saisies_multiples() {
	return array('checkbox', 'selection_multiple', 'mot', 'auteur', 'choix_multiple', 'selecteur_rubrique_multiple');
}

/**
 * Aplatit une liste de saisies (fieldsets compris).
 *
 * @return array nom => ['saisie' => type, 'options' => [...], 'groupe' => libellé du fieldset parent]
 */
function sptg_extras_aplatir($saisies, $groupe = '') {
	$out = array();
	if (!is_array($saisies)) {
		return $out;
	}
	foreach ($saisies as $s) {
		if (!is_array($s) or empty($s['saisie'])) {
			continue;
		}
		$opt = isset($s['options']) && is_array($s['options']) ? $s['options'] : array();
		if (!empty($s['saisies'])) {
			$libelle = isset($opt['label']) ? sptg_extras_texte($opt['label']) : '';
			$out = array_merge($out, sptg_extras_aplatir($s['saisies'], $libelle !== '' ? $libelle : $groupe));
			continue;
		}
		if (in_array($s['saisie'], sptg_extras_saisies_sans_valeur()) or empty($opt['nom'])) {
			continue;
		}
		$out[$opt['nom']] = array('saisie' => $s['saisie'], 'options' => $opt, 'groupe' => $groupe);
	}
	return $out;
}

/** Texte d'un libellé : clé de langue « module:cle » traduite, sinon texte tel quel (sans balises) */
function sptg_extras_texte($t) {
	$t = trim((string) $t);
	if ($t === '') {
		return '';
	}
	if (preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/i', $t) and function_exists('_T')) {
		$r = _T($t);
		if (is_string($r) and $r !== '' and $r !== $t and strpos($r, '[') !== 0) {
			$t = $r;
		}
	}
	return trim(strip_tags(html_entity_decode($t, ENT_QUOTES, 'UTF-8')));
}

/**
 * Définitions des champs extras, par type d'objet.
 *
 * @return array type => [nom => ['saisie','options','groupe']]
 */
function sptg_extras_definitions($reset = false) {
	static $cache = null;
	if ($reset) {
		$cache = null;
	}
	if ($cache !== null) {
		return $cache;
	}
	$cache = array();
	if (!function_exists('pipeline')) {
		return $cache;
	}
	$tables = pipeline('declarer_champs_extras', array());
	if (!is_array($tables)) {
		return $cache;
	}
	foreach ($tables as $table => $saisies) {
		$type = function_exists('objet_type') ? objet_type($table) : preg_replace('/^spip_/', '', rtrim($table, 's'));
		$def = sptg_extras_aplatir($saisies);
		if ($def) {
			$cache[$type] = isset($cache[$type]) ? array_merge($cache[$type], $def) : $def;
		}
	}
	return $cache;
}

/**
 * Champs extras d'un type d'objet, ordonnés comme dans leur déclaration, limités aux colonnes existantes.
 *
 * @return array nom => ['kind','def','saisie','options','groupe','label','multiple','texte']
 */
function sptg_extras_champs(array $info) {
	$defs = sptg_extras_definitions();
	$type = $info['type'];
	$out = array();
	if (empty($defs[$type])) {
		return $out;
	}
	foreach ($defs[$type] as $nom => $d) {
		if (!isset($info['champs'][$nom])) {
			continue; // déclaré mais colonne absente de la table : rien à exporter
		}
		$def_sql = $info['champs'][$nom];
		$kind = sptg_type_champ($def_sql);
		$multiple = in_array($d['saisie'], sptg_extras_saisies_multiples()) || !empty($d['options']['multiple']);
		$label = isset($d['options']['label']) ? sptg_extras_texte($d['options']['label']) : '';
		if ($multiple) {
			$kind = 'liste';
		} elseif ($d['saisie'] == 'oui_non' or $d['saisie'] == 'case') {
			$kind = 'bool';
		}
		$out[$nom] = array(
			'kind' => $kind, 'def' => $def_sql, 'saisie' => $d['saisie'], 'options' => $d['options'], 'groupe' => $d['groupe'],
			'label' => $label !== '' ? $label : ucfirst(str_replace('_', ' ', $nom)), 'multiple' => $multiple,
		);
	}
	return $out;
}

/** Noms des champs extras d'un type (exclus du bloc `spip:`) */
function sptg_extras_noms($type) {
	$defs = sptg_extras_definitions();
	return isset($defs[$type]) ? array_keys($defs[$type]) : array();
}

/** Valeurs proposées (datas) d'un champ : tableau valeur => libellé */
function sptg_extras_datas(array $options) {
	$datas = isset($options['datas']) ? $options['datas'] : array();
	if (is_string($datas)) {
		$tmp = array();
		foreach (preg_split('/\R/', $datas) as $ligne) {
			$ligne = trim($ligne);
			if ($ligne === '') {
				continue;
			}
			if (strpos($ligne, '|') !== false) {
				list($v, $l) = explode('|', $ligne, 2);
				$tmp[trim($v)] = trim($l);
			} else {
				$tmp[$ligne] = $ligne;
			}
		}
		$datas = $tmp;
	}
	$out = array();
	foreach ((array) $datas as $v => $l) {
		$out[(string) $v] = sptg_extras_texte(is_array($l) ? implode(' ', $l) : $l);
	}
	return $out;
}

/**
 * Valeur typée d'un champ extra pour l'entête.
 *
 * @return mixed null si vide
 */
function sptg_extras_valeur($v, array $c) {
	if ($v === null or $v === '') {
		return null;
	}
	switch ($c['kind']) {
		case 'liste':
			$l = array_values(array_filter(array_map('trim', explode(',', (string) $v)), 'strlen'));
			return $l ?: null;
		case 'bool':
			return in_array(strtolower((string) $v), array('on', 'oui', '1', 'true'), true);
		case 'int':
			return (int) $v;
		case 'nombre':
			return (float) $v;
		case 'date':
			return function_exists('sptg_date') ? sptg_date($v) : (string) $v;
	}
	return (string) $v;
}

/** Définition d'un champ de blueprint Grav d'après la configuration du champ extra */
function sptg_extra_blueprint($nom, array $c) {
	$o = $c['options'];
	$f = array('type' => 'text', 'label' => $c['label']);
	$datas = sptg_extras_datas($o);
	switch ($c['saisie']) {
		case 'textarea':
			$f['type'] = 'textarea';
			$f['rows'] = !empty($o['rows']) ? (int) $o['rows'] : 6;
			break;
		case 'oui_non':
		case 'case':
			$f['type'] = 'toggle';
			$f['highlight'] = 1;
			$f['options'] = array(1 => 'Oui', 0 => 'Non');
			$f['validate'] = array('type' => 'bool');
			break;
		case 'radio':
		case 'selection':
			$f['type'] = ($c['saisie'] == 'radio') ? 'radio' : 'select';
			$f['options'] = $datas;
			break;
		case 'checkbox':
		case 'selection_multiple':
			$f['type'] = $c['saisie'] == 'checkbox' ? 'checkboxes' : 'select';
			if ($c['saisie'] != 'checkbox') {
				$f['multiple'] = true;
			}
			$f['options'] = $datas;
			$f['use'] = 'keys';
			break;
		case 'date':
		case 'heure':
			$f['type'] = 'datetime';
			break;
		case 'fichier':
		case 'document':
			$f['type'] = 'text';
			$f['help'] = 'Fichier : chemin ou URL';
			break;
		default:
			if ($c['kind'] == 'int' or $c['kind'] == 'nombre') {
				$f['type'] = 'number';
			} elseif (!empty($o['type']) and in_array($o['type'], array('email', 'url', 'number', 'password', 'tel'))) {
				$f['type'] = $o['type'] == 'password' ? 'text' : $o['type'];
			}
	}
	if (!empty($o['explication'])) {
		$f['help'] = sptg_extras_texte($o['explication']);
	}
	if (isset($o['defaut']) and $o['defaut'] !== '' and !is_array($o['defaut'])) {
		$f['default'] = in_array($c['saisie'], array('oui_non', 'case')) ? (int) in_array(strtolower((string) $o['defaut']), array('on', 'oui', '1'), true) : $o['defaut'];
	}
	if (!empty($o['placeholder'])) {
		$f['placeholder'] = sptg_extras_texte($o['placeholder']);
	}
	if (!empty($o['obligatoire'])) {
		$f['validate'] = array_merge(isset($f['validate']) ? $f['validate'] : array(), array('required' => true));
	}
	if (!empty($o['maxlength'])) {
		$f['validate'] = array_merge(isset($f['validate']) ? $f['validate'] : array(), array('max' => (int) $o['maxlength']));
	}
	return $f;
}

/**
 * Champs de blueprint (clé `header.extras.<nom>`) de tous les champs extras d'un type, avec un séparateur
 * (`section`) à chaque changement de fieldset.
 */
function sptg_extras_blueprint_champs(array $info) {
	$out = array();
	$groupe = null;
	$i = 0;
	foreach (sptg_extras_champs($info) as $nom => $c) {
		if ($c['groupe'] !== '' and $c['groupe'] !== $groupe) {
			$out['extras_section_' . (++$i)] = array('type' => 'section', 'title' => $c['groupe'], 'underline' => true);
		}
		$groupe = $c['groupe'];
		$out['header.extras.' . $nom] = sptg_extra_blueprint($nom, $c);
	}
	return $out;
}
