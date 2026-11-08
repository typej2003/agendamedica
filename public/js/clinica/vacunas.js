/*
 * Las vacunas del niño (O-68/O-69; WEB-7.4.4b): el diálogo único de la dosis, que se abre desde tres lugares y hace dos cosas.
 *
 *  - «Agregar una vacuna» (`data-agregar-vacuna`): muestra la elección **vacuna + dosis** del catálogo del médico y la decisión
 *    («sí, ya se la apliqué» / «no, queda programada»). Es el *Escoger* del escritorio (`w_escoger_razon_vacuna_consu.srw:1180`).
 *  - «Aplicar» de una fila de la lista o «Registrar» de una dosis del esquema (`data-registrar-dosis`): viene llena con esa
 *    vacuna y esa dosis, y la decisión queda fija en aplicada.
 *
 * **Dos booleanos, no tres desenlaces** (O-83, Alexander 2026-11-06): «Uno si se aplicó o no, y el otro si se aplicó acá o
 * no (si es propia)». El primero es la decisión de arriba (`ejecutada`) y el segundo es «¿Dónde se la aplicaste?»
 * (`aplicada_aca`): al elegir «En otro lado» se avisa que la dosis queda como aplicada **por fuera** del consultorio. Los dos
 * arrancan sin opción marcada y el botón no se habilita hasta que estén los dos: el que aplica una dosis dice dónde.
 *
 * Según la decisión, el formulario postea a `data-url-registrar` o a `data-url-programar` (los pone el ViewModel; si no hay
 * historia no hay url de programación y la opción no se ofrece). Los campos de la opción que no se eligió se ocultan y se
 * deshabilitan: un campo deshabilitado no se envía, así el servidor recibe sólo lo de la acción elegida.
 *
 * La vista no lleva JS inline: el botón declara lo que sabe con `data-*` y este archivo llena el diálogo
 * `<x-ui.dialogo id="dialogo-registrar-dosis">`. «Cancelar» lo cierra con `data-cerrar-dialogo` (`shell.js`).
 */
(function () {
    'use strict';

    const dialogo = document.getElementById('dialogo-registrar-dosis');
    if (!dialogo) return;

    const formulario = dialogo.querySelector('[data-dosis-formulario]');
    const urlRegistrar = formulario ? (formulario.dataset.urlRegistrar || formulario.getAttribute('action')) : '';
    const urlProgramar = formulario ? (formulario.dataset.urlProgramar || '') : '';

    const titulo = document.getElementById('dialogo-registrar-dosis-titulo');
    const rotulo = dialogo.querySelector('[data-dosis-titulo]');
    const enviar = dialogo.querySelector('[data-dosis-enviar]');

    const eleccion = dialogo.querySelector('[data-dosis-eleccion]');
    const cajaModo = dialogo.querySelector('[data-dosis-modo-caja]');
    const modo = dialogo.querySelector('[data-dosis-modo]');
    const seccionProgramada = dialogo.querySelector('[data-dosis-programada]');
    const seccionAplicada = dialogo.querySelector('[data-dosis-aplicada]');

    const vacuna = dialogo.querySelector('[data-dosis-vacuna]');
    const dosis = dialogo.querySelector('[data-dosis-dosis]');
    const planeada = dialogo.querySelector('[data-dosis-planeada]');
    const fechaAplicacion = document.getElementById('dosis-fecha');
    // El segundo booleano de la dosis (O-83): «En este consultorio» (`S`) o «En otro lado» (`N`).
    const procedencia = dialogo.querySelector('[data-dosis-procedencia]');
    const avisoOtroLado = dialogo.querySelector('[data-dosis-otro-lado]');

    const campo = function (nombre) {
        return dialogo.querySelector('[data-dosis-campo="' + nombre + '"]');
    };

    const escribir = function (id, valor) {
        const entrada = document.getElementById(id);
        if (entrada) entrada.value = valor;
    };

    /* Muestra u oculta un bloque y, al ocultarlo, deshabilita sus controles para que no viajen en el envío. */
    const mostrar = function (bloque, visible) {
        if (!bloque) return;
        bloque.hidden = !visible;
        bloque.querySelectorAll('input, select, textarea').forEach(function (control) {
            control.disabled = !visible;
        });
    };

    /* Sólo las fechas obligatorias de cada modo (no el lote ni la vía): un bloque oculto no puede frenar el envío. */
    const exigir = function (bloque, valor) {
        if (!bloque) return;
        bloque.querySelectorAll('[data-dosis-obligatorio]').forEach(function (control) {
            control.required = valor;
        });
    };

    /* Las opciones de «Dosis» son las de la vacuna elegida (cada una trae su `data-vacuna`). */
    const filtrarDosis = function () {
        if (!vacuna || !dosis) return;

        let primera = null;
        dosis.querySelectorAll('option').forEach(function (opcion) {
            const propia = opcion.dataset.vacuna === vacuna.value;
            opcion.hidden = !propia;
            opcion.disabled = !propia;
            if (propia && primera === null) primera = opcion;
        });
        if (primera) primera.selected = true;
    };

    /* El texto que se guarda en `vacuna_paciente.dosis` es el rótulo de la dosis elegida (el escritorio guarda lo mismo). */
    const rotuloDeLaDosis = function () {
        if (!dosis) return '';
        const opcion = dosis.options[dosis.selectedIndex];

        return opcion ? (opcion.dataset.rotulo || '') : '';
    };

    /* El aviso de «aplicada en otro lado» sólo se ve cuando el médico elige esa opción: la consecuencia, a la vista. */
    const aplicarProcedencia = function () {
        const otroLado = !!procedencia && !procedencia.disabled && procedencia.value === 'N';
        if (avisoOtroLado) avisoOtroLado.hidden = !otroLado;
    };

    const aplicarDecision = function () {
        const elegida = modo ? modo.value : 'aplicada';
        const aplicada = elegida === 'aplicada';
        const programada = elegida === 'programada';

        mostrar(seccionAplicada, aplicada);
        mostrar(seccionProgramada, programada);
        exigir(seccionAplicada, aplicada);
        exigir(seccionProgramada, programada);

        // La «F. a aplicar» viaja también al aplicar (es la fecha que le tocaba a esa dosis) aunque su bloque esté oculto:
        // el servidor la guarda como `fecha_planeada` y la Action completa la fila en el lugar.
        if (planeada) planeada.disabled = false;

        if (formulario) formulario.action = programada && urlProgramar ? urlProgramar : urlRegistrar;

        aplicarProcedencia();

        if (enviar) {
            // Aplicar exige las dos respuestas: «¿ya se la aplicaste?» y «¿dónde?» (O-83).
            const dondeElegido = !procedencia || procedencia.value !== '';
            enviar.disabled = !(programada || (aplicada && dondeElegido));
            enviar.textContent = programada ? 'Programar la dosis' : 'Registrar la dosis';
        }
    };

    /* «Agregar una vacuna»: se elige vacuna, dosis y qué se hace con ella. */
    const abrirParaAgregar = function () {
        if (titulo) titulo.textContent = 'Agregar una vacuna';
        if (rotulo) rotulo.textContent = '';

        ['codigo', 'numero', 'rotulo'].forEach(function (nombre) {
            const entrada = campo(nombre);
            if (entrada) entrada.value = '';
        });

        if (eleccion) eleccion.hidden = false;
        if (eleccion) eleccion.querySelectorAll('input, select').forEach(function (control) { control.disabled = false; });

        if (cajaModo) cajaModo.hidden = false;
        if (cajaModo) cajaModo.querySelectorAll('input, select').forEach(function (control) { control.disabled = false; });
        if (modo) modo.value = '';
        // «¿Dónde se la aplicaste?» arranca sin opción marcada, como la decisión (O-83).
        if (procedencia) procedencia.value = '';

        if (planeada) planeada.value = '';
        if (fechaAplicacion) fechaAplicacion.value = fechaAplicacion.defaultValue || fechaAplicacion.value;
        ['dosis-lote', 'dosis-via', 'dosis-zona', 'dosis-vence', 'dosis-proxima', 'dosis-nota'].forEach(function (id) {
            escribir(id, '');
        });

        if (vacuna) {
            vacuna.selectedIndex = 0;
            filtrarDosis();
        }

        aplicarDecision();
    };

    /* Una fila de la lista (o una dosis del esquema): la vacuna y la dosis ya están y sólo se aplica. */
    const abrirParaAplicar = function (boton) {
        if (titulo) titulo.textContent = 'Aplicar la dosis';
        if (rotulo) rotulo.textContent = (boton.dataset.nombre || '') + ' · ' + (boton.dataset.rotulo || '');

        ['codigo', 'numero', 'rotulo'].forEach(function (nombre) {
            const entrada = campo(nombre);
            if (entrada) entrada.value = boton.dataset[nombre] || '';
        });

        if (eleccion) {
            eleccion.hidden = true;
            eleccion.querySelectorAll('input, select').forEach(function (control) { control.disabled = true; });
        }

        if (cajaModo) {
            cajaModo.hidden = true;
            cajaModo.querySelectorAll('input, select').forEach(function (control) { control.disabled = true; });
        }

        // La «F. a aplicar» de la fila viaja igual, para no perderla al aplicarla.
        if (planeada) planeada.value = boton.dataset.planeada || '';
        if (fechaAplicacion) fechaAplicacion.value = fechaAplicacion.defaultValue || fechaAplicacion.value;

        if (modo) modo.value = 'aplicada';
        // Aplicar una dosis también pregunta dónde: el médico tiene que decirlo (O-83).
        if (procedencia) procedencia.value = '';
        aplicarDecision();
    };

    document.querySelectorAll('[data-agregar-vacuna]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            abrirParaAgregar();
            dialogo.showModal();
            if (vacuna) vacuna.focus();
        });
    });

    document.querySelectorAll('[data-registrar-dosis]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            abrirParaAplicar(boton);
            dialogo.showModal();
            if (fechaAplicacion) fechaAplicacion.focus();
        });
    });

    if (modo) modo.addEventListener('change', aplicarDecision);
    if (procedencia) procedencia.addEventListener('change', aplicarDecision);
    if (vacuna) vacuna.addEventListener('change', filtrarDosis);

    if (formulario) {
        formulario.addEventListener('submit', function () {
            // Los desplegables visibles no tienen `name`: la vacuna, el número de la dosis y su rótulo viajan en los campos
            // ocultos que lee el servidor (`vacuna_paciente.codi_vacuna` es parte de la clave de la fila). Sin el código, el
            // registro de «Agregar una vacuna» rebotaba con «Falta elegir la vacuna».
            const codigo = campo('codigo');
            const numero = campo('numero');
            const texto = campo('rotulo');
            const eligiendo = eleccion && !eleccion.hidden;

            if (eligiendo && codigo && vacuna) codigo.value = vacuna.value;
            if (eligiendo && numero && dosis) numero.value = dosis.value;
            if (eligiendo && texto) texto.value = rotuloDeLaDosis();
        });
    }

    // Al abrir la página ya hay una vacuna elegida en el desplegable: sus dosis son las que se pueden escoger.
    filtrarDosis();
    // El diálogo arranca sin decisión: ningún bloque de campos visible y el botón apagado.
    aplicarDecision();
})();
