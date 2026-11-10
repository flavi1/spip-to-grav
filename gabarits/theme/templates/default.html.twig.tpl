{% extends 'partials/base.html.twig' %}
{% block content %}
<article>
	<h1>{{ page.title|e }}</h1>
	{{ page.content|raw }}
	{% include 'partials/enfants.html.twig' %}
</article>
{% endblock %}
