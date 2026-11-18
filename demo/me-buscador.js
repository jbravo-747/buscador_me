/* ME Buscador — comportamiento. Sin dependencias. */
(function () {
	'use strict';

	var CFG = window.MEB || {};
	var T = CFG.i18n || {};

	var ICONOS = {
		tema: '<path d="M9 4 7 20M17 4l-2 16M4 9h16M3 15h16"/>',
		contenido: '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/>',
		persona: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
		prev: '<path d="m15 5-7 7 7 7"/>',
		next: '<path d="m9 5 7 7-7 7"/>',
		x: '<path d="M6 6l12 12M18 6 6 18"/>'
	};

	function icono(nombre, size) {
		return '<svg class="meb-ico" viewBox="0 0 24 24" width="' + (size || 20) + '" height="' + (size || 20) +
			'" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
			ICONOS[nombre] + '</svg>';
	}

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function esc(text) {
		return String(text).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function pad(n) { return n < 10 ? '0' + n : String(n); }
	function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function parse(s) { var p = s.split('-'); return new Date(+p[0], p[1] - 1, +p[2]); }

	function fechaCorta(s, conAnio) {
		var d = parse(s);
		return d.getDate() + ' ' + CFG.mesesCortos[d.getMonth()].toLowerCase().replace('.', '') + (conAnio ? ' ' + d.getFullYear() : '');
	}

	// Quita acentos carácter por carácter para conservar los índices.
	function plano(text) {
		return text.split('').map(function (ch) {
			return ch.normalize('NFD').charAt(0).toLowerCase();
		}).join('');
	}

	function resaltar(titulo, q) {
		var i = q ? plano(titulo).indexOf(plano(q)) : -1;
		if (i < 0) { return esc(titulo); }
		return esc(titulo.slice(0, i)) + '<b>' + esc(titulo.slice(i, i + q.length)) + '</b>' + esc(titulo.slice(i + q.length));
	}

	function restUrl(ruta, params) {
		var base = CFG.rest + ruta;
		var qs = Object.keys(params).map(function (k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
		}).join('&');
		return base + (qs ? (base.indexOf('?') > -1 ? '&' : '?') + qs : '');
	}

	function getJSON(url, signal) {
		return fetch(url, { credentials: 'same-origin', signal: signal }).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		});
	}

	function init(root) {
		var form = $('.meb-form', root);
		var bar = $('.meb-bar', root);
		var input = $('.meb-input', root);
		var clearBtn = $('.meb-clear', root);
		var segs = $$('[data-seg]', root);
		var panels = $$('[data-panel]', root);
		var sugList = $('.meb-sug', root);
		var sugTitulo = $('[data-sug-titulo]', root);
		var cal = $('.meb-cal', root);
		var inDesde = form.elements.meb_desde;
		var inHasta = form.elements.meb_hasta;
		var modal = $('[data-modal]', root);
		var results = $('.meb-results', root);
		var grid = $('[data-grid]', root);
		var listado = $('[data-listado]', root);
		var destacado = $('[data-destacado]', root);
		var more = $('[data-more]', root);
		var compact = $('[data-compact]', root);
		var ajax = root.getAttribute('data-ajax') === '1' && !!results;

		var st = {
			abierto: null,
			desde: inDesde.value,
			hasta: inHasta.value,
			periodo: '',
			base: null,
			sug: [],
			sugOn: -1,
			pag: 1,
			qAplicada: input.value.trim(),
			activos: []
		};

		var hoy = new Date();
		var ancla = st.desde ? parse(st.desde) : new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1);
		st.base = new Date(ancla.getFullYear(), ancla.getMonth(), 1);

		/* ---------- Segmentos y paneles ---------- */

		function abrir(nombre) {
			st.abierto = nombre;
			bar.classList.toggle('is-open', !!nombre);
			segs.forEach(function (seg) {
				var on = seg.getAttribute('data-seg') === nombre;
				seg.classList.toggle('is-active', on);
				if (seg.hasAttribute('aria-expanded')) { seg.setAttribute('aria-expanded', on ? 'true' : 'false'); }
			});
			input.setAttribute('aria-expanded', nombre === 'q' ? 'true' : 'false');
			panels.forEach(function (panel) {
				panel.hidden = panel.getAttribute('data-panel') !== nombre;
			});
			if (nombre === 'q') { cargarSugerencias(); }
			if (nombre === 'fecha') { pintarCalendario(); }
		}

		segs.forEach(function (seg) {
			var nombre = seg.getAttribute('data-seg');
			if (nombre === 'q') {
				seg.addEventListener('click', function (e) {
					if (e.target.closest('.meb-clear')) { return; }
					input.focus();
				});
				return;
			}
			seg.addEventListener('click', function () {
				abrir(st.abierto === nombre ? null : nombre);
			});
		});

		input.addEventListener('focus', function () {
			if (st.abierto !== 'q') { abrir('q'); }
		});

		document.addEventListener('pointerdown', function (e) {
			if (st.abierto && !e.target.closest('.meb-barwrap')) { abrir(null); }
		});

		root.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') { return; }
			if (!modal.hidden) { cerrarModal(); } else if (st.abierto) { abrir(null); input.blur(); }
		});

		/* ---------- Sugerencias ---------- */

		var sugCache = {};
		var sugTimer = null;
		var sugCtl = null;

		function cargarSugerencias() {
			var q = input.value.trim();
			clearTimeout(sugTimer);
			if (sugCache[q]) { pintarSugerencias(sugCache[q], q); return; }
			sugTimer = setTimeout(function () {
				if (sugCtl) { sugCtl.abort(); }
				sugCtl = new AbortController();
				getJSON(restUrl('sugerir', { q: q, lang: CFG.lang || '' }), sugCtl.signal).then(function (data) {
					sugCache[q] = data;
					if (input.value.trim() === q) { pintarSugerencias(data, q); }
				}).catch(function () { /* petición cancelada o sin red: se deja la lista como está */ });
			}, q ? 200 : 0);
		}

		function pintarSugerencias(data, q) {
			st.sug = data.items || [];
			st.sugOn = -1;
			sugTitulo.textContent = data.titulo || '';
			if (!st.sug.length) {
				sugList.innerHTML = '<p class="meb-sug-vacio">' + esc((T.sinSugerencias || '').replace('%s', q)) + '</p>';
				return;
			}
			sugList.innerHTML = st.sug.map(function (item, i) {
				return '<li role="option" class="is-' + esc(item.clase) + '" data-i="' + i + '">' +
					'<span class="meb-sug-ico">' + icono(ICONOS[item.clase] ? item.clase : 'contenido', 22) + '</span>' +
					'<span class="meb-sug-text"><span class="meb-sug-title">' + resaltar(item.titulo, q) + '</span>' +
					'<span class="meb-sug-sub">' + esc(item.sub || '') + '</span></span></li>';
			}).join('');
		}

		function elegir(item) {
			if (!item) { return; }
			if (item.url) { window.location.href = item.url; return; }
			// Es un tema o eje: se marca en el tercer segmento y se busca de inmediato.
			var casilla = $('input[name="' + item.campo + '[]"][value="' + item.slug + '"]', form);
			if (casilla) { casilla.checked = true; }
			input.value = '';
			sync();
			if (ajax) {
				abrir(null);
				input.blur();
				buscar({ historial: true });
			} else {
				abrir('fecha');
			}
		}

		sugList.addEventListener('click', function (e) {
			var li = e.target.closest('li[data-i]');
			if (li) { elegir(st.sug[+li.getAttribute('data-i')]); }
		});

		input.addEventListener('input', function () {
			sync();
			if (st.abierto !== 'q') { abrir('q'); } else { cargarSugerencias(); }
		});

		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				if (!st.sug.length) { return; }
				e.preventDefault();
				st.sugOn = (st.sugOn + (e.key === 'ArrowDown' ? 1 : -1) + st.sug.length + (st.sugOn < 0 && e.key === 'ArrowUp' ? 1 : 0)) % st.sug.length;
				$$('li', sugList).forEach(function (li, i) {
					li.classList.toggle('is-on', i === st.sugOn);
					if (i === st.sugOn) { li.scrollIntoView({ block: 'nearest' }); }
				});
			} else if (e.key === 'Enter' && st.sugOn > -1) {
				e.preventDefault();
				elegir(st.sug[st.sugOn]);
			}
		});

		clearBtn.addEventListener('click', function () {
			input.value = '';
			sync();
			input.focus();
			cargarSugerencias();
			if (st.qAplicada) { auto(); }
		});

		/* ---------- Fechas ---------- */

		function pintarCalendario() {
			var html = '';
			for (var m = 0; m < 2; m++) {
				var first = new Date(st.base.getFullYear(), st.base.getMonth() + m, 1);
				var y = first.getFullYear();
				var mo = first.getMonth();
				var dias = new Date(y, mo + 1, 0).getDate();
				var prev = m === 0 ? '<button type="button" class="meb-nav" data-nav="-1" aria-label="' + esc(T.mesAnterior || '') + '">' + icono('prev', 16) + '</button>' : '<span></span>';
				var next = m === 1 ? '<button type="button" class="meb-nav" data-nav="1" aria-label="' + esc(T.mesSiguiente || '') + '">' + icono('next', 16) + '</button>' : '<span></span>';

				html += '<div class="meb-month"><div class="meb-month-head">' + prev +
					'<span class="meb-month-name">' + esc(CFG.meses[mo]) + ' ' + y + '</span>' + next + '</div>' +
					'<div class="meb-dow">' + CFG.dias.map(function (d) { return '<span>' + esc(d) + '</span>'; }).join('') + '</div>' +
					'<div class="meb-days">';

				for (var v = 0; v < first.getDay(); v++) { html += '<span></span>'; }
				for (var d = 1; d <= dias; d++) {
					var s = y + '-' + pad(mo + 1) + '-' + pad(d);
					var cls = 'meb-day';
					if (s === st.desde) { cls += ' is-start'; }
					if (s === st.hasta) { cls += ' is-end'; }
					if (st.desde && st.hasta && s > st.desde && s < st.hasta) { cls += ' is-in'; }
					if (s === iso(hoy)) { cls += ' is-today'; }
					html += '<button type="button" class="' + cls + '" data-date="' + s + '">' + d + '</button>';
				}
				html += '</div></div>';
			}
			cal.innerHTML = html;
		}

		cal.addEventListener('click', function (e) {
			var nav = e.target.closest('[data-nav]');
			if (nav) {
				st.base = new Date(st.base.getFullYear(), st.base.getMonth() + (+nav.getAttribute('data-nav')), 1);
				pintarCalendario();
				return;
			}
			var dia = e.target.closest('[data-date]');
			if (!dia) { return; }
			var s = dia.getAttribute('data-date');
			if (!st.desde || st.hasta || s < st.desde) {
				st.desde = s;
				st.hasta = '';
			} else {
				st.hasta = s;
			}
			st.periodo = '';
			sync();
			pintarCalendario();
			auto();
		});

		$$('[data-modo]', root).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var modo = btn.getAttribute('data-modo');
				$$('[data-modo]', root).forEach(function (b) { b.classList.toggle('is-on', b === btn); });
				$$('[data-vista]', root).forEach(function (v) { v.hidden = v.getAttribute('data-vista') !== modo; });
			});
		});

		$$('[data-periodo]', root).forEach(function (btn) {
			btn.addEventListener('click', function () {
				var partes = btn.getAttribute('data-periodo').split(':');
				if (partes[0] === 'mes') {
					st.desde = iso(new Date(hoy.getFullYear(), hoy.getMonth() - (+partes[1]), hoy.getDate()));
					st.hasta = iso(hoy);
				} else if (partes[1] === 'actual') {
					st.desde = hoy.getFullYear() + '-01-01';
					st.hasta = iso(hoy);
				} else {
					st.desde = partes[1] + '-01-01';
					st.hasta = partes[1] + '-12-31';
				}
				st.periodo = btn.textContent.trim();
				st.base = new Date(parse(st.desde).getFullYear(), parse(st.desde).getMonth(), 1);
				sync();
				auto();
			});
		});

		/* ---------- Estado visible ---------- */

		function textoFecha() {
			if (st.periodo) { return st.periodo; }
			if (st.desde && st.hasta) {
				var mismoAnio = st.desde.slice(0, 4) === st.hasta.slice(0, 4);
				return fechaCorta(st.desde, !mismoAnio) + ' – ' + fechaCorta(st.hasta, true);
			}
			if (st.desde) { return (T.desde || '%s').replace('%s', fechaCorta(st.desde, true)); }
			return '';
		}

		function textoTema() {
			var marcados = $$('input[name="meb_eje[]"]:checked, input[name="meb_tema[]"]:checked', form);
			if (!marcados.length) { return ''; }
			if (marcados.length === 1) {
				return marcados[0].getAttribute('data-nombre') || marcados[0].parentNode.textContent.trim();
			}
			return (T.temas || '%d').replace('%d', marcados.length);
		}

		function poner(el, texto, vacio) {
			if (!el) { return; }
			el.textContent = texto || vacio;
			el.classList.toggle('is-empty', !texto);
		}

		function sync() {
			inDesde.value = st.desde;
			inHasta.value = st.hasta;
			clearBtn.hidden = !input.value;

			var fecha = textoFecha();
			var tema = textoTema();
			poner($('[data-valor="fecha"]', root), fecha, T.cualquierFecha);
			poner($('[data-valor="tema"]', root), tema, T.cualquierTema);
			poner($('[data-compact-q]', root), input.value.trim(), T.cualquierCosa);
			poner($('[data-compact-fecha]', root), fecha, T.cualquierFecha);
			poner($('[data-compact-tema]', root), tema, T.cualquierTema);

			$$('[data-periodo]', root).forEach(function (btn) {
				btn.classList.toggle('is-on', !!st.periodo && btn.textContent.trim() === st.periodo);
			});

			var n = $$('input[name="meb_formato[]"]:checked, input[name="meb_programa[]"]:checked', form).length +
				(form.elements.meb_orden && form.elements.meb_orden.value ? 1 : 0);
			var badge = $('[data-filtros-n]', root);
			if (badge) { badge.textContent = n; badge.hidden = !n; }

			pintarActivos(fecha);
		}

		/* ---------- Filtros activos (chips con ×) ---------- */

		var activosBox = $('[data-activos]', root);

		function pintarActivos(fecha) {
			if (!activosBox) { return; }
			var items = [];
			if (st.qAplicada) { items.push({ tipo: 'q', texto: '«' + st.qAplicada + '»' }); }
			if (fecha) { items.push({ tipo: 'fecha', texto: fecha }); }
			$$('input[type="checkbox"]:checked', form).forEach(function (casilla) {
				items.push({ tipo: 'casilla', el: casilla, texto: casilla.getAttribute('data-nombre') || casilla.parentNode.textContent.trim() });
			});
			var orden = $('input[name="meb_orden"]:checked', form);
			if (orden && orden.value) { items.push({ tipo: 'orden', texto: orden.parentNode.textContent.trim() }); }

			st.activos = items;
			activosBox.hidden = !items.length;
			activosBox.innerHTML = items.map(function (item, i) {
				return '<button type="button" class="meb-activo" data-quitar="' + i + '" aria-label="' + esc((T.quitar || '%s').replace('%s', item.texto)) + '">' +
					'<span>' + esc(item.texto) + '</span>' + icono('x', 12) + '</button>';
			}).join('') + (items.length > 1 ? '<button type="button" class="meb-link" data-quitar="todo">' + esc(T.quitarTodo || '') + '</button>' : '');
		}

		function quitar(item) {
			if (item.tipo === 'q') { input.value = ''; st.qAplicada = ''; }
			if (item.tipo === 'fecha') { st.desde = ''; st.hasta = ''; st.periodo = ''; }
			if (item.tipo === 'casilla') { item.el.checked = false; }
			if (item.tipo === 'orden') { $$('input[name="meb_orden"]', form).forEach(function (r) { r.checked = r.value === ''; }); }
		}

		function limpiarTodo() {
			st.activos.slice().forEach(quitar);
			input.value = '';
			st.qAplicada = '';
			sync();
			if (st.abierto === 'fecha') { pintarCalendario(); }
		}

		if (activosBox) {
			activosBox.addEventListener('click', function (e) {
				var btn = e.target.closest('[data-quitar]');
				if (!btn) { return; }
				var cual = btn.getAttribute('data-quitar');
				if (cual === 'todo') {
					limpiarTodo();
				} else {
					quitar(st.activos[+cual]);
					sync();
					if (st.abierto === 'fecha') { pintarCalendario(); }
				}
				buscar();
			});
		}

		$('[data-accion="limpiar-fecha"]', root).addEventListener('click', function () {
			st.desde = '';
			st.hasta = '';
			st.periodo = '';
			sync();
			pintarCalendario();
			auto();
		});

		// Cualquier cambio (pestaña, tema, filtros) actualiza los resultados sin pasar por «Buscar».
		form.addEventListener('change', function () {
			sync();
			if (!modal.hidden) { contar(); }
			auto();
		});

		/* ---------- Búsqueda ---------- */

		function parametros() {
			var out = {};
			new FormData(form).forEach(function (valor, clave) {
				if (valor === '' || (clave === 'meb_tipo' && valor === 'todo')) { return; }
				clave = clave.replace('[]', '');
				out[clave] = out[clave] ? out[clave] + ',' + valor : valor;
			});
			return out;
		}

		function urlPublica(params) {
			var url = new URL(form.getAttribute('action'), window.location.href);
			Object.keys(params).forEach(function (k) { url.searchParams.set(k, params[k]); });
			return url.toString();
		}

		var buscarCtl = null;
		var autoTimer = null;

		// Búsqueda en vivo: solo cuando los resultados están en la misma página.
		function auto() {
			if (!ajax) { return; }
			clearTimeout(autoTimer);
			autoTimer = setTimeout(function () { buscar(); }, 200);
		}

		function buscar(opciones) {
			opciones = opciones || {};
			clearTimeout(autoTimer);
			var params = parametros();

			if (!ajax) {
				window.location.href = urlPublica(params);
				return;
			}

			st.pag = opciones.sumar ? st.pag + 1 : 1;
			st.qAplicada = params.meb_q ? params.meb_q.trim() : '';
			sync();
			var consulta = Object.assign({}, params, { meb_pag: st.pag, lang: CFG.lang || '' });

			if (buscarCtl) { buscarCtl.abort(); }
			buscarCtl = new AbortController();
			results.classList.add('is-loading');

			getJSON(restUrl('buscar', consulta), buscarCtl.signal).then(function (data) {
				$('[data-count]', root).textContent = data.titulo;
				if (opciones.sumar) {
					grid.insertAdjacentHTML('beforeend', data.cards);
				} else {
					grid.innerHTML = data.cards;
					if (destacado) {
						destacado.innerHTML = data.destacado || '';
						destacado.hidden = !data.destacado;
						listado.classList.toggle('has-destacado', !!data.destacado);
					}
					history[opciones.historial ? 'pushState' : 'replaceState']({ meb: true }, '', urlPublica(params));
				}
				more.hidden = !data.hay_mas;
				more.href = urlPublica(Object.assign({}, params, { meb_pag: st.pag + 1 }));
				results.classList.remove('is-loading');
			}).catch(function (err) {
				if (err.name === 'AbortError') { return; }
				results.classList.remove('is-loading');
				if (!opciones.sumar) {
					grid.innerHTML = '<p class="meb-empty">' + esc(T.error || '') + '</p>';
					more.hidden = true;
				}
			});
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			abrir(null);
			if (!modal.hidden) { cerrarModal(); }
			input.blur();
			buscar({ historial: true });
		});

		if (more) {
			more.addEventListener('click', function (e) {
				if (!ajax) { return; }
				e.preventDefault();
				buscar({ sumar: true });
			});
		}

		window.addEventListener('popstate', function () {
			if (ajax) { window.location.reload(); }
		});

		/* ---------- Modal de filtros ---------- */

		var contarTimer = null;
		var mostrarBtn = $('[data-mostrar]', root);

		function contar() {
			clearTimeout(contarTimer);
			contarTimer = setTimeout(function () {
				var consulta = Object.assign({}, parametros(), { solo_total: 1, lang: CFG.lang || '' });
				getJSON(restUrl('buscar', consulta)).then(function (data) {
					mostrarBtn.textContent = data.total ? (T.mostrar || '%s').replace('%s', data.titulo.toLowerCase()) : data.titulo;
				}).catch(function () { /* el botón conserva su texto anterior */ });
			}, 150);
		}

		function abrirModal() {
			abrir(null);
			modal.hidden = false;
			document.documentElement.style.overflow = 'hidden';
			contar();
			$('.meb-modal-close', modal).focus();
		}

		function cerrarModal() {
			modal.hidden = true;
			document.documentElement.style.overflow = '';
		}

		root.addEventListener('click', function (e) {
			var accion = e.target.closest('[data-accion]');
			if (!accion) {
				if (e.target === modal) { cerrarModal(); }
				return;
			}
			switch (accion.getAttribute('data-accion')) {
				case 'abrir-modal':
					abrirModal();
					break;
				case 'cerrar-modal':
					cerrarModal();
					break;
				case 'quitar-filtros':
					// Quita todo: palabra, fechas, temas y los filtros del modal.
					limpiarTodo();
					contar();
					auto();
					break;
			}
		});

		/* ---------- Barra compacta ---------- */

		if (compact && root.getAttribute('data-compacto') === '1' && 'IntersectionObserver' in window) {
			new IntersectionObserver(function (entries) {
				compact.hidden = entries[0].isIntersecting || entries[0].boundingClientRect.top > 0;
			}).observe(bar);

			compact.addEventListener('click', function () {
				bar.scrollIntoView({ behavior: 'smooth', block: 'center' });
				setTimeout(function () { input.focus({ preventScroll: true }); }, 350);
			});
		}

		sync();
	}

	function arrancar() {
		$$('[data-meb]').forEach(function (root) {
			if (root.getAttribute('data-meb') === 'listo') { return; }
			root.setAttribute('data-meb', 'listo');
			init(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', arrancar);
	} else {
		arrancar();
	}
})();
