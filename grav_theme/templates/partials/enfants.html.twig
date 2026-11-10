{% set rubriques = page.children.visible %}
{% set contenus = page.children.nonVisible.published.order('date', 'desc') %}
{% if rubriques|length %}
<section class="spip-sous-rubriques">
	<ul>{% for c in rubriques %}<li><a href="{{ c.url }}">{{ c.title|e }}</a></li>{% endfor %}</ul>
</section>
{% endif %}
{% if contenus|length %}
<section class="spip-contenus">
	<ul>
	{% for c in contenus %}
		<li><a href="{{ c.url }}">{{ c.title|e }}</a>{% if c.header.date %} <time>{{ c.date|date('d/m/Y') }}</time>{% endif %}
		{% if c.header.spip.descriptif %}<div>{% if 'descriptif' in (c.header.spip_html ?? []) %}{{ c.header.spip.descriptif|raw }}{% else %}{{ c.header.spip.descriptif|markdown|raw }}{% endif %}</div>{% endif %}</li>
	{% endfor %}
	</ul>
</section>
{% endif %}
