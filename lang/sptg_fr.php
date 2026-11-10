<?php
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

$GLOBALS[$GLOBALS['idx_lang']] = array(
	'titre_menu' => 'Export vers Grav',
	'titre_page' => 'Exporter le site vers Grav',
	'intro' => 'Cochez ce que vous souhaitez exporter. Le résultat est une archive ZIP contenant <code>user/pages</code>, un thème Grav (templates Twig + blueprints) généré d’après la structure de vos tables, et la configuration minimale du site.',

	'legende_objets' => 'Types d’objets à exporter',
	'aide_objets' => 'Les objets ajoutés par des plugins (brèves, lexique…) sont détectés automatiquement d’après la déclaration de leurs tables.',
	'objet_mot' => 'Mots-clés (exportés comme taxonomies Grav)',
	'objet_document' => 'Documents joints (métadonnées et fichiers)',

	'legende_secteurs' => 'Secteurs (rubriques de premier niveau)',
	'aide_secteurs' => 'Un secteur coché inclut toute son arborescence.',

	'legende_statuts' => 'Statuts à inclure',
	'statut_publie' => 'Publié',
	'statut_prop' => 'Proposé à l’évaluation',
	'statut_prepa' => 'En cours de rédaction',
	'statut_refuse' => 'Refusé',
	'statut_poubelle' => 'Poubelle',

	'legende_options' => 'Options',
	'option_generer_theme' => 'Générer le thème Grav (templates + blueprints)',
	'option_conserver_img' => 'Conserver aussi le dossier IMG tel quel à la racine (liens en /IMG/…)',
	'option_copier_documents' => 'Documents et images dans le dossier de leur page (média Grav)',
	'option_markdown' => 'Convertir en Markdown (sinon HTML de propre())',
	'option_verifier' => 'Vérifier chaque conversion (HTML de propre() comparé au rendu du Markdown)',
	'option_urls_spip' => 'Réutiliser les URL SPIP actuelles pour les slugs et générer les redirections',
	'option_zip' => 'Créer un zip à la fin (seulement si aucun écart de vérification)',
	'legende_cible' => 'Destination',
	'label_export_name' => 'Nom de l’export',
	'label_destination' => 'Dossier de destination',
	'aide_destination' => 'Si ce dossier est une installation Grav (user/, system/, index.php), l’export s’y fait directement ; sinon il va dans <code>destination/nom-de-l-export/user/…</code>.',
	'detection_grav' => 'Installation Grav détectée à cet emplacement.',
	'option_vider' => 'Vider user/pages et écraser le thème du même nom si présents',
	'aide_nom_theme' => 'Vide = nom de l’export + « -theme ».',
	'option_conserver_source' => 'Conserver le texte source SPIP (raccourcis typographiques) dans le frontmatter',
	'option_emails' => 'Inclure les adresses email des auteurs',
	'label_nom_theme' => 'Nom (slug) du thème Grav',
	'erreur_slug' => 'Minuscules, chiffres et tirets uniquement.',
	'erreur_destination' => 'Chemin absolu requis (sans « .. »).',
	'erreur_ecriture' => 'Ce dossier n’est pas accessible en écriture.',
	'erreur_non_vide' => 'Contenu existant : @liste@. Cochez « vider » pour le remplacer.',
	'info_installation' => 'Une installation Grav existe à cet emplacement.',

	'bouton_exporter' => 'Générer l’export',
	'erreur_aucun_objet' => 'Sélectionnez au moins un type d’objet.',
	'erreur_aucun_secteur' => 'Sélectionnez au moins un secteur.',
	'erreur_aucun_statut' => 'Sélectionnez au moins un statut.',
	'erreur_nom_theme' => 'Le nom du thème ne doit contenir que des minuscules, chiffres et tirets.',

	'resultat_ok' => 'Export terminé : @pages@ page(s) dans @dossier@.',
	'resultat_verif' => 'Vérification : @ecarts@ écart(s) ; @html@ corps conservé(s) en HTML ; @liens@ lien(s) interne(s) non résolu(s).',
	'resultat_urls' => 'URL : @redir@ redirection(s) générée(s) ; @non@ ancienne(s) URL à paramètres non redirigeable(s).',
	'resultat_echantillons' => 'Échantillons d’écarts (à copier-coller)',
	'resultat_config' => 'Configuration à fusionner dans votre installation',
	'resultat_rapport' => 'Télécharger le rapport complet',
	'resultat_zip_refuse' => 'Zip non créé : @raison@.',
	'resultat_ignores' => '@nb@ objet(s) ignoré(s) car leur rubrique n’est pas exportée.',
	'resultat_telecharger' => 'Télécharger l’archive ZIP',
);
