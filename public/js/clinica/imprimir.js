/* Listado del día para imprimir: el botón con `data-imprimir` abre el diálogo de impresión del navegador. */
document.querySelectorAll('[data-imprimir]').forEach(function (boton) {
    boton.addEventListener('click', function () { window.print(); });
});
