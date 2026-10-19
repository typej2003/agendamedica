{{-- **Exámenes/Estudios** de la consulta pediátrica (ítem 6 de su árbol, 2026-10-19): el contenedor de sus tres
     solapas —*Exámenes*, *Imagenología* y *Estudios exploratorios*—, las de `tab_3` de `tabpage_signos` del
     escritorio (`w_consulta_principal.srw:1650-1670`).

     En el escritorio **no** es una solapa de *Evaluación física*: es un ítem propio, con sus tres textos y sus
     plantillas al lado. La web lo tenía como un redirect a la solapa *Resultado de exámenes* del ítem 4, y por eso
     se veía la misma pantalla que la evaluación física (Alexander, 2026-10-19).

     Cada solapa es un enlace de la misma sección (`?seccion=examenes-estudios&pestana=imagenologia`), no
     JavaScript, y comparte el parcial de las solapas de texto del examen: es la misma pantalla —un campo, sus
     plantillas y el aviso de cambios sin guardar—, con el dato de `App\Clinica\Consulta\TextosDelExamen`. --}}
<div class="consulta-seccion" id="consulta-examenes-estudios">
    <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" etiqueta="Exámenes y estudios" />

    @include($seccion->parcial(), ['seccion' => $seccion->vista(), 'imagenes' => null])
</div>
