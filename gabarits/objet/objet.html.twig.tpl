{% extends 'partials/base.html.twig' %}

{% block content %}
<article class="spip-objet spip-@@TYPE@@">
	<header>
		<h1>{{ page.title|e }}</h1>
		{% if page.header.date %}<time datetime="{{ page.date|date('c') }}">{{ page.date|date('d/m/Y') }}</time>{% endif %}
	</header>
	{% set s = page.header.spip ?? {} %}
	{% include 'partials/logo.html.twig' %}
@@CHAMPS_TETE@@
	<div class="spip-texte">{{ page.content|raw }}</div>
@@CHAMPS_QUEUE@@
@@LISTE_CHAMPS@@
	{% include 'partials/documents.html.twig' %}
	{% include 'partials/taxonomies.html.twig' %}
@@ENFANTS@@
</article>
{% endblock %}
