# SPIP vers Grav (`spip_to_grav`)

Plugin SPIP 4.1+ qui génère, depuis un site SPIP, une archive ZIP contenant :

- `user/pages/` : le contenu (rubriques = dossiers, articles et autres objets = sous-dossiers) ;
- `user/themes/<slug>/` : un **thème Grav généré** (templates Twig + blueprints d'admin) à partir de la structure des tables SPIP ;
- `user/config/site.yaml` et `system.yaml` : thème actif, taxonomies ;
- `IMG/` (optionnel) : le dossier IMG conservé tel quel, liens réécrits en `/IMG/…`.

## Installation

Copier ce dépôt dans `plugins/spip_to_grav/` (ou `plugins-dist/`), activer le plugin, puis **Édition → Export vers Grav** (webmestres).

## Sélection de l'export (cases à cocher)

- types d'objets (noyau **et** objets de plugins, détectés via `lister_tables_objets_sql()` : brèves, lexique…) ;
- mots-clés (→ taxonomies Grav, une par groupe de mots) et documents joints ;
- secteurs (rubriques de premier niveau, arborescence incluse) ;
- statuts (publié, proposé, en rédaction, refusé, poubelle) ;
- options : thème, conserver `IMG`, copier les documents dans le dossier de leur page, conserver le texte source SPIP, inclure les emails des auteurs.

## Introspection des objets « exotiques »

Pour chaque table d'objet, le plugin lit sa déclaration (`trouver_table`) : clé primaire, champs et types SQL, colonne de titre, date, statut, présence de `id_rubrique`. Il en déduit :

- le placement : dans le dossier de la rubrique si `id_rubrique` existe, sinon dans une collection racine (`NN.<objets>/`) ;
- le frontmatter : `title`, `date`, `published`, `taxonomy`, et tous les autres champs sous `spip:` ;
- le template Twig `templates/<type>.html.twig` et le blueprint `blueprints/<type>.yaml` (text, textarea, number, toggle, select…).

Chaque page est un fichier `<type>.md` (ex. `article.md`, `breve.md`), donc `template` = type d'objet.

## Contenu

Les textes sont rendus par `propre()` : le corps des pages est du HTML (`process: markdown: false`). Les liens internes `[->art12]`, `[->rub3]`… sont convertis en routes Grav. Option : conserver aussi le texte source SPIP sous `spip_source`.

## Limites connues (v0.1)

- pas de multilinguisme (champ `lang` conservé sous `spip:`, pas de fichiers `page.fr.md`) ;
- forums, pétitions, sites syndiqués non exportés ;
- pas de page par mot-clé (taxonomies uniquement) ;
- l'export est synchrone : sur de très gros sites, augmenter `max_execution_time`.

Licence : GPL v3.
