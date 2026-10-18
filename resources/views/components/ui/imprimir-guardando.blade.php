@props(['url', 'form', 'documento', 'titulo' => 'Vista previa'])

{{-- «Imprimir» + «Visualizar» (WEB-11.24), con **guardar y después imprimir** (O-16).

     «Imprimir» es un `submit` del formulario de la sección —va en la cabecera de la tarjeta, así que lo enlaza por el atributo
     `form`— con `name="imprimir"` y la **clave del documento** (`eco-obstetrico`, `constancia`…). El `POST` guarda con la regla
     de siempre y el middleware `clinica.imprimir` (`ImprimeDespuesDeGuardar`) cambia el destino por la hoja: lo que el médico
     cambió ya está guardado cuando la hoja se arma. `formtarget="_blank"` la abre en otra pestaña, como antes, para no perder
     la consulta. Si el guardado no deja nada que imprimir, la sección lo dice (`Guarda los datos del estudio para poder
     imprimir`) en vez de mostrar la página 404.

     «Visualizar» sigue mostrando la hoja en el diálogo de la consulta (`consulta-visualizar.js`): es la vista previa de lo
     guardado. Lo que va en `$slot` es el contenido del botón «Imprimir»; la clase se aplica a los dos botones. --}}
<button type="submit" form="{{ $form }}" name="imprimir" value="{{ $documento }}" formtarget="_blank"
        {{ $attributes->merge(['class' => 'btn btn-sm btn-outline-secondary']) }}>{{ $slot }}</button>
<button type="button" {{ $attributes->merge(['class' => 'btn btn-sm btn-outline-secondary']) }}
        data-visualizar="{{ $url }}" data-titulo="{{ $titulo }}">
    <i class="bi bi-eye" aria-hidden="true"></i> Visualizar
</button>
