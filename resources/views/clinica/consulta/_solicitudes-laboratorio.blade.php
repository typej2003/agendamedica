{{-- La orden de exámenes de laboratorio (WEB-5.4): los exámenes del catálogo del médico y las rutinas (un tipo completo),
     con su resumen clínico. Recibe `$vm` (`OrdenDeCatalogoVista`). Una por consulta; cada «Pasar» y cada «Quitar» escribe
     al instante (O-29) y el resumen clínico se guarda con su botón.

     El botón «Pareja» (WEB-11.21) lo pinta el editor de la orden: su orden aparte, que se sigue guardando entera. --}}
@include('clinica.consulta._orden-catalogo')
