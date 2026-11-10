{#- logo_url est un chemin depuis la racine du site (/images/…) : le préfixe de l'installation (ex. /nouveau-site) doit y être ajouté -#}
{% set logo = page.header.logo ? page.url ~ '/' ~ page.header.logo : page.header.logo_url %}
{% if not page.header.logo and logo starts with '/' and not (logo starts with '//') %}{% set logo = base_url_relative ~ logo %}{% endif %}
{% if logo %}<img class="spip-logo" src="{{ logo }}" alt="">{% endif %}
