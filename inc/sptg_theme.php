<?php
/**
 * Génération du thème Grav (templates Twig + blueprints) d'après la structure
 * des tables SPIP introspectées. Le socle statique (blueprints, css) est dans grav_theme/ ; les fichiers PHP et Twig sont générés depuis gabarits/.
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/sptg_introspection');
include_spip('inc/sptg_yaml');

/** Champs « techniques » à ne pas afficher dans les templates */
function sptg_champs_masques_affichage() {
	return array('lang', 'statut', 'url_propre', 'virtuel', 'date_modif', 'date_redac', 'date_creation', 'composition', 'composition_lock', 'rang', 'profondeur');
}

function sptg_label_champ($nom) {
	return ucfirst(str_replace('_', ' ', $nom));
}

/** Définition d'un champ de blueprint Grav d'après son type SQL */
function sptg_champ_blueprint($nom, array $c) {
	$f = array('type' => 'text', 'label' => sptg_label_champ($nom));
	switch ($c['kind']) {
		case 'texte':
			$f['type'] = 'textarea';
			$f['rows'] = 8;
			break;
		case 'int':
		case 'nombre':
			$f['type'] = 'number';
			break;
		case 'bool':
			$f['type'] = 'toggle';
			$f['highlight'] = 1;
			$f['default'] = 0;
			$f['options'] = array(1 => 'Oui', 0 => 'Non');
			$f['validate'] = array('type' => 'bool');
			break;
		case 'date':
			$f['placeholder'] = 'AAAA-MM-JJ HH:MM';
			break;
		case 'enum':
			$f['type'] = 'select';
			$f['options'] = sptg_enum_options($c['def']);
			break;
	}
	if (strpos($nom, 'id_') === 0) {
		$f['readonly'] = true;
	}
	return $f;
}

function sptg_blueprint_objet(array $info, $emails) {
	$champs = array();
	foreach (sptg_champs_exportables($info, $emails) as $nom => $c) {
		$champs['header.spip.' . $nom] = sptg_champ_blueprint($nom, $c);
	}
	return array(
		'title' => $info['label'] . ' (SPIP)',
		'@extends' => array('type' => 'default', 'context' => 'blueprints://pages'),
		'form' => array('fields' => array('tabs' => array(
			'type' => 'tabs',
			'fields' => array('spip' => array(
				'type' => 'tab',
				'title' => 'SPIP : ' . $info['label'],
				'fields' => $champs,
			)),
		))),
	);
}

// ---------------------------------------------------------------- helpers d'un thème existant

/** Version d'API thème <-> plugin attendue (doit égaler Grav\Theme\Sptg\Runtime::API ; vérifié par les tests) */
define('SPTG_API_THEME', 2);
/** Préfixe des liens stables (doit égaler Runtime::SCHEME dans gabarits/theme/sptg_runtime.php.tpl). */
define('SPTG_LIEN_SCHEME', 'idx');

/**
 * Un thème déjà présent (réutilisé sans régénération) comporte-t-il nos helpers, et sont-ils assez récents ?
 * La version est lue dans son sptg_runtime.php (« const API = N; »). Un thème venant d'une ancienne
 * version du plugin n'a pas cette constante ; il a pu aussi être personnalisé : on ne le modifie jamais.
 *
 * @return array ['etat' => absent|ancien|ok, 'api' => int|null, 'message' => string]
 */
function sptg_theme_helpers($dir_theme) {
	$f = rtrim($dir_theme, '/') . '/sptg_runtime.php';
	if (!is_file($f)) {
		return array('etat' => 'absent', 'api' => null, 'message' => "le thème n'a pas les helpers spip_to_grav (sptg_runtime.php absent)");
	}
	$api = preg_match('/\bconst\s+API\s*=\s*(\d+)\s*;/', (string) file_get_contents($f), $m) ? (int) $m[1] : null;
	if ($api === null or $api < SPTG_API_THEME) {
		return array('etat' => 'ancien', 'api' => $api, 'message' => 'les helpers du thème sont trop anciens (API ' . ($api === null ? 'inconnue' : $api) . ', ' . SPTG_API_THEME . ' requise)');
	}
	$php = glob(rtrim($dir_theme, '/') . '/*.php');
	$classe = false;
	foreach ((array) $php as $p) {
		if (basename($p) !== 'sptg_runtime.php' and strpos((string) file_get_contents($p), 'sptgRuntime') !== false) {
			$classe = true;
		}
	}
	if (!$classe) {
		return array('etat' => 'absent', 'api' => $api, 'message' => "le fichier PHP principal du thème n'utilise pas les helpers (sptgRuntime absent)");
	}
	return array('etat' => 'ok', 'api' => $api, 'message' => '');
}

// ---------------------------------------------------------------- gabarits

/** Dossier des « templates de templates » (fichiers .tpl dont sont générés les fichiers PHP et Twig du thème) */
function sptg_gabarits_dir() {
	return dirname(__DIR__) . '/gabarits';
}

/**
 * Rend un gabarit.
 *
 * Marqueurs : @@NOM@@ (majuscules, chiffres, _). Un marqueur seul sur sa ligne est remplacé par un
 * bloc (la ligne est supprimée si le bloc est vide) ; ailleurs, par du texte en ligne. Un marqueur sans
 * valeur est une erreur (exception) : un thème ne doit jamais contenir de marqueur oublié. Les valeurs ne
 * sont pas ré-interprétées (pas d'injection de marqueurs).
 *
 * @param string $nom  chemin relatif à gabarits/, sans le suffixe .tpl (ex. 'objet/champ_texte.twig')
 * @param array  $vars NOM => valeur
 * @throws RuntimeException
 */
function sptg_gabarit($nom, array $vars) {
	$f = sptg_gabarits_dir() . '/' . $nom . '.tpl';
	if (!is_file($f)) {
		throw new RuntimeException("Gabarit introuvable : $nom");
	}
	return sptg_gabarit_texte(file_get_contents($f), $vars, $nom);
}

function sptg_gabarit_texte($texte, array $vars, $nom = '?') {
	$valeur = function ($cle) use ($vars, $nom) {
		if (!array_key_exists($cle, $vars)) {
			throw new RuntimeException("Gabarit $nom : variable $cle non fournie");
		}
		return (string) $vars[$cle];
	};
	$lignes = explode("\n", $texte);
	$out = array();
	foreach ($lignes as $l) {
		if (preg_match('/^[ \t]*@@([A-Z][A-Z0-9_]*)@@[ \t]*$/', $l, $m)) {
			$v = rtrim($valeur($m[1]), "\n");
			if ($v !== '') {
				$out[] = $v;
			}
			continue;
		}
		$out[] = preg_replace_callback('/@@([A-Z][A-Z0-9_]*)@@/', function ($m) use ($valeur) {
			return $valeur($m[1]);
		}, $l);
	}
	return implode("\n", $out);
}

/** Nombre de marqueurs @@NOM@@ non résolus dans un texte généré (0 attendu) */
function sptg_gabarit_restes($texte) {
	return preg_match_all('/@@[A-Z][A-Z0-9_]*@@/', (string) $texte);
}

/** Template Twig spécifique à un type d'objet, généré depuis gabarits/objet/ */
function sptg_twig_objet(array $info, $emails) {
	$champs = sptg_champs_exportables($info, $emails);
	$masques = sptg_champs_masques_affichage();
	$lead = array();
	$tail = array();
	$dl = array();
	foreach ($champs as $nom => $c) {
		if (in_array($nom, $masques) or strpos($nom, 'id_') === 0) {
			continue;
		}
		if ($c['kind'] == 'texte') {
			if (in_array($nom, array('surtitre', 'soustitre', 'chapo', 'descriptif'))) {
				$lead[] = $nom;
			} else {
				$tail[] = $nom;
			}
		} elseif ($c['kind'] == 'chaine') {
			if (in_array($nom, array('surtitre', 'soustitre'))) {
				$lead[] = $nom;
			} else {
				$dl[] = $nom;
			}
		}
	}
	$ordre = array('surtitre', 'soustitre', 'descriptif', 'chapo');
	usort($lead, function ($a, $b) use ($ordre) {
		return array_search($a, $ordre) <=> array_search($b, $ordre);
	});

	$bloc_texte = function (array $noms) {
		$h = array();
		foreach ($noms as $n) {
			$h[] = rtrim(sptg_gabarit('objet/champ_texte.twig', array('NOM' => $n)), "\n");
		}
		return implode("\n", $h);
	};
	$liste = '';
	if ($dl) {
		$lignes = array();
		foreach ($dl as $n) {
			$valeur = sptg_gabarit(strpos($n, 'url') !== false ? 'objet/valeur_url.twig' : 'objet/valeur_texte.twig', array('NOM' => $n));
			$lignes[] = rtrim(sptg_gabarit('objet/ligne_champ.twig', array(
				'NOM' => $n, 'LABEL' => sptg_label_champ($n), 'VALEUR' => trim($valeur),
			)), "\n");
		}
		$liste = sptg_gabarit('objet/liste_champs.twig', array('LIGNES' => implode("\n", $lignes)));
	}
	return sptg_gabarit('objet/objet.html.twig', array(
		'TYPE' => $info['type'],
		'CHAMPS_TETE' => $bloc_texte($lead),
		'CHAMPS_QUEUE' => $bloc_texte($tail),
		'LISTE_CHAMPS' => $liste,
		'ENFANTS' => $info['type'] == 'rubrique' ? sptg_gabarit('objet/enfants.twig', array()) : '',
	));
}

/**
 * Génère les fichiers PHP et Twig du socle du thème : chaque gabarits/theme/**\/*.tpl devient le fichier
 * du même nom sans « .tpl » (« __slug__ » dans un nom de fichier est remplacé par le slug du thème).
 *
 * Le fichier PHP principal du thème (résolveur d'anciennes URL, index des identifiants SPIP, fonctions Twig
 * sptg_traductions() et sptg_alternates()) vient de gabarits/theme/__slug__.php.tpl ; la logique est dans
 * sptg_runtime.php. Plugin::getSubscribedEvents() abonne automatiquement toute méthode publique dont le nom
 * commence par « on ».
 *
 * @return array liste des fichiers écrits (relatifs à $dest)
 */
function sptg_generer_socle($dest, $slug, array $vars) {
	$base = sptg_gabarits_dir() . '/theme';
	$vars = $vars + array('SLUG' => $slug);
	$ecrits = array();
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $fichier) {
		$rel = substr($fichier->getPathname(), strlen($base) + 1);
		if (substr($rel, -4) !== '.tpl') {
			continue;
		}
		$rel = str_replace('__slug__', $slug, substr($rel, 0, -4));
		sptg_ecrire($dest . $rel, sptg_gabarit('theme/' . substr($fichier->getPathname(), strlen($base) + 1, -4), $vars));
		$ecrits[] = $rel;
	}
	sort($ecrits);
	return $ecrits;
}

function sptg_generer_theme($dest, $slug, array $types, $emails) {
	sptg_copier_arbre(dirname(__DIR__) . '/grav_theme', rtrim($dest, '/'));

	$classe = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));

	$langue = isset($GLOBALS['meta']['langue_site']) ? (string) $GLOBALS['meta']['langue_site'] : 'fr';
	if (!preg_match('/^[a-z]{2,3}([_-][a-zA-Z]{2,4})?$/', $langue)) {
		$langue = 'fr';
	}
	sptg_generer_socle($dest, $slug, array('CLASSE' => $classe, 'LANGUE_SITE' => $langue));
	sptg_ecrire($dest . $slug . '.yaml', "enabled: true\n");
	sptg_ecrire($dest . 'blueprints.yaml', sptg_yaml(array(
		'name' => ucfirst($slug),
		'slug' => $slug,
		'type' => 'theme',
		'version' => '0.1.0',
		'description' => 'Thème généré depuis un site SPIP (templates Twig + blueprints par type d\'objet).',
		'icon' => 'file-text-o',
		'author' => array('name' => 'spip_to_grav'),
		'dependencies' => array(array('name' => 'grav', 'version' => '>=1.7.0')),
		'form' => array('validation' => 'loose', 'fields' => array('enabled' => array(
			'type' => 'toggle', 'label' => 'PLUGIN_ADMIN.PLUGIN_STATUS', 'highlight' => 1, 'default' => 1,
			'options' => array(1 => 'PLUGIN_ADMIN.ENABLED', 0 => 'PLUGIN_ADMIN.DISABLED'),
			'validate' => array('type' => 'bool'),
		))),
	)));

	foreach ($types as $type => $info) {
		sptg_ecrire($dest . 'templates/' . $type . '.html.twig', sptg_twig_objet($info, $emails));
		sptg_ecrire($dest . 'blueprints/' . $type . '.yaml', sptg_yaml(sptg_blueprint_objet($info, $emails)));
	}
}
