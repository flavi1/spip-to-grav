<?php
/**
 * Mini sérialiseur YAML (sans dépendance).
 * Les chaînes sont émises en JSON, qui est un sous-ensemble valide de YAML (guillemets doubles).
 */
if (!defined('_ECRIRE_INC_VERSION')) {
	return;
}

function sptg_yaml_scalaire($v) {
	if (is_null($v)) {
		return 'null';
	}
	if (is_bool($v)) {
		return $v ? 'true' : 'false';
	}
	if (is_int($v)) {
		return (string) $v;
	}
	if (is_float($v)) {
		return is_finite($v) ? rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') : 'null';
	}
	return json_encode((string) $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

function sptg_yaml_cle($k) {
	$k = (string) $k;
	return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $k) ? $k : sptg_yaml_scalaire($k);
}

function sptg_yaml_est_liste(array $a) {
	return $a === array() or array_keys($a) === range(0, count($a) - 1);
}

/** @param mixed $v tableau (assoc ou liste) ou scalaire */
function sptg_yaml($v, $niveau = 0) {
	$pad = str_repeat('  ', $niveau);
	if (!is_array($v)) {
		return $pad . sptg_yaml_scalaire($v) . "\n";
	}
	if ($v === array()) {
		return $pad . "[]\n";
	}
	$out = '';
	if (sptg_yaml_est_liste($v)) {
		foreach ($v as $item) {
			if (is_array($item) and $item !== array()) {
				$out .= $pad . "-\n" . sptg_yaml($item, $niveau + 1);
			} else {
				$out .= $pad . '- ' . (is_array($item) ? '[]' : sptg_yaml_scalaire($item)) . "\n";
			}
		}
		return $out;
	}
	foreach ($v as $k => $item) {
		$cle = sptg_yaml_cle($k);
		if (is_array($item)) {
			if ($item === array()) {
				$out .= $pad . $cle . ": " . (sptg_yaml_est_liste($item) ? '[]' : '{}') . "\n";
			} else {
				$out .= $pad . $cle . ":\n" . sptg_yaml($item, $niveau + 1);
			}
		} else {
			$out .= $pad . $cle . ': ' . sptg_yaml_scalaire($item) . "\n";
		}
	}
	return $out;
}

/** Fichier page Grav : frontmatter + corps */
function sptg_page_md(array $header, $corps = '') {
	// Jamais de Twig dans le contenu importé : Grav 2.x l'ouvre par défaut sur tout le site (security.twig_content),
	// et le texte SPIP peut contenir des « {{ » ou « {% » littéraux qui provoquent une erreur 500.
	$process = isset($header['process']) && is_array($header['process']) ? $header['process'] : array();
	$process['twig'] = false;
	if (!isset($process['markdown'])) {
		$process['markdown'] = true;
	}
	$header['process'] = $process;
	return "---\n" . sptg_yaml($header) . "---\n\n" . $corps . "\n";
}
