<?php
/**
 * Moteur d'export SPIP -> Grav.
 *
 * Ordre des opérations :
 *   1. cible (dossier, détection d'une installation Grav, vidage éventuel)
 *   2. CHEMINS : arborescence des rubriques, route Grav de chaque objet (avant tout rendu)
 *   3. relations (mots, auteurs, documents)
 *   4. rendu : propre() -> (HTML) -> Markdown, avec vérification de l'aller-retour
 *   5. écriture des pages, de la configuration, du thème
 *   6. rapport, et zip optionnel si tout s'est bien passé
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/sptg_introspection');
include_spip('inc/sptg_yaml');
include_spip('inc/sptg_markdown');
include_spip('inc/sptg_urls');
include_spip('inc/sptg_index');
include_spip('inc/sptg_langues');
include_spip('inc/sptg_grav_install');
include_spip('inc/sptg_comptes');
include_spip('inc/sptg_logos');
include_spip('inc/sptg_extras');

// ---------------------------------------------------------------- utilitaires

function sptg_mkdir($dir) {
	if (!is_dir($dir)) {
		@mkdir($dir, 0775, true);
	}
}

function sptg_ecrire($fichier, $contenu) {
	sptg_mkdir(dirname($fichier));
	file_put_contents($fichier, $contenu);
}

function sptg_copier_arbre($src, $dst) {
	if (!is_dir($src)) {
		return 0;
	}
	sptg_mkdir($dst);
	$n = 0;
	foreach (scandir($src) as $f) {
		if ($f === '.' or $f === '..' or $f === '.htaccess' or $f === '.git') {
			continue;
		}
		if (is_dir("$src/$f")) {
			$n += sptg_copier_arbre("$src/$f", "$dst/$f");
		} elseif (copy("$src/$f", "$dst/$f")) {
			$n++;
		}
	}
	return $n;
}

function sptg_rm_contenu($dir) {
	if (!is_dir($dir)) {
		return;
	}
	foreach (scandir($dir) as $f) {
		if ($f === '.' or $f === '..') {
			continue;
		}
		$p = "$dir/$f";
		if (is_dir($p) and !is_link($p)) {
			sptg_rm_contenu($p);
			@rmdir($p);
		} else {
			@unlink($p);
		}
	}
}

function sptg_slug($txt, $max = 60, $lang = '') {
	include_spip('inc/charsets');
	if (function_exists('extraire_multi')) {
		$txt = extraire_multi($txt, $lang !== '' ? $lang : null);
	}
	$txt = supprimer_numero(textebrut($txt));
	$txt = strtolower(translitteration($txt));
	$txt = trim(preg_replace('/[^a-z0-9]+/', '-', $txt), '-');
	return substr($txt, 0, $max);
}

function sptg_nom_unique(array &$utilises, $dir, $base) {
	$n = $base;
	$i = 2;
	while (isset($utilises[$dir][$n])) {
		$n = $base . '-' . $i++;
	}
	$utilises[$dir][$n] = 1;
	return $n;
}

function sptg_titre_propre($t, $lang = '') {
	if ($lang !== '' and function_exists('extraire_multi')) {
		$t = extraire_multi($t, $lang);
	}
	return trim(supprimer_numero(textebrut(typo($t))));
}

function sptg_cle_tri($titre) {
	$n = recuperer_numero($titre);
	return array($n === '' ? PHP_INT_MAX : (int) $n, strtolower(sptg_titre_propre($titre)));
}

/**
 * La page doit-elle rester masquée jusqu'à sa date ? Seuls les articles sont concernés par la meta
 * SPIP `post_dates` (« non » = les articles datés du futur ne sont pas affichés).
 */
function sptg_publication_differee(array $info, $date, $maintenant = null) {
	if ($info['table'] !== 'spip_articles') {
		return false;
	}
	$meta = isset($GLOBALS['meta']['post_dates']) ? $GLOBALS['meta']['post_dates'] : 'oui';
	if ($meta !== 'non') {
		return false;
	}
	$t = strtotime($date);
	return $t !== false and $t > ($maintenant === null ? time() : $maintenant);
}

function sptg_date($d) {
	if (!$d or strncmp($d, '0000', 4) === 0) {
		return null;
	}
	return substr($d, 0, 16);
}

// ------------------------------------------------------------------- cible

/** Une installation Grav : user/, system/ et index.php */
function sptg_detecter_grav($dir) {
	$dir = rtrim($dir, '/\\');
	return is_dir("$dir/user") and is_dir("$dir/system") and is_file("$dir/index.php");
}

/**
 * Racine de l'export : le dossier de destination lui-même (celui qui contient ou contiendra user/).
 * Le nom d'export est indépendant : il ne nomme que le thème, le rapport et le zip.
 *
 * @return array [racine (avec slash final), est_installation_grav]
 */
function sptg_cible($dest) {
	$dest = rtrim($dest, '/\\');
	return array($dest . '/', sptg_detecter_grav($dest));
}

/** Dossier de destination proposé par défaut : <tmp de SPIP>/grav/<nom_export> (chemin absolu) */
function sptg_destination_defaut($export_name) {
	$tmp = defined('_DIR_TMP') ? realpath(_DIR_TMP) : false;
	return rtrim($tmp ?: sys_get_temp_dir(), '/\\') . '/grav/' . $export_name;
}

/**
 * Une destination peut-elle recevoir l'export ? Grav, dossier vide ou inexistant, ou dossier contenant déjà user/.
 * Un autre dossier non vide (racine d'un site, /var/www…) est refusé : l'export y écrirait user/, themes…
 */
function sptg_destination_acceptable($dest) {
	$dest = rtrim($dest, '/\\');
	return !sptg_dossier_non_vide($dest) or sptg_detecter_grav($dest) or is_dir($dest . '/user');
}

/** Contrôle de sécurité sur un chemin de destination saisi */
function sptg_chemin_valide($dest) {
	$dest = trim($dest);
	if ($dest === '' or !preg_match('~^(/|[A-Za-z]:[\\\\/])~', $dest)) {
		return false;
	}
	if (preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)~', $dest)) {
		return false;
	}
	return strlen(trim($dest, '/\\')) > 1 and trim($dest, '/\\') !== '';
}

function sptg_dossier_non_vide($dir) {
	if (!is_dir($dir)) {
		return false;
	}
	return count(array_diff(scandir($dir), array('.', '..'))) > 0;
}

// ------------------------------------------------------ URLs et liens SPIP

/**
 * URL actuelle d'un objet selon la configuration du site.
 *
 * @return array|null ['path' => ...] si l'URL est un chemin ; ['query' => ...] si elle contient un « ? »
 */
function sptg_url_spip($type, $id) {
	include_spip('inc/urls');
	if (!function_exists('generer_objet_url')) {
		return null;
	}
	try {
		$u = generer_objet_url($id, $type, '', '', true);
	} catch (\Throwable $e) {
		return null;
	}
	if (!is_string($u) or $u === '') {
		return null;
	}
	$u = preg_replace('~#.*$~', '', $u);
	$u = preg_replace('~^https?://[^/]+/?~i', '', $u);
	// generer_objet_url() préfixe _DIR_RACINE : « ../ » dans l'espace privé, « ./ » pour les URL de type page
	$u = ltrim(preg_replace('~^(\.{1,2}/)+~', '', $u), '/');
	if ($u === '') {
		return null;
	}
	return strpos($u, '?') !== false ? array('query' => $u) : array('path' => $u);
}

/** Le site génère-t-il des URL à paramètres (spip.php?...) ? Test sur une rubrique (ou un article) publiée. */
function sptg_urls_a_parametres() {
	$u = null;
	$r = sql_fetsel('id_rubrique', 'spip_rubriques', "statut='publie'", '', 'id_rubrique', '0,1');
	if ($r) {
		$u = sptg_url_spip('rubrique', (int) $r['id_rubrique']);
	}
	if (!$u) {
		$a = sql_fetsel('id_article', 'spip_articles', "statut='publie'", '', 'id_article', '0,1');
		$u = $a ? sptg_url_spip('article', (int) $a['id_article']) : null;
	}
	return (bool) ($u and isset($u['query']));
}

/**
 * Liens stables : les liens internes déjà remplacés par des routes (sptg_liens_internes) deviennent
 * `idx:<type>/<id>[#ancre]` (SPTG_LIEN_SCHEME), résolus à l'affichage par le thème. Seuls les href qui sont exactement la
 * route d'un objet exporté (préfixe de langue compris) sont touchés.
 *
 * @param array $inverse route (sans préfixe de langue) => « type/id »
 */
function sptg_liens_stables($html, array $inverse, $prefixe = '') {
	return preg_replace_callback(
		'/\bhref=(["\'])([^"\'#]*)(#[^"\']*)?\1/i',
		function ($m) use ($inverse, $prefixe) {
			$u = html_entity_decode($m[2]);
			if ($prefixe !== '' and strpos($u, $prefixe . '/') === 0) {
				$u = substr($u, strlen($prefixe));
			}
			if ($u !== '' and $u[0] === '/' and isset($inverse[$u])) {
				return 'href=' . $m[1] . SPTG_LIEN_SCHEME . ':' . $inverse[$u] . (isset($m[3]) ? $m[3] : '') . $m[1];
			}
			return $m[0];
		},
		$html
	);
}

/** Hôte (sans « www. », en minuscules) du site SPIP, d'après la meta adresse_site ; '' si inconnu */
function sptg_hote_site() {
	$h = !empty($GLOBALS['meta']['adresse_site']) ? parse_url($GLOBALS['meta']['adresse_site'], PHP_URL_HOST) : '';
	return $h ? preg_replace('/^www\./', '', strtolower($h)) : '';
}

/**
 * Objet désigné par une URL SPIP interne, quelle que soit sa forme :
 * `spip.php?article12`, `spip.php?page=article&id_article=12`, `?id_article=12`, `article.php3?id_article=12`,
 * `/article12`, `/article12.html`, avec ou sans le domaine du site. Une URL d'un autre domaine, ou une URL de
 * page sans objet (`spip.php?page=contact`), ne désigne aucun objet.
 *
 * @return array|null [type, id, ancre]
 */
function sptg_url_vers_objet($url, $hote_site = '') {
	$url = html_entity_decode((string) $url);
	$p = parse_url($url);
	if ($p === false) {
		return null;
	}
	if (!empty($p['host'])) {
		if ($hote_site === '' or preg_replace('/^www\./', '', strtolower($p['host'])) !== $hote_site) {
			return null; // un autre site
		}
	} elseif (!empty($p['scheme'])) {
		return null;
	}
	$chemin = isset($p['path']) ? $p['path'] : '';
	$q = isset($p['query']) ? $p['query'] : '';
	$ancre = isset($p['fragment']) && $p['fragment'] !== '' ? '#' . $p['fragment'] : '';
	$types = 'article|rubrique|breve|mot|auteur|site|syndic';
	$base = basename($chemin);

	if ($q !== '' and ($base === 'spip.php' or $base === '' or $base === 'index.php')) {
		if (preg_match('/^(' . $types . '|[a-z_]+?)(\d+)(?:&|$)/', $q, $m)) {
			return array($m[1], (int) $m[2], $ancre);
		}
		parse_str($q, $v);
		if (!empty($v['page']) and preg_match('/^[a-z_]+$/', $v['page']) and isset($v['id_' . $v['page']]) and ctype_digit((string) $v['id_' . $v['page']])) {
			return array($v['page'], (int) $v['id_' . $v['page']], $ancre);
		}
		if (empty($v['page'])) {
			foreach ($v as $k => $val) {
				if (preg_match('/^id_([a-z_]+)$/', $k, $m) and ctype_digit((string) $val)) {
					return array($m[1], (int) $val, $ancre);
				}
			}
		}
		return null;
	}
	if ($q !== '' and preg_match('/^(' . $types . ')\.php3?$/', $base, $m)) {
		parse_str($q, $v);
		if (isset($v['id_' . $m[1]]) and ctype_digit((string) $v['id_' . $m[1]])) {
			return array($m[1], (int) $v['id_' . $m[1]], $ancre);
		}
		return null;
	}
	if ($q === '' and preg_match('/^(' . $types . ')(\d+)(?:\.html)?$/', $base, $m)) {
		return array($m[1], (int) $m[2], $ancre);
	}
	return null;
}

/** Remplace les liens internes SPIP ([->art12]) par la route Grav correspondante (préfixée par la langue en mode i18n) */
function sptg_liens_internes($texte, array $routes, $prefixe = '') {
	$alias = array(
		'art' => 'article', 'article' => 'article', 'rub' => 'rubrique', 'rubrique' => 'rubrique',
		'br' => 'breve', 'breve' => 'breve', 'aut' => 'auteur', 'auteur' => 'auteur', 'mot' => 'mot',
		'' => 'article',
	);
	return preg_replace_callback(
		'/->\s*([a-z_]*?)\s*(\d+)(#[A-Za-z0-9_-]+)?\s*(?=\])/i',
		function ($m) use ($routes, $alias, $prefixe) {
			$t = strtolower($m[1]);
			$type = isset($alias[$t]) ? $alias[$t] : $t;
			if (isset($routes[$type][(int) $m[2]])) {
				return '->' . $prefixe . $routes[$type][(int) $m[2]] . (isset($m[3]) ? $m[3] : '');
			}
			return $m[0];
		},
		$texte
	);
}

// ----------------------------------------------------- documents et fichiers

function sptg_doc_info($id) {
	static $cache = array();
	$id = (int) $id;
	if (!isset($cache[$id])) {
		$cache[$id] = sql_fetsel('*', 'spip_documents', 'id_document=' . $id) ?: null;
	}
	return $cache[$id];
}

function sptg_est_image($ext) {
	return in_array(strtolower($ext), array('jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'));
}

/**
 * Référence utilisable dans le contenu pour un fichier du site.
 *
 *  - image, mode « partage » : copiée une seule fois dans user/pages/images/, référence /images/<chemin>
 *  - image, mode « img »     : laissée dans IMG/, référence /IMG/<chemin> (le dossier est copié à la fin)
 *  - autre document          : copié dans le dossier de la page, référence = nom du fichier
 *
 * @param string $abs chemin du fichier sur le disque
 * @param string $rel chemin relatif à la racine du site (IMG/jpg/x.jpg, local/cache-vignettes/…)
 */
function sptg_fichier_ref(array &$ctx, $abs, $rel) {
	$rel = ltrim($rel, '/');
	if (sptg_est_image(pathinfo($rel, PATHINFO_EXTENSION))) {
		if ($ctx['mode_images'] == 'img') {
			return '/' . $rel;
		}
		$sous = preg_replace('~^(IMG|local)/~', '', $rel);
		if (!is_file($abs)) {
			$ctx['stats']['fichiers_absents']++;
			return '/images/' . $sous;
		}
		if (!isset($ctx['partage_vus'][$sous])) {
			sptg_mkdir(dirname($ctx['partage_dir'] . $sous));
			copy($abs, $ctx['partage_dir'] . $sous);
			$ctx['partage_vus'][$sous] = 1;
			$ctx['stats']['images_partagees']++;
		}
		return '/images/' . $sous;
	}
	if (!is_file($abs)) {
		$ctx['stats']['fichiers_absents']++;
		return '/' . $rel;
	}
	if (isset($ctx['extensions'])) {
		$ctx['extensions'][strtolower(pathinfo($abs, PATHINFO_EXTENSION))] = 1;
	}
	$nom = basename($abs);
	if (isset($ctx['noms'][$nom]) and $ctx['noms'][$nom] !== $abs) {
		$nom = substr(md5($abs), 0, 6) . '-' . $nom;
	}
	if (!isset($ctx['noms'][$nom])) {
		sptg_mkdir($ctx['dirabs']);
		copy($abs, $ctx['dirabs'] . $nom);
		$ctx['noms'][$nom] = $abs;
		$ctx['stats']['fichiers']++;
	}
	return $nom;
}

/** Extensions de fichiers que Grav sert par défaut (system/config/media.yaml) ; les autres donnent une 404 */
function sptg_media_grav_defauts() {
	return array(
		'jpg', 'jpe', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg', 'mp4', 'mov', 'm4v', 'swf', 'flv', 'webm', 'ogv', 'mp3', 'ogg',
		'wma', 'm4a', 'wav', 'aiff', 'aif', 'txt', 'xml', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pps', 'rtf', 'bmp', 'tiff',
		'mpeg', 'mpg', 'mpe', 'avi', 'wmv', 'html', 'htm', 'ics', 'pdf', 'ai', 'psd', 'zip', '7z', 'gz', 'tar', 'css', 'js', 'json', 'vcf',
	);
}

/**
 * Types de média à déclarer dans user/config/media.yaml pour que Grav serve les documents joints
 * dont l'extension n'est pas connue de sa configuration par défaut (odt, csv, epub…).
 *
 * @param array $extensions extensions => 1
 * @return array ext => [type, thumb, mime] (vide si rien à ajouter)
 */
function sptg_config_media(array $extensions) {
	$mimes = array(
		'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
		'odp' => 'application/vnd.oasis.opendocument.presentation', 'odg' => 'application/vnd.oasis.opendocument.graphics',
		'csv' => 'text/csv', 'epub' => 'application/epub+zip', 'rar' => 'application/vnd.rar', 'bz2' => 'application/x-bzip2',
		'tgz' => 'application/gzip', 'flac' => 'audio/flac', 'opus' => 'audio/ogg', 'oga' => 'audio/ogg', 'm4b' => 'audio/mp4',
		'mkv' => 'video/x-matroska', 'md' => 'text/markdown', 'kml' => 'application/vnd.google-earth.kml+xml', 'eps' => 'application/postscript',
	);
	$types = array();
	foreach (array_keys($extensions) as $e) {
		if (!preg_match('/^[a-z0-9]{1,8}$/', $e) or in_array($e, sptg_media_grav_defauts(), true)) {
			continue;
		}
		$types[$e] = array('type' => 'file', 'thumb' => 'media/thumb.png', 'mime' => $mimes[$e] ?? 'application/octet-stream');
	}
	ksort($types);
	return $types;
}

/** Lit les lignes d'un type d'objet en appliquant le filtre de statuts */
function sptg_lire_objets(array $info, array $statuts) {
	$where = array();
	if ($info['type'] == 'auteur') {
		$where[] = sql_in('statut', array('0minirezo', '1comite'));
	} elseif ($info['statut']) {
		$where[] = sql_in($info['statut'], $statuts);
	}
	$sel = '*' . ($info['titre_sql'] ? ', ' . $info['titre_sql'] : '');
	return sql_allfetsel($sel, $info['table'], $where) ?: array();
}

// ------------------------------------------------------------ rendu du texte

/**
 * SPIP -> HTML (propre) -> Markdown, avec vérification de l'aller-retour.
 *
 * @return array ['val' => texte, 'fmt' => 'md'|'html']
 */
function sptg_rendre_texte($raw, array &$ctx, $champ) {
	if (trim((string) $raw) === '') {
		return array('val' => '', 'fmt' => 'md');
	}
	// contexte de langue de SPIP : propre() / typo() suivent la langue demandée
	$lang = isset($ctx['lang']) ? $ctx['lang'] : '';
	$prec = isset($GLOBALS['spip_lang']) ? $GLOBALS['spip_lang'] : '';
	if ($lang !== '' and $lang !== $prec) {
		include_spip('inc/lang');
		changer_langue($lang);
	}
	try {
		return sptg_rendre_texte_interne($raw, $ctx, $champ);
	} finally {
		if ($lang !== '' and $lang !== $prec and $prec !== '') {
			changer_langue($prec);
		}
	}
}

function sptg_rendre_texte_interne($raw, array &$ctx, $champ) {
	include_spip('inc/texte');
	include_spip('inc/notes');

	// 1. pré-traitement du source SPIP, hors <code>/<cadre>/<html>/<text>
	$segments = preg_split('#(<(?:code|cadre|html|text)\b.*?</(?:code|cadre|html|text)>)#is', $raw, -1, PREG_SPLIT_DELIM_CAPTURE);
	foreach ($segments as $k => &$seg) {
		if ($k % 2 == 0) {
			if (!empty($ctx['lang']) and function_exists('extraire_multi')) {
				$seg = extraire_multi($seg, $ctx['lang']);
			}
			$seg = sptg_liens_internes($seg, $ctx['routes'], isset($ctx['prefixe']) ? $ctx['prefixe'] : '');
			$seg = preg_replace_callback(
				'/<(img|image|doc|document|emb|embed)\s*(\d+)\s*((?:\|[^>]*)?)\s*\/?>/i',
				function ($m) {
					return '<span class="sptg-modele" data-modele="' . strtolower($m[1]) . '" data-id="' . (int) $m[2] . '"></span>';
				},
				$seg
			);
		}
	}
	unset($seg);
	$pre = implode('', $segments);

	// 2. rendu SPIP
	try {
		$html = propre($pre);
		$notes = function_exists('calculer_notes') ? calculer_notes() : '';
	} catch (\Throwable $e) {
		$ctx['ecarts'][] = array(
			'page' => $ctx['route'], 'champ' => $champ, 'attendu' => 'exception propre() : ' . $e->getMessage(),
			'obtenu' => '', 'source' => mb_substr($raw, 0, 300), 'markdown' => '',
		);
		return array('val' => $raw, 'fmt' => 'html');
	}
	if ($champ == 'texte' and trim((string) $notes) !== '') {
		$html .= "\n" . $notes;
	}

	// 3. modèles d'images / documents -> HTML simple
	$html = preg_replace_callback(
		'/<span class="sptg-modele" data-modele="(\w+)" data-id="(\d+)"><\/span>/',
		function ($m) use (&$ctx) {
			$d = sptg_doc_info($m[2]);
			if (!$d) {
				$ctx['stats']['modeles_orphelins']++;
				return '';
			}
			$titre = sptg_titre_propre($d['titre']);
			if ($d['distant'] == 'oui') {
				$ref = $d['fichier'];
			} else {
				$ref = sptg_fichier_ref($ctx, _DIR_IMG . $d['fichier'], 'IMG/' . $d['fichier']);
			}
			$image = sptg_est_image($d['extension']);
			if ($image) {
				return '<img src="' . htmlspecialchars($ref, ENT_QUOTES) . '" alt="' . htmlspecialchars($titre, ENT_QUOTES) . '" />';
			}
			return '<a href="' . htmlspecialchars($ref, ENT_QUOTES) . '">'
				. htmlspecialchars($titre !== '' ? $titre : basename($d['fichier'])) . '</a>';
		},
		$html
	);

	// 4. autres fichiers du site (IMG/, local/) -> copie locale ou chemin absolu
	$html = preg_replace_callback(
		'~\b(src|href)=(["\'])(?:\./)?((?:IMG|local)/[^"\']+)\2~i',
		function ($m) use (&$ctx) {
			$rel = html_entity_decode($m[3]);
			$ref = sptg_fichier_ref($ctx, _DIR_RACINE . $rel, $rel);
			return $m[1] . '=' . $m[2] . htmlspecialchars($ref, ENT_QUOTES) . $m[2];
		},
		$html
	);

	// liens « à l'ancienne » vers un objet du site (spip.php?article12, article.php3?id_article=12, /article12.html…)
	$hote_site = sptg_hote_site();
	$html = preg_replace_callback(
		'~\bhref=(["\'])([^"\']+)\1~i',
		function ($m) use (&$ctx, $hote_site) {
			if (strpos($m[2], '#') === 0 or stripos($m[2], 'mailto:') === 0) {
				return $m[0];
			}
			$o = sptg_url_vers_objet($m[2], $hote_site);
			if ($o and isset($ctx['routes'][$o[0]][$o[1]])) {
				return 'href=' . $m[1] . (isset($ctx['prefixe']) ? $ctx['prefixe'] : '') . $ctx['routes'][$o[0]][$o[1]] . $o[2] . $m[1];
			}
			return $m[0];
		},
		$html
	);

	// liens vers l'espace privé (ex. glossaire : ecrire/?exec=definition&id_definition=1) : propre() les produit
	// quand l'export tourne dans ecrire/ ; remplacés par la route de l'objet exporté, sinon signalés
	$html = preg_replace_callback(
		'~\bhref=(["\'])(?:https?://[^/"\']+)?/?(?:\.\./)?ecrire/\?exec=([a-z_]+)&(?:amp;)?id_\2=(\d+)(#[^"\']*)?\1~i',
		function ($m) use (&$ctx, $champ) {
			$type = strtolower($m[2]);
			$id = (int) $m[3];
			if (isset($ctx['routes'][$type][$id])) {
				return 'href=' . $m[1] . (isset($ctx['prefixe']) ? $ctx['prefixe'] : '') . $ctx['routes'][$type][$id] . (isset($m[4]) ? $m[4] : '') . $m[1];
			}
			if (isset($ctx['liens_prives'])) {
				$ctx['liens_prives'][] = array('page' => $ctx['route'], 'champ' => $champ, 'url' => html_entity_decode($m[0]));
			}
			return $m[0];
		},
		$html
	);

	// liens absolus vers le site SPIP actuel (non résolus : à repasser en liens internes si besoin)
	if (isset($ctx['liens_site']) and !empty($GLOBALS['meta']['adresse_site'])) {
		$hote = parse_url($GLOBALS['meta']['adresse_site'], PHP_URL_HOST);
		if ($hote and preg_match_all('~href=["\'](https?://' . preg_quote($hote, '~') . '[^"\']*)["\']~i', $html, $ms)) {
			foreach ($ms[1] as $u) {
				$ctx['liens_site'][] = array('page' => $ctx['route'], 'champ' => $champ, 'url' => html_entity_decode($u));
			}
		}
	}

	// liens internes non résolus (URL SPIP « à paramètres »)
	if (preg_match_all('~href=["\']([^"\']*(?:spip\.php\?|[?&]id_(?:article|rubrique|breve|mot|auteur)=)[^"\']*)["\']~i', $html, $mm)) {
		foreach ($mm[1] as $u) {
			// un autre site (ex. http://autre.org/spip.php?article1) n'est pas notre affaire
			$hote_u = parse_url(html_entity_decode($u), PHP_URL_HOST);
			if ($hote_u and preg_replace('/^www\./', '', strtolower($hote_u)) !== $hote_site) {
				continue;
			}
			$ctx['liens_bruts'][] = array('page' => $ctx['route'], 'champ' => $champ, 'url' => html_entity_decode($u));
		}
	}

	// liens stables (option) : routes -> idx:type/id, résolus par le thème
	if (!empty($ctx['stables']) and !empty($ctx['inverse'])) {
		$html = sptg_liens_stables($html, $ctx['inverse'], isset($ctx['prefixe']) ? $ctx['prefixe'] : '');
	}

	if (empty($ctx['markdown'])) {
		return array('val' => $html, 'fmt' => 'html');
	}

	// 5. Markdown + vérification
	$md = sptg_html2md($html);
	if (!empty($ctx['verifier'])) {
		$diff = sptg_comparer_html($html, sptg_md2html($md));
		if ($diff) {
			$ctx['ecarts'][] = array(
				'page' => $ctx['route'], 'champ' => $champ, 'attendu' => $diff['attendu'], 'obtenu' => $diff['obtenu'],
				'source' => mb_substr($raw, max(0, 0), 400), 'markdown' => mb_substr($md, 0, 600),
			);
			return array('val' => $html, 'fmt' => 'html');
		}
	}
	return array('val' => rtrim($md), 'fmt' => 'md');
}

// ------------------------------------------------------------------ export

function sptg_exporter(array $o) {
	include_spip('inc/texte');
	include_spip('inc/filtres');
	include_spip('inc/charsets');
	include_spip('inc/flock');
	include_spip('base/abstract_sql');
	include_spip('inc/sptg_theme');
	@set_time_limit(0);

	$o += array(
		'export_name' => 'spip2grav', 'dossier_destination' => '', 'vider' => false,
		'objets' => array(), 'secteurs' => array(), 'statuts' => array('publie'),
		'generer_theme' => true, 'nom_theme' => '', 'markdown' => true, 'verifier' => true,
		'creer_zip' => false, 'mode_images' => 'partage',
		'conserver_source' => false, 'inclure_emails' => false, 'urls_spip' => true,
		'installer_grav' => false, 'schema_urls' => 'spip', 'redirections' => 'auto', 'langues' => 'auto',
		'liens_stables' => false, 'creer_comptes' => false,
	);
	if (!isset(sptg_schemas_urls()[$o['schema_urls']])) {
		$o['schema_urls'] = 'spip';
	}
	$export_name = $o['export_name'];
	if ($o['dossier_destination'] === '') {
		$o['dossier_destination'] = sptg_destination_defaut($export_name);
	}
	$slug_theme = $o['nom_theme'] !== '' ? $o['nom_theme'] : $export_name . '-theme';

	$rapport = array(
		'pages' => 0, 'ignores' => 0, 'details' => array(), 'ecarts' => array(), 'liens_bruts' => array(),
		'theme' => $o['generer_theme'] ? $slug_theme : '',
		'stats' => array('fichiers' => 0, 'images_partagees' => 0, 'fichiers_absents' => 0, 'modeles_orphelins' => 0),
		'redirections' => 0, 'non_redirigeables' => 0, 'approx' => 0, 'fmt_html' => 0,
		'doublons' => array(), 'trad_conflits' => array(), 'langues' => array(), 'index' => 0,
		'grav' => '', 'moteur_redirections' => '', 'schema_urls' => $o['schema_urls'],
		'liens_stables' => '', 'theme_helpers' => '', 'media_types' => array(), 'comptes' => array(),
		'logos' => array(), 'logos_nb' => array(), 'logos_survol' => 0,
		'liens_prives' => array(), 'liens_site' => array(), 'alertes' => array(), 'extras' => array(),
	);

	// ---- 1. Cible (et installation / mise à jour de Grav si demandée)
	list($racine, $est_install) = sptg_cible($o['dossier_destination']);
	if ($o['installer_grav']) {
		$g = sptg_grav_installer($racine);
		$rapport['grav'] = $g['message'];
		if ($g['etat'] == 'installe') {
			$o['vider'] = true; // les pages d'exemple de Grav sont remplacées
			$est_install = false; // installation neuve : la configuration est écrite
		} else {
			$est_install = sptg_detecter_grav($racine);
		}
	}
	$rapport['racine'] = $racine;
	$rapport['est_install'] = $est_install;
	$pages = $racine . 'user/pages/';
	$themes = $racine . 'user/themes/';

	// Helpers du thème : régénérés avec le thème, sinon ceux du thème réutilisé (qui peut venir d'une
	// ancienne version du plugin ou avoir été personnalisé : il n'est jamais modifié). Sans helpers
	// compatibles, pas de résolveur d'anciennes URL ni de liens stables (routes absolues à la place).
	$helpers_ok = true;
	if (!$o['generer_theme']) {
		$h = sptg_theme_helpers($themes . $slug_theme);
		$helpers_ok = $h['etat'] == 'ok';
		$rapport['theme_helpers'] = $helpers_ok ? 'Thème réutilisé : helpers compatibles (API ' . $h['api'] . ').' : 'Thème réutilisé : ' . $h['message'] . '.';
	}
	$stables = (bool) $o['liens_stables'] && $helpers_ok;
	if ($o['liens_stables']) {
		$rapport['liens_stables'] = $stables ? 'actifs' : 'non activés (routes absolues) : ' . $rapport['theme_helpers'];
	}
	$inverse = array();
	$extensions = array();
	sptg_mkdir($pages);
	if (!is_dir($pages) or !is_writable($pages)) {
		throw new \RuntimeException('Dossier de destination non accessible en écriture : ' . $pages);
	}
	if ($o['vider']) {
		sptg_rm_contenu(rtrim($pages, '/'));
		if ($o['generer_theme'] and is_dir($themes . $slug_theme)) {
			sptg_rm_contenu($themes . $slug_theme);
			@rmdir($themes . $slug_theme);
		}
	}
	sptg_index_vider($racine);

	$intro = sptg_introspecter(false);
	$demandes = (array) $o['objets'];
	$types = array_values(array_intersect(array_keys($intro), $demandes));
	$avec_mots = in_array('mot', $demandes);
	$avec_docs = in_array('document', $demandes);
	$utilise_rub = in_array('rubrique', $types);

	$lignes_cache = array();
	$lire = function ($type) use (&$lignes_cache, $intro, $o) {
		if (!isset($lignes_cache[$type])) {
			$lignes_cache[$type] = sptg_lire_objets($intro[$type], $o['statuts']);
		}
		return $lignes_cache[$type];
	};

	// ---- langues : configuration, blocs <multi>, groupes de traduction (id_trad)
	$cl = sptg_config_langues();
	$multi_actif = false;
	if ($o['langues'] == 'auto' and count($cl['langues']) > 1) {
		foreach ($types as $type) {
			foreach ($lire($type) as $r) {
				foreach ($r as $v) {
					if (is_string($v) and sptg_contient_multi($v)) {
						$multi_actif = true;
						break 3;
					}
				}
			}
		}
	}
	$groupes = array();
	if ($o['langues'] == 'auto') {
		foreach ($types as $type) {
			$conf = array();
			$g = sptg_groupes_trad($lire($type), $intro[$type]['id'], $conf);
			if ($g) {
				$groupes[$type] = $g;
			}
			foreach ($conf as $c) {
				$rapport['trad_conflits'][] = array($type, $c[0], $c[1], $c[2]);
			}
		}
	}
	$rapport['langues'] = $multi_actif ? $cl['langues'] : array();
	$langues_objet = function (array $r) use ($multi_actif, $cl) {
		if (!$multi_actif) {
			return array();
		}
		$presentes = array($cl['langue_site'] => 1);
		foreach ($r as $v) {
			if (is_string($v)) {
				foreach (sptg_langues_du_multi($v, $cl['langues']) as $l) {
					$presentes[$l] = 1;
				}
			}
		}
		return array_values(array_filter($cl['langues'], function ($l) use ($presentes) {
			return isset($presentes[$l]);
		}));
	};

	$items = array();
	$routes = array();
	$noms = array();
	if ($o['mode_images'] == 'partage') {
		$noms[''] = array('images' => 1); // user/pages/images/ est réservé aux images partagées
	}
	$collections = array();
	$rub_chemin = array();
	$redirects = array();
	$legacy = array();

	$slug_de = function ($type, $id, $titre) use ($o) {
		$u = $o['urls_spip'] ? sptg_url_spip($type, $id) : null;
		$slug = '';
		if ($u and isset($u['path'])) {
			$seg = preg_replace('/\.[a-z0-9]{2,5}$/i', '', basename($u['path']));
			// « article12 », « 12 » : URL non descriptive, on préfère le titre
			$slug = preg_match('/^[a-z_-]*\d+$/i', $seg) ? '' : sptg_slug($seg);
		}
		if (!$slug) {
			$slug = sptg_slug($titre) ?: $type . '-' . $id;
		}
		return array($slug, $u);
	};

	// ---- 2. CHEMINS : arborescence des dossiers (admin Grav), puis routes publiques
	$nb_racine = 0;
	if ($utilise_rub) {
		$info = $intro['rubrique'];
		$lignes = array();
		foreach ($lire('rubrique') as $r) {
			$lignes[(int) $r['id_rubrique']] = $r;
		}
		$cache = array();
		$incluse = function ($id) use (&$incluse, &$lignes, &$cache, $o) {
			if (isset($cache[$id])) {
				return $cache[$id];
			}
			if (!isset($lignes[$id])) {
				return $cache[$id] = false;
			}
			$p = (int) $lignes[$id]['id_parent'];
			return $cache[$id] = ($p == 0 ? in_array($id, $o['secteurs']) : $incluse($p));
		};
		$enfants = array();
		foreach ($lignes as $id => $r) {
			if ($incluse($id)) {
				$enfants[(int) $r['id_parent']][] = $r;
			}
		}
		$parcourir = function ($parent_id, $dir, $route, $depart) use (&$parcourir, &$enfants, &$noms, &$rub_chemin, &$routes, &$items, $info, $slug_de) {
			if (empty($enfants[$parent_id])) {
				return 0;
			}
			$fr = $enfants[$parent_id];
			usort($fr, function ($a, $b) {
				return sptg_cle_tri($a['titre']) <=> sptg_cle_tri($b['titre']);
			});
			$n = $depart;
			foreach ($fr as $r) {
				$id = (int) $r['id_rubrique'];
				list($slug, $u) = $slug_de('rubrique', $id, $r['titre']);
				$slug = sptg_nom_unique($noms, $dir, $slug);
				$sdir = $dir . sprintf('%02d.', $n++) . $slug;
				$sroute = $route . '/' . $slug;
				$rub_chemin[$id] = array('dir' => $sdir . '/', 'route' => $sroute);
				$routes['rubrique'][$id] = $sroute;
				$items[] = array(
					'info' => $info, 'row' => $r, 'dir' => $sdir . '/', 'route' => $sroute, 'old' => $u,
					'cle' => 'rubrique:' . $id, 'parent' => $parent_id ? 'rubrique:' . $parent_id : '',
				);
				$parcourir($id, $sdir . '/', $sroute, 1);
			}
			return count($fr);
		};
		$nb_racine = $parcourir(0, '', '', 2);
	}
	$prochain_racine = 2 + $nb_racine;

	foreach ($types as $type) {
		if ($type == 'rubrique') {
			continue;
		}
		$info = $intro[$type];
		$lignes = $lire($type);
		usort($lignes, function ($a, $b) {
			return sptg_cle_tri(isset($a['titre']) ? $a['titre'] : '') <=> sptg_cle_tri(isset($b['titre']) ? $b['titre'] : '');
		});
		foreach ($lignes as $r) {
			$id = (int) $r[$info['id']];
			$titre = isset($r['titre']) ? $r['titre'] : '';
			list($slug, $u) = $slug_de($type, $id, $titre);

			if ($info['a_rubrique'] and $utilise_rub and (int) $r['id_rubrique'] > 0) {
				if (!isset($rub_chemin[(int) $r['id_rubrique']])) {
					$rapport['ignores']++;
					continue;
				}
				$parent = $rub_chemin[(int) $r['id_rubrique']];
				$pdir = $parent['dir'];
				$proute = $parent['route'];
				$cle_parent = 'rubrique:' . (int) $r['id_rubrique'];
			} else {
				if (!isset($collections[$type])) {
					$cslug = sptg_nom_unique($noms, '', $info['objets']);
					$collections[$type] = array(
						'dir' => sprintf('%02d.', $prochain_racine++) . $cslug . '/',
						'route' => '/' . $cslug,
						'info' => $info,
					);
				}
				$pdir = $collections[$type]['dir'];
				$proute = $collections[$type]['route'];
				$cle_parent = 'collection:' . $type;
			}
			$slug = sptg_nom_unique($noms, $pdir, $slug);
			$route = $proute . '/' . $slug;
			$routes[$type][$id] = $route;
			$items[] = array(
				'info' => $info, 'row' => $r, 'dir' => $pdir . $slug . '/', 'route' => $route, 'old' => $u,
				'cle' => $type . ':' . $id, 'parent' => $cle_parent,
			);
		}
	}

	// routes publiques (routes.default) : découplées des dossiers, sans doublon
	$occupe = array();
	$pub = array();
	foreach ($items as $it) {
		$occupe[$it['route']] = $it['cle'];
	}
	foreach ($collections as $type => $c) {
		$occupe[$c['route']] = 'collection:' . $type;
		$pub['collection:' . $type] = $c['route'];
	}
	$a_routes_default = false;
	foreach ($items as $k => $it) {
		$type = $it['info']['type'];
		$id = (int) $it['row'][$it['info']['id']];
		$parent_pub = ($it['parent'] !== '' and isset($pub[$it['parent']])) ? $pub[$it['parent']] : '';
		$r = sptg_route_publique($o['schema_urls'], $type, basename($it['route']), $it['route'], $parent_pub, $it['old']);
		if ($r !== $it['route']) {
			$base = $r;
			$i = 2;
			while (isset($occupe[$r]) and $occupe[$r] !== $it['cle']) {
				$r = $base . '-' . $i++;
			}
			if ($r !== $base) {
				$rapport['doublons'][] = array($base, $r, $it['cle']);
			}
			$occupe[$r] = $it['cle'];
		}
		if ($r !== $it['route']) {
			$a_routes_default = true;
		}
		$items[$k]['pub'] = $r;
		$pub[$it['cle']] = $r;
		$routes[$type][$id] = $r;
		$inverse[$r] = $type . '/' . $id;
	}

	// anciennes URL : table du thème (résolveur) ou redirections de site.yaml (chemins seulement)
	$moteur = $o['redirections'] == 'aucune' ? 'aucune' : ($helpers_ok ? 'resolveur' : 'site');
	$rapport['moteur_redirections'] = $moteur;
	if ($moteur != 'aucune') {
		// chemin SPIP actuel (sans langue ni terminaison) des rubriques : parents des anciennes URL arborescentes
		$chemins_rub = array();
		foreach ($items as $it) {
			if ($it['info']['type'] == 'rubrique' and $it['old'] and isset($it['old']['path'])) {
				$chemins_rub[(int) $it['row']['id_rubrique']] = sptg_chemin_decouper($it['old']['path'], $cl['langues'])[2];
			}
		}
		$chemin_parent = function ($id_parent) use ($chemins_rub) {
			return $chemins_rub[$id_parent] ?? null;
		};
		foreach ($items as $it) {
			$type = $it['info']['type'];
			$id = (int) $it['row'][$it['info']['id']];
			$hist = $o['urls_spip'] ? sptg_urls_historiques($type, $id) : array();
			foreach (sptg_anciennes_urls($type, $id, $it['old'], $it['pub'], $hist, $chemin_parent, $cl['langues']) as $a) {
				if ($a['approx']) {
					$rapport['approx']++;
				}
				if ($moteur == 'resolveur') {
					$legacy[] = array($a['chemin'], $a['requete'], $type, $id);
				} elseif ($a['requete'] === '') {
					$redirects[$a['chemin']] = $it['pub'];
				} else {
					$rapport['non_redirigeables']++;
				}
			}
		}
	}
	$rapport['redirections'] = $moteur == 'resolveur' ? count($legacy) : count($redirects);

	// ---- 3. Relations : mots, auteurs, documents
	$cles_types = array_values(array_unique(array_map(function ($i) {
		return $i['info']['type'];
	}, $items)));
	$taxo_obj = array();
	$taxonomies = array('category', 'tag');
	if ($avec_mots and $cles_types) {
		$q = sql_allfetsel(
			'l.objet, l.id_objet, m.titre AS mot, g.titre AS groupe',
			'spip_mots_liens AS l INNER JOIN spip_mots AS m ON m.id_mot=l.id_mot INNER JOIN spip_groupes_mots AS g ON g.id_groupe=m.id_groupe',
			sql_in('l.objet', $cles_types)
		) ?: array();
		foreach ($q as $m) {
			$cle = sptg_slug(sptg_titre_propre($m['groupe'])) ?: 'mot';
			$taxonomies[] = $cle;
			$taxo_obj[$m['objet'] . ':' . $m['id_objet']][$cle][] = sptg_titre_propre($m['mot']);
		}
	}
	// Rattachement des pages aux comptes Grav (identifiants), indépendant de l'export des pages d'auteurs.
	// Les comptes sont planifiés avant l'écriture des pages ; sans création de comptes, seuls ceux qui existent
	// déjà dans la destination sont utilisés.
	$plan_comptes = sptg_comptes_planifier($racine);
	$map_comptes = $o['creer_comptes'] ? $plan_comptes['map'] : array_diff($plan_comptes['map'], array_keys($plan_comptes['comptes']));
	$auteurs_obj = array();
	$auteurs_ext = array();
	if ($cles_types) {
		$q = sql_allfetsel(
			'l.objet, l.id_objet, a.id_auteur, a.nom',
			'spip_auteurs_liens AS l INNER JOIN spip_auteurs AS a ON a.id_auteur=l.id_auteur',
			array(sql_in('l.objet', $cles_types), "a.statut!='5poubelle'")
		) ?: array();
		foreach ($q as $a) {
			$cle_a = $a['objet'] . ':' . $a['id_objet'];
			if (isset($map_comptes[(int) $a['id_auteur']])) {
				$auteurs_obj[$cle_a][] = $map_comptes[(int) $a['id_auteur']];
			} else {
				$auteurs_ext[$cle_a][] = sptg_titre_propre($a['nom']);
			}
		}
		if ($auteurs_obj) {
			$taxonomies[] = 'auteur';
		}
	}
	$docs_obj = array();
	if ($avec_docs and $cles_types) {
		$q = sql_allfetsel(
			'l.objet, l.id_objet, d.*',
			'spip_documents_liens AS l INNER JOIN spip_documents AS d ON d.id_document=l.id_document',
			array(sql_in('l.objet', $cles_types), sql_in('d.mode', array('document', 'image')))
		) ?: array();
		foreach ($q as $d) {
			$docs_obj[$d['objet'] . ':' . $d['id_objet']][] = $d;
		}
	}

	// ---- 4/5. Rendu et écriture des pages (un fichier par langue en mode <multi>)
	$compte = array();
	$partage_vus = array();
	$logos_vus = array();
	$prefixe_de = function ($lang) use ($multi_actif, $cl) {
		return ($multi_actif and $lang !== '' and $lang !== $cl['langue_site']) ? '/' . $lang : '';
	};
	foreach ($items as $it) {
		$info = $it['info'];
		$type = $info['type'];
		$id = (int) $it['row'][$info['id']];
		$r = $it['row'];
		$cle = $type . ':' . $id;
		$dirabs = $pages . $it['dir'];
		$langues_obj = $langues_objet($r);
		$lang_objet = isset($r['lang']) ? (string) $r['lang'] : '';
		$noms_item = array();

		foreach ($langues_obj ?: array('') as $lang_fichier) {
			$lang_rendu = $lang_fichier !== '' ? $lang_fichier : ($lang_objet !== '' ? $lang_objet : $cl['langue_site']);
			$ctx = array(
				'routes' => &$routes, 'extensions' => &$extensions, 'stables' => $stables, 'inverse' => &$inverse, 'markdown' => $o['markdown'], 'verifier' => $o['verifier'],
				'mode_images' => $o['mode_images'], 'partage_dir' => $pages . 'images/', 'partage_vus' => &$partage_vus,
				'dirabs' => $dirabs, 'route' => $it['pub'] . ($lang_fichier !== '' ? ' [' . $lang_fichier . ']' : ''), 'noms' => &$noms_item,
				'ecarts' => &$rapport['ecarts'], 'liens_bruts' => &$rapport['liens_bruts'], 'stats' => &$rapport['stats'],
				'liens_prives' => &$rapport['liens_prives'], 'liens_site' => &$rapport['liens_site'],
				'lang' => $lang_rendu, 'prefixe' => $prefixe_de($lang_fichier),
			);
			$html_champs = array();

			// documents joints
			$docs = array();
			if (!empty($docs_obj[$cle])) {
				foreach ($docs_obj[$cle] as $d) {
					$fiche = array(
						'id' => (int) $d['id_document'],
						'titre' => sptg_titre_propre($d['titre'], $lang_rendu),
						'extension' => $d['extension'],
						'mode' => $d['mode'],
						'largeur' => (int) $d['largeur'],
						'hauteur' => (int) $d['hauteur'],
						'taille' => (int) $d['taille'],
						'credits' => $d['credits'],
					);
					$desc = sptg_rendre_texte($d['descriptif'], $ctx, 'document ' . $d['id_document'] . ' descriptif');
					$fiche['descriptif'] = $desc['val'];
					if ($d['distant'] == 'oui') {
						$fiche['url'] = $d['fichier'];
					} else {
						$ref = sptg_fichier_ref($ctx, _DIR_IMG . $d['fichier'], 'IMG/' . $d['fichier']);
						$fiche['url'] = $ref;
						if (strpos($ref, '/') !== 0) {
							$fiche['local'] = $ref; // document non image : dossier de la page
						}
					}
					$docs[] = array_filter($fiche, function ($v) {
						return $v !== '' and $v !== null and $v !== 0;
					});
				}
			}

			// logo (image : dossier partagé ou IMG selon le mode)
			$logo = array();
			$lg = sptg_logo_trouver($type, $id);
			if ($lg) {
				$logo['logo_url'] = sptg_fichier_ref($ctx, $lg['abs'], $lg['rel']);
			}
			if (!isset($logos_vus[$cle])) {
				$logos_vus[$cle] = 1;
				$rapport['logos_nb'][$type] = isset($rapport['logos_nb'][$type]) ? $rapport['logos_nb'][$type] : array('objets' => 0, 'logos' => 0);
				$rapport['logos_nb'][$type]['objets']++;
				if ($lg) {
					$rapport['logos_nb'][$type]['logos']++;
					$rapport['logos'][] = array(
						'type' => $type, 'id' => $id, 'route' => $it['pub'], 'source' => $lg['source'], 'fichier' => $lg['rel'],
						'ref' => $logo['logo_url'], 'present' => is_file($lg['abs']),
					);
				}
			}

			// champs
			$spip = array('id' => $id, 'type' => $type);
			$source = array();
			foreach (sptg_champs_exportables($info, $o['inclure_emails']) as $nom => $c) {
				if (!isset($r[$nom]) or $r[$nom] === '') {
					continue;
				}
				$v = $r[$nom];
				switch ($c['kind']) {
					case 'texte':
						if ($o['conserver_source']) {
							$source[$nom] = $v;
						}
						$res = sptg_rendre_texte($v, $ctx, $nom);
						$spip[$nom] = $res['val'];
						if ($res['fmt'] == 'html') {
							$html_champs[] = $nom;
						}
						break;
					case 'int':
						$spip[$nom] = (int) $v;
						break;
					case 'bool':
						$spip[$nom] = (bool) $v;
						break;
					case 'nombre':
						$spip[$nom] = (float) $v;
						break;
					case 'date':
						$spip[$nom] = sptg_date($v);
						break;
					default:
						if (sptg_contient_multi($v) and function_exists('extraire_multi')) {
							$v = extraire_multi($v, $lang_rendu);
						}
						$spip[$nom] = in_array($nom, array('surtitre', 'soustitre')) ? sptg_titre_propre($v, $lang_rendu) : $v;
				}
			}
			$spip = array_filter($spip, function ($v) {
				return $v !== null;
			});

			// champs extras (plugin Champs Extras) : bloc `extras:` de l'entête, valeurs typées
			$extras = array();
			$extras_html = array();
			foreach (sptg_extras_champs($info) as $nom => $c) {
				if (!isset($r[$nom]) or $r[$nom] === '') {
					continue;
				}
				if ($c['kind'] == 'texte') {
					$res = sptg_rendre_texte($r[$nom], $ctx, $nom);
					$val = $res['val'];
					if ($res['fmt'] == 'html') {
						$extras_html[] = $nom;
					}
				} else {
					$val = sptg_extras_valeur($r[$nom], $c);
					if (is_string($val) and sptg_contient_multi($val) and function_exists('extraire_multi')) {
						$val = extraire_multi($val, $lang_rendu);
					}
				}
				if ($val !== null and $val !== '') {
					$extras[$nom] = $val;
				}
			}
			if (!isset($rapport['extras'][$type]) and sptg_extras_champs($info)) {
				foreach (sptg_extras_champs($info) as $nom => $c) {
					$rapport['extras'][$type][] = array('nom' => $nom, 'saisie' => $c['saisie'], 'label' => $c['label'], 'groupe' => $c['groupe']);
				}
			}

			$publie = true;
			if ($info['statut'] and isset($r[$info['statut']]) and $type != 'auteur') {
				$publie = ($r[$info['statut']] === $info['publie']);
			}
			$titre = isset($r['titre']) ? sptg_titre_propre($r['titre'], $lang_rendu) : '';
			$header = array('title' => $titre !== '' ? $titre : ($type . ' ' . $id));
			$date = $info['date'] && isset($r[$info['date']]) ? sptg_date($r[$info['date']]) : null;
			if ($date) {
				$header['date'] = $date;
			}
			$header['published'] = $publie;
			// Publication différée : SPIP ne montre un article publié à date future que si la meta post_dates
			// vaut « oui » (défaut). Avec « non », il apparaît à sa date : c'est le publish_date de Grav
			// (option system.pages.publish_dates, active par défaut).
			if ($publie and $date and sptg_publication_differee($info, $date)) {
				$header['publish_date'] = $date;
				$rapport['alertes']['Publication différée (publish_date, la page apparaîtra à cette date)'][] = $it['pub'] . ' → ' . $date;
			}
			// identité stable, invisible dans l'admin (clés absentes des blueprints ; admin2 fusionne les
			// champs modifiés sur l'entête existant : elles sont conservées à chaque enregistrement)
			$header['spip_id'] = $id;
			$header['spip_type'] = $type;
			$lang_entete = $lang_fichier !== '' ? $lang_fichier : $lang_objet;
			if ($lang_entete !== '') {
				$header['spip_lang'] = $lang_entete;
			}
			if (!empty($r['id_trad']) and (int) $r['id_trad'] > 0) {
				$header['spip_trad'] = (int) $r['id_trad'];
			}
			if ($it['pub'] !== $it['route']) {
				$header['routes'] = array('default' => $it['pub']);
			}

			$corps = '';
			$corps_fmt = 'md';
			if (isset($info['champs']['texte'])) {
				$res = sptg_rendre_texte(isset($r['texte']) ? $r['texte'] : '', $ctx, 'texte');
				$corps = $res['val'];
				$corps_fmt = $res['fmt'];
			}
			$route_al = $it['pub'] . ($lang_fichier !== '' ? ' [' . $lang_fichier . ']' : '');
			if (preg_match('/\{\{|\{%|\{#/', $corps)) {
				$rapport['alertes']['Contenu contenant « {{ », « {% » ou « {# » (Twig neutralisé : process.twig=false)'][] = $route_al;
			}
			foreach (array('script', 'iframe', 'embed', 'object', 'form', 'style') as $balise_al) {
				if (stripos($corps, '<' . $balise_al) !== false) {
					$rapport['alertes']['Contenu contenant une balise <' . $balise_al . '>'][] = $route_al;
				}
			}
			if (trim(isset($header['title']) ? (string) $header['title'] : '') === '') {
				$rapport['alertes']['Page sans titre'][] = $route_al;
			}
			if ($type !== 'rubrique' and isset($info['champs']['texte']) and trim(strip_tags($corps)) === '' and stripos($corps, '<img') === false) {
				$rapport['alertes']['Page au corps vide (hors rubriques)'][] = $route_al;
			}
			if ($corps_fmt == 'html') {
				$header['process'] = array('markdown' => false, 'twig' => false);
				$rapport['fmt_html']++;
			}
			if (!empty($taxo_obj[$cle])) {
				$header['taxonomy'] = array_map('array_values', array_map('array_unique', $taxo_obj[$cle]));
			}
			if (!empty($auteurs_obj[$cle])) {
				$header['auteurs'] = array_values(array_unique($auteurs_obj[$cle]));
				$header['taxonomy']['auteur'] = $header['auteurs'];
			}
			if (!empty($auteurs_ext[$cle])) {
				$header['auteurs_sans_compte'] = array_values(array_unique($auteurs_ext[$cle]));
			}
			if ($type == 'auteur' and isset($map_comptes[$id])) {
				// la page d'un auteur est accrochée à son compte : nom, intitulé et (si demandé) email sont lus dans le compte
				$header['account'] = $map_comptes[$id];
				if ($o['inclure_emails']) {
					$header['afficher_email'] = true;
				}
			}
			$header['spip'] = $spip;
			if ($extras) {
				$header['extras'] = $extras;
				if ($extras_html) {
					$header['extras_html'] = $extras_html;
				}
			}
			if ($html_champs) {
				$header['spip_html'] = $html_champs;
			}
			if ($source) {
				$header['spip_source'] = $source;
			}
			if ($docs) {
				$header['documents'] = $docs;
			}
			$header = array_merge($header, $logo);

			$fichier_md = $type . ($lang_fichier !== '' ? '.' . $lang_fichier : '') . '.md';
			sptg_ecrire($dirabs . $fichier_md, sptg_page_md($header, $corps));
			$rapport['pages']++;
			$compte[$info['label']] = isset($compte[$info['label']]) ? $compte[$info['label']] + 1 : 1;
			unset($ctx);
		}

		// index des identifiants
		$trad = array();
		if (isset($groupes[$type][$id])) {
			foreach ($groupes[$type][$id] as $l => $idt) {
				if (isset($routes[$type][$idt])) {
					$trad[$l] = $idt;
				}
			}
		}
		if (!$trad and $lang_objet !== '') {
			$trad[$lang_objet] = $id;
		}
		sptg_index_ecrire($racine, array(
			'type' => $type, 'id' => $id, 'lang' => $lang_objet, 'trad' => $trad,
			'dossier' => rtrim($it['dir'], '/'), 'langues' => $langues_obj,
		));
		$rapport['index']++;
	}
	foreach ($legacy as $l) {
		sptg_index_legacy_url($racine, $l[0], $l[1], $l[2], $l[3]);
	}

	// collections (pages d'index pour les objets hors rubriques)
	foreach ($collections as $c) {
		foreach ($multi_actif ? $cl['langues'] : array('') as $lf) {
			sptg_ecrire($pages . $c['dir'] . 'collection' . ($lf !== '' ? '.' . $lf : '') . '.md', sptg_page_md(array(
				'title' => $c['info']['label'],
				'process' => array('markdown' => false, 'twig' => false),
			)));
		}
	}

	// accueil
	$nom_site = isset($GLOBALS['meta']['nom_site']) ? $GLOBALS['meta']['nom_site'] : 'Site SPIP';
	$desc_site = isset($GLOBALS['meta']['descriptif_site']) ? $GLOBALS['meta']['descriptif_site'] : '';
	foreach ($multi_actif ? $cl['langues'] : array('') as $lf) {
		$lr = $lf !== '' ? $lf : $cl['langue_site'];
		$ctx = array(
			'routes' => &$routes, 'extensions' => &$extensions, 'stables' => $stables, 'inverse' => &$inverse, 'markdown' => $o['markdown'], 'verifier' => $o['verifier'],
			'mode_images' => $o['mode_images'], 'partage_dir' => $pages . 'images/', 'partage_vus' => &$partage_vus,
			'dirabs' => $pages . '01.home/', 'route' => '/home', 'noms' => array(),
			'ecarts' => &$rapport['ecarts'], 'liens_bruts' => &$rapport['liens_bruts'], 'stats' => &$rapport['stats'],
			'liens_prives' => &$rapport['liens_prives'], 'liens_site' => &$rapport['liens_site'],
			'lang' => $lr, 'prefixe' => $prefixe_de($lf),
		);
		$res = sptg_rendre_texte($desc_site, $ctx, 'descriptif_site');
		$hh = array('title' => sptg_titre_propre($nom_site, $lr));
		if ($res['fmt'] == 'html') {
			$hh['process'] = array('markdown' => false, 'twig' => false);
		}
		sptg_ecrire($pages . '01.home/home' . ($lf !== '' ? '.' . $lf : '') . '.md', sptg_page_md($hh, $res['val']));
		unset($ctx);
	}

	// ---- configuration Grav
	$taxonomies = array_values(array_unique($taxonomies));
	$config_site = array(
		'title' => sptg_titre_propre($nom_site, $cl['langue_site']),
		'lang' => $cl['langue_site'],
		'metadata' => array('description' => textebrut(typo($desc_site))),
	);
	if ($redirects) {
		$config_site['redirects'] = array();
		foreach ($redirects as $ancien => $nouveau) {
			$config_site['redirects'][preg_quote($ancien, '#') . '$'] = $nouveau;
		}
	}
	$config_system = array(
		'pages' => array(
			'markdown' => array('extra' => false, 'auto_line_breaks' => false, 'auto_url_links' => false, 'escape_markup' => false),
		),
		'taxonomies' => $taxonomies,
	);
	if ($a_routes_default) {
		// la route « brute » (dossiers) renvoie vers routes.default : pas de contenu en double
		$config_system['pages']['redirect_default_route'] = 301;
	}
	if ($multi_actif) {
		$fallback = array();
		foreach ($cl['langues'] as $l) {
			if ($l !== $cl['langue_site']) {
				$fallback[$l] = array($cl['langue_site']); // SPIP : sans bloc [lang], le premier bloc est servi
			}
		}
		$config_system['languages'] = array(
			'supported' => $cl['langues'],
			'default_lang' => $cl['langue_site'],
			'include_default_lang' => false,
			'include_default_lang_file_extension' => true,
			'http_accept_language' => false,
			'content_fallback' => $fallback,
		);
	}
	if ($o['generer_theme']) {
		$config_system['pages']['theme'] = $slug_theme;
	}
	$fichiers_config = array();
	$types_media = sptg_config_media($extensions);
	$rapport['media_types'] = array_keys($types_media);
	if (!$est_install) {
		sptg_ecrire($racine . 'user/config/site.yaml', sptg_yaml($config_site));
		sptg_ecrire($racine . 'user/config/system.yaml', sptg_yaml($config_system));
		if ($types_media) {
			sptg_ecrire($racine . 'user/config/media.yaml', sptg_yaml(array('types' => $types_media)));
		}
		$fichiers_config = array('user/config');
	}

	// ---- thème
	if ($o['generer_theme']) {
		$types_utilises = array();
		foreach ($items as $it) {
			$types_utilises[$it['info']['type']] = $it['info'];
		}
		sptg_generer_theme($themes . $slug_theme . '/', $slug_theme, $types_utilises, (bool) $o['inclure_emails']);
	}

	$rapport['logos_survol'] = sptg_logos_survol_nb();

	// ---- comptes des auteurs (rédacteurs admin2, sans mot de passe)
	if ($o['creer_comptes']) {
		$c = sptg_comptes_exporter($racine, $plan_comptes);
		$c['resume'] = 'Comptes Grav : ' . $c['crees'] . ' créé(s)'
			. ($c['desactives'] ? ', dont ' . count($c['desactives']) . ' désactivé(s) faute d\'email exploitable (voir le rapport)' : '')
			. ($c['rattaches'] ? ' ; ' . count($c['rattaches']) . ' auteur(s) rattaché(s) à un compte existant' : '') . '.';
		$rapport['comptes'] = $c;
	}

	// ---- IMG conservé tel quel
	$sous_dossiers = array('user/pages', 'user/data/spip-id');
	if (!empty($rapport['comptes']['fichiers'])) {
		// uniquement les comptes écrits ici : jamais ceux déjà présents (mots de passe hachés de l'administrateur)
		$sous_dossiers = array_merge($sous_dossiers, $rapport['comptes']['fichiers']);
	}
	if ($o['generer_theme']) {
		$sous_dossiers[] = 'user/themes/' . $slug_theme;
	}
	if ($o['mode_images'] == 'img') {
		$n = sptg_copier_arbre(rtrim(_DIR_IMG, '/'), $racine . 'IMG');
		$n += sptg_copier_arbre(_DIR_VAR . 'cache-vignettes', $racine . 'local/cache-vignettes');
		$rapport['details'][] = "IMG : $n fichier(s)";
		$sous_dossiers[] = 'IMG';
		$sous_dossiers[] = 'local';
	}
	foreach ($compte as $lab => $n) {
		$rapport['details'][] = "$lab : $n";
	}

	// ---- rapport
	$snippet = '';
	if ($est_install) {
		$snippet = "# À fusionner dans user/config/system.yaml\n" . sptg_yaml($config_system)
			. "\n# À fusionner dans user/config/site.yaml\n" . sptg_yaml($config_site);
		if ($types_media) {
			$snippet .= "\n# À fusionner dans user/config/media.yaml (sans cela Grav répond 404 pour ces extensions)\n"
				. sptg_yaml(array('types' => $types_media));
		}
	}
	$tmp = sous_repertoire(_DIR_TMP, 'sptg');
	$nom_rapport = $export_name . '-rapport.md';
	sptg_ecrire($tmp . $nom_rapport, sptg_rapport_texte($rapport, $o, $snippet));
	$rapport['rapport'] = $nom_rapport;
	$rapport['snippet'] = $snippet;

	// ---- zip (uniquement si tout s'est bien passé)
	$rapport['zip'] = '';
	$rapport['zip_refuse'] = '';
	if ($o['creer_zip']) {
		if ($rapport['ecarts']) {
			$rapport['zip_refuse'] = count($rapport['ecarts']) . ' écart(s) de vérification';
		} else {
			$nom_zip = $export_name . '.zip';
			sptg_zipper($racine, array_merge($sous_dossiers, $fichiers_config), $tmp . $nom_zip);
			$rapport['zip'] = $nom_zip;
		}
	}
	return $rapport;
}

function sptg_rapport_texte(array $r, array $o, $snippet) {
	$t = "# Rapport d'export SPIP vers Grav : " . $o['export_name'] . "\n\n";
	$t .= "- Destination : `" . $r['racine'] . "`" . ($r['est_install'] ? " (installation Grav détectée)" : '') . "\n";
	$t .= "- Pages : " . $r['pages'] . " (dont " . $r['fmt_html'] . " corps conservés en HTML)\n";
	$t .= "- Objets ignorés (rubrique non exportée) : " . $r['ignores'] . "\n";
	$t .= "- Images partagées copiées dans user/pages/images : " . $r['stats']['images_partagees'] . "\n";
	$t .= "- Documents copiés dans les dossiers de pages : " . $r['stats']['fichiers'] . "\n";
	$t .= "- Fichiers référencés mais absents du disque : " . $r['stats']['fichiers_absents'] . "\n";
	$t .= "- Modèles d'image/document introuvables : " . $r['stats']['modeles_orphelins'] . "\n";
	if ($r['grav']) {
		$t .= "- Grav : " . $r['grav'] . "\n";
	}
	if ($r['theme_helpers']) {
		$t .= "- " . $r['theme_helpers'] . "\n";
	}
	if ($r['media_types']) {
		$t .= "- Types de média ajoutés à Grav (user/config/media.yaml) : " . implode(', ', $r['media_types']) . "\n";
	}
	if (!empty($r['comptes'])) {
		$t .= "- " . $r['comptes']['resume'] . "\n";
	}
	if ($r['liens_stables']) {
		$t .= "- Liens internes stables (idx:type/id) : " . $r['liens_stables'] . "\n";
	}
	$t .= "- Schéma d'URL : " . $r['schema_urls'] . " ; index des identifiants : " . $r['index'] . " entrée(s) dans user/data/spip-id\n";
	$t .= "- Anciennes URL : " . $r['redirections'] . " (moteur : " . ($r['moteur_redirections'] ?: 'aucun') . ")"
		. ($r['non_redirigeables'] ? " ; non redirigeables avec site.yaml (URL à paramètres) : " . $r['non_redirigeables'] : '')
		. ($r['approx'] ? " ; reconstituées par approximation (URL arborescentes historiques) : " . $r['approx'] : '') . "\n";
	if ($r['langues']) {
		$t .= "- Langues (blocs <multi>, i18n Grav) : " . implode(', ', $r['langues']) . "\n";
	}
	foreach ($r['details'] as $d) {
		$t .= "- $d\n";
	}

	// champs extras
	if (!empty($r['extras'])) {
		$t .= "\n## Champs extras (plugin Champs Extras)\n\n";
		$t .= "Écrits dans l'entête de chaque page sous `extras:` ; le blueprint reprend libellé, type de saisie, valeurs proposées, obligatoire, défaut et aide.\n\n";
		foreach ($r['extras'] as $ty => $liste) {
			foreach ($liste as $e) {
				$t .= "- " . $ty . " : `" . $e['nom'] . "` — " . $e['label'] . " (saisie " . $e['saisie'] . ($e['groupe'] !== '' ? ', groupe « ' . $e['groupe'] . ' »' : '') . ")\n";
			}
		}
	}

	// logos
	$t .= "\n## Logos\n\n";
	foreach ($r['logos_nb'] as $ty => $n) {
		$t .= "- " . $ty . " : " . $n['logos'] . " logo(s) pour " . $n['objets'] . " objet(s) exporté(s)\n";
	}
	$t .= "- Logos de survol (mode logooff, non exportés) : " . (int) $r['logos_survol'] . "\n";
	$t .= "- Affichage : champ `logo_url` de l'entête de la page, rendu par `partials/logo.html.twig` du thème généré.\n\n";
	if ($r['logos']) {
		$t .= "| objet | route | source | fichier SPIP | référence écrite | sur le disque |\n|---|---|---|---|---|---|\n";
		foreach ($r['logos'] as $l) {
			$t .= "| " . $l['type'] . " " . $l['id'] . " | " . $l['route'] . " | " . ($l['source'] == 'base' ? 'document SPIP' : 'ancien fichier') . " | `" . $l['fichier'] . "` | `" . $l['ref'] . "` | " . ($l['present'] ? 'oui' : '**ABSENT**') . " |\n";
		}
	}
	if (!empty($r['comptes'])) {
		$t .= "\n## Comptes des auteurs\n\n- Prérequis : " . $r['comptes']['prerequis'] . "\n";
		foreach ($r['comptes']['liste'] as $u => $c) {
			$t .= "- Créé : `" . $u . "` — " . $c['nom'] . " (" . $c['intitule'] . ($c['super'] ? ', super-administrateur' : '') . ($c['etat'] == 'disabled' ? ', **désactivé**' : '') . ")\n";
		}
		foreach ($r['comptes']['desactives'] as $i) {
			$t .= "- Compte désactivé, sans email : auteur " . $i['id_auteur'] . " (" . $i['nom'] . ") → `" . $i['compte'] . "` — " . $i['raison'] . ". Il porte l'attribution des pages ; renseignez l'email dans admin2 puis activez le compte.\n";
		}
		foreach ($r['comptes']['rattaches'] as $i) {
			$t .= "- Rattaché à un compte existant : auteur " . $i['id_auteur'] . " (" . $i['nom'] . ") → `" . $i['compte'] . "` — " . $i['raison'] . "\n";
		}
	}
	if ($r['doublons']) {
		$t .= "\n## Routes en double, suffixées (" . count($r['doublons']) . ")\n\n";
		foreach ($r['doublons'] as $d) {
			$t .= "- " . $d[2] . " : " . $d[0] . " -> " . $d[1] . "\n";
		}
	}
	if ($r['trad_conflits']) {
		$t .= "\n## Traductions en conflit : deux objets d'un même groupe ont la même langue (" . count($r['trad_conflits']) . ")\n\n";
		foreach ($r['trad_conflits'] as $c) {
			$t .= "- " . $c[0] . " " . $c[1] . " conservé, " . $c[2] . " ignoré dans le groupe (langue " . $c[3] . ")\n";
		}
	}
	if ($r['alertes']) {
		$t .= "\n## Points d'attention\n\n";
		foreach ($r['alertes'] as $titre => $pages) {
			$t .= "### " . $titre . " (" . count($pages) . ")\n\n";
			foreach (array_slice($pages, 0, 40) as $p) {
				$t .= "- " . $p . "\n";
			}
			if (count($pages) > 40) {
				$t .= "- … " . (count($pages) - 40) . " autre(s)\n";
			}
			$t .= "\n";
		}
	}
	if ($r['liens_prives']) {
		$t .= "\n## Liens vers l'espace privé de SPIP non résolus (" . count($r['liens_prives']) . ")\n\n";
		foreach (array_slice($r['liens_prives'], 0, 40) as $l) {
			$t .= "- " . $l['page'] . " / " . $l['champ'] . " : " . $l['url'] . "\n";
		}
	}
	if ($r['liens_site']) {
		$t .= "\n## Liens absolus vers le site SPIP actuel (" . count($r['liens_site']) . ")\n\n";
		foreach (array_slice($r['liens_site'], 0, 40) as $l) {
			$t .= "- " . $l['page'] . " / " . $l['champ'] . " : " . $l['url'] . "\n";
		}
		if (count($r['liens_site']) > 40) {
			$t .= "- … " . (count($r['liens_site']) - 40) . " autre(s)\n";
		}
	}
	$t .= "\n## Écarts de vérification (" . count($r['ecarts']) . ")\n\n";
	$t .= "Pour chacun, le contenu est conservé en HTML. Collez ces blocs pour enrichir tests/fixtures.\n\n";
	foreach ($r['ecarts'] as $k => $e) {
		$t .= "### " . ($k + 1) . ". " . $e['page'] . " / " . $e['champ'] . "\n\n";
		$t .= "Attendu (HTML normalisé de propre()) :\n```\n" . $e['attendu'] . "\n```\nObtenu (Markdown rendu en HTML) :\n```\n" . $e['obtenu'] . "\n```\n";
		$t .= "Markdown produit :\n```\n" . $e['markdown'] . "\n```\nSource SPIP :\n```\n" . $e['source'] . "\n```\n\n";
	}

	$t .= "## Liens SPIP laissés tels quels (" . count($r['liens_bruts']) . ")\n\n";
	$t .= "URL de page sans objet identifiable (par ex. `spip.php?page=contact`) ou d'un objet non exporté : à traiter à la main, sans effet sur l'export ni sur le zip. Les liens vers d'autres sites sont ignorés.\n\n";
	foreach ($r['liens_bruts'] as $l) {
		$t .= "- " . $l['page'] . " / " . $l['champ'] . " : " . $l['url'] . "\n";
	}
	if ($snippet) {
		$t .= "\n## Configuration à fusionner à la main (installation existante)\n\n```yaml\n" . $snippet . "```\n";
	}
	return $t;
}

function sptg_zipper($racine, array $chemins, $zip) {
	@unlink($zip);
	$racine = rtrim(realpath($racine), '/') . '/';
	$fichiers = array();
	foreach ($chemins as $c) {
		$p = $racine . $c;
		if (is_file($p)) {
			$fichiers[] = $p;
		} elseif (is_dir($p)) {
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if ($f->isFile()) {
					$fichiers[] = $f->getPathname();
				}
			}
		}
	}
	if (class_exists('ZipArchive')) {
		$z = new ZipArchive();
		if ($z->open($zip, ZipArchive::CREATE) === true) {
			foreach ($fichiers as $f) {
				$z->addFile($f, substr($f, strlen($racine)));
			}
			$z->close();
			return true;
		}
	}
	include_spip('inc/pclzip');
	$p = new PclZip($zip);
	return $p->create($fichiers, PCLZIP_OPT_REMOVE_PATH, rtrim($racine, '/')) != 0;
}
