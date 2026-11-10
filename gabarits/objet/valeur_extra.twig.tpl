{% if e.@@NOM@@ is iterable %}{{ e.@@NOM@@|join(', ')|e }}{% elseif e.@@NOM@@ is same as(true) %}Oui{% elseif e.@@NOM@@ is same as(false) %}Non{% else %}{{ e.@@NOM@@|e }}{% endif %}
