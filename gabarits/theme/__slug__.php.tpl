<?php
namespace Grav\Theme;

use Grav\Common\Theme;
use Grav\Theme\Sptg\Runtime;

require_once __DIR__ . '/sptg_runtime.php';

/**
 * Thème généré par le plugin SPIP « spip_to_grav » (gabarit gabarits/theme/__slug__.php.tpl) :
 * ne pas éditer, relancer l'export.
 */
class @@CLASSE@@ extends Theme
{
	/** @var Runtime|null */
	private $sptg;

	private function sptgRuntime(): Runtime
	{
		if ($this->sptg) {
			return $this->sptg;
		}
		$grav = $this->grav;
		$locator = $grav['locator'];
		$pagesDir = rtrim((string) $locator->findResource('page://', true), '/');
		$dataDir = (string) $locator->findResource('user://data', true, true);

		$info = function ($page) use ($grav) {
			$h = $page->header();
			$type = isset($h->spip_type) ? (string) $h->spip_type : '';
			$id = isset($h->spip_id) ? (int) $h->spip_id : 0;
			if ($type === '' or $id <= 0) {
				return null;
			}
			return [
				'type' => $type, 'id' => $id,
				'lang' => isset($h->spip_lang) ? (string) $h->spip_lang : '',
				'url' => $page->url(true), 'title' => (string) $page->title(),
			];
		};
		$charger = function ($dossier) use ($grav, $pagesDir, $info) {
			$page = $grav['pages']->get($pagesDir . '/' . $dossier);
			return $page ? $info($page) : null;
		};
		$parcourir = function () use ($grav, $pagesDir, $info) {
			foreach ($grav['pages']->all() as $page) {
				$i = $info($page);
				if ($i and strpos((string) $page->path(), $pagesDir . '/') === 0) {
					$i['dossier'] = substr((string) $page->path(), strlen($pagesDir) + 1);
					yield $i;
				}
			}
		};
		$langues = function (array $p) use ($grav, $pagesDir) {
			$out = [];
			$page = $grav['pages']->get($pagesDir . '/' . $p['dossier']);
			if ($page) {
				foreach ($page->translatedLanguages(true) as $lang => $route) {
					$out[$lang] = $grav['uri']->rootUrl(true) . $grav['pages']->baseRoute($lang) . $route;
				}
			}
			return $out;
		};
		return $this->sptg = new Runtime($dataDir, $charger, $parcourir, $langues);
	}

	/** Ancienne URL SPIP (chemin ou spip.php?...) : redirection 301 vers la page actuelle de l'objet */
	public function onPageNotFound(): void
	{
		$req = (string) ($_SERVER['REQUEST_URI'] ?? '/');
		$chemin = (string) parse_url($req, PHP_URL_PATH);
		$requete = (string) parse_url($req, PHP_URL_QUERY);
		$racine = (string) $this->grav['uri']->rootUrl(false);
		if ($racine !== '' and strpos($chemin, $racine) === 0) {
			$chemin = substr($chemin, strlen($racine));
		}
		$p = $this->sptgRuntime()->pourAncienneUrl($chemin, $requete);
		if (!$p) {
			return;
		}
		// pas de boucle : la cible n'est pas la requête elle-même
		if ($requete === '' and rtrim((string) parse_url($p['url'], PHP_URL_PATH), '/') === rtrim($racine . $chemin, '/')) {
			return;
		}
		$this->grav->redirect($p['url'], 301);
	}

	public function onTwigExtensions(): void
	{
		$twig = $this->grav['twig']->twig();
		$self = $this;
		$ident = function ($page) {
			$h = $page ? $page->header() : null;
			return [isset($h->spip_type) ? (string) $h->spip_type : '', isset($h->spip_id) ? (int) $h->spip_id : 0,
				isset($h->spip_lang) ? (string) $h->spip_lang : ''];
		};
		$twig->addFunction(new \Twig\TwigFunction('sptg_traductions', function ($page) use ($self, $ident) {
			list($type, $id, $lang) = $ident($page);
			if ($type === '' or $id <= 0) {
				return [];
			}
			$actif = $self->grav['language']->enabled() ? (string) $self->grav['language']->getActive() : '';
			return $self->sptgRuntime()->traductions($type, $id, $actif !== '' ? $actif : $lang);
		}));
		$twig->addFunction(new \Twig\TwigFunction('sptg_alternates', function ($page) use ($self, $ident) {
			list($type, $id, $lang) = $ident($page);
			if ($type === '' or $id <= 0) {
				return '';
			}
			$langue = $self->grav['language'];
			$defaut = $langue->enabled() ? (string) $langue->getDefault() : (string) $self->grav['config']->get('site.lang', '');
			$actif = $langue->enabled() ? (string) $langue->getActive() : '';
			return $self->sptgRuntime()->alternates($type, $id, $defaut, $actif !== '' ? $actif : $lang);
		}, ['is_safe' => ['html']]));
	}
}
