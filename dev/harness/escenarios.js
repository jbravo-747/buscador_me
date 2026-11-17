/* Escenarios de prueba para capturas en Chrome headless: /?h_esc=nombre */
(function () {
	var nombre = new URLSearchParams(location.search).get('h_esc');
	var root = document.querySelector('.meb');
	var q = function (s) { return root.querySelector(s); };
	var qa = function (s) { return Array.prototype.slice.call(root.querySelectorAll(s)); };
	var espera = function (ms) { return new Promise(function (r) { setTimeout(r, ms); }); };
	var log = {};

	function escribir(texto) {
		var input = q('.meb-input');
		input.focus();
		input.value = texto;
		input.dispatchEvent(new Event('input', { bubbles: true }));
	}

	var escenarios = {
		q: function () { q('.meb-input').focus(); return espera(400); },
		q2: function () { escribir('segu'); return espera(700); },
		fecha: function () {
			q('[data-seg="fecha"]').click();
			var dias = qa('.meb-day');
			dias[9].click();
			qa('.meb-day')[40].click();
			return espera(100);
		},
		periodo: function () {
			q('[data-seg="fecha"]').click();
			q('[data-modo="periodo"]').click();
			qa('[data-periodo]')[1].click();
			return espera(100);
		},
		tema: function () {
			q('[data-seg="tema"]').click();
			qa('input[name="meb_eje[]"]')[0].click();
			qa('input[name="meb_tema[]"]')[2].click();
			return espera(100);
		},
		modal: function () {
			q('[data-accion="abrir-modal"]').click();
			qa('input[name="meb_formato[]"]')[0].click();
			return espera(700);
		},
		compacto: function () { window.scrollTo(0, 900); return espera(500); },
		flujo: function () {
			escribir('segu');
			return espera(700).then(function () {
				log.sugerencias = qa('.meb-sug li').length;
				log.negrita = !!q('.meb-sug-title b');
				q('.meb-sug li.is-tema').click();
				log.temaMarcado = qa('input[name="meb_tema[]"]:checked').map(function (c) { return c.value; });
				log.panelTrasElegir = (qa('[data-panel]').filter(function (p) { return !p.hidden; })[0] || {}).dataset;
				log.valorTema = q('[data-valor="tema"]').textContent;
				qa('.meb-day')[3].click();
				qa('.meb-day')[20].click();
				log.valorFecha = q('[data-valor="fecha"]').textContent;
				log.antes = q('[data-count]').textContent;
				q('.meb-submit').click();
				return espera(800);
			}).then(function () {
				log.despues = q('[data-count]').textContent;
				log.url = location.search;
				log.panelesAbiertos = qa('[data-panel]').filter(function (p) { return !p.hidden; }).length;
				// Cambiar de pestaña debe buscar de inmediato.
				qa('input[name="meb_tipo"]')[2].click();
				return espera(800);
			}).then(function () {
				log.trasPestana = q('[data-count]').textContent;
				log.urlPestana = location.search;
				// Quitar todo y cargar más.
				q('[data-accion="limpiar-fecha"]').click();
				qa('input[name="meb_tema[]"]:checked').forEach(function (c) { c.click(); });
				qa('input[name="meb_tipo"]')[0].click();
				return espera(800);
			}).then(function () {
				log.todo = q('[data-count]').textContent;
				log.tarjetas = qa('.meb-card').length;
				q('[data-more]').click();
				return espera(800);
			}).then(function () {
				log.tarjetasTrasMas = qa('.meb-card').length;
				q('[data-accion="abrir-modal"]').click();
				qa('input[name="meb_programa[]"]')[0].click();
				return espera(700);
			}).then(function () {
				log.botonModal = q('[data-mostrar]').textContent;
				log.insignia = q('[data-filtros-n]').textContent;
				q('[data-mostrar]').click();
				return espera(800);
			}).then(function () {
				log.trasModal = q('[data-count]').textContent;
				log.modalCerrado = q('[data-modal]').hidden;
				log.urlFinal = location.search;
			});
		}
	};

	window.addEventListener('error', function (e) { log.error = e.message; });

	window.addEventListener('load', function () {
		if (!escenarios[nombre]) { return; }
		Promise.resolve(escenarios[nombre]()).catch(function (e) { log.error = String(e); }).then(function () {
			var pre = document.createElement('pre');
			pre.id = 'h-log';
			pre.hidden = nombre !== 'flujo';
			pre.textContent = JSON.stringify(log, null, 1);
			document.body.appendChild(pre);
		});
	});
})();
