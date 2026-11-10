<?php
/**
 * Tests de l'aller-retour HTML (propre) -> Markdown -> HTML.
 *
 *   php tests/run.php            # tous les cas
 *   php tests/run.php -v         # + affiche le Markdown produit
 *
 * Chaque fichier tests/fixtures/*.html est un cas : un fragment HTML tel que le produit propre().
 * Pour ajouter un défaut remonté par l'export : coller l'échantillon dans un nouveau fichier.
 * Les attentes précises (Markdown exact) sont dans tests/expected.php.
 * Lance aussi tests/unit.php et tests/integration.php (processus séparés : doublures SPIP).
 */
define('SPTG_TESTS', 1);
require __DIR__ . '/../inc/sptg_markdown.php';

$verbeux = in_array('-v', $argv);
$echecs = 0;
$total = 0;

foreach (glob(__DIR__ . '/fixtures/*.html') as $f) {
	$nom = basename($f, '.html');
	$html = file_get_contents($f);
	$total++;
	$md = sptg_html2md($html);
	$retour = sptg_md2html($md);
	$diff = sptg_comparer_html($html, $retour);
	if ($diff) {
		$echecs++;
		echo "ÉCHEC $nom\n  attendu : {$diff['attendu']}\n  obtenu  : {$diff['obtenu']}\n  markdown :\n" . preg_replace('/^/m', '    ', $md) . "\n";
	} elseif ($verbeux) {
		echo "ok    $nom\n" . preg_replace('/^/m', '    ', $md) . "\n";
	}
}

$attendus = require __DIR__ . '/expected.php';
foreach ($attendus as $nom => $paire) {
	$total++;
	$md = sptg_html2md($paire[0]);
	if (trim($md) !== trim($paire[1])) {
		$echecs++;
		echo "ÉCHEC (markdown exact) $nom\n  attendu :\n" . preg_replace('/^/m', '    ', $paire[1]) . "\n  obtenu :\n" . preg_replace('/^/m', '    ', $md) . "\n";
	}
}

echo "\nmarkdown : " . ($total - $echecs) . "/$total OK\n";

foreach (array('unit', 'integration') as $suite) {
	passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$suite.php"), $code);
	if ($code) {
		$echecs++;
	}
}
exit($echecs ? 1 : 0);
