# SPIP vers Grav (`spip_to_grav`)

Plugin SPIP 4.1+ qui génère, depuis un site SPIP, une archive ZIP contenant :

- `user/pages/` : le contenu (rubriques = dossiers, articles et autres objets = sous-dossiers) ;
- `user/themes/<slug>/` : un **thème Grav généré** (templates Twig + blueprints d'admin) à partir de la structure des tables SPIP ;
- `user/config/site.yaml` et `system.yaml` : thème actif, taxonomies ;
- `IMG/` (optionnel) : le dossier IMG conservé tel quel, liens réécrits en `/IMG/…`.

## Installation

Copier ce dépôt dans `plugins/spip_to_grav/` (ou `plugins-dist/`), activer le plugin, puis **Édition → Export vers Grav** (webmestres).

## Première utilisation : parcourir le résultat dans un navigateur

- Dossier de destination : pour parcourir le résultat dans un navigateur, un dossier sous la racine web (ex. `<spip>/grav-test`) et non le `tmp/` de SPIP (protégé par un `.htaccess`, d'où l'intérêt de le changer du défaut), accessible en écriture au serveur web. Avec « installer la dernière version de Grav » et un dossier vide, la destination reçoit Grav complet.
- Grav dans un sous-dossier : les liens et images Markdown sont préfixés par Grav ; le HTML brut (contenus gardés en HTML) est préfixé par le thème à la sortie (`prefixerRacine`). L'administration n'est pas touchée.
- Documents joints : copiés dans le dossier de la page et servis par Grav via la route de la page ; seules les extensions connues de Grav sont servies, les autres (odt, csv, epub…) sont déclarées dans `user/config/media.yaml` (ou données à fusionner dans le rapport si Grav était déjà installé).
- Images : dossier partagé `/images/…` (servi directement) ou `/IMG/…` copié à la racine de Grav (mode IMG).

## Destination

- Le dossier de destination est la **racine de Grav** (celui qui contient `user/`), indépendant du nom d'export ; par défaut `<tmp de SPIP>/grav/<export_name>` (chemin absolu). Le nom d'export ne sert qu'à nommer le thème (`<export_name>-theme`), le rapport et le zip. Une destination doit être une installation Grav, un dossier vide ou inexistant, ou contenir déjà `user/` ; tout autre dossier non vide est refusé.
- Le dossier de destination est modifiable. S'il contient une installation Grav (`user/`, `system/`, `index.php`), l'export s'y fait directement ; si `user/pages` n'est pas vide (ou si le thème du même nom existe), il faut cocher « vider » : `user/pages` est vidé et le thème du même nom écrasé. Les fichiers de configuration d'une installation existante ne sont jamais modifiés : le rapport donne le YAML à fusionner (taxonomies, thème, redirections).
- Le thème s'appelle `<export_name>-theme` par défaut ; on peut n'exporter que les pages.
- Le zip est optionnel et n'est créé que si la vérification n'a relevé aucun écart.

## Sélection de l'export (cases à cocher)

types d'objets (noyau **et** plugins : brèves, lexique… détectés via `lister_tables_objets_sql()`), mots-clés (taxonomies), documents, secteurs, statuts, puis options (Markdown, vérification, URL SPIP, stockage des images, source SPIP, emails, thème, zip).

## Stockage des images (au choix)

- **Dossier partagé** (défaut) : chaque image est copiée une seule fois dans `user/pages/images/` (sous-dossiers de SPIP conservés) et référencée `![alt](/images/jpg/photo.jpg)`. Le slug `images` est réservé à ce dossier.
- **IMG conservé** : le dossier IMG (et `local/cache-vignettes`) est copié tel quel, références `![alt](/IMG/jpg/photo.jpg)`.
- Les autres documents joints (PDF, etc.) vont toujours dans le dossier de leur page (`local` dans l'entête, média Grav).

## Chemins d'abord, conversion ensuite

Toutes les routes Grav (`rubrique/sous-rubrique/mon-article`) sont calculées **avant** le rendu d'un seul texte. Les liens internes `[->art12]`, `[->rub3]`… sont remplacés dans le source par la route cible, puis `propre()` produit `<a href="/rubrique/mon-article">`. Le slug réutilise l'URL SPIP actuelle quand elle est descriptive (URLs propres/arborescentes), sinon le titre.

## Markdown : fidèle à `propre()`

Le HTML de `propre()` (donc exactement ce que montre le site) est converti en Markdown par `inc/sptg_markdown.php`. Principe : on ne convertit que ce qui se traduit **exactement** (titres `###`, paragraphes, `<br />`, listes, citations, code, `**gras**`, `*italique*`, liens, images) ; tout le reste (tableaux, `<div>`, `<span class>`, notes, ancres…) reste en HTML brut, que Markdown laisse passer. Les classes SPIP (`spip`, `spip_in`, `spip_out`…) sont ignorées à la comparaison.

Modèles `<imgN>`, `<docN>`, `<embN>` : images selon le mode de stockage choisi, autres documents en lien vers le fichier du dossier de la page. L'alignement (`|left`) n'est pas conservé.

## Vérification et tests unitaires

Pour chaque texte : HTML de `propre()` → Markdown → HTML (rendu qui reproduit Parsedown pour le sous-ensemble émis) → comparaison des deux HTML normalisés. En cas d'écart, le texte est conservé en HTML (`process: markdown: false` pour le corps, champ listé dans `spip_html` sinon) et l'écart est consigné dans le rapport avec : attendu, obtenu, Markdown produit, source SPIP. Les liens internes restés en `spip.php?…` sont aussi listés.

Pour améliorer le convertisseur : coller un échantillon du rapport dans `tests/fixtures/mon_cas.html` (fragment HTML tel que produit par `propre()`), puis `php tests/run.php` (lance aussi `tests/unit.php` et `tests/integration.php`). Les attentes de Markdown exact sont dans `tests/expected.php`.

## Installer / mettre à jour Grav

Case « installer la dernière version de Grav à cet emplacement » : sans effet si Grav est déjà à jour ; mise à jour (`bin/gpm selfupgrade`) si une version plus ancienne est détectée, sauf changement de version majeure (le guide de migration de getgrav.org s'applique alors). Sur un dossier vide, Grav (grav-admin) est téléchargé et extrait ; un dossier non vide sans Grav est refusé. Nécessite l'accès réseau du serveur SPIP ; en cas d'échec, l'export continue et le rapport l'indique.

## URL, routage et redirections

L'arborescence des dossiers (celle de l'admin Grav, préfixes numériques = ordre d'affichage) est **découplée** des URL publiques : la route publique est posée dans l'entête de chaque page (`routes: default:`) quand elle diffère de la route des dossiers, et l'export règle `pages.redirect_default_route: 301` dans `user/config/system.yaml` pour que l'ancienne route redirige vers la route publique.

Schémas au choix : `spip` (même URL que SPIP quand c'est possible, sinon hiérarchique), `hierarchique`, `hierarchique_type` (`/rubrique/article/mon-article`), `plat_type` (`/article/mon-article`). Deux routes identiques : la seconde reçoit le suffixe `-2` et le cas est listé dans le rapport.

Chaque page porte `spip_id`, `spip_type`, `spip_lang` (et `spip_trad`) dans son entête : l'identité d'une page ne dépend ni de son dossier ni de son préfixe d'ordre.

### Index `user/data/spip-id/`

- `<type>/<id>.json` : un fichier par objet (`type`, `id`, `lang`, `trad` = `{langue: id}`, `dossier`, `langues` pour les `<multi>`).
- Auto-réparation (thème) : si l'entrée pointe vers un dossier absent ou vers une page d'un autre (type, id), le thème retrouve la page par parcours, réécrit l'entrée et déplace l'ancienne dans `_legacy/<type>/<id>.json`.
- `_legacy/urls/<sha1>.json` : anciennes URL SPIP (URL courante, historique `spip_urls`, URL à paramètres comme `spip.php?article12`) → (type, id). Le thème les résout dans `onPageNotFound` et redirige en 301 vers la page actuelle. Sans thème, les chemins (sans paramètres) deviennent des `redirects` de `site.yaml`. Les formes que SPIP accepte toujours, quel que soit son jeu d'URL (`/article12`, `/article12.html`, `spip.php?article12`, `article.php3?id_article=12`, `spip.php?page=article&id_article=12`), sont reconnues par le thème sans entrée d'index. L'historique de `spip_urls` (plugin urls_etendues : `id_parent`, `url`, `segments`, `langue`) est converti en chemins : parent actuel + ancienne `url` + terminaison (`.html`, `/`), avec préfixe de langue si le site utilise `_url_arbo_multilang`. Quand le parent d'une ancienne URL n'est pas retrouvé, le chemin est approximatif et compté dans le rapport.

## Langues

- Objets avec traductions SPIP (`id_trad`) : un dossier par objet, avec sa route exacte ; l'index liste les traductions ; le thème injecte `<link rel="alternate" hreflang>` (+ `x-default`) et fournit un sélecteur de langue (fonctions Twig `sptg_traductions(page)` et `sptg_alternates(page)`).
- Blocs `<multi>` : un fichier `<type>.<lang>.md` par langue, avec la config `languages` de Grav (`include_default_lang: false`, `content_fallback` vers la langue par défaut, comme le repli de `<multi>`). Limite : Grav préfixe toujours les routes des langues non par défaut.
- Les titres et textes sont rendus avec `extraire_multi` dans la langue de la page.

## Gabarits (templates de templates)

Aucun fichier PHP ni Twig du thème n'est écrit à la main dans le code : tous sont générés depuis `gabarits/` (fichiers `.tpl`, marqueurs `@@NOM@@`).

- `gabarits/theme/**` : socle du thème (classe PHP `__slug__.php.tpl`, `sptg_runtime.php.tpl`, templates et partials Twig). Chaque `.tpl` devient le fichier du même nom ; `__slug__` est remplacé par le slug du thème.
- `gabarits/objet/*` : fragments du template Twig propre à chaque type d'objet (champs texte, liste de champs, rubriques → liste des enfants), assemblés d'après la structure SQL.
- Le moteur (`sptg_gabarit()`, `inc/sptg_theme.php`) refuse tout marqueur sans valeur ; les tests vérifient qu'aucun marqueur ne reste, que le PHP généré est valide et que les blocs Twig sont équilibrés. Les blueprints (`.yaml`) restent générés par `sptg_yaml()` ; `grav_theme/` ne contient plus que du CSS et des blueprints fixes.

## Liens internes : routes ou liens stables

Par défaut, les liens `[->art12]` deviennent des routes absolues (avec préfixe de langue pour les langues non par défaut en mode `<multi>`). L'option « liens internes stables » écrit `[texte](idx:article/12#ancre)` : le thème résout le lien à l'affichage (`onOutputGenerated`, via l'index `spip-id`), donc déplacer ou renommer une page dans Grav ne casse rien. Dans les pages `<multi>`, le lien suit la langue active ; cible introuvable : `href="#"`.

Vérifié dans le code de Grav : le traitement des liens de Markdown laisse intact un `href` à schéma inconnu (`idx:article/12#ancre`). La résolution se fait à la sortie et non sur le contenu, car le cache de contenu de Grav figerait les URL. Limite : les plugins qui lisent le contenu hors rendu de page (flux RSS, recherche) voient `idx:` non résolu.

**Thème réutilisé.** Le thème est toujours généré une première fois ; ensuite on peut ne régénérer que le contenu. Le plugin ne modifie alors jamais le thème existant, mais le contrôle : il lit `const API` dans son `sptg_runtime.php` et cherche `sptgRuntime` dans son PHP principal. Helpers compatibles : liens stables et résolveur d'anciennes URL actifs. Absents, anciens (thème issu d'une vieille version du plugin) ou retirés : routes absolues à la place, redirections par `site.yaml`, et un avertissement dans le rapport qui dit quoi faire (régénérer le thème).

## Auteurs : comptes de rédacteurs dans admin2

Option « Créer un compte de rédacteur Grav (admin2) pour chaque auteur » (cochée par défaut). Pour chaque administrateur ou rédacteur SPIP ayant une adresse email valide et unique, l'export écrit `user/accounts/<identifiant>.yaml` : nom, intitulé, email, `state: enabled`, et les droits `site.login`, `api.access`, `api.pages` (lecture/écriture), `api.media` (lecture/écriture). Les **administrateurs SPIP deviennent super-administrateurs de Grav** (`admin.super`, `api.super`, `admin.login`) ; les administrateurs restreints à des rubriques, qui n'ont pas la main sur tout le site, reçoivent les droits de rédacteur, qui n'incluent aucun droit de configuration, de plugins, d'utilisateurs ni de super-administration.

Les comptes n'ont **pas de mot de passe**. À la première connexion, l'auteur clique sur « Mot de passe oublié » (admin2 ou page de connexion du site) et saisit l'adresse enregistrée dans SPIP : le plugin `login` retrouve le compte par cette adresse, pose un jeton valable 24 h et envoie le lien de création du mot de passe (vérifié dans le code de `login` : `Controller::taskForgot` / `taskReset`, et dans celui de `api` : `POST /auth/forgot-password`, `/auth/reset-password`). La réponse est toujours neutre : personne ne peut découvrir quelles adresses ont un compte. L'email n'est écrit que dans le compte (`user/accounts/` n'est pas servi), jamais dans les pages, même avec « inclure les emails ».

Règles : les comptes existants ne sont jamais modifiés (ni l'administrateur créé à l'installation de Grav) ; l'archive zip ne contient que les comptes créés par l'export ; un auteur sans email valide, ou dont l'email est déjà pris, est listé dans le rapport (la réinitialisation retrouve le compte par l'email, deux comptes ne peuvent donc pas le partager) ; identifiants dérivés du login SPIP (`[a-z0-9_-]{3,16}`, suffixés si doublon). Visiteurs, « nouveaux » et auteurs à la poubelle n'ont pas de compte.

Prérequis côté Grav (rappelés dans le rapport) : plugins `login`, `email`, `api`, `admin2`, un expéditeur configuré (`plugins.email.from` et moteur d'envoi), idéalement `plugins.login.site_host`. Sans envoi d'email, le lien ne part pas : l'administrateur peut alors définir le mot de passe lui-même.

## Contenu des pages

Chaque page est `<type>.md` (`article.md`, `breve.md`…). Entête : `title`, `date`, `published`, `taxonomy`, puis tous les champs de la table sous `spip:`. Les blueprints et templates du thème sont générés d'après la structure SQL de chaque type.

## Limites connues

- notes de bas de page : seules celles du corps sont reprises, en HTML ;
- non testé contre une vraie installation de Grav ni de SPIP : seules des doublures (`tests/stubs.php`) ont servi ;
- forums, pétitions, sites syndiqués non exportés ; pas de page par mot-clé ;
- la conformité réelle à Parsedown n'a pas pu être testée sans Grav : le rapport est fait pour faire remonter les écarts.

## Mises à jour automatiques depuis l'admin SPIP (dépôt SVP)

Chaque push sur `main` déclenche `.github/workflows/deploy.yml` : zip du plugin + `archives.xml` publiés sur GitHub Pages. La version est calculée toute seule (`x.y.<nombre de commits>`, `x.y` venant de `paquet.xml`) et n'est injectée que dans le zip.

Côté SPIP (une seule fois) : **Configuration → Gestion des plugins → Ajouter des plugins → Dépôts**, ajouter
`https://flavi1.github.io/spip-to-grav/archives.xml`. Les mises à jour apparaissent ensuite dans la gestion des plugins.

Pour changer de version mineure (0.2, 1.0…), modifier `version` dans `paquet.xml`.
Si le plugin a d'abord été installé par `git clone` dans `plugins/`, le supprimer avant d'installer via SVP (même préfixe `sptg`).
