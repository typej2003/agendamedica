// Chips de navegación (WEB-11.1): deja el chip elegido a la vista cuando la fila se desplaza.
(function () {
    document.querySelectorAll('[data-chips]').forEach(function (barra) {
        var activo = barra.querySelector('.chip-opcion.activo');
        if (!activo || barra.scrollWidth <= barra.clientWidth) { return; }
        barra.scrollLeft = activo.offsetLeft - (barra.clientWidth - activo.offsetWidth) / 2;
    });
})();
