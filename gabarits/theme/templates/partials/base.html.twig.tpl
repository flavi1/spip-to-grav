{#- Généré par spip_to_grav depuis gabarits/theme/templates/partials/base.html.twig.tpl -#}
<!DOCTYPE html>
<html lang="{{ page.header.spip_lang|default(grav.language.enabled ? grav.language.active : site.lang)|default('@@LANGUE_SITE@@') }}">
<head>
{% block head %}
	<meta charset="utf-8">
	<meta name="generator" content="@@BUILD@@">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>{% if not page.home %}{{ page.title|e }} | {% endif %}{{ site.title|e }}</title>
	{% if site.metadata.description %}<meta name="description" content="{{ site.metadata.description|e('html_attr') }}">{% endif %}
	{{ sptg_alternates(page) }}
	{% do assets.addCss('theme://css/site.css') %}
{% endblock %}
	{{ assets.css()|raw }}
</head>
<body class="tpl-{{ page.template }}">
	<header class="site-header">
		<a class="site-title" href="{{ base_url_relative }}/">{{ site.title|e }}</a>
		{% include 'partials/nav.html.twig' %}
		{% include 'partials/langues.html.twig' %}
	</header>
	<main>
		{% block content %}{{ page.content|raw }}{% endblock %}
	</main>
	<footer class="site-footer">{{ site.title|e }}</footer>
	{{ assets.js()|raw }}
</body>
</html>
