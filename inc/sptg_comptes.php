<?php
/**
 * Auteurs SPIP -> comptes Grav (rédacteurs d'admin2).
 *
 * Un compte est écrit dans `user/accounts/<identifiant>.yaml` pour chaque auteur « administrateur » ou
 * « rédacteur » SPIP, et les pages (articles, etc.) et la page d'un auteur lui sont rattachées par cet identifiant.
 *  - Email valide et unique : compte actif, SANS mot de passe. À la première connexion, l'auteur utilise
 *    « Mot de passe oublié » avec l'adresse enregistrée dans SPIP : le plugin `login` retrouve le compte par cette
 *    adresse, pose un jeton valable 24 h et envoie le lien (POST /auth/forgot-password du plugin `api` ;
 *    Controller::taskForgot de `login`) ; la réponse est toujours neutre, l'adresse n'est donc divulguée à personne.
 *  - Sinon : compte désactivé et sans email, qui ne sert qu'à porter l'attribution des pages.
 *
 * L'email n'est écrit que dans le compte (`user/accounts/` n'est pas servi par Grav), jamais dans les pages ;
 * le thème le lit dans le compte quand on demande de l'afficher (page d'auteur, option « inclure les emails »).
 * Les comptes portent `spip_auteur: <id>` : un nouvel export retrouve ses comptes et n'en crée pas de doublons.
 *
 * Prérequis côté Grav, signalés dans le rapport : plugins `login`, `email`, `api` et `admin2`, et un expéditeur email
 * configuré (`plugins.email.from` + moteur d'envoi).
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

/** Statuts SPIP qui donnent un compte Grav => intitulé. */
function sptg_comptes_statuts() {
	return array('0minirezo' => 'Administrateur', '1comite' => 'Rédacteur');
}

/**
 * Droits d'un administrateur : super-administrateur de Grav (admin classique et admin2).
 */
function sptg_comptes_acces_admin() {
	return array(
		'site' => array('login' => true),
		'admin' => array('login' => true, 'super' => true),
		'api' => array('access' => true, 'super' => true),
	);
}

/**
 * Droits d'un rédacteur dans admin2 : connexion, lecture/écriture des pages et des médias.
 * Pas de droits de configuration, de plugins, d'utilisateurs ni de super-administration.
 * Les administrateurs SPIP (non restreints) reçoivent à la place sptg_comptes_acces_admin().
 */
function sptg_comptes_acces() {
	return array(
		'site' => array('login' => true),
		'api' => array(
			'access' => true,
			'pages' => array('read' => true, 'write' => true),
			'media' => array('read' => true, 'write' => true),
		),
	);
}

/** Identifiant Grav (`^[a-z0-9_-]{3,16}$`) tiré d'un login ou d'un nom, sans accents. */
function sptg_compte_identifiant($texte, $id_auteur) {
	$t = (string) $texte;
	if (function_exists('translitteration')) {
		$t = translitteration($t);
	} elseif (function_exists('iconv')) {
		$s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
		$t = $s === false ? $t : $s;
	}
	$t = strtolower($t);
	$t = trim(preg_replace('/[^a-z0-9_-]+/', '-', $t), '-_');
	if ($t === '') {
		$t = 'auteur' . (int) $id_auteur;
	}
	while (strlen($t) < 3) {
		$t .= (int) $id_auteur > 0 ? (string) (int) $id_auteur : '0';
	}
	return substr($t, 0, 16);
}

/** Identifiant libre : suffixe numérique, la base étant raccourcie pour rester sous 16 caractères. */
function sptg_compte_identifiant_libre($base, array $pris) {
	$u = $base;
	for ($i = 2; isset($pris[$u]); $i++) {
		$u = substr($base, 0, 16 - strlen((string) $i) - 1) . '-' . $i;
	}
	return $u;
}

/**
 * Construit les comptes à écrire (fonction pure, testable).
 *
 * Chaque administrateur / rédacteur SPIP a un compte, pour que les pages puissent leur être rattachées :
 *  - adresse email valide et unique : compte actif, sans mot de passe (première connexion par « mot de passe oublié ») ;
 *  - sinon : compte DÉSACTIVÉ et sans email (il porte l'attribution des pages ; à compléter puis activer dans admin2) ;
 *  - un compte déjà présent dans la destination n'est jamais modifié : l'auteur lui est rattaché s'il a déjà été créé
 *    par un export précédent (clé `spip_auteur`) ou s'il a la même adresse email.
 *
 * @param array $auteurs  lignes spip_auteurs : id_auteur, nom, login, email, statut[, restreint]
 * @param array $existants identifiants déjà présents dans user/accounts/ (nom => true)
 * @param array $emails_existants emails des comptes présents, en minuscules (email => identifiant)
 * @param array $par_id comptes créés par un export précédent (id_auteur => identifiant)
 * @return array [
 *   'comptes'    => identifiant => données à écrire,
 *   'map'        => id_auteur => identifiant (tous les auteurs rattachables),
 *   'rattaches'  => [['id_auteur','nom','compte','raison']] auteurs rattachés à un compte existant,
 *   'desactives' => [['id_auteur','nom','compte','raison']] comptes créés désactivés,
 * ]
 */
function sptg_comptes_construire(array $auteurs, array $existants = array(), array $emails_existants = array(), array $par_id = array()) {
	$statuts = sptg_comptes_statuts();
	$comptes = array();
	$map = array();
	$rattaches = array();
	$desactives = array();
	$pris = $existants;
	$emails_crees = array();

	usort($auteurs, function ($a, $b) {
		return (int) $a['id_auteur'] - (int) $b['id_auteur'];
	});
	foreach ($auteurs as $a) {
		$id = (int) $a['id_auteur'];
		$nom = trim((string) $a['nom']);
		if (!isset($statuts[$a['statut']])) {
			continue;
		}
		if (isset($par_id[$id])) {
			$map[$id] = $par_id[$id];
			continue;
		}
		$email = trim((string) $a['email']);
		$valide = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
		$cle_email = strtolower($email);
		if ($valide and isset($emails_existants[$cle_email]) and is_string($emails_existants[$cle_email])) {
			$map[$id] = $emails_existants[$cle_email];
			$rattaches[] = array('id_auteur' => $id, 'nom' => $nom, 'compte' => $emails_existants[$cle_email], 'raison' => 'même adresse email que ce compte existant');
			continue;
		}
		$actif = $valide;
		$raison = '';
		if (!$valide) {
			$raison = 'pas d\'adresse email valide';
		} elseif (isset($emails_existants[$cle_email]) or isset($emails_crees[$cle_email])) {
			// « mot de passe oublié » retrouve le compte par l'email : deux comptes ne peuvent pas le partager
			$actif = false;
			$raison = 'adresse email déjà utilisée par un autre compte';
		}
		$base = sptg_compte_identifiant($a['login'] !== '' && $a['login'] !== null ? $a['login'] : $nom, $id);
		$u = sptg_compte_identifiant_libre($base, $pris);
		$pris[$u] = true;
		$compte = array();
		if ($actif) {
			$emails_crees[$cle_email] = $u;
			$compte['email'] = $email;
		} else {
			$desactives[] = array('id_auteur' => $id, 'nom' => $nom, 'compte' => $u, 'raison' => $raison);
		}
		$compte += array(
			'fullname' => $nom !== '' ? $nom : $u,
			'title' => $statuts[$a['statut']] . (($a['statut'] === '0minirezo' and !empty($a['restreint'])) ? ' restreint' : ''),
			'state' => $actif ? 'enabled' : 'disabled',
			'spip_auteur' => $id,
			'access' => ($a['statut'] === '0minirezo' and empty($a['restreint'])) ? sptg_comptes_acces_admin() : sptg_comptes_acces(),
		);
		$comptes[$u] = $compte;
		$map[$id] = $u;
	}
	return array('comptes' => $comptes, 'map' => $map, 'rattaches' => $rattaches, 'desactives' => $desactives);
}

/**
 * Auteurs exportables (administrateurs et rédacteurs). `restreint` : administrateur limité à des rubriques
 * (il n'est pas super-administrateur de Grav : un administrateur restreint n'a pas la main sur tout le site).
 */
function sptg_comptes_lire() {
	$auteurs = sql_allfetsel(
		'id_auteur, nom, login, email, statut',
		'spip_auteurs',
		sql_in('statut', array_keys(sptg_comptes_statuts()))
	) ?: array();
	$restreints = array();
	foreach (sql_allfetsel('id_auteur', 'spip_auteurs_liens', "objet='rubrique'") ?: array() as $l) {
		$restreints[(int) $l['id_auteur']] = true;
	}
	foreach ($auteurs as &$a) {
		$a['restreint'] = isset($restreints[(int) $a['id_auteur']]);
	}
	return $auteurs;
}

/**
 * Comptes déjà présents dans la destination.
 *
 * @return array [identifiants => true, emails en minuscules => identifiant, id_auteur SPIP => identifiant]
 */
function sptg_comptes_existants($racine) {
	$ident = array();
	$emails = array();
	$par_id = array();
	foreach ((array) glob(rtrim($racine, '/') . '/user/accounts/*.yaml') as $f) {
		$u = basename($f, '.yaml');
		$contenu = (string) @file_get_contents($f);
		$ident[$u] = true;
		if (preg_match('/^email:\s*["\']?([^"\'\r\n#]+?)["\']?\s*$/mi', $contenu, $m)) {
			$emails[strtolower(trim($m[1]))] = $u;
		}
		if (preg_match('/^spip_auteur:\s*["\']?(\d+)["\']?\s*$/m', $contenu, $m)) {
			$par_id[(int) $m[1]] = $u;
		}
	}
	return array($ident, $emails, $par_id);
}

/** Plan des comptes pour une destination (lit SPIP et le dossier user/accounts/ existant). */
function sptg_comptes_planifier($racine, ?array $auteurs = null) {
	$auteurs = $auteurs === null ? sptg_comptes_lire() : $auteurs;
	list($ident, $emails, $par_id) = sptg_comptes_existants($racine);
	return sptg_comptes_construire($auteurs, $ident, $emails, $par_id);
}

/**
 * Écrit les comptes du plan dans $racine/user/accounts/. Un compte existant n'est jamais modifié.
 *
 * @return array ['crees' => n, 'fichiers' => chemins relatifs écrits, 'liste' => [...], 'desactives' => [...], 'rattaches' => [...], 'prerequis' => texte]
 */
function sptg_comptes_exporter($racine, ?array $plan = null) {
	$plan = $plan === null ? sptg_comptes_planifier($racine) : $plan;
	$fichiers = array();
	$liste = array();
	foreach ($plan['comptes'] as $u => $data) {
		sptg_ecrire(rtrim($racine, '/') . '/user/accounts/' . $u . '.yaml', sptg_yaml($data));
		$fichiers[] = 'user/accounts/' . $u . '.yaml';
		$liste[$u] = array(
			'nom' => $data['fullname'], 'intitule' => $data['title'], 'etat' => $data['state'],
			'super' => !empty($data['access']['admin']['super']),
		);
	}
	return array(
		'crees' => count($plan['comptes']),
		'fichiers' => $fichiers,
		'liste' => $liste,
		'desactives' => $plan['desactives'],
		'rattaches' => $plan['rattaches'],
		'prerequis' => 'Plugins Grav `login`, `email`, `api` et `admin2` installés ; expéditeur configuré '
			. '(`plugins.email.from` et moteur d\'envoi) ; idéalement `plugins.login.site_host` renseigné. '
			. 'Première connexion : « Mot de passe oublié » avec l\'adresse enregistrée dans SPIP.',
	);
}
