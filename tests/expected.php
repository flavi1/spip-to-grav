<?php
// [html, markdown exact attendu]
return array(
	'gras_italique' => array('<p class="spip">a <strong class="spip">b</strong> <i>c</i></p>', 'a **b** *c*'),
	'intertitre' => array('<h3 class="spip">Titre</h3>', '### Titre'),
	'lien_interne' => array('<p class="spip"><a href="/a/b" class="spip_in">x</a></p>', '[x](/a/b)'),
	'liste' => array('<ul class="spip"><li>a</li><li>b<ul class="spip"><li>c</li></ul></li></ul>', "- a\n- b\n    - c"),
	'echappement' => array('<p class="spip">a_b *c* [d]</p>', 'a\_b \*c\* \[d\]'),
	'debut_ligne' => array('<p class="spip">1. x</p><p class="spip">- y</p>', "1\\. x\n\n\\- y"),
	'tableau_brut' => array('<table class="spip"><tr><td>1</td></tr></table>', '<table class="spip"><tr><td>1</td></tr></table>'),
);
