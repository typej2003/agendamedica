@props(['url', 'que' => 'lo de la consulta anterior'])

{{-- El botón «Anterior» (WEB-11.4): copia a la consulta lo que tenía la consulta anterior de la misma historia. Un `POST` con confirmación
     (`data-confirmar` de shell.js): suma a lo que ya hay, no reemplaza. Se pinta sólo desde la 2.ª consulta (`$vm->tieneAnterior()`). --}}
<form method="POST" action="{{ $url }}" {{ $attributes->merge(['class' => 'd-inline']) }}
      data-confirmar="¿Copiar {{ $que }} de la consulta anterior? Se suma a lo que ya hay en esta.">
    @csrf
    <button class="btn btn-sm btn-outline-secondary" title="Copiar {{ $que }} de la consulta anterior de este paciente">
        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Anterior
    </button>
</form>
