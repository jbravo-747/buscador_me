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
			var abiertos = function () { return qa('[data-panel]').filter(function (p) { return !p.hidden; }).length; };
			var chips = function () { return qa('.meb-activo').map(function (c) { return c.textContent.trim(); }); };
			var total = function () { return q('[data-count]').textContent; };
			log.inicio = total();
			escribir('segu');
			return espera(700).then(function () {
				log.sugerencias = qa('.meb-sug li').length;
				// 1. Elegir un tema busca solo, sin pasar por fecha.
				q('.meb-sug li.is-tema').click();
				return espera(700);
			}).then(function () {
				log.trasElegirTema = total();
				log.panelesTrasElegir = abiertos();
				log.chips1 = chips();
				// 2. Fecha: al completar el rango se actualiza sin pulsar Buscar.
				q('[data-seg="fecha"]').click();
				qa('.meb-day')[3].click();
				qa('.meb-day')[20].click();
				return espera(700);
			}).then(function () {
				log.trasFecha = total();
				log.chips2 = chips();
				log.url = location.search;
				// 3. Quitar la fecha con la × de su chip.
				qa('.meb-activo')[0].click();
				return espera(700);
			}).then(function () {
				log.trasQuitarFecha = total();
				log.chips3 = chips();
				// 4. Desmarcar el tema en el panel también actualiza.
				q('[data-seg="tema"]').click();
				q('input[name="meb_tema[]"]:checked').click();
				return espera(700);
			}).then(function () {
				log.trasDesmarcarTema = total();
				log.chips4 = chips();
				// 5. Solo palabra clave con Enter (sin fecha ni tema).
				escribir('salud');
				q('.meb-form').dispatchEvent(new Event('submit', { cancelable: true }));
				return espera(700);
			}).then(function () {
				log.soloPalabra = total();
				log.chips5 = chips();
				// 6. Filtros del modal + pestaña, y luego «Quitar filtros» del modal lo limpia todo.
				qa('input[name="meb_tipo"]')[1].click();
				q('[data-accion="abrir-modal"]').click();
				qa('input[name="meb_programa[]"]')[0].click();
				return espera(700);
			}).then(function () {
				log.conModal = total();
				log.botonModal = q('[data-mostrar]').textContent;
				log.chips6 = chips();
				q('[data-accion="quitar-filtros"]').click();
				return espera(700);
			}).then(function () {
				log.trasQuitarFiltrosModal = total();
				log.botonModal2 = q('[data-mostrar]').textContent;
				log.chips7 = chips();
				log.inputVacio = q('.meb-input').value === '';
				q('[data-mostrar]').click();
				qa('input[name="meb_tipo"]')[0].click();
				return espera(700);
			}).then(function () {
				log.final = total();
				log.urlFinal = location.search;
				// 7. «Quitar todo» en la fila de chips.
				qa('input[name="meb_tema[]"]')[0].click();
				qa('input[name="meb_eje[]"]')[0].click();
				return espera(700);
			}).then(function () {
				log.dosFiltros = total();
				log.hayQuitarTodo = !!q('[data-quitar="todo"]');
				q('[data-quitar="todo"]').click();
				return espera(700);
			}).then(function () {
				log.trasQuitarTodo = total();
				log.chips8 = chips();
				q('[data-more]').click();
				return espera(700);
			}).then(function () {
				log.tarjetasTrasMas = qa('.meb-card').length;
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
