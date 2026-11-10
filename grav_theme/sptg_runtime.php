<?php
/**
 * Runtime SPIP -> Grav du thème : index des identifiants, redirections des anciennes URL,
 * traductions et balises rel="alternate".
 *
 * Ce fichier ne dépend ni de SPIP ni de Grav : tout accès à Grav passe par les trois fonctions
 * fournies au constructeur (voir la classe du thème), ce qui permet de le tester seul
 * (tests/run.php).
 *
 * Index : user/data/spip-id/<type>/<id>.json  {type, id, lang, trad:{lang:id}, dossier, langues?}
 *         user/data/spip-id/_legacy/urls/<sha1>.json  {url, type, id}
 *         user/data/spip-id/_legacy/<type>/<id>.json  entrées déplacées par l'auto-réparation
 *
 * `dossier` : chemin du dossier de la page relatif à user/pages/. Aucune route n'est stockée.
 */

namespace Grav\Theme\Sptg;

class Runtime
{
    /** @var string */
    private $dir;
    /** @var callable(string): ?array  dossier relatif -> ['type','id','lang','url','title'] ou null */
    private $chargerPage;
    /** @var callable(): iterable  parcours complet : ['dossier','type','id','lang'] */
    private $parcourir;
    /** @var callable(array): array  page chargée -> [lang => url absolue] des langues du dossier (i18n Grav) */
    private $languesDossier;
    /** @var bool */
    private $parcouru = false;
    /** @var array<string,array> type:id => entrée trouvée par le parcours */
    private $trouves = array();

    public function __construct($dataDir, callable $chargerPage, callable $parcourir, ?callable $languesDossier = null)
    {
        $this->dir = rtrim($dataDir, '/\\') . '/spip-id';
        $this->chargerPage = $chargerPage;
        $this->parcourir = $parcourir;
        $this->languesDossier = $languesDossier ?: function () {
            return array();
        };
    }

    // ------------------------------------------------------------------ clés legacy

    /** Copie exacte de sptg_cle_legacy() (inc/sptg_urls.php) : tests/run.php vérifie l'identité. */
    public static function cleLegacy($chemin, $requete = '')
    {
        $chemin = rawurldecode((string) $chemin);
        $chemin = preg_replace('~^(\./)+~', '', $chemin);
        $chemin = '/' . trim($chemin, '/');
        $chemin = mb_strtolower($chemin);
        $morceaux = array();
        foreach (explode('&', (string) $requete) as $m) {
            $m = trim(str_replace('&amp;', '', $m));
            if ($m === '') {
                continue;
            }
            $nom = mb_strtolower(rawurldecode(strtok($m, '=')));
            if (preg_match('/^(var_|phpsessid$|fbclid$|utm_|lang$)/', $nom)) {
                continue;
            }
            $morceaux[] = mb_strtolower(rawurldecode($m));
        }
        sort($morceaux);
        return $chemin . ($morceaux ? '?' . implode('&', $morceaux) : '');
    }

    public static function nomLegacy($cle)
    {
        return sha1($cle) . '.json';
    }

    // ------------------------------------------------------------------ fichiers

    private function lire($f)
    {
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    private function ecrire($f, array $d)
    {
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0775, true);
        }
        file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    }

    private function fichierEntree($type, $id)
    {
        return $this->dir . '/' . preg_replace('/[^a-z0-9_]/i', '', $type) . '/' . (int) $id . '.json';
    }

    public function entree($type, $id)
    {
        return $this->lire($this->fichierEntree($type, $id));
    }

    // ------------------------------------------------------------------ résolution

    /**
     * Page d'un objet SPIP, avec auto-réparation de l'index.
     *
     * Si l'entrée (type, id) pointe vers un dossier qui n'existe plus, ou dont la page est un autre
     * (type, id), l'objet est cherché dans toutes les pages ; l'entrée est réécrite avec le dossier
     * trouvé et l'ancienne est déplacée dans _legacy/<type>/<id>.json.
     *
     * @return array|null page ('type','id','lang','url','title','dossier') ou null
     */
    public function resoudre($type, $id)
    {
        $id = (int) $id;
        $entree = $this->entree($type, $id);
        if ($entree and !empty($entree['dossier'])) {
            $p = call_user_func($this->chargerPage, $entree['dossier']);
            if ($p and $p['type'] === $type and (int) $p['id'] === $id) {
                $p['dossier'] = $entree['dossier'];
                return $p;
            }
        }
        $this->parcourirUneFois();
        $cle = $type . ':' . $id;
        if (!isset($this->trouves[$cle])) {
            return null;
        }
        $t = $this->trouves[$cle];
        $this->reparer($type, $id, $entree, $t);
        $p = call_user_func($this->chargerPage, $t['dossier']);
        if ($p) {
            $p['dossier'] = $t['dossier'];
        }
        return $p ?: null;
    }

    private function parcourirUneFois()
    {
        if ($this->parcouru) {
            return;
        }
        $this->parcouru = true;
        foreach (call_user_func($this->parcourir) as $it) {
            if (!empty($it['type']) and !empty($it['id']) and !empty($it['dossier'])) {
                $this->trouves[$it['type'] . ':' . (int) $it['id']] = $it;
            }
        }
    }

    private function reparer($type, $id, $ancienne, array $trouve)
    {
        if ($ancienne) {
            // ancienne entrée conservée dans _legacy
            $this->ecrire($this->dir . '/_legacy/' . preg_replace('/[^a-z0-9_]/i', '', $type) . '/' . $id . '.json', $ancienne);
        }
        $nouvelle = array(
            'type' => $type,
            'id' => $id,
            'lang' => isset($trouve['lang']) && $trouve['lang'] !== '' ? $trouve['lang'] : ($ancienne['lang'] ?? null),
            'trad' => $ancienne['trad'] ?? new \stdClass(),
            'dossier' => $trouve['dossier'],
        );
        if (!empty($ancienne['langues'])) {
            $nouvelle['langues'] = $ancienne['langues'];
        }
        $this->ecrire($this->fichierEntree($type, $id), $nouvelle);
    }

    // ------------------------------------------------------------------ anciennes URL

    /**
     * Page correspondant à une ancienne URL SPIP, ou null.
     *
     * @param string $chemin chemin de la requête, relatif à la racine du site
     * @param string $requete chaîne de requête (sans « ? »)
     */
    public function pourAncienneUrl($chemin, $requete = '')
    {
        $cle = self::cleLegacy($chemin, $requete);
        $d = $this->lire($this->dir . '/_legacy/urls/' . self::nomLegacy($cle));
        if (!$d or empty($d['type']) or empty($d['id'])) {
            return null;
        }
        return $this->resoudre($d['type'], (int) $d['id']);
    }

    // ------------------------------------------------------------------ traductions

    /**
     * Toutes les versions d'un objet : traductions SPIP (id_trad) et langues du dossier (<multi>).
     *
     * @return array liste de ['lang','id','url','title','courant'] triée selon l'ordre de l'index
     */
    public function traductions($type, $id, $langueCourante = '')
    {
        $entree = $this->entree($type, (int) $id);
        $out = array();
        $trad = ($entree and !empty($entree['trad'])) ? (array) $entree['trad'] : array();
        if (!$trad and $entree and !empty($entree['lang'])) {
            $trad = array($entree['lang'] => (int) $id);
        }
        foreach ($trad as $lang => $idt) {
            $p = $this->resoudre($type, (int) $idt);
            if (!$p) {
                continue;
            }
            $out[$lang] = array(
                'lang' => $lang, 'id' => (int) $idt, 'url' => $p['url'], 'title' => $p['title'],
                'courant' => ((int) $idt === (int) $id and ($langueCourante === '' or $langueCourante === $lang)),
            );
        }
        // langues du même dossier (<multi>, i18n Grav) : une URL par langue
        $self = $this->resoudre($type, (int) $id);
        if ($self and $entree and !empty($entree['langues'])) {
            $dossier = call_user_func($this->languesDossier, $self);
            foreach ($entree['langues'] as $lang) {
                if (isset($dossier[$lang])) {
                    $out[$lang] = array(
                        'lang' => $lang, 'id' => (int) $id, 'url' => $dossier[$lang], 'title' => $self['title'],
                        'courant' => ($langueCourante !== '' and $langueCourante === $lang),
                    );
                }
            }
        }
        return array_values($out);
    }

    /**
     * Balises <link rel="alternate" hreflang="…">, y compris la page elle-même, plus x-default
     * (langue par défaut) quand il y a au moins deux versions.
     */
    public function alternates($type, $id, $langueParDefaut = '', $langueCourante = '')
    {
        $versions = $this->traductions($type, $id, $langueCourante);
        if (count($versions) < 2) {
            return '';
        }
        $h = '';
        $defaut = null;
        foreach ($versions as $v) {
            $h .= '<link rel="alternate" hreflang="' . htmlspecialchars($v['lang'], ENT_QUOTES) . '" href="'
                . htmlspecialchars($v['url'], ENT_QUOTES) . "\">\n";
            if ($langueParDefaut !== '' and $v['lang'] === $langueParDefaut) {
                $defaut = $v;
            }
        }
        if ($defaut) {
            $h .= '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($defaut['url'], ENT_QUOTES) . "\">\n";
        }
        return $h;
    }
}
