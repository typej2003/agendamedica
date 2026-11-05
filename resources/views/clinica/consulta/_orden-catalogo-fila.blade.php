{{-- Una fila de la orden de laboratorio o de la solicitud de estudios con **escritura inmediata** (O-29): el «Quitar»
     es un `DELETE` que se envía al hacer clic (rastro `source = web` por la Action de la orden), sin botón de guardar.

     El código viaja en el cuerpo y en el `data-*`; el nombre es sólo de pantalla (el servidor lo vuelve a resolver
     contra el catálogo y nunca lo toma del formulario). Es el mismo marcado de la fila del editor que se guarda
     entero (`_orden-fila`), con el botón dentro de su formulario.

     En el **laboratorio** suma el resultado y la casilla «Reporte» (O-47): `examen_paciente.resultado` y
     `.vinculante`, las dos columnas del escritorio. Van con `form="orden-resultados"` porque esta fila ya tiene
     el formulario del «Quitar» y un formulario no puede ir dentro de otro: el botón vive al pie de la lista.
     La casilla viene **marcada por defecto** (2026-11-05): sólo se destilda la excepción. --}}
<li class="orden-fila" data-codigo="{{ $item['codigo'] }}">
    <span class="orden-fila-datos">
        <span class="orden-nombre">{{ $item['nombre'] }}</span>
        <span class="texto-tenue small d-block">{{ $item['aviso'] ?? $item['tipo'] }}</span>
    </span>

    @if ($vm->pideResultados())
        <span class="orden-fila-resultado">
            <input type="text" class="form-control form-control-sm @error('resultados.' . $item['codigo']) is-invalid @enderror"
                   name="resultados[{{ $item['codigo'] }}]" value="{{ $item['resultado'] ?? '' }}"
                   maxlength="{{ $vm->largoDelResultado() }}" placeholder="Resultado" autocomplete="off"
                   aria-label="Resultado de {{ $item['nombre'] }}" form="orden-resultados">

            <label class="form-check form-check-inline mb-0" title="Sale en el informe">
                <input class="form-check-input" type="checkbox" name="reportes[]" value="{{ $item['codigo'] }}"
                       form="orden-resultados" @checked($item['reporte'] ?? true)>
                <span class="form-check-label small">Reporte</span>
            </label>
        </span>
    @endif

    <form method="POST" action="{{ $vm->urlQuitarDe($item['codigo']) }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="codigo" value="{{ $item['codigo'] }}">
        <button class="btn btn-sm btn-outline-danger orden-quitar"
                title="Quitar «{{ $item['nombre'] }}» de la {{ $vm->esLaboratorio() ? 'orden' : 'solicitud' }}"
                aria-label="Quitar {{ $item['nombre'] }} de la {{ $vm->esLaboratorio() ? 'orden' : 'solicitud' }}">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </form>
</li>
