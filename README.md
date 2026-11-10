# SPIP vers Grav (`spip_to_grav`)

Plugin SPIP 4.1+ qui génère, depuis un site SPIP, une archive ZIP contenant :

- `user/pages/` : le contenu (rubriques = dossiers, articles et autres objets = sous-dossiers) ;
- `user/themes/<slug>/` : un **thème Grav généré** (templates Twig + blueprints d'admin) à partir de la structure des tables SPIP ;
- `user/config/site.yaml` et `system.yaml` : thème actif, taxonomies ;
- `IMG/` (optionnel) : le dossier IMG conservé tel quel, liens réécrits en `/IMG/…`.

## Installation

Copier ce dépôt dans `plugins/spip_to_grav/` (ou `plugins-dist/`), activer le plugin, puis **Édition → Export vers Grav** (webmestres).

## Destination

- Par défaut : `/tmp/grav/<export_name>/user/{pages,themes}`.
- Le dossier de destination est modifiable. S'il contient une installation Grav (`user/`, `system/`, `index.php`), l'export s'y fait directement ; si `user/pages` n'est pas vide (ou si le thème du même nom existe), il faut cocher « vider » : `user/pages` est vidé et le thème du même nom écrasé. Les fichiers de configuration d'une installation existante ne sont jamais modifiés : le rapport donne le YAML à fusionner (taxonomies, thème, redirections).
- Le thème s'appelle `<export_name>-theme` par défaut ; on peut n'exporter que les pages.
- Le zip est optionnel et n'est créé que si la vérification n'a relevé aucun écart.

## Sélection de l'export (cases à cocher)

types d'objets (noyau **et** plugins : brèves, lexique… détectés via `lister_tables_objets_sql()`), mots-clés (taxonomies), documents, secteurs, statuts, puis options (Markdown, vérification, URL SPIP, documents dans les pages, IMG conservé, source SPIP, emails, thème, zip).

## Chemins d'abord, conversion ensuite

Toutes les routes Grav (`rubrique/sous-rubrique/mon-article`) sont calculées **avant** le rendu d'un seul texte. Les liens internes `[->art12]`, `[->rub3]`… sont remplacés dans le source par la route cible, puis `propre()` produit `<a href="/rubrique/mon-article">`. Le slug réutilise l'URL SPIP actuelle quand elle est descriptive (URLs propres/arborescentes), sinon le titre.

## Markdown : fidèle à `propre()`

Le HTML de `propre()` (donc exactement ce que montre le site) est converti en Markdown par `inc/sptg_markdown.php`. Principe : on ne convertit que ce qui se traduit **exactement** (titres `###`, paragraphes, `<br />`, listes, citations, code, `**gras**`, `*italique*`, liens, images) ; tout le reste (tableaux, `<div>`, `<span class>`, notes, ancres…) reste en HTML brut, que Markdown laisse passer. Les classes SPIP (`spip`, `spip_in`, `spip_out`…) sont ignorées à la comparaison.

Modèles `<imgN>`, `<docN>`, `<embN>` : le fichier est copié dans le dossier de la page (média Grav) et référencé par son nom (`![titre](photo.jpg)`). L'alignement (`|left`) n'est pas conservé.

## Vérification et tests unitaires

Pour chaque texte : HTML de `propre()` → Markdown → HTML (rendu qui reproduit Parsedown pour le sous-ensemble émis) → comparaison des deux HTML normalisés. En cas d'écart, le texte est conservé en HTML (`process: markdown: false` pour le corps, champ listé dans `spip_html` sinon) et l'écart est consigné dans le rapport avec : attendu, obtenu, Markdown produit, source SPIP. Les liens internes restés en `spip.php?…` sont aussi listés.

Pour améliorer le convertisseur : coller un échantillon du rapport dans `tests/fixtures/mon_cas.html` (fragment HTML tel que produit par `propre()`), puis `php tests/run.php`. Les attentes de Markdown exact sont dans `tests/expected.php`.

## URL et redirections

Les anciennes URL SPIP qui sont des chemins (URLs propres, arborescentes, `.html`) génèrent des `redirects` Grav. Les URL à paramètres (`spip.php?page=article&id_article=12`) ne peuvent pas être redirigées par Grav : elles sont comptées dans le rapport.

## Contenu des pages

Chaque page est `<type>.md` (`article.md`, `breve.md`…). Entête : `title`, `date`, `published`, `taxonomy`, puis tous les champs de la table sous `spip:`. Les blueprints et templates du thème sont générés d'après la structure SQL de chaque type.

## Limites connues

- pas de multilinguisme (`lang` conservé sous `spip:`) ;
- forums, pétitions, sites syndiqués non exportés ; pas de page par mot-clé ;
- notes de bas de page : seules celles du corps sont reprises (en HTML) ;
- la conformité réelle à Parsedown n'a pas pu être testée sans Grav : le rapport est fait pour faire remonter les écarts.

## Mises à jour automatiques depuis l'admin SPIP (dépôt SVP)

Chaque push sur `main` déclenche `.github/workflows/deploy.yml` : zip du plugin + `archives.xml` publiés sur GitHub Pages. La version est calculée toute seule (`x.y.<nombre de commits>`, `x.y` venant de `paquet.xml`) et n'est injectée que dans le zip.

Côté SPIP (une seule fois) : **Configuration → Gestion des plugins → Ajouter des plugins → Dépôts**, ajouter
`https://flavi1.github.io/spip-to-grav/archives.xml`. Les mises à jour apparaissent ensuite dans la gestion des plugins.

Pour changer de version mineure (0.2, 1.0…), modifier `version` dans `paquet.xml`.
Si le plugin a d'abord été installé par `git clone` dans `plugins/`, le supprimer avant d'installer via SVP (même préfixe `sptg`).
