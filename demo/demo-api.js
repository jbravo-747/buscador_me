/*
 * Demo estática: sustituye las dos rutas REST del plugin (buscar y sugerir) por
 * una versión que corre en el navegador sobre datos.json. Replica la lógica de
 * me-buscador.php; en WordPress este archivo no existe.
 */
(function () {
	'use strict';

	var fetchReal = window.fetch.bind(window);
	var datos = null;
	var carga = null;

	function cargar() {
		if (!carga) {
			carga = fetchReal('datos.json').then(function (r) { return r.json(); }).then(function (d) { datos = d; });
		}
		return carga;
	}

	function esc(text) {
		return String(text).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function plano(text) {
		return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
	}

	function lista(params, clave) {
		return (params.get(clave) || '').split(',').filter(Boolean);
	}

	function termino(tax, slug) {
		return (datos.terminos[tax] || []).filter(function (t) { return t.slug === slug; })[0];
	}

	function tituloTotal(n) {
		if (!n) { return 'Sin resultados'; }
		return n.toLocaleString('es-MX') + (n === 1 ? ' resultado' : ' resultados');
	}

	function tarjeta(c) {
		var formato = (c.tax[datos.tax.formato] || [])[0];
		var tema = (c.tax[datos.tax.tema] || [])[0];
		var insignia = formato ? termino(datos.tax.formato, formato).nombre : datos.etiquetas[c.tipo];
		var meta = [c.fechaTx];
		if (tema) { meta.push(termino(datos.tax.tema, tema).nombre); }

		return '<a class="meb-card" href="' + esc(c.url) + '" target="_blank" rel="noopener">' +
			'<span class="meb-card-img' + (c.img ? '' : ' is-empty') + '">' +
			(c.img ? '<img src="' + esc(c.img) + '" alt="" loading="lazy" decoding="async">' : '') +
			'<span class="meb-badge">' + esc(insignia) + '</span></span>' +
			'<span class="meb-card-title">' + esc(c.titulo) + '</span>' +
			'<span class="meb-card-meta">' + esc(meta.join(' · ')) + '</span>' +
			(c.texto ? '<span class="meb-card-text">' + esc(c.texto) + '</span>' : '') +
			'</a>';
	}

	function filtrar(params) {
		var tipo = params.get('meb_tipo');
		var tipos = datos.tipos.indexOf(tipo) > -1 ? [tipo] : datos.tipos;
		var palabras = plano(params.get('meb_q') || '').split(/\s+/).filter(Boolean);
		var desde = params.get('meb_desde') || '';
		var hasta = params.get('meb_hasta') || '';
		var filtros = ['tema', 'eje', 'formato', 'programa'].map(function (k) {
			return { tax: datos.tax[k], slugs: lista(params, 'meb_' + k) };
		}).filter(function (f) { return f.tax && f.slugs.length; });

		var out = datos.contenidos.filter(function (c) {
			if (tipos.indexOf(c.tipo) < 0) { return false; }
			var texto = plano(c.titulo + ' ' + c.texto);
			if (palabras.some(function (p) { return texto.indexOf(p) < 0; })) { return false; }
			if ((desde && c.fecha < desde) || (hasta && c.fecha > hasta)) { return false; }
			return filtros.every(function (f) {
				var tiene = c.tax[f.tax] || [];
				return f.slugs.some(function (s) { return tiene.indexOf(s) > -1; });
			});
		});

		var asc = params.get('meb_orden') === 'antiguo';
		out.sort(function (a, b) {
			return asc ? a.orden.localeCompare(b.orden) : b.orden.localeCompare(a.orden);
		});
		return out;
	}

	function buscar(params) {
		var todos = filtrar(params);
		var total = todos.length;
		if (params.get('solo_total')) {
			return { total: total, titulo: tituloTotal(total) };
		}
		var pag = Math.max(1, parseInt(params.get('meb_pag'), 10) || 1);
		var cards = todos.slice((pag - 1) * datos.porPagina, pag * datos.porPagina).map(tarjeta).join('');
		if (!cards && pag === 1) {
			cards = '<p class="meb-empty">No encontramos contenido con esa combinación. Prueba con otra palabra o quita algún filtro.</p>';
		}
		return {
			total: total,
			titulo: tituloTotal(total),
			cards: cards,
			hay_mas: pag * datos.porPagina < total,
			pag: pag
		};
	}

	function sugerir(params) {
		var q = (params.get('q') || '').trim();
		var qp = plano(q);

		function temas(tax, campo, cuantos, filtro) {
			return (datos.terminos[tax] || [])
				.filter(function (t) { return t.n > 0 && (!filtro || plano(t.nombre).indexOf(filtro) > -1); })
				.sort(function (a, b) { return b.n - a.n; })
				.slice(0, cuantos)
				.map(function (t) {
					return {
						clase: 'tema',
						campo: campo,
						slug: t.slug,
						titulo: t.nombre,
						sub: 'Tema · ' + t.n + (t.n === 1 ? ' contenido' : ' contenidos')
					};
				});
		}

		if (!q) {
			return { titulo: 'Temas más consultados', items: temas(datos.tax.tema, 'meb_tema', 7) };
		}

		function titulos(tipos, cuantos, sub) {
			return datos.contenidos
				.filter(function (c) { return tipos.indexOf(c.tipo) > -1 && plano(c.titulo).indexOf(qp) > -1; })
				.sort(function (a, b) { return b.orden.localeCompare(a.orden); })
				.slice(0, cuantos)
				.map(function (c) {
					return {
						clase: sub ? 'persona' : 'contenido',
						titulo: c.titulo,
						sub: sub || datos.etiquetas[c.tipo] + ' · ' + c.fechaTx,
						url: c.url
					};
				});
		}

		return {
			titulo: '',
			items: temas(datos.tax.tema, 'meb_tema', 3, qp)
				.concat(temas(datos.tax.eje, 'meb_eje', 2, qp))
				.concat(titulos(['equipo'], 2, 'Equipo'))
				.concat(titulos(datos.tipos, 5))
		};
	}

	window.fetch = function (url, opciones) {
		var ruta = String(url).match(/me-buscador\/v1\/(buscar|sugerir)/);
		if (!ruta) { return fetchReal(url, opciones); }
		return cargar().then(function () {
			var params = new URL(String(url), window.location.href).searchParams;
			var cuerpo = ruta[1] === 'buscar' ? buscar(params) : sugerir(params);
			return new Response(JSON.stringify(cuerpo), { status: 200, headers: { 'Content-Type': 'application/json' } });
		});
	};

	/*
	 * La página es estática, así que una dirección compartida (?meb_tema=…) llega
	 * con el formulario vacío. Aquí se rellena antes de que arranque el buscador
	 * y, cuando ya está listo, se lanza la búsqueda.
	 */
	var params = new URLSearchParams(window.location.search);
	var form = document.querySelector('.meb-form');
	var hayConsulta = false;

	if (form) {
		params.forEach(function (valor, clave) {
			if (clave.indexOf('meb_') !== 0 || clave === 'meb_pag' || !valor) { return; }
			hayConsulta = true;
			var simples = form.querySelectorAll('[name="' + clave + '"]');
			var multiples = form.querySelectorAll('[name="' + clave + '[]"]');
			Array.prototype.forEach.call(simples, function (campo) {
				if (campo.type === 'radio') { campo.checked = campo.value === valor; } else { campo.value = valor; }
			});
			var slugs = valor.split(',');
			Array.prototype.forEach.call(multiples, function (campo) {
				campo.checked = slugs.indexOf(campo.value) > -1;
			});
		});
	}

	window.demoListo = function () {
		if (hayConsulta && form) {
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		}
	};
})();
