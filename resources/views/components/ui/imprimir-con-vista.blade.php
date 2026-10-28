@props(['url', 'titulo' => 'Vista previa'])

{{-- «Imprimir» + «Visualizar» (WEB-11.24): el escritorio ofrece los dos botones al pie de cada solicitud, récipe y eco. «Imprimir» abre la hoja
     en otra pestaña (como siempre) y «Visualizar» la muestra en un diálogo de la misma consulta, con «Imprimir» y «Modificar» (cierra el
     diálogo y vuelve al formulario). Lo que va en `$slot` es el contenido del botón «Imprimir»; la clase se aplica a los dos botones.
     El diálogo es uno solo por página (`clinica.consulta._visualizar`) y lo maneja `consulta-visualizar.js`.

     Sin plan vigente (o con un plan que no incluye impresión) se reemplazan por el enlace para mejorar el plan. --}}
@if (($planPermiteImprimir ?? true) === false)
    <a href="{{ route('clinica.plan') }}" {{ $attributes->merge(['class' => 'btn btn-sm btn-outline-warning']) }}
       title="{{ $motivoSinImpresion ?? 'Tu plan no permite imprimir reportes ni PDF.' }}">
        <i class="bi bi-lock" aria-hidden="true"></i> Mejora tu plan para imprimir
    </a>
@else
    <a {{ $attributes->merge(['class' => 'btn btn-sm btn-outline-secondary']) }} href="{{ $url }}" target="_blank" rel="noopener">{{ $slot }}</a>
    <button type="button" {{ $attributes->merge(['class' => 'btn btn-sm btn-outline-secondary']) }}
            data-visualizar="{{ $url }}" data-titulo="{{ $titulo }}">
        <i class="bi bi-eye" aria-hidden="true"></i> Visualizar
    </button>
@endif
