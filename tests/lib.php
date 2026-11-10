<?php
/** Mini-framework d'assertions des suites de tests. */
$GLOBALS['T'] = array('ok' => 0, 'ko' => 0);

function t_ok($cond, $nom, $detail = '') {
	if ($cond) {
		$GLOBALS['T']['ok']++;
		return true;
	}
	$GLOBALS['T']['ko']++;
	echo "ÉCHEC $nom" . ($detail !== '' ? "\n  $detail" : '') . "\n";
	return false;
}
function t_eq($obtenu, $attendu, $nom) {
	return t_ok($obtenu === $attendu, $nom, 'attendu : ' . var_export($attendu, true) . "\n  obtenu  : " . var_export($obtenu, true));
}
function t_fin($suite) {
	$t = $GLOBALS['T'];
	echo sprintf("%s : %d ok, %d échec(s)\n", $suite, $t['ok'], $t['ko']);
	exit($t['ko'] ? 1 : 0);
}
function t_tmp($nom = 'x') {
	$d = sys_get_temp_dir() . '/sptg-' . $nom . '-' . getmypid() . '-' . mt_rand();
	mkdir($d, 0775, true);
	register_shutdown_function(function () use ($d) {
		exec('rm -rf ' . escapeshellarg($d));
	});
	return $d;
}
function t_json($f) {
	return is_file($f) ? json_decode(file_get_contents($f), true) : null;
}
