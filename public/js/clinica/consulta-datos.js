// «Imprimir la ficha» de la sección de datos personales (WEB-11.25): imprime sólo la ficha (el CSS de impresión oculta el resto).
(function () {
    'use strict';

    var boton = document.querySelector('[data-imprimir-ficha]');
    if (!boton) { return; }

    boton.addEventListener('click', function () {
        document.body.classList.add('imprimiendo-ficha');
        window.print();
    });
    window.addEventListener('afterprint', function () {
        document.body.classList.remove('imprimiendo-ficha');
    });
})();
