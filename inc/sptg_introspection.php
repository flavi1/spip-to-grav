<?php
/**
 * Introspection de la structure des objets éditoriaux SPIP
 * (noyau + objets déclarés par plugins : brèves, lexique…).
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Tables d'objets qui ne sont pas exportées comme « pages » */
function sptg_tables_exclues() {
	return array(
		'spip_groupes_mots', 'spip_mots', 'spip_documents', 'spip_forum', 'spip_signatures',
		'spip_petitions', 'spip_syndic_articles', 'spip_jobs', 'spip_urls', 'spip_versions',
		'spip_versions_fragments', 'spip_meta', 'spip_resultats', 'spip_visites', 'spip_referers',
		'spip_paquets', 'spip_plugins', 'spip_depots', 'spip_messages',
	);
}

/** Catégorie d'un type SQL : int, bool, date, texte, enum, nombre, chaine */
function sptg_type_champ($def) {
	$def = strtolower(trim($def));
	if (preg_match('/^tinyint\(1\)/', $def)) {
		return 'bool';
	}
	if (preg_match('/^(tiny|small|medium|big)?int/', $def)) {
		return 'int';
	}
	if (preg_match('/^(datetime|date|timestamp)/', $def)) {
		return 'date';
	}
	if (preg_match('/^((tiny|medium|long)?text|(tiny|medium|long)?blob)/', $def)) {
		return 'texte';
	}
	if (preg_match('/^enum\(/', $def)) {
		return 'enum';
	}
	if (preg_match('/^(float|double|decimal)/', $def)) {
		return 'nombre';
	}
	return 'chaine';
}

function sptg_enum_options($def) {
	$opts = array();
	if (preg_match('/^enum\((.*?)\)/i', $def, $m) and preg_match_all("/'((?:[^']|'')*)'/", $m[1], $mm)) {
		foreach ($mm[1] as $v) {
			$v = str_replace("''", "'", $v);
			$opts[$v] = $v;
		}
	}
	return $opts;
}

/**
 * Introspecte tous les types d'objets éditoriaux.
 *
 * @return array type => [type, table, objets, id, label, champs, titre_sql, col_titre, date, statut, publie, a_rubrique, nb]
 */
function sptg_introspecter($comptes = false) {
	include_spip('base/objets');
	include_spip('base/abstract_sql');
	include_spip('base/connect_sql');
	$trouver_table = charger_fonction('trouver_table', 'base');

	$res = array();
	foreach (lister_tables_objets_sql() as $table => $d) {
		if (in_array($table, sptg_tables_exclues())) {
			continue;
		}
		$desc = $trouver_table($table);
		if (!$desc or empty($desc['field'])) {
			continue;
		}
		$type = objet_type($table);
		$id = id_table_objet($type);
		if (!isset($desc['field'][$id])) {
			continue;
		}

		$statut = isset($desc['field']['statut']) ? 'statut' : '';
		$publie = 'publie';
		if (!empty($d['statut'][0]['champ'])) {
			$statut = $d['statut'][0]['champ'];
		}

		$titre_sql = !empty($d['titre']) ? $d['titre'] : '';
		$col_titre = 'titre';
		if ($titre_sql and preg_match('/(\w+)\s+AS\s+titre\b/i', $titre_sql, $m)) {
			$col_titre = $m[1];
		} elseif (!$titre_sql) {
			foreach (array('titre', 'nom', 'mot', 'terme') as $c) {
				if (isset($desc['field'][$c])) {
					$col_titre = $c;
					$titre_sql = $c . ($c == 'titre' ? '' : ' AS titre');
					break;
				}
			}
		}

		$label = '';
		if (!empty($d['texte_objets'])) {
			$label = _T($d['texte_objets']);
			if (strpos($label, '[') === 0 or $label === $d['texte_objets']) {
				$label = '';
			}
		}
		$objets = table_objet($type);
		if (!$label) {
			$label = ucfirst($objets);
		}

		$res[$type] = array(
			'type' => $type,
			'table' => $table,
			'objets' => $objets,
			'id' => $id,
			'label' => $label,
			'champs' => $desc['field'],
			'titre_sql' => $titre_sql,
			'col_titre' => $col_titre,
			'date' => !empty($d['date']) ? $d['date'] : '',
			'statut' => $statut,
			'publie' => $publie,
			'a_rubrique' => isset($desc['field']['id_rubrique']) and $type != 'rubrique',
			'nb' => $comptes ? (int) sql_countsel($table) : 0,
		);
	}
	return $res;
}

/** Champs qui ne doivent jamais être exportés */
function sptg_champs_sensibles($emails = false) {
	$l = array(
		'maj', 'pass', 'htpass', 'alea_actuel', 'alea_futur', 'low_sec', 'cookie_oubli', 'prefs',
		'imessage', 'messagerie', 'webmestre', 'login', 'en_ligne', 'source', 'id_secteur', 'id_trad',
		'visites', 'referers', 'popularite', 'accepter_forum', 'export',
	);
	if (!$emails) {
		$l[] = 'email';
	}
	return $l;
}

/**
 * Champs exportés dans le bloc `spip:` du frontmatter (et décrits dans les blueprints).
 *
 * @return array nom => ['kind' => ..., 'def' => ...]
 */
function sptg_champs_exportables(array $info, $emails = false) {
	$exclus = array_merge(
		sptg_champs_sensibles($emails),
		array($info['id'], $info['col_titre'], $info['date'], 'texte')
	);
	$out = array();
	foreach ($info['champs'] as $nom => $def) {
		if (in_array($nom, $exclus)) {
			continue;
		}
		$out[$nom] = array('kind' => sptg_type_champ($def), 'def' => $def);
	}
	return $out;
}
