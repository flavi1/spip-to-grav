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

	/**
	 * Sortie de page : liens stables « idx:type/id » résolus (le cache de contenu de Grav n'est pas concerné) et,
	 * pour un site installé dans un sous-dossier, chemins absolus du HTML brut préfixés par ce sous-dossier.
	 * L'administration n'est jamais touchée.
	 */
	public function onOutputGenerated(): void
	{
		$out = $this->grav->output ?? null;
		if (!is_string($out) or $out === '') {
			return;
		}
		$chemin = (string) $this->grav['uri']->path();
		foreach (['admin', 'admin2'] as $plugin) {
			$route = (string) $this->grav['config']->get('plugins.' . $plugin . '.route', '');
			if ($route !== '' and strpos($chemin, $route) === 0) {
				return;
			}
		}
		$rt = $this->sptgRuntime();
		$langue = $this->grav['language'];
		$actif = $langue->enabled() ? (string) $langue->getActive() : '';
		$out = $rt->resoudreLiens($out, $actif);
		$out = $rt->prefixerRacine($out, (string) $this->grav['base_url_relative']);
		$this->grav->output = $out;
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
		// compte Grav d'un auteur (nom, intitulé, email) : l'email n'est affiché que si le template le décide
		$twig->addFunction(new \Twig\TwigFunction('sptg_compte', function ($username) use ($self) {
			$username = (string) $username;
			if (!preg_match('/^[a-z0-9_-]{3,16}$/', $username)) {
				return null;
			}
			try {
				$u = $self->grav['accounts']->load($username);
			} catch (\Throwable $e) {
				return null;
			}
			$nom = (string) $u->get('fullname');
			$email = (string) $u->get('email');
			if ($nom === '' && $email === '') {
				return null;
			}
			return ['username' => $username, 'fullname' => $nom !== '' ? $nom : $username, 'title' => (string) $u->get('title'), 'email' => $email];
		}));
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
