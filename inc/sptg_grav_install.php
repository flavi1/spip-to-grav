<?php
/**
 * Installation ou mise à jour de Grav à l'emplacement de l'export.
 *
 *  - pas de Grav à cet emplacement : téléchargement de la dernière version « Core + Admin » et extraction
 *  - Grav déjà installé, à jour     : rien
 *  - Grav installé, plus ancien     : mise à jour par `bin/gpm selfupgrade` (même version majeure
 *                                     uniquement ; une migration 1.x -> 2.x se fait à la main)
 *
 * Aucun appel réseau n'est fait si l'option n'est pas cochée.
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

define('SPTG_GRAV_API_RELEASE', 'https://api.github.com/repos/getgrav/grav/releases/latest');
define('SPTG_GRAV_ZIP_ADMIN', 'https://getgrav.org/download/core/grav-admin/%s');
define('SPTG_GRAV_ZIP_ADMIN_GITHUB', 'https://github.com/getgrav/grav/releases/download/%s/grav-admin-v%s.zip');

/** Version de Grav installée dans $dir (lue dans system/defines.php), ou null */
function sptg_grav_version_installee($dir) {
	$f = rtrim($dir, '/\\') . '/system/defines.php';
	if (!is_file($f)) {
		return null;
	}
	return sptg_grav_version_depuis_defines(file_get_contents($f));
}

function sptg_grav_version_depuis_defines($php) {
	return preg_match('/define\(\s*[\'"]GRAV_VERSION[\'"]\s*,\s*[\'"]([0-9][^\'"]*)[\'"]/', (string) $php, $m) ? $m[1] : null;
}

/** Numéro de version depuis un tag (v2.0.15, 1.7.49, grav-2.0.0) */
function sptg_grav_version_depuis_tag($tag) {
	return preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)*)/', (string) $tag, $m) ? $m[1] : null;
}

function sptg_grav_majeure($v) {
	return (int) strtok((string) $v, '.');
}

/** Télécharge $url dans $fichier ; retourne true si un fichier non vide a été écrit */
function sptg_grav_telecharger($url, $fichier) {
	@unlink($fichier);
	if (function_exists('recuperer_url')) {
		try {
			$r = recuperer_url($url, array('file' => $fichier, 'taille_max' => 300 * 1024 * 1024));
			if (is_array($r) and isset($r['status']) and (int) $r['status'] == 200 and is_file($fichier) and filesize($fichier) > 0) {
				return true;
			}
		} catch (\Throwable $e) {
			// repli ci-dessous
		}
		@unlink($fichier);
	}
	if (function_exists('curl_init')) {
		$h = @fopen($fichier, 'wb');
		if ($h) {
			$c = curl_init($url);
			curl_setopt_array($c, array(
				CURLOPT_FILE => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
				CURLOPT_TIMEOUT => 600, CURLOPT_USERAGENT => 'spip_to-grav',
			));
			$ok = curl_exec($c);
			$code = curl_getinfo($c, CURLINFO_HTTP_CODE);
			curl_close($c);
			fclose($h);
			if ($ok and $code == 200 and filesize($fichier) > 0) {
				return true;
			}
		}
		@unlink($fichier);
	}
	return false;
}

/** Texte d'une URL (API JSON) ou null */
function sptg_grav_lire_url($url) {
	$tmp = tempnam(sys_get_temp_dir(), 'sptg');
	$ok = sptg_grav_telecharger($url, $tmp);
	$txt = $ok ? file_get_contents($tmp) : null;
	@unlink($tmp);
	return $txt;
}

/** Dernière version stable de Grav, ou null si l'API est injoignable */
function sptg_grav_derniere_version() {
	$json = sptg_grav_lire_url(SPTG_GRAV_API_RELEASE);
	$d = $json ? json_decode($json, true) : null;
	return is_array($d) && !empty($d['tag_name']) ? sptg_grav_version_depuis_tag($d['tag_name']) : null;
}

/**
 * Extrait un zip dans $dest en retirant le dossier racine commun éventuel (grav-admin/).
 * Refuse les entrées qui sortent du dossier (« .. », chemins absolus).
 *
 * @return int nombre de fichiers extraits, -1 en cas d'erreur
 */
function sptg_zip_extraire_sans_racine($zip, $dest) {
	if (!class_exists('ZipArchive')) {
		return -1;
	}
	$z = new ZipArchive();
	if ($z->open($zip) !== true) {
		return -1;
	}
	$noms = array();
	for ($i = 0; $i < $z->numFiles; $i++) {
		$noms[$i] = $z->getNameIndex($i);
	}
	$racine = '';
	$premiers = array_unique(array_map(function ($n) {
		return strpos($n, '/') !== false ? substr($n, 0, strpos($n, '/') + 1) : '';
	}, $noms));
	if (count($premiers) == 1 and $premiers[0] !== '') {
		$racine = $premiers[0];
	}
	sptg_mkdir($dest);
	$dest = rtrim($dest, '/') . '/';
	$n = 0;
	foreach ($noms as $i => $nom) {
		$rel = $racine !== '' ? substr($nom, strlen($racine)) : $nom;
		if ($rel === '' or $rel === false) {
			continue;
		}
		if ($rel[0] === '/' or preg_match('~(^|/)\.\.(/|$)~', $rel) or strpos($rel, "\0") !== false) {
			$z->close();
			return -1;
		}
		if (substr($rel, -1) === '/') {
			sptg_mkdir($dest . $rel);
			continue;
		}
		sptg_mkdir(dirname($dest . $rel));
		$in = $z->getStream($nom);
		if (!$in) {
			continue;
		}
		$out = fopen($dest . $rel, 'wb');
		stream_copy_to_stream($in, $out);
		fclose($in);
		fclose($out);
		$n++;
	}
	$z->close();
	return $n;
}

/** Exécutable PHP CLI pour lancer bin/gpm (PHP_BINARY peut être php-fpm), ou null */
function sptg_grav_php_cli() {
	$cands = array();
	if (defined('PHP_BINDIR')) {
		$cands[] = PHP_BINDIR . '/php';
	}
	$cands[] = '/usr/bin/php';
	$cands[] = '/usr/local/bin/php';
	foreach ($cands as $c) {
		if (@is_executable($c)) {
			return $c;
		}
	}
	return null;
}

/**
 * Met à jour une installation existante (même version majeure).
 *
 * @return array [ok(bool), message]
 */
function sptg_grav_selfupgrade($dir) {
	$php = sptg_grav_php_cli();
	$gpm = rtrim($dir, '/') . '/bin/gpm';
	$cmd = 'cd ' . escapeshellarg($dir) . ' && php bin/gpm selfupgrade -f -y';
	if (!$php or !is_file($gpm) or !function_exists('proc_open')
		or in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))))) {
		return array(false, "Mise à jour automatique impossible depuis SPIP. Lancez : $cmd");
	}
	$p = @proc_open(
		array($php, $gpm, 'selfupgrade', '-f', '-y'),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes,
		$dir
	);
	if (!is_resource($p)) {
		return array(false, "Mise à jour automatique impossible depuis SPIP. Lancez : $cmd");
	}
	$sortie = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
	$code = proc_close($p);
	return array($code === 0, $code === 0 ? 'Grav mis à jour (bin/gpm selfupgrade).' : "Échec de la mise à jour (code $code). Lancez à la main : $cmd\n" . trim($sortie));
}

/**
 * Installe ou met à jour Grav dans $racine.
 *
 * @param string $racine racine de l'export (qui contiendra user/)
 * @return array ['etat' => installe|a_jour|mis_a_jour|echec|ignore, 'version' => ..., 'message' => ...]
 */
function sptg_grav_installer($racine) {
	$racine = rtrim($racine, '/\\');
	$derniere = sptg_grav_derniere_version();

	if (sptg_detecter_grav($racine)) {
		$v = sptg_grav_version_installee($racine);
		if ($derniere === null) {
			return array('etat' => 'echec', 'version' => $v, 'message' => 'Grav ' . $v . " détecté ; la dernière version n'a pas pu être consultée (réseau).");
		}
		if ($v !== null and version_compare($v, $derniere, '>=')) {
			return array('etat' => 'a_jour', 'version' => $v, 'message' => "Grav $v déjà installé, à jour.");
		}
		if ($v !== null and sptg_grav_majeure($v) != sptg_grav_majeure($derniere)) {
			return array('etat' => 'echec', 'version' => $v, 'message' => "Grav $v est installé ; la version $derniere change de version majeure. "
				. 'Suivez le guide de migration de getgrav.org (migrate-to-2) : une mise à jour automatique casserait peut-être les plugins.');
		}
		list($ok, $msg) = sptg_grav_selfupgrade($racine);
		return array('etat' => $ok ? 'mis_a_jour' : 'echec', 'version' => $derniere, 'message' => $msg);
	}

	if ($derniere === null) {
		return array('etat' => 'echec', 'version' => null, 'message' => "Installation impossible : la dernière version de Grav n'a pas pu être déterminée (réseau).");
	}
	if (sptg_dossier_non_vide($racine)) {
		return array('etat' => 'echec', 'version' => null, 'message' => "Installation refusée : $racine n'est pas vide et ne contient pas Grav.");
	}
	$tmp = sous_repertoire(_DIR_TMP, 'sptg') . 'grav-' . $derniere . '.zip';
	$ok = sptg_grav_telecharger(sprintf(SPTG_GRAV_ZIP_ADMIN, $derniere), $tmp)
		or $ok = sptg_grav_telecharger(sprintf(SPTG_GRAV_ZIP_ADMIN_GITHUB, $derniere, $derniere), $tmp);
	if (!$ok) {
		return array('etat' => 'echec', 'version' => $derniere, 'message' => "Téléchargement de Grav $derniere impossible (réseau).");
	}
	$n = sptg_zip_extraire_sans_racine($tmp, $racine);
	@unlink($tmp);
	if ($n < 1 or !sptg_detecter_grav($racine)) {
		return array('etat' => 'echec', 'version' => $derniere, 'message' => "Extraction de Grav $derniere impossible.");
	}
	return array('etat' => 'installe', 'version' => $derniere, 'message' => "Grav $derniere installé ($n fichiers).");
}
