/*
 * La consulta (WEB-4.2): el contenido de sus secciones, dentro del shell.
 *
 * Las vistas no llevan JS: declaran lo que quieren con `data-*` y este archivo —el de la pantalla, como
 * `agenda-pacientes.js`— lo resuelve. Hace dos cosas, cada una atada a un bloque de la pantalla:
 *
 * 1. **El buscador de motivos** (`#consulta-motivos`): filtra el catálogo del médico mientras se escribe
 *    (250 ms de espera, como el buscador de pacientes), un motivo por nombre, y ofrece **crear el que
 *    falta** cuando lo escrito no es un motivo del catálogo. Los resultados se pintan con las plantillas
 *    de la vista y los formularios postean como cualquier otro (el JS no manda nada por su cuenta: llena
 *    el `codemotivo` de un formulario y lo deja enviar).
 *
 *    Sin JavaScript la pantalla sigue sirviendo: el servidor ya pintó el catálogo dentro de
 *    `#motivo-resultados`.
 *
 * 2. **El aviso de "hay cambios sin guardar"** de la enfermedad actual (`[data-consulta-enfermedad]`):
 *    el texto clínico se guarda con un botón, así que la pantalla tiene que decir cuándo hay algo escrito
 *    que todavía no está guardado y el navegador tiene que avisar antes de perderlo.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------- Buscador de motivos

    const motivos = document.getElementById('consulta-motivos');

    if (motivos) {
        const caja = document.getElementById('motivos-busqueda');
        const texto = document.getElementById('motivo-texto');
        const lista = document.getElementById('motivo-resultados');
        const ayuda = document.getElementById('motivo-ayuda');
        const plantilla = document.getElementById('motivo-plantilla');
        const plantillaCrear = document.getElementById('motivo-crear-plantilla');
        const botonNuevo = document.getElementById('motivo-nuevo-boton');
        const dialogo = document.getElementById('dialogo-motivo');
        const campoDescripcion = document.getElementById('motivo-descripcion');
        const urlBuscar = motivos.dataset.urlBuscar;
        const ayudaInicial = ayuda.textContent;
        let temporizador = null;
        let ultimaBusqueda = '';

        function limpiar() {
            lista.innerHTML = '';
            lista.hidden = true;
        }

        function filaDelMotivo(motivo) {
            const fila = plantilla.content.firstElementChild.cloneNode(true);
            const datos = fila.querySelector('.resultado-paciente-datos');
            datos.textContent = motivo.descripcion;

            if (motivo.yaEsta) {
                datos.insertAdjacentHTML('beforeend', ' <span class="texto-tenue small">· ya está en la consulta</span>');
                fila.querySelector('form').remove();
                return fila;
            }

            fila.querySelector('input[name=codemotivo]').value = motivo.codemotivo;
            return fila;
        }

        function filaParaCrear() {
            const fila = plantillaCrear.content.firstElementChild.cloneNode(true);
            fila.querySelector('.resultado-paciente-datos').textContent =
                motivos.dataset.textoCrear.replace('{texto}', ultimaBusqueda);
            return fila;
        }

        function pintar(datos) {
            lista.innerHTML = '';

            // Crear sólo si lo escrito **no es** un motivo del catálogo: si ya existe uno igual, lo que
            // corresponde es agregarlo, y el servidor tampoco lo duplicaría (el móvil hace lo mismo).
            if (ultimaBusqueda !== '' && !datos.existe) {
                lista.appendChild(filaParaCrear());
            }

            datos.motivos.forEach(function (motivo) {
                lista.appendChild(filaDelMotivo(motivo));
            });

            if (lista.children.length === 0) {
                limpiar();
                ayuda.textContent = ultimaBusqueda === ''
                    ? ayudaInicial
                    : 'Tu catálogo no tiene motivos que coincidan con «' + ultimaBusqueda + '».';
                return;
            }

            lista.hidden = false;

            const cuantos = datos.motivos.length;
            ayuda.textContent = cuantos === 0
                ? 'Ningún motivo de tu catálogo coincide con «' + ultimaBusqueda + '»: puedes crearlo.'
                : cuantos + (cuantos === 1 ? ' motivo' : ' motivos')
                    + (ultimaBusqueda === '' ? ' en tu catálogo.' : ' que coinciden con «' + ultimaBusqueda + '».');
        }

        function buscar() {
            const consulta = texto.value.trim();
            ultimaBusqueda = consulta;

            if (consulta === '') {
                limpiar();
                ayuda.textContent = ayudaInicial;
                return;
            }

            fetch(urlBuscar + '?q=' + encodeURIComponent(consulta), { headers: { 'Accept': 'application/json' } })
                .then(function (respuesta) { return respuesta.json(); })
                .then(function (datos) {
                    // Una respuesta vieja no pisa lo que se escribió después.
                    if (datos.buscar !== texto.value.trim()) return;
                    pintar(datos);
                })
                .catch(function () {
                    limpiar();
                    ayuda.textContent = 'No se pudo buscar en el catálogo: revisa la conexión con el servidor.';
                });
        }

        texto.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, 250);
        });

        document.addEventListener('click', function (evento) {
            if (evento.target !== texto && !caja.contains(evento.target)) {
                limpiar();
            }
        });

        // El botón "Crear un motivo nuevo" y la fila "Crear «…»" del buscador abren el mismo diálogo: la
        // fila le pasa lo escrito, el botón deja el campo vacío.
        function abrirCrear(textoInicial) {
            if (!dialogo) return;

            if (campoDescripcion && textoInicial) {
                campoDescripcion.value = textoInicial;
            }

            limpiar();
            dialogo.showModal();
            if (campoDescripcion) campoDescripcion.focus();
        }

        lista.addEventListener('click', function (evento) {
            if (evento.target.closest('#motivo-crear-boton')) {
                evento.preventDefault();
                abrirCrear(ultimaBusqueda);
            }
        });

        if (botonNuevo) {
            botonNuevo.addEventListener('click', function () { abrirCrear(''); });
        }

        // El diálogo se abre solo después de un error de validación: el servidor dejó `crear_motivo` en la
        // sesión y el campo conserva lo escrito (`old()`).
        if (dialogo && motivos.dataset.crearAbierto === '1') {
            dialogo.showModal();
        }
    }

    // ------------------------------------------------- Aviso de cambios sin guardar (enfermedad actual)

    document.querySelectorAll('[data-consulta-enfermedad]').forEach(function (formulario) {
        const campo = formulario.querySelector('textarea');
        const aviso = document.getElementById('enfermedad-sin-guardar');
        const guardado = campo ? campo.value : '';

        if (!campo) return;

        function sucio() {
            return campo.value !== guardado;
        }

        function pintarAviso() {
            if (aviso) aviso.hidden = !sucio();
        }

        campo.addEventListener('input', pintarAviso);

        // Cancelar vuelve a la sección sin guardar: el navegador pregunta antes de perder el texto.
        const cancelar = formulario.querySelector('a[href]');
        if (cancelar) {
            cancelar.addEventListener('click', function (evento) {
                if (sucio() && !window.confirm(formulario.dataset.aviso)) {
                    evento.preventDefault();
                }
            });
        }

        window.addEventListener('beforeunload', function (evento) {
            if (!sucio()) return;
            evento.preventDefault();
            // El texto de retorno lo ignora el navegador (muestra el suyo), pero sin algo no avisa.
            evento.returnValue = formulario.dataset.aviso;
        });

        pintarAviso();
    });
})();
