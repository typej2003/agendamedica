// El aviso de alertas del paciente (WEB-11.6): si el servidor marcó el diálogo (`data-abrir-al-cargar="1"`), se abre al entrar a la
// consulta. «Entendido» lo cierra (`data-cerrar-dialogo`, de shell.js) y, como el servidor ya anotó que se avisó, no reaparece.
(function () {
    'use strict';

    var dialogo = document.querySelector('dialog[data-abrir-al-cargar="1"]');

    if (dialogo && typeof dialogo.showModal === 'function') {
        dialogo.showModal();
    }
})();
