<?php
/**
 * Génération du thème Grav (templates Twig + blueprints) d'après la structure
 * des tables SPIP introspectées. Le socle statique est dans grav_theme/.
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

/** Template Twig spécifique à un type d'objet */
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

	$t = "{% extends 'partials/base.html.twig' %}\n\n{% block content %}\n";
	$t .= '<article class="spip-objet spip-' . $info['type'] . "\">\n";
	$t .= "\t<header>\n\t\t<h1>{{ page.title|e }}</h1>\n";
	$t .= "\t\t{% if page.header.date %}<time datetime=\"{{ page.date|date('c') }}\">{{ page.date|date('d/m/Y') }}</time>{% endif %}\n\t</header>\n";
	$t .= "\t{% set s = page.header.spip ?? {} %}\n";
	$t .= "\t{% include 'partials/logo.html.twig' %}\n";
	foreach ($lead as $n) {
		$t .= "\t{% if s.$n %}<div class=\"spip-champ spip-$n\">{% if '$n' in (page.header.spip_html ?? []) %}{{ s.$n|raw }}{% else %}{{ s.$n|markdown|raw }}{% endif %}</div>{% endif %}\n";
	}
	$t .= "\t<div class=\"spip-texte\">{{ page.content|raw }}</div>\n";
	foreach ($tail as $n) {
		$t .= "\t{% if s.$n %}<div class=\"spip-champ spip-$n\">{% if '$n' in (page.header.spip_html ?? []) %}{{ s.$n|raw }}{% else %}{{ s.$n|markdown|raw }}{% endif %}</div>{% endif %}\n";
	}
	if ($dl) {
		$t .= "\t<dl class=\"spip-champs\">\n";
		foreach ($dl as $n) {
			$val = (strpos($n, 'url') !== false)
				? "<a href=\"{{ s.$n|e('html_attr') }}\">{{ s.$n|e }}</a>"
				: "{{ s.$n|e }}";
			$t .= "\t\t{% if s.$n %}<dt>" . sptg_label_champ($n) . "</dt><dd>$val</dd>{% endif %}\n";
		}
		$t .= "\t</dl>\n";
	}
	$t .= "\t{% include 'partials/documents.html.twig' %}\n";
	$t .= "\t{% include 'partials/taxonomies.html.twig' %}\n";
	if ($info['type'] == 'rubrique') {
		$t .= "\t{% include 'partials/enfants.html.twig' %}\n";
	}
	$t .= "</article>\n{% endblock %}\n";
	return $t;
}

function sptg_generer_theme($dest, $slug, array $types, $emails) {
	sptg_copier_arbre(dirname(__DIR__) . '/grav_theme', rtrim($dest, '/'));

	$classe = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));

	sptg_ecrire($dest . $slug . '.php', "<?php\nnamespace Grav\\Theme;\n\nuse Grav\\Common\\Theme;\n\n"
		. "/** Thème généré par le plugin SPIP « spip_to_grav » */\nclass $classe extends Theme\n{\n}\n");
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
