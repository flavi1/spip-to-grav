{# Sélecteur de langue : traductions SPIP (id_trad) et langues du dossier (<multi>). Rien si une seule version. #}
{% set sptg_versions = sptg_traductions(page) %}
{% if sptg_versions|length > 1 %}
<nav class="langues" aria-label="Langues">
	{% for v in sptg_versions %}
		{% if v.courant %}<span lang="{{ v.lang }}" aria-current="true">{{ v.lang|upper }}</span>
		{% else %}<a lang="{{ v.lang }}" hreflang="{{ v.lang }}" href="{{ v.url }}" title="{{ v.title|e('html_attr') }}">{{ v.lang|upper }}</a>{% endif %}
	{% endfor %}
</nav>
{% endif %}
