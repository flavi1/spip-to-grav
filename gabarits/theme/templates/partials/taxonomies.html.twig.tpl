{% if page.taxonomy %}
<footer class="spip-taxonomies">
	{% for nom, valeurs in page.taxonomy %}
		{% if valeurs and nom != 'auteur' %}<p class="taxo taxo-{{ nom }}"><strong>{{ nom|capitalize }}</strong> : {{ valeurs|join(', ') }}</p>{% endif %}
	{% endfor %}
</footer>
{% endif %}
