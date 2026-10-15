// Panel lateral de plantillas con buscador (WEB-11.2).
//  - Buscar: filtra la lista por título, sin tildes ni mayúsculas.
//  - Elegir una plantilla REEMPLAZA el texto del campo; si el campo ya tiene texto pide confirmación.
//    Dispara `input` para que el aviso de «cambios sin guardar» se entere como si se hubiera escrito.
//  - Guardar como plantilla: manda el texto del campo y el título al servidor (JSON) y suma la plantilla a la lista.
(function () {
    'use strict';

    function normal(texto) {
        return (texto || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function escribir(campo, texto) {
        campo.value = texto;
        campo.dispatchEvent(new Event('input', { bubbles: true }));
    }

    document.querySelectorAll('[data-plantillas-panel]').forEach(function (panel) {
        var campo = document.querySelector(panel.dataset.destino);
        var campoTitulo = panel.dataset.tituloDestino ? document.querySelector(panel.dataset.tituloDestino) : null;
        var buscar = panel.querySelector('[data-plantillas-buscar]');
        var lista = panel.querySelector('[data-plantillas-lista]');
        var vacio = panel.querySelector('[data-plantillas-vacio]');
        var sinPlantillas = panel.querySelector('[data-plantillas-sin-plantillas]');
        var sinResultados = panel.querySelector('[data-plantillas-sin-resultados]');
        if (!campo || !lista) { return; }

        function filtrar() {
            var q = normal(buscar.value.trim());
            var visibles = 0;
            lista.querySelectorAll('[data-plantilla-fila]').forEach(function (fila) {
                var boton = fila.querySelector('[data-plantilla-titulo]');
                var coincide = q === '' || normal(boton.dataset.plantillaTitulo).indexOf(q) !== -1;
                fila.hidden = !coincide;
                if (coincide) { visibles++; }
            });
            var hayFilas = lista.querySelectorAll('[data-plantilla-fila]').length > 0;
            vacio.hidden = visibles > 0;
            sinPlantillas.hidden = hayFilas;
            sinResultados.hidden = !hayFilas;
        }
        buscar.addEventListener('input', filtrar);

        // Enter en el buscador o en el título no debe enviar el formulario de la consulta que rodea al panel.
        panel.addEventListener('keydown', function (evento) {
            if (evento.key === 'Enter' && evento.target.matches('input')) { evento.preventDefault(); }
        });

        lista.addEventListener('click', function (evento) {
            var boton = evento.target.closest('[data-plantilla-titulo]');
            if (!boton) { return; }
            var actual = campo.value.trim();
            if (actual !== '' && actual !== boton.dataset.plantillaTexto.trim()
                && !window.confirm('El campo ya tiene texto. ¿Reemplazarlo con la plantilla «' + boton.dataset.plantillaTitulo + '»?')) {
                return;
            }
            escribir(campo, boton.dataset.plantillaTexto);
            if (campoTitulo) { escribir(campoTitulo, boton.dataset.plantillaTitulo); }
            campo.focus();
        });

        var titulo = panel.querySelector('[data-plantillas-titulo]');
        var mensaje = panel.querySelector('[data-plantillas-mensaje]');
        var confirmar = panel.querySelector('[data-plantillas-confirmar]');

        function decir(texto, error) {
            mensaje.hidden = false;
            mensaje.textContent = texto;
            mensaje.classList.toggle('text-danger', !!error);
        }

        confirmar.addEventListener('click', function () {
            if (campo.value.trim() === '') { decir('Escribe primero el texto que quieres guardar.', true); return; }
            if (titulo.value.trim() === '') { decir('Escribe el título de la plantilla.', true); titulo.focus(); return; }

            var token = document.querySelector('meta[name="csrf-token"]');
            confirmar.disabled = true;
            fetch(panel.dataset.urlGuardar, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token ? token.content : '',
                },
                body: JSON.stringify({ tipo: panel.dataset.tipo, titulo: titulo.value, descripcion: campo.value }),
            }).then(function (respuesta) {
                return respuesta.json().then(function (datos) { return { ok: respuesta.ok, datos: datos }; });
            }).then(function (r) {
                if (!r.ok) { decir(r.datos.mensaje || 'No se pudo guardar la plantilla.', true); return; }
                var fila = document.createElement('li');
                fila.setAttribute('data-plantilla-fila', '');
                var boton = document.createElement('button');
                boton.type = 'button';
                boton.className = 'plantillas-item';
                boton.dataset.plantillaTitulo = r.datos.titulo;
                boton.dataset.plantillaTexto = r.datos.texto;
                var icono = document.createElement('i');
                icono.className = 'bi bi-files';
                icono.setAttribute('aria-hidden', 'true');
                var rotulo = document.createElement('span');
                rotulo.textContent = r.datos.titulo;
                boton.appendChild(icono);
                boton.appendChild(rotulo);
                fila.appendChild(boton);
                lista.appendChild(fila);
                titulo.value = '';
                buscar.value = '';
                filtrar();
                decir('Plantilla «' + r.datos.titulo + '» guardada.', false);
            }).catch(function () {
                decir('No se pudo guardar la plantilla. Revisa la conexión.', true);
            }).then(function () { confirmar.disabled = false; });
        });
    });
})();
