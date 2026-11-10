	{% if s.@@NOM@@ %}<div class="spip-champ spip-@@NOM@@">{% if '@@NOM@@' in (page.header.spip_html ?? []) %}{{ s.@@NOM@@|raw }}{% else %}{{ s.@@NOM@@|markdown|raw }}{% endif %}</div>{% endif %}
