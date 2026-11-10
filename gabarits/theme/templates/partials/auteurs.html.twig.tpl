{% set auteurs = page.header.auteurs ?? [] %}
{% set externes = page.header.auteurs_sans_compte ?? [] %}
{% if auteurs or externes %}
<p class="spip-auteurs"><strong>{{ (auteurs|length + externes|length) > 1 ? 'Auteurs' : 'Auteur' }}</strong> :
	{% for a in auteurs %}{% set c = sptg_compte(a) %}{{ (c ? c.fullname : a)|e }}{% if not loop.last or externes %}, {% endif %}{% endfor %}{% for n in externes %}{{ n|e }}{% if not loop.last %}, {% endif %}{% endfor %}</p>
{% endif %}
