{{-- La solicitud de estudios (WEB-5.4): los estudios de imágenes del catálogo del médico, en el orden en que se piden, con
     su resumen clínico. Recibe `$vm` (`OrdenDeCatalogoVista`). Una por consulta; cada «Pasar» y cada «Quitar» escribe al
     instante (O-29) y el resumen clínico se guarda con su botón. --}}
@include('clinica.consulta._orden-catalogo')
