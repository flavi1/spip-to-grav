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
	'option_conserver_img' => 'Conserver le dossier IMG (copié tel quel à la racine, liens réécrits en /IMG/…)',
	'option_copier_documents' => 'Copier aussi les documents dans le dossier de leur page (média Grav)',
	'option_conserver_source' => 'Conserver le texte source SPIP (raccourcis typographiques) dans le frontmatter',
	'option_emails' => 'Inclure les adresses email des auteurs',
	'label_nom_theme' => 'Nom (slug) du thème Grav',

	'bouton_exporter' => 'Générer l’export',
	'erreur_aucun_objet' => 'Sélectionnez au moins un type d’objet.',
	'erreur_aucun_secteur' => 'Sélectionnez au moins un secteur.',
	'erreur_aucun_statut' => 'Sélectionnez au moins un statut.',
	'erreur_nom_theme' => 'Le nom du thème ne doit contenir que des minuscules, chiffres et tirets.',

	'resultat_ok' => 'Export terminé : @pages@ page(s) générée(s).',
	'resultat_ignores' => '@nb@ objet(s) ignoré(s) car leur rubrique n’est pas exportée.',
	'resultat_telecharger' => 'Télécharger l’archive ZIP',
);
