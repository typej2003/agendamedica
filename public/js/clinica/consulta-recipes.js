/*
 * Los récipes de la consulta (WEB-5.1, ítem 10 del panel): el editor de un récipe.
 *
 * Las vistas no llevan JS: declaran lo que quieren con `data-*` y este archivo —el de la sección, como
 * `consulta.js`— lo resuelve. Sólo corre si el editor está en pantalla (`#recipe-form`); la lista de récipes
 * no necesita nada (sus acciones son enlaces y un formulario con `data-confirmar`, que resuelve `shell.js`).
 *
 * Hace cuatro cosas, cada una atada a un bloque de la pantalla:
 *
 * 1. **El buscador** (`#recipe-busqueda`): pide al servidor el vademécum del médico —o sus tratamientos,
 *    según el botón elegido— mientras se escribe (250 ms de espera, como los otros buscadores) y pinta los
 *    resultados con la plantilla de la vista. Elegir un medicamento lo **agrega al récipe en edición** con
 *    la indicación del vademécum; elegir un tratamiento agrega **todos sus medicamentos** con su cantidad e
 *    indicaciones. Lo agregado no se guarda: entra al editor y se guarda con el botón.
 *
 *    En el editor de récipes (A-04) la lista es la **columna derecha**, en línea y siempre a la vista
 *    (`data-recipe-lista-en-linea`): el vademécum se carga al abrir el editor, sin escribir nada, y la lista no
 *    se esconde al hacer clic afuera. En el catálogo de tratamientos (WEB-8.1a) sigue siendo el desplegable
 *    flotante de una sola columna.
 *
 * 2. **Las filas del récipe** (`#recipe-items`): se agregan clonando la plantilla de la vista (el script no
 *    arma HTML), un medicamento entra **una sola vez** por récipe (la clave del escritorio es historia +
 *    consulta + medicamento) y «Quitar» saca la fila del formulario. Editar las indicaciones de una fila
 *    sólo cambia ese récipe: nunca el vademécum ni el tratamiento.
 *
 * (WEB-5.1b: el editor del **récipe de la pareja** es el mismo marcado y este mismo script; lo único que cambia
 * es que no tiene fecha de emisión y que su cédula y su nombre cuentan para el aviso de cambios sin guardar.)
 *
 * 3. **El aviso de «hay cambios sin guardar»** y el del navegador al salir: el récipe se guarda con un
 *    botón, así que la pantalla dice cuándo hay algo que todavía no está guardado.
 *
 * 4. **El alta del medicamento que falta** (`#dialogo-medicamento`): el formulario del diálogo se manda por
 *    `fetch` —y no con una recarga— para no perder lo que hay en el editor; el medicamento creado entra al
 *    récipe como cualquier otro. Es lo único que necesita conexión.
 */
(function () {
    'use strict';

    const formulario = document.getElementById('recipe-form');

    if (!formulario) return;

    const raiz = document.getElementById('consulta-recipes');
    const lista = document.getElementById('recipe-items');
    const plantillaFila = document.getElementById('recipe-fila-plantilla');
    const vacio = document.getElementById('recipe-vacio');
    const contador = document.getElementById('recipe-contador');
    const avisoSucio = document.getElementById('recipe-sin-guardar');
    const campoFecha = document.getElementById('recipe-fecha');

    const caja = document.getElementById('recipe-busqueda');
    const texto = document.getElementById('recipe-texto');
    const etiqueta = document.getElementById('recipe-etiqueta');
    const resultados = document.getElementById('recipe-resultados');
    const ayuda = document.getElementById('recipe-ayuda');
    const plantillaResultado = document.getElementById('recipe-resultado-plantilla');
    const botonesDeModo = Array.prototype.slice.call(document.querySelectorAll('[data-recipe-modo]'));

    const dialogo = document.getElementById('dialogo-medicamento');
    const formularioMedicamento = document.getElementById('medicamento-form');
    const errorMedicamento = document.getElementById('medicamento-error');
    const botonCrear = document.getElementById('medicamento-crear');
    const botonNuevo = document.getElementById('recipe-nuevo-medicamento');

    // Lo que se edita: «récipe» (la consulta) o «tratamiento» (el catálogo, WEB-8.1a, que usa este mismo editor).
    const cosa = formulario.dataset.cosa || 'récipe';

    // La lista del buscador es una columna a la vista (el editor de récipes, A-04) y no un desplegable: se carga sola
    // al abrir el editor y no se esconde. El catálogo de tratamientos no lleva el atributo.
    const listaEnLinea = raiz.dataset.recipeListaEnLinea === '1';

    let siguienteClave = parseInt(formulario.dataset.siguienteClave, 10) || 0;
    let modo = 'medicamentos';
    let temporizador = null;
    let saliendo = false;

    // ------------------------------------------------------------------------------- Filas del récipe

    function filas() {
        return Array.prototype.slice.call(lista.querySelectorAll('[data-recipe-fila]'));
    }

    function codigos() {
        return filas().map(function (fila) { return fila.dataset.codigo; });
    }

    /**
     * Lo que decide si hay cambios sin guardar: la fecha, los campos extra del editor (`data-recipe-extra`: en el
     * récipe de la pareja, su cédula y su nombre) y, por fila, el código, la cantidad y las indicaciones.
     */
    function instantanea() {
        return JSON.stringify([
            campoFecha ? campoFecha.value : '',
            Array.prototype.map.call(formulario.querySelectorAll('[data-recipe-extra]'), function (campo) { return campo.value; }),
            filas().map(function (fila) {
                return [
                    fila.dataset.codigo,
                    fila.querySelector('input[name$="[cantidad]"]').value,
                    fila.querySelector('textarea').value,
                ];
            }),
        ]);
    }

    const inicial = instantanea();

    function sucio() {
        return instantanea() !== inicial;
    }

    function actualizar() {
        const total = filas().length;

        if (vacio) vacio.hidden = total > 0;

        if (contador) {
            const icono = contador.querySelector('i');
            contador.className = 'estado ' + (total === 0 ? 'estado-neutro' : 'estado-marca');
            contador.textContent = ' ' + (total === 0 ? 'Sin medicamentos' : total + (total === 1 ? ' medicamento' : ' medicamentos'));
            if (icono) {
                icono.className = 'bi ' + (total === 0 ? 'bi-dash-circle' : 'bi-capsule');
                contador.insertBefore(icono, contador.firstChild);
            }
        }

        if (avisoSucio) avisoSucio.hidden = !sucio();
    }

    /**
     * Agrega un medicamento al récipe en edición clonando la plantilla de la vista. Devuelve la fila, o
     * `null` si el medicamento ya estaba (no se repite: un medicamento entra una sola vez por récipe).
     */
    function agregar(medicamento) {
        if (codigos().indexOf(medicamento.codigo) >= 0) return null;

        const clave = siguienteClave++;
        const contenedor = document.createElement('ul');
        contenedor.innerHTML = plantillaFila.innerHTML.split('__CLAVE__').join(String(clave)).trim();
        const fila = contenedor.firstElementChild;

        fila.dataset.codigo = medicamento.codigo;
        fila.querySelector('input[name$="[codemedicina]"]').value = medicamento.codigo;
        fila.querySelector('input[name$="[nombre]"]').value = medicamento.nombre;
        fila.querySelector('[data-recipe-nombre]').textContent = medicamento.nombre;
        fila.querySelector('[data-recipe-generico]').textContent = medicamento.generico ? '· ' + medicamento.generico : '';
        fila.querySelector('input[name$="[cantidad]"]').value = medicamento.cantidad || 1;
        fila.querySelector('textarea').value = medicamento.indicaciones || '';

        const quitar = fila.querySelector('[data-recipe-quitar]');
        quitar.title = 'Quitar «' + medicamento.nombre + '» del ' + cosa;
        quitar.setAttribute('aria-label', 'Quitar ' + medicamento.nombre + ' del ' + cosa);

        lista.appendChild(fila);
        actualizar();

        return fila;
    }

    // «Subir» y «bajar» (sólo en el editor de un tratamiento del catálogo): mueven la fila en la lista; el orden que
    // se guarda es el de la lista, y mover una fila cuenta como un cambio sin guardar (`instantanea` mira el orden).
    lista.addEventListener('click', function (evento) {
        const mover = evento.target.closest('[data-recipe-subir], [data-recipe-bajar]');

        if (!mover) return;

        const fila = mover.closest('[data-recipe-fila]');
        if (!fila) return;

        if (mover.hasAttribute('data-recipe-subir')) {
            if (fila.previousElementSibling) lista.insertBefore(fila, fila.previousElementSibling);
        } else if (fila.nextElementSibling) {
            lista.insertBefore(fila.nextElementSibling, fila);
        }

        mover.focus();
        actualizar();
    });

    // «Quitar» saca la fila del formulario: no borra nada hasta guardar, y no toca el vademécum.
    lista.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-recipe-quitar]');

        if (!boton) return;

        const fila = boton.closest('[data-recipe-fila]');
        if (fila) fila.remove();
        actualizar();
        pintarMarcas();
    });

    lista.addEventListener('input', actualizar);
    if (campoFecha) campoFecha.addEventListener('input', actualizar);
    Array.prototype.forEach.call(formulario.querySelectorAll('[data-recipe-extra]'), function (campo) {
        campo.addEventListener('input', actualizar);
    });

    // ------------------------------------------------------------------------------------ El buscador

    function ocultarResultados() {
        resultados.innerHTML = '';
        resultados.hidden = true;
    }

    /** Marca en los resultados lo que ya está en el récipe (cada vez que el récipe cambia). */
    function pintarMarcas() {
        if (modo !== 'medicamentos') return;

        Array.prototype.forEach.call(resultados.querySelectorAll('[data-codigo]'), function (resultado) {
            const yaEsta = codigos().indexOf(resultado.dataset.codigo) >= 0;
            const textoAccion = resultado.querySelector('[data-resultado-texto]');
            const accion = resultado.querySelector('[data-resultado-accion]');

            resultado.disabled = yaEsta;
            accion.classList.toggle('btn-outline-primary', !yaEsta);
            accion.classList.toggle('btn-outline-secondary', yaEsta);
            textoAccion.textContent = yaEsta ? 'Ya está en el ' + cosa : 'Agregar';
        });
    }

    function filaDeResultado(nombre, detalle, textoAccion) {
        const resultado = plantillaResultado.content.firstElementChild.cloneNode(true);
        const campoNombre = resultado.querySelector('[data-resultado-nombre]');
        campoNombre.textContent = nombre;
        // La fila mide una sola línea (el nombre se recorta con puntos suspensivos): el completo va en el `title`,
        // como en el catálogo de una orden (WEB-5.4).
        campoNombre.title = nombre;
        const campoDetalle = resultado.querySelector('[data-resultado-detalle]');
        campoDetalle.textContent = detalle || '';
        resultado.title = detalle ? nombre + ' · ' + detalle : nombre;
        resultado.querySelector('[data-resultado-texto]').textContent = textoAccion;
        return resultado;
    }

    function pintarMedicamentos(datos) {
        resultados.innerHTML = '';

        datos.medicamentos.forEach(function (medicamento) {
            const resultado = filaDeResultado(
                medicamento.nombre,
                medicamento.generico && medicamento.generico !== medicamento.comercial ? medicamento.generico : '',
                'Agregar',
            );
            resultado.dataset.codigo = medicamento.codigo;
            resultado.addEventListener('click', function () {
                agregar({
                    codigo: medicamento.codigo,
                    nombre: medicamento.nombre,
                    generico: medicamento.generico,
                    cantidad: 1,
                    indicaciones: medicamento.uso,
                });
                pintarMarcas();
            });
            resultados.appendChild(resultado);
        });

        if (datos.medicamentos.length === 0) {
            ocultarResultados();
            ayuda.textContent = datos.buscar === ''
                ? 'Tu vademécum todavía no tiene medicamentos. Créalos con el botón de abajo.'
                : 'Tu vademécum no tiene medicamentos que coincidan con «' + datos.buscar + '». '
                    + 'Puedes crearlo con el botón de abajo.';
            return;
        }

        resultados.hidden = false;
        pintarMarcas();

        const cuantos = datos.medicamentos.length;
        ayuda.textContent = datos.buscar === ''
            ? 'Tu vademécum, a la vista. Elige un medicamento para agregarlo al ' + cosa + '.'
            : cuantos + (cuantos === 1 ? ' medicamento' : ' medicamentos')
                + ' que coinciden con «' + datos.buscar + '». Elige uno para agregarlo al ' + cosa + '.';
    }

    function pintarTratamientos(datos) {
        resultados.innerHTML = '';

        datos.tratamientos.forEach(function (tratamiento) {
            const cuantos = tratamiento.items.length;
            // Una sola línea: el nombre y, como dato secundario, cuántos medicamentos trae; cuáles son va en el `title`.
            const resultado = filaDeResultado(
                tratamiento.nombre,
                cuantos === 0 ? 'Sin medicamentos' : cuantos + (cuantos === 1 ? ' medicamento' : ' medicamentos'),
                'Cargar',
            );
            resultado.title = tratamiento.nombre + (cuantos === 0
                ? ''
                : ': ' + tratamiento.items.map(function (item) { return item.nombre; }).join(', '));
            resultado.disabled = cuantos === 0;

            resultado.addEventListener('click', function () {
                let nuevos = 0;

                tratamiento.items.forEach(function (item) {
                    const fila = agregar({
                        codigo: item.codigo,
                        nombre: item.nombre,
                        generico: null,
                        cantidad: item.cantidad,
                        indicaciones: item.indicaciones,
                    });
                    if (fila) nuevos++;
                });

                ayuda.textContent = nuevos === 0
                    ? 'Los medicamentos de «' + tratamiento.nombre + '» ya estaban en el récipe.'
                    : 'Se cargaron ' + nuevos + (nuevos === 1 ? ' medicamento' : ' medicamentos')
                        + ' de «' + tratamiento.nombre + '». Revísalos y guarda el récipe.';

                // La lista se queda a la vista (es la columna del catálogo): se puede cargar otro tratamiento.
                if (!listaEnLinea) ocultarResultados();
            });
            resultados.appendChild(resultado);
        });

        if (datos.tratamientos.length === 0) {
            ocultarResultados();
            ayuda.textContent = datos.buscar === ''
                ? 'Todavía no tienes tratamientos en tu catálogo.'
                : 'Ningún tratamiento coincide con «' + datos.buscar + '».';
            return;
        }

        resultados.hidden = false;
        const cuantos = datos.tratamientos.length;
        ayuda.textContent = cuantos + (cuantos === 1 ? ' tratamiento' : ' tratamientos')
            + '. Elige uno para cargar todos sus medicamentos.';
    }

    function buscar() {
        const consulta = texto.value.trim();

        // Los medicamentos se buscan por lo que se escribe; los tratamientos son pocos y se listan todos. En el
        // editor de récipes la lista está a la vista, así que sin texto se muestra el vademécum completo.
        if (modo === 'medicamentos' && consulta === '' && !listaEnLinea) {
            ocultarResultados();
            ayuda.textContent = ayuda.dataset.ayudaMedicamentos;
            return;
        }

        const url = (modo === 'medicamentos' ? raiz.dataset.urlMedicamentos : raiz.dataset.urlTratamientos)
            + '?q=' + encodeURIComponent(consulta);
        const modoPedido = modo;

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (respuesta) {
                if (!respuesta.ok) throw new Error('http ' + respuesta.status);
                return respuesta.json();
            })
            .then(function (datos) {
                // Una respuesta vieja no pisa lo que se escribió o se eligió después.
                if (modoPedido !== modo || datos.buscar !== texto.value.trim()) return;

                if (modo === 'medicamentos') pintarMedicamentos(datos);
                else pintarTratamientos(datos);
            })
            .catch(function () {
                ocultarResultados();
                ayuda.textContent = 'No se pudo buscar: revisa la conexión con el servidor.';
            });
    }

    texto.addEventListener('input', function () {
        clearTimeout(temporizador);
        temporizador = setTimeout(buscar, 250);
    });

    // En tratamientos la lista completa aparece al enfocar el campo, sin escribir nada.
    texto.addEventListener('focus', function () {
        if (modo === 'tratamientos' && resultados.hidden) buscar();
    });

    botonesDeModo.forEach(function (boton) {
        boton.addEventListener('click', function () {
            modo = boton.dataset.recipeModo;

            botonesDeModo.forEach(function (otro) {
                const activo = otro === boton;
                otro.classList.toggle('active', activo);
                otro.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });

            const esMedicamentos = modo === 'medicamentos';
            etiqueta.textContent = esMedicamentos ? 'Busca en tu vademécum' : 'Busca un tratamiento';
            texto.placeholder = esMedicamentos ? texto.dataset.placeholderMedicamentos : texto.dataset.placeholderTratamientos;
            ayuda.textContent = esMedicamentos ? ayuda.dataset.ayudaMedicamentos : ayuda.dataset.ayudaTratamientos;
            texto.value = '';
            ocultarResultados();
            texto.focus();

            // Con la lista a la vista se vuelve a cargar: el vademécum sin texto y los tratamientos del médico.
            if (!esMedicamentos || listaEnLinea) buscar();
        });
    });

    document.addEventListener('click', function (evento) {
        // La lista de la columna derecha no se esconde: es parte de la pantalla.
        if (listaEnLinea) return;

        if (evento.target !== texto && !caja.contains(evento.target)) {
            ocultarResultados();
        }
    });

    texto.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape' && !listaEnLinea) ocultarResultados();
    });

    // --------------------------------------------------------------- El alta del medicamento que falta

    function mostrarError(mensaje) {
        errorMedicamento.textContent = mensaje;
        errorMedicamento.hidden = mensaje === '';
    }

    if (botonNuevo && dialogo) {
        botonNuevo.addEventListener('click', function () {
            mostrarError('');
            // Lo que se escribió en el buscador es, casi seguro, el nombre del medicamento que falta.
            const campo = document.getElementById('medicamento-comercial');
            if (campo && modo === 'medicamentos' && texto.value.trim() !== '' && campo.value === '') {
                campo.value = texto.value.trim().toUpperCase().slice(0, campo.maxLength);
            }
            // La lista de la columna derecha no se esconde para abrir el diálogo; el que es flotante, sí.
            if (!listaEnLinea) ocultarResultados();
            dialogo.showModal();
            if (campo) campo.focus();
        });
    }

    if (formularioMedicamento) {
        formularioMedicamento.addEventListener('submit', function (evento) {
            // `fetch` en vez de la recarga: el editor puede tener cambios sin guardar y navegar los perdería.
            evento.preventDefault();
            mostrarError('');
            botonCrear.disabled = true;

            fetch(formularioMedicamento.action, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(formularioMedicamento),
            })
                .then(function (respuesta) {
                    return respuesta.json().then(function (datos) { return { ok: respuesta.ok, datos: datos }; });
                })
                .then(function (resultado) {
                    botonCrear.disabled = false;

                    if (!resultado.ok) {
                        const errores = resultado.datos.errors ? Object.keys(resultado.datos.errors) : [];
                        mostrarError(errores.length > 0 ? resultado.datos.errors[errores[0]][0] : (resultado.datos.message || 'No se pudo crear el medicamento.'));
                        return;
                    }

                    const medicamento = resultado.datos.medicamento;
                    const fila = agregar({
                        codigo: medicamento.codigo,
                        nombre: medicamento.nombre,
                        generico: medicamento.generico,
                        cantidad: 1,
                        indicaciones: medicamento.uso,
                    });

                    formularioMedicamento.reset();
                    dialogo.close();
                    ayuda.textContent = (resultado.datos.creado
                        ? 'Creaste «' + medicamento.nombre + '» en tu vademécum'
                        : '«' + medicamento.nombre + '» ya estaba en tu vademécum')
                        + (fila ? ' y quedó agregado al récipe.' : ' y ya estaba en el récipe.');
                })
                .catch(function () {
                    botonCrear.disabled = false;
                    mostrarError('No se pudo crear el medicamento: revisa la conexión con el servidor.');
                });
        });
    }

    // ---------------------------------------------------------------- Cambios sin guardar y al salir

    // Cancelar vuelve a la lista sin guardar: la pantalla pregunta antes de perder los cambios. Si el médico
    // lo confirma, salir es a propósito y el navegador no vuelve a preguntar (un solo aviso, no dos).
    const cancelar = formulario.querySelector('a[href]');
    if (cancelar) {
        cancelar.addEventListener('click', function (evento) {
            if (sucio() && !window.confirm(formulario.dataset.aviso)) {
                evento.preventDefault();
                return;
            }

            saliendo = true;
        });
    }

    // Guardar también es salir a propósito: no hay nada que avisar.
    formulario.addEventListener('submit', function () {
        saliendo = true;
    });

    // Y eliminar el récipe de la pareja (un formulario aparte, `data-recipe-salir`): si el médico lo confirma,
    // salir es a propósito. `shell.js` pide la confirmación en su propio `submit` (más arriba en el
    // documento) y la cancela con `preventDefault()`; este oyente corre después y sólo cuenta lo confirmado.
    document.addEventListener('submit', function (evento) {
        if (evento.target.hasAttribute && evento.target.hasAttribute('data-recipe-salir') && !evento.defaultPrevented) {
            saliendo = true;
        }
    });

    window.addEventListener('beforeunload', function (evento) {
        if (saliendo || !sucio()) return;
        evento.preventDefault();
        // El texto de retorno lo ignora el navegador (muestra el suyo), pero sin algo no avisa.
        evento.returnValue = formulario.dataset.aviso;
    });

    actualizar();

    // La columna derecha del editor de récipes arranca con el vademécum a la vista, sin escribir nada (A-04).
    if (listaEnLinea) buscar();
})();
