<?php
/**
 * Auteurs SPIP -> comptes Grav (rédacteurs d'admin2).
 *
 * Un compte est écrit dans `user/accounts/<identifiant>.yaml` pour chaque auteur « administrateur » ou
 * « rédacteur » SPIP ayant une adresse email valide et unique. Le compte n'a PAS de mot de passe : à la
 * première connexion, l'auteur utilise « Mot de passe oublié » sur la page de connexion d'admin2 (ou du site)
 * avec l'adresse enregistrée dans SPIP. Le plugin `login` retrouve le compte par cette adresse, pose un jeton
 * valable 24 h et envoie le lien (POST /auth/forgot-password du plugin `api` ; Controller::taskForgot de `login`) ;
 * la réponse est toujours neutre, l'adresse n'est donc divulguée à personne.
 *
 * L'email n'est écrit que dans le compte (`user/accounts/` n'est pas servi par Grav), jamais dans les pages,
 * quelle que soit l'option « inclure les emails ».
 *
 * Prérequis côté Grav, signalés dans le rapport : plugins `login`, `email`, `api` et `admin2`, et un expéditeur
 * email configuré (`plugins.email.from` + moteur d'envoi).
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
 * @param array $auteurs  lignes spip_auteurs : id_auteur, nom, login, email, statut
 * @param array $existants identifiants déjà présents dans user/accounts/ (nom => true)
 * @param array $emails_existants emails déjà présents, en minuscules (email => true)
 * @return array ['comptes' => identifiant => données, 'ignores' => [['id_auteur','nom','raison']]]
 */
function sptg_comptes_construire(array $auteurs, array $existants = array(), array $emails_existants = array()) {
	$statuts = sptg_comptes_statuts();
	$comptes = array();
	$ignores = array();
	$pris = $existants;
	$emails = $emails_existants;

	usort($auteurs, function ($a, $b) {
		return (int) $a['id_auteur'] - (int) $b['id_auteur'];
	});
	foreach ($auteurs as $a) {
		$id = (int) $a['id_auteur'];
		$nom = trim((string) $a['nom']);
		$ignorer = function ($raison) use (&$ignores, $id, $nom) {
			$ignores[] = array('id_auteur' => $id, 'nom' => $nom, 'raison' => $raison);
		};
		if (!isset($statuts[$a['statut']])) {
			continue;
		}
		$email = trim((string) $a['email']);
		if ($email === '' or !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$ignorer('pas d\'adresse email valide');
			continue;
		}
		$cle_email = strtolower($email);
		if (isset($emails[$cle_email])) {
			// « mot de passe oublié » retrouve le compte par l'email : deux comptes ne peuvent pas le partager
			$ignorer('adresse email déjà utilisée par un autre compte');
			continue;
		}
		$base = sptg_compte_identifiant($a['login'] !== '' && $a['login'] !== null ? $a['login'] : $nom, $id);
		$u = sptg_compte_identifiant_libre($base, $pris);
		$pris[$u] = true;
		$emails[$cle_email] = true;
		$comptes[$u] = array(
			'email' => $email,
			'fullname' => $nom !== '' ? $nom : $u,
			'title' => $statuts[$a['statut']] . (($a['statut'] === '0minirezo' and !empty($a['restreint'])) ? ' restreint' : ''),
			'state' => 'enabled',
			'access' => ($a['statut'] === '0minirezo' and empty($a['restreint'])) ? sptg_comptes_acces_admin() : sptg_comptes_acces(),
		);
	}
	return array('comptes' => $comptes, 'ignores' => $ignores);
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

/** Comptes déjà présents dans la destination : [identifiants => true, emails en minuscules => true] */
function sptg_comptes_existants($racine) {
	$ident = array();
	$emails = array();
	foreach ((array) glob(rtrim($racine, '/') . '/user/accounts/*.yaml') as $f) {
		$ident[basename($f, '.yaml')] = true;
		if (preg_match('/^email:\s*["\']?([^"\'\r\n#]+?)["\']?\s*$/mi', (string) @file_get_contents($f), $m)) {
			$emails[strtolower(trim($m[1]))] = true;
		}
	}
	return array($ident, $emails);
}

/**
 * Écrit les comptes dans $racine/user/accounts/. Un compte existant n'est jamais modifié.
 *
 * @return array ['crees' => n, 'fichiers' => chemins relatifs écrits, 'ignores' => [...], 'prerequis' => texte]
 */
function sptg_comptes_exporter($racine, array $auteurs = null) {
	$auteurs = $auteurs === null ? sptg_comptes_lire() : $auteurs;
	list($ident, $emails) = sptg_comptes_existants($racine);
	$r = sptg_comptes_construire($auteurs, $ident, $emails);
	$fichiers = array();
	foreach ($r['comptes'] as $u => $data) {
		sptg_ecrire(rtrim($racine, '/') . '/user/accounts/' . $u . '.yaml', sptg_yaml($data));
		$fichiers[] = 'user/accounts/' . $u . '.yaml';
	}
	return array(
		'crees' => count($r['comptes']),
		'fichiers' => $fichiers,
		'ignores' => $r['ignores'],
		'prerequis' => 'Plugins Grav `login`, `email`, `api` et `admin2` installés ; expéditeur configuré '
			. '(`plugins.email.from` et moteur d\'envoi) ; idéalement `plugins.login.site_host` renseigné. '
			. 'Première connexion : « Mot de passe oublié » avec l\'adresse enregistrée dans SPIP.',
	);
}
