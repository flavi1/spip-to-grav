{% if '@@NOM@@' in (page.header.extras_html ?? []) %}{{ e.@@NOM@@|raw }}{% else %}{{ e.@@NOM@@|markdown|raw }}{% endif %}
