<?php
/**
 * Moteur d'export SPIP -> Grav.
 *
 * Arborescence produite (dans l'archive ZIP) :
 *   user/pages/...            pages (rubriques = dossiers, objets = sous-dossiers)
 *   user/themes/<slug>/...    thème généré (templates Twig + blueprints)
 *   user/config/*.yaml        configuration minimale
 *   IMG/ ...                  dossier IMG (optionnel)
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

include_spip('inc/sptg_introspection');
include_spip('inc/sptg_yaml');

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

function sptg_slug($txt, $max = 60) {
	include_spip('inc/charsets');
	$txt = textebrut($txt);
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

function sptg_titre_propre($t) {
	return trim(supprimer_numero(textebrut(typo($t))));
}

function sptg_cle_tri($titre) {
	$n = recuperer_numero($titre);
	return array($n === '' ? PHP_INT_MAX : (int) $n, strtolower(sptg_titre_propre($titre)));
}

function sptg_date($d) {
	if (!$d or strncmp($d, '0000', 4) === 0) {
		return null;
	}
	return substr($d, 0, 16);
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

// ------------------------------------------------------------- contenu HTML

/** Remplace les liens internes SPIP ([->art12]) par la route Grav correspondante */
function sptg_liens_internes($texte, array $routes) {
	$alias = array(
		'art' => 'article', 'article' => 'article', 'rub' => 'rubrique', 'rubrique' => 'rubrique',
		'br' => 'breve', 'breve' => 'breve', 'aut' => 'auteur', 'auteur' => 'auteur', 'mot' => 'mot',
		'' => 'article',
	);
	return preg_replace_callback(
		'/->\s*([a-z_]*?)\s*(\d+)(#[A-Za-z0-9_-]+)?\s*(?=\])/i',
		function ($m) use ($routes, $alias) {
			$t = strtolower($m[1]);
			$type = isset($alias[$t]) ? $alias[$t] : $t;
			if (isset($routes[$type][(int) $m[2]])) {
				return '->' . $routes[$type][(int) $m[2]] . (isset($m[3]) ? $m[3] : '');
			}
			return $m[0];
		},
		$texte
	);
}

function sptg_html($texte, array $routes, array $remplacements = array()) {
	if (trim((string) $texte) === '') {
		return '';
	}
	$texte = sptg_liens_internes($texte, $routes);
	try {
		$html = propre($texte);
	} catch (\Throwable $e) {
		$html = nl2br(entites_html($texte));
	}
	foreach ($remplacements as $de => $vers) {
		$html = str_replace($de, $vers, $html);
	}
	// IMG/ et vignettes : liens absolus depuis la racine du site Grav
	return preg_replace('~(\b(?:src|href)=["\'])(?:\./)?((?:IMG|local/cache-vignettes)/)~i', '$1/$2', $html);
}

function sptg_prefixe_logo($type) {
	if (function_exists('type_du_logo')) {
		return type_du_logo(id_table_objet($type));
	}
	$m = array('article' => 'arton', 'rubrique' => 'rubon', 'auteur' => 'auton', 'mot' => 'moton', 'breve' => 'breveon');
	return isset($m[$type]) ? $m[$type] : $type . 'on';
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
		'objets' => array(), 'secteurs' => array(), 'statuts' => array('publie'),
		'generer_theme' => true, 'nom_theme' => 'spip2grav', 'conserver_img' => true,
		'copier_documents' => false, 'conserver_source' => false, 'inclure_emails' => false,
	);
	$intro = sptg_introspecter(false);
	$demandes = (array) $o['objets'];
	$types = array_values(array_intersect(array_keys($intro), $demandes));
	$avec_mots = in_array('mot', $demandes);
	$avec_docs = in_array('document', $demandes);
	$utilise_rub = in_array('rubrique', $types);

	$tmp = sous_repertoire(_DIR_TMP, 'sptg');
	$stamp = date('Ymd_His');
	$base = $tmp . 'export_' . $stamp . '/';
	$pages = $base . 'user/pages/';
	sptg_mkdir($pages);

	$rapport = array('pages' => 0, 'ignores' => 0, 'details' => array());
	$items = array();    // pages à écrire
	$routes = array();   // routes[type][id] = '/chemin'
	$noms = array();     // slugs utilisés par dossier parent
	$collections = array();
	$rub_chemin = array();

	// ---- 1. Rubriques (arborescence)
	$nb_racine = 0;
	if ($utilise_rub) {
		$info = $intro['rubrique'];
		$lignes = array();
		foreach (sptg_lire_objets($info, $o['statuts']) as $r) {
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
		$parcourir = function ($parent_id, $dir, $route, $depart) use (&$parcourir, &$enfants, &$noms, &$rub_chemin, &$routes, &$items, $info) {
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
				$slug = sptg_slug($r['titre']) ?: 'rubrique-' . $id;
				$slug = sptg_nom_unique($noms, $dir, $slug);
				$sdir = $dir . sprintf('%02d.', $n++) . $slug;
				$sroute = $route . '/' . $slug;
				$rub_chemin[$id] = array('dir' => $sdir . '/', 'route' => $sroute);
				$routes['rubrique'][$id] = $sroute;
				$items[] = array('info' => $info, 'row' => $r, 'dir' => $sdir . '/', 'route' => $sroute);
				$parcourir($id, $sdir . '/', $sroute, 1);
			}
			return count($fr);
		};
		$nb_racine = $parcourir(0, '', '', 2);
	}
	$prochain_racine = 2 + $nb_racine;

	// ---- 2. Autres objets
	foreach ($types as $type) {
		if ($type == 'rubrique') {
			continue;
		}
		$info = $intro[$type];
		$lignes = sptg_lire_objets($info, $o['statuts']);
		usort($lignes, function ($a, $b) use ($info) {
			return sptg_cle_tri(isset($a['titre']) ? $a['titre'] : '') <=> sptg_cle_tri(isset($b['titre']) ? $b['titre'] : '');
		});
		foreach ($lignes as $r) {
			$id = (int) $r[$info['id']];
			$titre = isset($r['titre']) ? $r['titre'] : '';
			$slug = sptg_slug($titre) ?: $type . '-' . $id;

			if ($info['a_rubrique'] and $utilise_rub and (int) $r['id_rubrique'] > 0) {
				if (!isset($rub_chemin[(int) $r['id_rubrique']])) {
					$rapport['ignores']++;
					continue;
				}
				$parent = $rub_chemin[(int) $r['id_rubrique']];
				$pdir = $parent['dir'];
				$proute = $parent['route'];
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
			}
			$slug = sptg_nom_unique($noms, $pdir, $slug);
			$dir = $pdir . $slug . '/';
			$route = $proute . '/' . $slug;
			$routes[$type][$id] = $route;
			$items[] = array('info' => $info, 'row' => $r, 'dir' => $dir, 'route' => $route);
		}
	}

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
	$auteurs_obj = array();
	if (in_array('auteur', $types) and $cles_types) {
		$taxonomies[] = 'auteur';
		$q = sql_allfetsel(
			'l.objet, l.id_objet, a.nom',
			'spip_auteurs_liens AS l INNER JOIN spip_auteurs AS a ON a.id_auteur=l.id_auteur',
			array(sql_in('l.objet', $cles_types), sql_in('a.statut', array('0minirezo', '1comite')))
		) ?: array();
		foreach ($q as $a) {
			$auteurs_obj[$a['objet'] . ':' . $a['id_objet']][] = sptg_titre_propre($a['nom']);
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

	// ---- 4. Écriture des pages
	$compte = array();
	foreach ($items as $it) {
		$info = $it['info'];
		$type = $info['type'];
		$id = (int) $it['row'][$info['id']];
		$r = $it['row'];
		$cle = $type . ':' . $id;
		$dirabs = $pages . $it['dir'];

		// documents
		$remp = array();
		$docs = array();
		$noms_fichiers = array();
		if (!empty($docs_obj[$cle])) {
			foreach ($docs_obj[$cle] as $d) {
				$fichier = $d['fichier'];
				$fiche = array(
					'id' => (int) $d['id_document'],
					'titre' => sptg_titre_propre($d['titre']),
					'descriptif' => sptg_html($d['descriptif'], $routes),
					'extension' => $d['extension'],
					'mode' => $d['mode'],
					'largeur' => (int) $d['largeur'],
					'hauteur' => (int) $d['hauteur'],
					'taille' => (int) $d['taille'],
					'credits' => $d['credits'],
				);
				if ($d['distant'] == 'oui') {
					$fiche['url'] = $fichier;
				} else {
					$fiche['url'] = '/IMG/' . $fichier;
					if ($o['copier_documents'] and is_file(_DIR_IMG . $fichier)) {
						$nom = basename($fichier);
						if (isset($noms_fichiers[$nom])) {
							$nom = $d['id_document'] . '-' . $nom;
						}
						$noms_fichiers[$nom] = 1;
						sptg_mkdir($dirabs);
						copy(_DIR_IMG . $fichier, $dirabs . $nom);
						$fiche['local'] = $nom;
						if (!$o['conserver_img']) {
							$remp['IMG/' . $fichier] = $it['route'] . '/' . $nom;
						}
					}
				}
				$docs[] = array_filter($fiche, function ($v) {
					return $v !== '' and $v !== null and $v !== 0;
				});
				$rapport['documents'] = isset($rapport['documents']) ? $rapport['documents'] + 1 : 1;
			}
		}

		// logo
		$logo = array();
		if ($info['type'] != 'document') {
			$g = glob(_DIR_IMG . sptg_prefixe_logo($type) . $id . '.*');
			if ($g) {
				$lf = basename($g[0]);
				$logo['logo_url'] = '/IMG/' . $lf;
				if ($o['copier_documents']) {
					$ext = pathinfo($lf, PATHINFO_EXTENSION);
					sptg_mkdir($dirabs);
					copy($g[0], $dirabs . 'logo.' . $ext);
					$logo['logo'] = 'logo.' . $ext;
				}
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
					$spip[$nom] = sptg_html($v, $routes, $remp);
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
					$spip[$nom] = in_array($nom, array('surtitre', 'soustitre')) ? sptg_titre_propre($v) : $v;
			}
		}
		$spip = array_filter($spip, function ($v) {
			return $v !== null;
		});

		$publie = true;
		if ($info['statut'] and isset($r[$info['statut']]) and $type != 'auteur') {
			$publie = ($r[$info['statut']] === $info['publie']);
		}
		$titre = isset($r['titre']) ? sptg_titre_propre($r['titre']) : '';
		$header = array('title' => $titre !== '' ? $titre : ($type . ' ' . $id));
		$date = $info['date'] && isset($r[$info['date']]) ? sptg_date($r[$info['date']]) : null;
		if ($date) {
			$header['date'] = $date;
		}
		$header['published'] = $publie;
		$header['process'] = array('markdown' => false, 'twig' => false);
		if (!empty($taxo_obj[$cle])) {
			$header['taxonomy'] = array_map('array_values', array_map('array_unique', $taxo_obj[$cle]));
		}
		if (!empty($auteurs_obj[$cle])) {
			$header['taxonomy']['auteur'] = array_values(array_unique($auteurs_obj[$cle]));
		}
		$header['spip'] = $spip;
		if ($source) {
			$header['spip_source'] = $source;
		}
		if ($docs) {
			$header['documents'] = $docs;
		}
		$header = array_merge($header, $logo);

		$corps = isset($info['champs']['texte']) ? sptg_html(isset($r['texte']) ? $r['texte'] : '', $routes, $remp) : '';
		sptg_ecrire($dirabs . $type . '.md', sptg_page_md($header, $corps));
		$rapport['pages']++;
		$compte[$info['label']] = isset($compte[$info['label']]) ? $compte[$info['label']] + 1 : 1;
	}

	// collections (pages d'index pour les objets hors rubriques)
	foreach ($collections as $c) {
		sptg_ecrire($pages . $c['dir'] . 'collection.md', sptg_page_md(array(
			'title' => $c['info']['label'],
			'process' => array('markdown' => false, 'twig' => false),
			'content' => array('items' => '@self.children', 'order' => array('by' => 'title', 'dir' => 'asc')),
		)));
	}

	// accueil
	$nom_site = isset($GLOBALS['meta']['nom_site']) ? $GLOBALS['meta']['nom_site'] : 'Site SPIP';
	$desc_site = isset($GLOBALS['meta']['descriptif_site']) ? $GLOBALS['meta']['descriptif_site'] : '';
	sptg_ecrire($pages . '01.home/home.md', sptg_page_md(array(
		'title' => $nom_site,
		'process' => array('markdown' => false, 'twig' => false),
	), sptg_html($desc_site, $routes)));

	// ---- 5. Configuration Grav
	$slug_theme = $o['nom_theme'];
	sptg_ecrire($base . 'user/config/site.yaml', sptg_yaml(array(
		'title' => $nom_site,
		'lang' => isset($GLOBALS['meta']['langue_site']) ? $GLOBALS['meta']['langue_site'] : 'fr',
		'metadata' => array('description' => textebrut(typo($desc_site))),
	)));
	sptg_ecrire($base . 'user/config/system.yaml', sptg_yaml(array(
		'pages' => array('theme' => $slug_theme),
		'taxonomies' => array_values(array_unique($taxonomies)),
	)));

	// ---- 6. Thème
	if ($o['generer_theme']) {
		$types_utilises = array();
		foreach ($items as $it) {
			$types_utilises[$it['info']['type']] = $it['info'];
		}
		sptg_generer_theme($base . 'user/themes/' . $slug_theme . '/', $slug_theme, $types_utilises, (bool) $o['inclure_emails']);
	}

	// ---- 7. IMG
	if ($o['conserver_img']) {
		$n = sptg_copier_arbre(rtrim(_DIR_IMG, '/'), $base . 'IMG');
		$n += sptg_copier_arbre(_DIR_VAR . 'cache-vignettes', $base . 'local/cache-vignettes');
		$rapport['details'][] = "IMG : $n fichier(s)";
	}

	foreach ($compte as $lab => $n) {
		$rapport['details'][] = "$lab : $n";
	}

	sptg_ecrire($base . 'LISEZMOI-spip-to-grav.md', sptg_lisezmoi($slug_theme, $o));

	// ---- 8. ZIP
	$nom_zip = 'sptg_export_' . $stamp . '.zip';
	sptg_zipper($base, $tmp . $nom_zip);
	supprimer_repertoire($base);

	$rapport['zip'] = $nom_zip;
	return $rapport;
}

function sptg_lisezmoi($slug, array $o) {
	return "# Export SPIP vers Grav\n\n"
		. "Copier le contenu de cette archive à la racine d'une installation Grav :\n\n"
		. "- `user/pages/` : contenu\n"
		. "- `user/themes/$slug/` : thème généré (templates Twig + blueprints)\n"
		. "- `user/config/site.yaml`, `user/config/system.yaml` : configuration minimale (thème actif, taxonomies)\n"
		. ($o['conserver_img'] ? "- `IMG/` (et `local/cache-vignettes/`) : à placer à la racine web de Grav ; les liens du contenu pointent vers `/IMG/...`\n" : '')
		. "\nLes pages contiennent du HTML déjà rendu par SPIP (`process: markdown: false`).\n"
		. "Les champs propres à chaque objet sont dans l'entête sous la clé `spip:`.\n";
}

function sptg_zipper($dossier, $zip) {
	@unlink($zip);
	if (class_exists('ZipArchive')) {
		$z = new ZipArchive();
		if ($z->open($zip, ZipArchive::CREATE) === true) {
			$racine = rtrim(realpath($dossier), '/') . '/';
			$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
			foreach ($it as $f) {
				if ($f->isFile()) {
					$z->addFile($f->getPathname(), substr($f->getPathname(), strlen($racine)));
				}
			}
			$z->close();
			return true;
		}
	}
	include_spip('inc/pclzip');
	$p = new PclZip($zip);
	return $p->create(rtrim($dossier, '/'), PCLZIP_OPT_REMOVE_PATH, rtrim($dossier, '/')) != 0;
}
