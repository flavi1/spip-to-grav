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

/**
 * Classe PHP du thème : résolveur d'anciennes URL (onPageNotFound), index des identifiants SPIP,
 * fonctions Twig sptg_traductions() et sptg_alternates(). La logique est dans sptg_runtime.php ;
 * ici seul l'accès à Grav (pages, URL, langues).
 *
 * Plugin::getSubscribedEvents() abonne automatiquement toute méthode publique dont le nom commence par « on ».
 */
function sptg_classe_theme($classe) {
	return <<<PHP
<?php
namespace Grav\Theme;

use Grav\Common\Theme;
use Grav\Theme\Sptg\Runtime;

require_once __DIR__ . '/sptg_runtime.php';

/** Thème généré par le plugin SPIP « spip_to_grav » */
class $classe extends Theme
{
	/** @var Runtime|null */
	private \$sptg;

	private function sptgRuntime(): Runtime
	{
		if (\$this->sptg) {
			return \$this->sptg;
		}
		\$grav = \$this->grav;
		\$locator = \$grav['locator'];
		\$pagesDir = rtrim((string) \$locator->findResource('page://', true), '/');
		\$dataDir = (string) \$locator->findResource('user://data', true, true);

		\$info = function (\$page) use (\$grav) {
			\$h = \$page->header();
			\$type = isset(\$h->spip_type) ? (string) \$h->spip_type : '';
			\$id = isset(\$h->spip_id) ? (int) \$h->spip_id : 0;
			if (\$type === '' or \$id <= 0) {
				return null;
			}
			return [
				'type' => \$type, 'id' => \$id,
				'lang' => isset(\$h->spip_lang) ? (string) \$h->spip_lang : '',
				'url' => \$page->url(true), 'title' => (string) \$page->title(),
			];
		};
		\$charger = function (\$dossier) use (\$grav, \$pagesDir, \$info) {
			\$page = \$grav['pages']->get(\$pagesDir . '/' . \$dossier);
			return \$page ? \$info(\$page) : null;
		};
		\$parcourir = function () use (\$grav, \$pagesDir, \$info) {
			foreach (\$grav['pages']->all() as \$page) {
				\$i = \$info(\$page);
				if (\$i and strpos((string) \$page->path(), \$pagesDir . '/') === 0) {
					\$i['dossier'] = substr((string) \$page->path(), strlen(\$pagesDir) + 1);
					yield \$i;
				}
			}
		};
		\$langues = function (array \$p) use (\$grav, \$pagesDir) {
			\$out = [];
			\$page = \$grav['pages']->get(\$pagesDir . '/' . \$p['dossier']);
			if (\$page) {
				foreach (\$page->translatedLanguages(true) as \$lang => \$route) {
					\$out[\$lang] = \$grav['uri']->rootUrl(true) . \$grav['pages']->baseRoute(\$lang) . \$route;
				}
			}
			return \$out;
		};
		return \$this->sptg = new Runtime(\$dataDir, \$charger, \$parcourir, \$langues);
	}

	/** Ancienne URL SPIP (chemin ou spip.php?...) : redirection 301 vers la page actuelle de l'objet */
	public function onPageNotFound(): void
	{
		\$req = (string) (\$_SERVER['REQUEST_URI'] ?? '/');
		\$chemin = (string) parse_url(\$req, PHP_URL_PATH);
		\$requete = (string) parse_url(\$req, PHP_URL_QUERY);
		\$racine = (string) \$this->grav['uri']->rootUrl(false);
		if (\$racine !== '' and strpos(\$chemin, \$racine) === 0) {
			\$chemin = substr(\$chemin, strlen(\$racine));
		}
		\$p = \$this->sptgRuntime()->pourAncienneUrl(\$chemin, \$requete);
		if (!\$p) {
			return;
		}
		// pas de boucle : la cible n'est pas la requête elle-même
		if (\$requete === '' and rtrim((string) parse_url(\$p['url'], PHP_URL_PATH), '/') === rtrim(\$racine . \$chemin, '/')) {
			return;
		}
		\$this->grav->redirect(\$p['url'], 301);
	}

	public function onTwigExtensions(): void
	{
		\$twig = \$this->grav['twig']->twig();
		\$self = \$this;
		\$ident = function (\$page) {
			\$h = \$page ? \$page->header() : null;
			return [isset(\$h->spip_type) ? (string) \$h->spip_type : '', isset(\$h->spip_id) ? (int) \$h->spip_id : 0,
				isset(\$h->spip_lang) ? (string) \$h->spip_lang : ''];
		};
		\$twig->addFunction(new \\Twig\\TwigFunction('sptg_traductions', function (\$page) use (\$self, \$ident) {
			list(\$type, \$id, \$lang) = \$ident(\$page);
			if (\$type === '' or \$id <= 0) {
				return [];
			}
			\$actif = \$self->grav['language']->enabled() ? (string) \$self->grav['language']->getActive() : '';
			return \$self->sptgRuntime()->traductions(\$type, \$id, \$actif !== '' ? \$actif : \$lang);
		}));
		\$twig->addFunction(new \\Twig\\TwigFunction('sptg_alternates', function (\$page) use (\$self, \$ident) {
			list(\$type, \$id, \$lang) = \$ident(\$page);
			if (\$type === '' or \$id <= 0) {
				return '';
			}
			\$langue = \$self->grav['language'];
			\$defaut = \$langue->enabled() ? (string) \$langue->getDefault() : (string) \$self->grav['config']->get('site.lang', '');
			\$actif = \$langue->enabled() ? (string) \$langue->getActive() : '';
			return \$self->sptgRuntime()->alternates(\$type, \$id, \$defaut, \$actif !== '' ? \$actif : \$lang);
		}, ['is_safe' => ['html']]));
	}
}

PHP;
}

function sptg_generer_theme($dest, $slug, array $types, $emails) {
	sptg_copier_arbre(dirname(__DIR__) . '/grav_theme', rtrim($dest, '/'));

	$classe = str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)));

	sptg_ecrire($dest . $slug . '.php', sptg_classe_theme($classe));
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
