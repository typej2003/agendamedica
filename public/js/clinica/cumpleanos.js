/*
 * Cumpleaños (`/clinica/cumpleanos`, WEB-2.9): el diálogo del saludo y la vista previa de la plantilla.
 * Sin dependencias; todo se declara con atributos `data-*` en la vista.
 *
 *   <button data-dialogo="cumple" data-paciente-id data-paciente data-mensaje data-enviado>
 *   <textarea id="plantilla">  +  <div id="cumple-preview">
 */
(function () {
    'use strict';

    const $ = function (id) { return document.getElementById(id); };

    // --------------------------------------------------------------------------------- Vista previa
    // La plantilla tal como está escrita en el formulario, con el primer paciente de hoy: es el mismo
    // reemplazo que hace el servidor (`MensajeDeCumpleanos`), limitado a lo que un navegador puede hacer
    // sin pedir nada (las dos etiquetas son texto plano).
    const plantilla = $('plantilla');
    const preview = $('cumple-preview');

    if (plantilla && preview) {
        // La vista previa arranca **ya armada por el servidor** con el primer cumpleañero de hoy. El
        // nombre con el que saluda viaja en un `data-*` para que el reemplazo de acá sea el mismo que el
        // del servidor: si hoy no cumple nadie, el servidor usó el de ejemplo y el JS también.
        const ejemplo = {
            paciente: preview.dataset.paciente || 'Ana Pérez',
            // El nombre del médico del contexto, para el `{doctor}`.
            doctor: (document.querySelector('[data-doctor]') || { dataset: {} }).dataset.doctor || '',
        };

        const actualizar = function () {
            preview.textContent = plantilla.value
                .replace(/\{paciente\}/g, ejemplo.paciente)
                .replace(/\{doctor\}/g, ejemplo.doctor);
        };

        plantilla.addEventListener('input', actualizar);
        actualizar();
    }

    // ------------------------------------------------------------------------------------- Diálogo
    // El mensaje llega **ya armado** por el servidor con los datos del paciente. Si la fila dice que ya
    // se le mandó el saludo, se avisa antes de mandarlo de nuevo: el reenvío es posible (a decisión de
    // una persona) pero no puede pasar sin que se vea.
    function abrir(boton) {
        const datos = boton.dataset;

        $('cumple-paciente-id').value = datos.pacienteId;
        $('cumple-paciente').textContent = datos.paciente;
        $('cumple-texto').value = datos.mensaje;

        const aviso = $('cumple-aviso');
        aviso.hidden = datos.enviado !== '1';
        aviso.textContent = datos.enviado === '1'
            ? 'A este paciente ya se le mandó el saludo de hoy. Si lo mandás de nuevo, le llega dos veces.'
            : '';

        actualizarContador();
        $('dialogo-cumple').showModal();
        $('cumple-texto').focus();
    }

    function actualizarContador() {
        const texto = $('cumple-texto').value;
        $('cumple-contador').textContent = texto.length + ' caracteres';
    }

    document.addEventListener('click', function (evento) {
        const boton = evento.target.closest('[data-dialogo="cumple"]');
        if (boton) abrir(boton);
    });

    if ($('cumple-texto')) {
        $('cumple-texto').addEventListener('input', actualizarContador);
    }
})();
