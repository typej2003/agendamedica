@props(['anterior', 'destino' => null])

{{-- **La casilla «Anterior»** (WEB-11.4; casilla como la del escritorio, pedido de Alexander del 2026-10-20): trae lo de la consulta
     anterior de la misma historia. Marcada = lo de la anterior está en ésta; marcarla lo trae sin repetir y desmarcarla saca lo que
     coincide con la anterior. Recibe un `App\Clinica\Consulta\Vistas\AnteriorVista` (o `null`: primera consulta, no se pinta).

     - `lista` (motivos, diagnósticos, que guardan al instante): un formulario `POST`; `anterior.js` lo envía al cambiar la casilla
       (y pregunta antes de quitar). Sin JavaScript, el botón «Aplicar».
     - `texto` (`destino` = el campo) y `recipe` (el editor): la casilla sólo cambia el editor; se guarda con el guardado de la
       sección. Lo resuelve `anterior.js` desde los `data-*`. --}}
@if ($anterior)
    @if ($anterior->tipo() === \App\Clinica\Consulta\Vistas\AnteriorVista::LISTA)
        <form method="POST" action="{{ $anterior->url() }}" {{ $attributes->merge(['class' => 'anterior']) }}
              data-anterior-lista data-confirmar-quitar="{{ $anterior->confirmarQuitar() }}">
            @csrf
            <span class="form-check mb-0" title="{{ $anterior->titulo() }}">
                <input class="form-check-input" type="checkbox" name="activar" value="1" id="{{ $anterior->id() }}"
                       @if ($anterior->activo()) checked @endif @if (! $anterior->habilitado()) disabled @endif>
                <label class="form-check-label" for="{{ $anterior->id() }}">Anterior</label>
            </span>
            <noscript><button class="btn btn-sm btn-link p-0">Aplicar</button></noscript>
        </form>
    @else
        <span {{ $attributes->merge(['class' => 'anterior form-check mb-0']) }} title="{{ $anterior->titulo() }}">
            <input class="form-check-input" type="checkbox" id="{{ $anterior->id() }}" @if (! $anterior->habilitado()) disabled @endif
                   data-anterior-{{ $anterior->tipo() }}
                   data-de-cual="{{ $anterior->deCual() }}"
                   @if ($anterior->tipo() === \App\Clinica\Consulta\Vistas\AnteriorVista::TEXTO)
                       data-destino="{{ $destino }}" data-texto="{{ $anterior->texto() }}"
                   @else
                       data-items="{{ json_encode($anterior->items(), JSON_UNESCAPED_UNICODE) }}"
                   @endif>
            <label class="form-check-label" for="{{ $anterior->id() }}">Anterior</label>
        </span>
    @endif
@endif
