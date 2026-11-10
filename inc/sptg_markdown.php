<?php
/**
 * Conversion HTML (sortie de propre()) -> Markdown, rendu Markdown -> HTML
 * et normalisation, pour vérifier l'aller-retour.
 *
 * Principes (prédictibilité) :
 *  - on ne convertit en Markdown que ce qui se traduit EXACTEMENT ;
 *  - tout le reste est conservé en HTML brut (balise complète), que Markdown laisse passer ;
 *  - le sous-ensemble Markdown émis est volontairement réduit : titres ATX, paragraphes, listes
 *    simples, citations, blocs de code clôturés, ligne horizontale, **gras**, *italique*, `code`,
 *    liens [t](u "titre"), images ![alt](src "titre"). Pas de tableaux (Parsedown « extra » n'est
 *    pas actif par défaut dans Grav) : les tableaux restent en HTML.
 *  - le rendu Markdown -> HTML (sptg_md2html) reproduit le comportement de Parsedown pour ce
 *    sous-ensemble uniquement.
 *
 * Aucune dépendance à SPIP : ce fichier est testable seul (tests/run.php).
 */
if (!defined('_ECRIRE_INC_VERSION') and !defined('SPTG_TESTS')) {
	return;
}

// ------------------------------------------------------------------ DOM

function sptg_dom_racine($html) {
	$doc = new DOMDocument('1.0', 'UTF-8');
	$prev = libxml_use_internal_errors(true);
	$doc->loadHTML(
		'<?xml encoding="utf-8"?><div id="sptg-root">' . $html . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
	);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	return $doc->getElementById('sptg-root') ?: $doc->documentElement;
}

function sptg_tags_blocs() {
	static $t = null;
	if ($t === null) {
		$t = array_flip(array(
			'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'hr',
			'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'dl', 'dt', 'dd', 'figure',
			'figcaption', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'main', 'form',
			'fieldset', 'address', 'details', 'summary', 'iframe', 'object', 'video', 'audio', 'script', 'style',
		));
	}
	return $t;
}

function sptg_tags_inline_md() {
	static $t = null;
	if ($t === null) {
		$t = array_flip(array(
			'a', 'abbr', 'b', 'bdo', 'br', 'cite', 'code', 'del', 'dfn', 'em', 'i', 'img', 'ins', 'kbd',
			'mark', 'q', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 's', 'font', 'wbr',
		));
	}
	return $t;
}

function sptg_tags_vides() {
	return array('br', 'hr', 'img', 'input', 'wbr', 'meta', 'link', 'col', 'area', 'base', 'embed', 'source', 'track');
}

function sptg_est_bloc($tag) {
	return isset(sptg_tags_blocs()[strtolower($tag)]);
}

// ------------------------------------------------------------ attributs

/** Classes SPIP sans incidence sur le rendu une fois en Markdown (spip, spip_in, autobr…) */
function sptg_classe_ignorable($c) {
	return (bool) preg_match('/^(spip.*|autobr)$/', $c);
}

/**
 * Attributs « résiduels » : ceux qui ne sont ni ignorables ni exprimables en Markdown.
 *
 * @return array nom => valeur
 */
function sptg_attrs_residuels(DOMElement $e, array $permis = array()) {
	$tag = strtolower($e->nodeName);
	$res = array();
	foreach ($e->attributes as $a) {
		$n = strtolower($a->nodeName);
		$v = $a->nodeValue;
		if (in_array($n, $permis)) {
			continue;
		}
		if ($n == 'class') {
			$reste = array_values(array_filter(preg_split('/\s+/', trim($v)), function ($c) {
				return $c !== '' and !sptg_classe_ignorable($c);
			}));
			if ($reste) {
				$res['class'] = implode(' ', $reste);
			}
			continue;
		}
		if ($n == 'rel' and $tag == 'a' and in_array(trim($v), array('external', 'nofollow', 'external nofollow', 'nofollow external'))) {
			continue;
		}
		if ($n == 'dir' and trim($v) == 'ltr') {
			continue;
		}
		$res[$n] = $v;
	}
	return $res;
}

function sptg_attrs_serialiser(DOMElement $e) {
	$s = '';
	foreach ($e->attributes as $a) {
		$s .= ' ' . $a->nodeName . '="' . htmlspecialchars($a->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
	}
	return $s;
}

// ------------------------------------------------------------ HTML -> MD

function sptg_html2md($html) {
	$racine = sptg_dom_racine($html);
	$md = trim(sptg_h2m_blocs($racine));
	return $md === '' ? '' : $md . "\n";
}

/** Échappement du texte courant pour Markdown */
function sptg_h2m_texte($s) {
	$s = preg_replace('/[ \t\r\n\f]+/', ' ', $s);
	$s = strtr($s, array(
		'\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_', '[' => '\\[', ']' => '\\]',
		'&' => '&amp;', '<' => '&lt;', '>' => '&gt;', "\xC2\xA0" => '&nbsp;',
	));
	return $s;
}

/** Évite qu'un début de ligne soit pris pour un marqueur de bloc */
function sptg_h2m_debut_lignes($s) {
	$lignes = explode("\n", $s);
	foreach ($lignes as &$l) {
		if (preg_match('/^(\d+)([.)])(\s|$)/', $l)) {
			$l = preg_replace('/^(\d+)([.)])/', '$1\\\\$2', $l);
		} elseif (preg_match('/^[#\-+~=]/', $l)) {
			$l = '\\' . $l;
		}
	}
	return implode("\n", $lignes);
}

function sptg_h2m_raw(DOMNode $n) {
	$h = trim($n->ownerDocument->saveHTML($n));
	if (stripos($h, '<pre') === false) {
		$h = preg_replace("/\n[ \t]*(?:\n[ \t]*)+/", "\n", $h);
	}
	return $h;
}

/** Convertit une suite de noeuds « en ligne » ; null si un noeud bloc s'y trouve */
function sptg_h2m_inline_liste($noeuds) {
	$out = '';
	foreach ($noeuds as $n) {
		$r = sptg_h2m_inline_noeud($n);
		if ($r === null) {
			return null;
		}
		$out .= $r;
	}
	$out = preg_replace("/\n[ \t]+/", "\n", $out);
	return $out;
}

function sptg_h2m_enfants(DOMNode $n) {
	$l = array();
	foreach ($n->childNodes as $c) {
		$l[] = $c;
	}
	return $l;
}

function sptg_h2m_inline_noeud(DOMNode $n) {
	switch ($n->nodeType) {
		case XML_TEXT_NODE:
		case XML_CDATA_SECTION_NODE:
			return sptg_h2m_texte($n->nodeValue);
		case XML_ELEMENT_NODE:
			break;
		default:
			return ''; // commentaires, instructions
	}
	/** @var DOMElement $n */
	$tag = strtolower($n->nodeName);
	if (sptg_est_bloc($tag)) {
		return null;
	}

	if ($tag == 'br') {
		return sptg_attrs_residuels($n) ? sptg_h2m_inline_raw($n) : "<br />\n";
	}

	if (in_array($tag, array('strong', 'b', 'em', 'i')) and !sptg_attrs_residuels($n)) {
		$in = sptg_h2m_inline_liste(sptg_h2m_enfants($n));
		if ($in === null) {
			return null;
		}
		$d = in_array($tag, array('strong', 'b')) ? '**' : '*';
		preg_match('/^(\s*)(.*?)(\s*)$/s', $in, $m);
		if ($m[2] === '') {
			return $m[1] . $m[3];
		}
		if ($m[2][0] == '*' or preg_match('/(?<!\\\\)\*$/', $m[2])) {
			// emphase imbriquée collée au délimiteur (***) : ambigu en Markdown, on garde la balise
			$bal = in_array($tag, array('strong', 'b')) ? 'strong' : 'em';
			return $m[1] . '<' . $bal . '>' . $m[2] . '</' . $bal . '>' . $m[3];
		}
		return $m[1] . $d . $m[2] . $d . $m[3];
	}

	if ($tag == 'code' and !sptg_attrs_residuels($n)) {
		$enf = sptg_h2m_enfants($n);
		$ok = true;
		foreach ($enf as $c) {
			if ($c->nodeType != XML_TEXT_NODE and $c->nodeType != XML_CDATA_SECTION_NODE) {
				$ok = false;
			}
		}
		if ($ok) {
			$t = preg_replace('/[ \t\r\n\f]+/', ' ', $n->textContent);
			$t = trim($t);
			if ($t === '') {
				return '';
			}
			$max = 0;
			if (preg_match_all('/`+/', $t, $mm)) {
				foreach ($mm[0] as $run) {
					$max = max($max, strlen($run));
				}
			}
			$f = str_repeat('`', $max + 1);
			return $f . ($max ? ' ' . $t . ' ' : $t) . $f;
		}
	}

	if ($tag == 'a' and !sptg_attrs_residuels($n, array('href', 'title'))) {
		$href = $n->getAttribute('href');
		$title = $n->hasAttribute('title') ? $n->getAttribute('title') : null;
		if ($n->hasAttribute('href') and $href !== ''
			and !preg_match('/[\s()<>"\'\\\\]/', $href)
			and ($title === null or !preg_match('/[&<>"\'\\\\\n]/', $title))
		) {
			$in = sptg_h2m_inline_liste(sptg_h2m_enfants($n));
			if ($in === null) {
				return null;
			}
			if (trim($in) !== '') {
				return '[' . $in . '](' . $href . ($title !== null && $title !== '' ? ' "' . $title . '"' : '') . ')';
			}
		}
	}

	if ($tag == 'img' and !sptg_attrs_residuels($n, array('src', 'alt', 'title'))) {
		$src = $n->getAttribute('src');
		$alt = $n->getAttribute('alt');
		$title = $n->hasAttribute('title') ? $n->getAttribute('title') : null;
		if ($src !== '' and !preg_match('/[\s()<>"\'\\\\]/', $src)
			and !preg_match('/[&<>"\'\\\\\[\]\n*_`]/', $alt)
			and ($title === null or !preg_match('/[&<>"\'\\\\\n]/', $title))
		) {
			return '![' . $alt . '](' . $src . ($title !== null && $title !== '' ? ' "' . $title . '"' : '') . ')';
		}
	}

	return sptg_h2m_inline_raw($n);
}

/** Élément en ligne conservé en HTML (attributs intacts), contenu converti */
function sptg_h2m_inline_raw(DOMElement $e) {
	$tag = strtolower($e->nodeName);
	$open = '<' . $tag . sptg_attrs_serialiser($e);
	if (in_array($tag, sptg_tags_vides())) {
		return $open . ' />';
	}
	$in = sptg_h2m_inline_liste(sptg_h2m_enfants($e));
	if ($in === null) {
		return null;
	}
	return $open . '>' . $in . '</' . $tag . '>';
}

/** Suite de blocs enfants d'un noeud */
function sptg_h2m_blocs(DOMNode $parent) {
	$blocs = array();
	$run = array();
	$vider = function () use (&$blocs, &$run) {
		if (!$run) {
			return;
		}
		$texte = sptg_h2m_inline_liste($run);
		if ($texte === null) {
			$texte = '';
			foreach ($run as $n) {
				$texte .= sptg_h2m_raw($n);
			}
		} else {
			$texte = sptg_h2m_debut_lignes(trim($texte));
		}
		if (trim($texte) !== '') {
			$blocs[] = $texte;
		}
		$run = array();
	};
	foreach (sptg_h2m_enfants($parent) as $n) {
		if ($n->nodeType == XML_ELEMENT_NODE and sptg_est_bloc($n->nodeName)) {
			$vider();
			$b = sptg_h2m_bloc($n);
			if ($b !== '') {
				$blocs[] = $b;
			}
		} elseif ($n->nodeType == XML_TEXT_NODE or $n->nodeType == XML_CDATA_SECTION_NODE or $n->nodeType == XML_ELEMENT_NODE) {
			$run[] = $n;
		}
	}
	$vider();
	return implode("\n\n", $blocs);
}

function sptg_h2m_bloc(DOMElement $e) {
	$tag = strtolower($e->nodeName);
	$md = null;
	if ($tag == 'p') {
		if (!sptg_attrs_residuels($e)) {
			$in = sptg_h2m_inline_liste(sptg_h2m_enfants($e));
			if ($in !== null) {
				$in = trim($in);
				return $in === '' ? '' : sptg_h2m_debut_lignes($in);
			}
		}
	} elseif (preg_match('/^h([1-6])$/', $tag, $m)) {
		if (!sptg_attrs_residuels($e)) {
			$in = sptg_h2m_inline_liste(sptg_h2m_enfants($e));
			if ($in !== null and strpos($in, "\n") === false and trim($in) !== '') {
				$md = str_repeat('#', (int) $m[1]) . ' ' . preg_replace('/\s+#+$/', '', trim($in));
			}
		}
	} elseif ($tag == 'ul' or $tag == 'ol') {
		$md = sptg_h2m_liste($e);
	} elseif ($tag == 'blockquote') {
		if (!sptg_attrs_residuels($e)) {
			$inner = sptg_h2m_blocs($e);
			if (trim($inner) !== '') {
				$md = preg_replace('/^/m', '> ', $inner);
				$md = preg_replace('/^> $/m', '>', $md);
			}
		}
	} elseif ($tag == 'pre') {
		$md = sptg_h2m_pre($e);
	} elseif ($tag == 'hr') {
		if (!sptg_attrs_residuels($e)) {
			$md = '---';
		}
	}
	return $md !== null ? $md : sptg_h2m_raw($e);
}

function sptg_h2m_liste(DOMElement $e) {
	if (sptg_attrs_residuels($e)) {
		return null;
	}
	$ordonnee = strtolower($e->nodeName) == 'ol';
	$i = 1;
	$items = array();
	foreach (sptg_h2m_enfants($e) as $li) {
		if ($li->nodeType == XML_TEXT_NODE and trim($li->nodeValue) === '') {
			continue;
		}
		if ($li->nodeType == XML_COMMENT_NODE) {
			continue;
		}
		if ($li->nodeType != XML_ELEMENT_NODE or strtolower($li->nodeName) != 'li' or sptg_attrs_residuels($li)) {
			return null;
		}
		$texte = array();
		$sous = array();
		foreach (sptg_h2m_enfants($li) as $c) {
			if ($c->nodeType == XML_ELEMENT_NODE and in_array(strtolower($c->nodeName), array('ul', 'ol'))) {
				$sous[] = $c;
			} elseif ($sous) {
				if ($c->nodeType == XML_TEXT_NODE and trim($c->nodeValue) === '') {
					continue;
				}
				return null; // contenu après une sous-liste : trop ambigu
			} else {
				$texte[] = $c;
			}
		}
		$t = sptg_h2m_inline_liste($texte);
		if ($t === null) {
			return null;
		}
		$t = sptg_h2m_debut_lignes(trim($t));
		if ($t === '' and !$sous) {
			return null;
		}
		$item = ($ordonnee ? ($i++) . '. ' : '- ') . str_replace("\n", "\n    ", $t);
		foreach ($sous as $s) {
			$m = sptg_h2m_liste($s);
			if ($m === null) {
				return null;
			}
			$item .= "\n" . preg_replace('/^/m', '    ', $m);
		}
		$items[] = $item;
	}
	return $items ? implode("\n", $items) : null;
}

function sptg_h2m_pre(DOMElement $e) {
	if (sptg_attrs_residuels($e)) {
		return null;
	}
	$code = null;
	$texte = null;
	$lang = '';
	$enf = sptg_h2m_enfants($e);
	if (count($enf) == 1 and $enf[0]->nodeType == XML_ELEMENT_NODE and strtolower($enf[0]->nodeName) == 'code') {
		$code = $enf[0];
		$res = sptg_attrs_residuels($code);
		if ($res) {
			if (count($res) == 1 and isset($res['class']) and preg_match('/^language-([\w+-]+)$/', $res['class'], $m)) {
				$lang = $m[1];
			} else {
				return null;
			}
		}
		foreach (sptg_h2m_enfants($code) as $c) {
			if ($c->nodeType != XML_TEXT_NODE and $c->nodeType != XML_CDATA_SECTION_NODE) {
				return null;
			}
		}
		$texte = $code->textContent;
	} else {
		foreach ($enf as $c) {
			if ($c->nodeType != XML_TEXT_NODE and $c->nodeType != XML_CDATA_SECTION_NODE) {
				return null;
			}
		}
		$texte = $e->textContent;
	}
	$texte = preg_replace("/\n$/", '', str_replace("\r\n", "\n", $texte));
	$max = 2;
	if (preg_match_all('/`{3,}/', $texte, $mm)) {
		foreach ($mm[0] as $run) {
			$max = max($max, strlen($run));
		}
	}
	$f = str_repeat('`', $max + 1);
	return $f . $lang . "\n" . $texte . "\n" . $f;
}

// ------------------------------------------------------------ MD -> HTML

function sptg_md2html($md) {
	$lignes = explode("\n", str_replace(array("\r\n", "\r"), "\n", $md));
	return sptg_m2h_blocs($lignes);
}

function sptg_m2h_e($s) {
	return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sptg_m2h_attr($s) {
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sptg_m2h_debut_bloc($l) {
	if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $l)
		or preg_match('/^ {0,3}#{1,6}[ \t]/', $l)
		or preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $l)
		or preg_match('/^ {0,3}>/', $l)
		or preg_match('/^ {0,3}([-+*]|\d{1,9}[.)])[ \t]+\S/', $l)
	) {
		return true;
	}
	return sptg_m2h_html_bloc_tag($l) !== null;
}

/** Nom de la balise si la ligne ouvre un bloc HTML (balise non « en ligne ») */
function sptg_m2h_html_bloc_tag($l) {
	if (preg_match('/^<!--/', $l)) {
		return '!--';
	}
	if (preg_match('/^<([A-Za-z][A-Za-z0-9-]*)(?:\s[^>]*)?>/', $l, $m) and !isset(sptg_tags_inline_md()[strtolower($m[1])])) {
		return strtolower($m[1]);
	}
	return null;
}

function sptg_m2h_blocs(array $L) {
	$out = array();
	$n = count($L);
	$i = 0;
	while ($i < $n) {
		$l = $L[$i];
		if (trim($l) === '') {
			$i++;
			continue;
		}
		// bloc de code clôturé
		if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*([^`\s]*)[^`]*$/', $l, $m)) {
			$f = $m[1];
			$lang = $m[2];
			$buf = array();
			$i++;
			while ($i < $n and !preg_match('/^ {0,3}' . preg_quote($f[0], '/') . '{' . strlen($f) . ',}[ \t]*$/', $L[$i])) {
				$buf[] = $L[$i++];
			}
			$i++;
			$out[] = '<pre><code' . ($lang !== '' ? ' class="language-' . sptg_m2h_attr($lang) . '"' : '') . '>'
				. sptg_m2h_e(implode("\n", $buf)) . "\n</code></pre>";
			continue;
		}
		// titre ATX
		if (preg_match('/^ {0,3}(#{1,6})[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$/', $l, $m)) {
			$h = strlen($m[1]);
			$out[] = "<h$h>" . sptg_m2h_inline($m[2]) . "</h$h>";
			$i++;
			continue;
		}
		// ligne horizontale
		if (preg_match('/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $l)) {
			$out[] = '<hr />';
			$i++;
			continue;
		}
		// citation
		if (preg_match('/^ {0,3}>/', $l)) {
			$buf = array();
			while ($i < $n and preg_match('/^ {0,3}>[ ]?(.*)$/', $L[$i], $m)) {
				$buf[] = $m[1];
				$i++;
			}
			$out[] = "<blockquote>\n" . sptg_m2h_blocs($buf) . "\n</blockquote>";
			continue;
		}
		// liste
		if (preg_match('/^( {0,3})([-+*]|\d{1,9}[.)])[ \t]+\S/', $l, $m)) {
			$ordonnee = ctype_digit($m[2][0]);
			$items = array();
			$cur = null;
			while ($i < $n) {
				$x = $L[$i];
				if (preg_match('/^ {0,1}([-+*]|\d{1,9}[.)])[ \t]+(.*)$/', $x, $mm) and (ctype_digit($mm[1][0]) == $ordonnee)) {
					if ($cur !== null) {
						$items[] = $cur;
					}
					$cur = array($mm[2]);
					$i++;
				} elseif ($cur !== null and preg_match('/^ {2,}\S/', $x)) {
					$cur[] = preg_replace('/^ {1,4}/', '', $x);
					$i++;
				} else {
					break;
				}
			}
			if ($cur !== null) {
				$items[] = $cur;
			}
			$html = '';
			foreach ($items as $it) {
				$texte = array();
				$sous = array();
				foreach ($it as $k => $x) {
					if ($k > 0 and !$sous and preg_match('/^([-+*]|\d{1,9}[.)])[ \t]+\S/', $x)) {
						$sous[] = $x;
					} elseif ($sous) {
						$sous[] = $x;
					} else {
						$texte[] = $x;
					}
				}
				$html .= '<li>' . sptg_m2h_inline(implode("\n", $texte)) . ($sous ? sptg_m2h_blocs($sous) : '') . "</li>\n";
			}
			$t = $ordonnee ? 'ol' : 'ul';
			$out[] = "<$t>\n$html</$t>";
			continue;
		}
		// bloc HTML
		if (($tag = sptg_m2h_html_bloc_tag($l)) !== null) {
			$buf = array();
			if ($tag == '!--') {
				while ($i < $n) {
					$buf[] = $L[$i];
					if (strpos($L[$i], '-->') !== false) {
						$i++;
						break;
					}
					$i++;
				}
			} elseif (in_array($tag, sptg_tags_vides())) {
				$buf[] = $L[$i++];
			} else {
				$prof = 0;
				while ($i < $n) {
					$x = $L[$i];
					$buf[] = $x;
					$prof += preg_match_all('/<' . preg_quote($tag, '/') . '(?:\s[^>]*)?(?<!\/)>/i', $x);
					$prof -= preg_match_all('/<\/' . preg_quote($tag, '/') . '\s*>/i', $x);
					$i++;
					if ($prof <= 0) {
						break;
					}
				}
			}
			$out[] = implode("\n", $buf);
			continue;
		}
		// paragraphe
		$buf = array();
		while ($i < $n and trim($L[$i]) !== '' and (!$buf or !sptg_m2h_debut_bloc($L[$i]))) {
			$buf[] = trim($L[$i]);
			$i++;
		}
		$out[] = '<p>' . sptg_m2h_inline(implode("\n", $buf)) . '</p>';
	}
	return implode("\n", $out);
}

function sptg_m2h_inline($s) {
	$out = '';
	$n = strlen($s);
	$i = 0;
	while ($i < $n) {
		$c = $s[$i];
		if ($c == '\\' and $i + 1 < $n and strpos('\\`*_{}[]()#+-.!|<>~=', $s[$i + 1]) !== false) {
			$out .= sptg_m2h_e($s[$i + 1]);
			$i += 2;
			continue;
		}
		if ($c == '`') {
			preg_match('/^`+/', substr($s, $i), $m);
			$len = strlen($m[0]);
			if (preg_match('/(?<!`)`{' . $len . '}(?!`)/', $s, $mm, PREG_OFFSET_CAPTURE, $i + $len)) {
				$code = substr($s, $i + $len, $mm[0][1] - $i - $len);
				$out .= '<code>' . sptg_m2h_e(trim(preg_replace('/[ ]*\n/', ' ', $code))) . '</code>';
				$i = $mm[0][1] + $len;
				continue;
			}
			$out .= $m[0];
			$i += $len;
			continue;
		}
		if ($c == '!' and $i + 1 < $n and $s[$i + 1] == '[') {
			$r = sptg_m2h_lien($s, $i + 1);
			if ($r) {
				$out .= '<img src="' . sptg_m2h_attr($r['url']) . '" alt="' . sptg_m2h_attr($r['texte']) . '"'
					. ($r['titre'] !== null ? ' title="' . sptg_m2h_attr($r['titre']) . '"' : '') . ' />';
				$i = $r['fin'];
				continue;
			}
		}
		if ($c == '[') {
			$r = sptg_m2h_lien($s, $i);
			if ($r) {
				$out .= '<a href="' . sptg_m2h_attr($r['url']) . '"'
					. ($r['titre'] !== null ? ' title="' . sptg_m2h_attr($r['titre']) . '"' : '') . '>'
					. sptg_m2h_inline($r['texte']) . '</a>';
				$i = $r['fin'];
				continue;
			}
		}
		if ($c == '*') {
			if ($i + 1 < $n and $s[$i + 1] == '*') {
				$j = sptg_m2h_fermant($s, $i + 2, true);
				if ($j !== null and $j > $i + 2) {
					$out .= '<strong>' . sptg_m2h_inline(substr($s, $i + 2, $j - $i - 2)) . '</strong>';
					$i = $j + 2;
					continue;
				}
			} else {
				$j = sptg_m2h_fermant($s, $i + 1, false);
				if ($j !== null and $j > $i + 1) {
					$out .= '<em>' . sptg_m2h_inline(substr($s, $i + 1, $j - $i - 1)) . '</em>';
					$i = $j + 1;
					continue;
				}
			}
			$out .= '*';
			$i++;
			continue;
		}
		if ($c == '<') {
			if (preg_match('/^<\/?[A-Za-z][A-Za-z0-9-]*(?:\s+[^<>]*?)?\s*\/?>/', substr($s, $i), $m) or preg_match('/^<!--.*?-->/s', substr($s, $i), $m)) {
				$out .= $m[0];
				$i += strlen($m[0]);
				continue;
			}
			if (preg_match('/^<(https?:\/\/[^\s<>]+)>/', substr($s, $i), $m)) {
				$out .= '<a href="' . sptg_m2h_attr($m[1]) . '">' . sptg_m2h_e($m[1]) . '</a>';
				$i += strlen($m[0]);
				continue;
			}
			$out .= '&lt;';
			$i++;
			continue;
		}
		if ($c == '&') {
			if (preg_match('/^&(#\d+|#x[0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);/', substr($s, $i), $m)) {
				$out .= $m[0];
				$i += strlen($m[0]);
				continue;
			}
			$out .= '&amp;';
			$i++;
			continue;
		}
		if ($c == '>') {
			$out .= '&gt;';
			$i++;
			continue;
		}
		$out .= $c;
		$i++;
	}
	return $out;
}

/** Position du délimiteur fermant ** (strong=true) ou * (em) */
function sptg_m2h_fermant($s, $debut, $strong) {
	return sptg_m2h_fermant_gen($s, $debut, $strong);
}

function sptg_m2h_fermant_gen($s, $i, $strong) {
	$n = strlen($s);
	while ($i < $n) {
		$c = $s[$i];
		if ($c == '\\') {
			$i += 2;
			continue;
		}
		if ($c == '`') {
			preg_match('/^`+/', substr($s, $i), $m);
			$p = strpos($s, $m[0], $i + strlen($m[0]));
			$i = $p === false ? $i + strlen($m[0]) : $p + strlen($m[0]);
			continue;
		}
		if ($c == '*') {
			$double = ($i + 1 < $n and $s[$i + 1] == '*');
			if ($strong and $double) {
				return $i;
			}
			if (!$strong and !$double) {
				return $i;
			}
			if (!$strong and $double) { // **strong** à l'intérieur d'un em
				$j = sptg_m2h_fermant_gen($s, $i + 2, true);
				if ($j === null) {
					return null;
				}
				$i = $j + 2;
				continue;
			}
			if ($strong and !$double) { // *em* à l'intérieur d'un strong
				$j = sptg_m2h_fermant_gen($s, $i + 1, false);
				if ($j === null) {
					return null;
				}
				$i = $j + 1;
				continue;
			}
		}
		$i++;
	}
	return null;
}

/** Analyse [texte](url "titre") à partir du crochet ouvrant en $i */
function sptg_m2h_lien($s, $i) {
	$n = strlen($s);
	$prof = 0;
	$j = $i;
	for (; $j < $n; $j++) {
		if ($s[$j] == '\\') {
			$j++;
			continue;
		}
		if ($s[$j] == '[') {
			$prof++;
		} elseif ($s[$j] == ']') {
			$prof--;
			if ($prof == 0) {
				break;
			}
		}
	}
	if ($j >= $n or $prof != 0) {
		return null;
	}
	$texte = substr($s, $i + 1, $j - $i - 1);
	if (!preg_match('/^\(\s*([^()\s]*)(?:\s+"([^"]*)")?\s*\)/', substr($s, $j + 1), $m)) {
		return null;
	}
	return array(
		'texte' => $texte,
		'url' => $m[1],
		'titre' => isset($m[2]) ? $m[2] : null,
		'fin' => $j + 1 + strlen($m[0]),
	);
}

// ---------------------------------------------------------- normalisation

/** Forme canonique d'un fragment HTML : sert à comparer deux rendus */
function sptg_normaliser_html($html) {
	$racine = sptg_dom_racine($html);
	$pieces = sptg_norm_paragrapher(sptg_norm_pieces($racine, false));
	return trim(sptg_norm_assembler($pieces, true));
}

function sptg_norm_attrs(DOMElement $e) {
	$tag = strtolower($e->nodeName);
	$a = array();
	foreach ($e->attributes as $at) {
		$nom = strtolower($at->nodeName);
		$v = $at->nodeValue;
		if ($nom == 'class') {
			$reste = array_values(array_filter(preg_split('/\s+/', trim($v)), function ($c) {
				return $c !== '' and !sptg_classe_ignorable($c);
			}));
			sort($reste);
			if ($reste) {
				$a['class'] = implode(' ', $reste);
			}
			continue;
		}
		if ($nom == 'rel' and $tag == 'a' and in_array(trim($v), array('external', 'nofollow', 'external nofollow', 'nofollow external'))) {
			continue;
		}
		if ($nom == 'dir' and trim($v) == 'ltr') {
			continue;
		}
		$a[$nom] = $v;
	}
	ksort($a);
	$s = '';
	foreach ($a as $k => $v) {
		$s .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
	}
	return $s;
}

/** @return array de [type, chaine] ; type = text | bloc | br | inline */
function sptg_norm_pieces(DOMNode $parent, $pre) {
	$p = array();
	foreach ($parent->childNodes as $n) {
		if ($n->nodeType == XML_TEXT_NODE or $n->nodeType == XML_CDATA_SECTION_NODE) {
			$t = $n->nodeValue;
			if (!$pre) {
				$t = preg_replace('/[ \t\r\n\f]+/', ' ', $t);
			}
			if ($t !== '') {
				$p[] = array('text', htmlspecialchars($t, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'));
			}
			continue;
		}
		if ($n->nodeType != XML_ELEMENT_NODE) {
			continue;
		}
		$tag = strtolower($n->nodeName);
		$tag = $tag == 'i' ? 'em' : ($tag == 'b' ? 'strong' : $tag);
		$ouv = '<' . $tag . sptg_norm_attrs($n) . '>';
		if ($tag == 'br') {
			$p[] = array('br', '<br>');
		} elseif (in_array($tag, sptg_tags_vides())) {
			$p[] = array(sptg_est_bloc($tag) ? 'bloc' : 'inline', $ouv);
		} else {
			$enf = sptg_norm_pieces($n, $pre or $tag == 'pre');
			$est_bloc = sptg_est_bloc($tag);
			if ($tag == 'pre') {
				$in = '';
				foreach ($enf as $e) {
					$in .= $e[1];
				}
				$in = preg_replace("/\n$/", '', $in);
			} else {
				if ($tag == 'blockquote') {
					$enf = sptg_norm_paragrapher($enf);
				}
				$in = sptg_norm_assembler($enf, $est_bloc);
			}
			if ($tag == 'p' and trim($in) === '') {
				continue; // paragraphe vide : sans incidence
			}
			if ($est_bloc) {
				$p[] = array('bloc', $ouv . $in . '</' . $tag . '>');
			} else {
				// espaces de bord d'un élément en ligne : équivalents à des espaces extérieurs
				if (trim($in) === '' and !preg_match('/^<(a|span)\b/', $ouv)) {
					$p[] = array('text', $in === '' ? '' : ' ');
					continue;
				}
				if (preg_match('/^(\s*)(.*?)(\s*)$/s', $in, $mm)) {
					if ($mm[1] !== '') {
						$p[] = array('text', ' ');
					}
					$p[] = array('inline', $ouv . $mm[2] . '</' . $tag . '>');
					if ($mm[3] !== '') {
						$p[] = array('text', ' ');
					}
				}
			}
		}
	}
	return $p;
}

/** Texte et éléments en ligne sans bloc parent : Markdown les met dans un <p> */
function sptg_norm_paragrapher(array $pieces) {
	$out = array();
	$run = array();
	$vider = function () use (&$out, &$run) {
		if ($run) {
			$in = trim(sptg_norm_assembler($run, true));
			if ($in !== '') {
				$out[] = array('bloc', '<p>' . $in . '</p>');
			}
			$run = array();
		}
	};
	foreach ($pieces as $p) {
		if ($p[0] == 'bloc') {
			$vider();
			$out[] = $p;
		} else {
			$run[] = $p;
		}
	}
	$vider();
	return $out;
}

/** Assemble en supprimant les espaces insignifiants (bords de blocs, autour de br et de blocs) */
function sptg_norm_assembler(array $pieces, $est_bloc) {
	$n = count($pieces);
	$out = '';
	foreach ($pieces as $k => $p) {
		$s = $p[1];
		if ($p[0] == 'text') {
			$prev = $k > 0 ? $pieces[$k - 1][0] : ($est_bloc ? 'bord' : null);
			$next = $k < $n - 1 ? $pieces[$k + 1][0] : ($est_bloc ? 'bord' : null);
			if (in_array($prev, array('bloc', 'br', 'bord'), true)) {
				$s = ltrim($s, ' ');
			}
			if (in_array($next, array('bloc', 'br', 'bord'), true)) {
				$s = rtrim($s, ' ');
			}
			if ($s !== '' and $k > 0 and $pieces[$k - 1][0] == 'text' and substr($out, -1) === ' ') {
				$s = ltrim($s, ' ');
			}
			if ($s === '') {
				continue;
			}
		}
		$out .= $s;
	}
	return $out;
}

/**
 * Compare deux fragments HTML après normalisation.
 *
 * @return array|null null si identiques, sinon extraits autour de la première différence
 */
function sptg_comparer_html($a, $b) {
	$na = sptg_normaliser_html($a);
	$nb = sptg_normaliser_html($b);
	if ($na === $nb) {
		return null;
	}
	$i = 0;
	$l = min(strlen($na), strlen($nb));
	while ($i < $l and $na[$i] === $nb[$i]) {
		$i++;
	}
	while ($i > 0 and (ord($na[$i] ?? 'a') & 0xC0) == 0x80) {
		$i--;
	}
	$debut = max(0, $i - 100);
	$cut = function ($s) use ($debut) {
		return function_exists('mb_strcut') ? mb_strcut($s, $debut, 260, 'UTF-8') : substr($s, $debut, 260);
	};
	return array('attendu' => $cut($na), 'obtenu' => $cut($nb), 'position' => $i);
}
