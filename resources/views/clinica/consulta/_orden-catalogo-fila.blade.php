{{-- Una fila de la orden de laboratorio o de la solicitud de estudios con **escritura inmediata** (O-29): el «Quitar»
     es un `DELETE` que se envía al hacer clic (rastro `source = web` por la Action de la orden), sin botón de guardar.

     El código viaja en el cuerpo y en el `data-*`; el nombre es sólo de pantalla (el servidor lo vuelve a resolver
     contra el catálogo y nunca lo toma del formulario). Es el mismo marcado de la fila del editor que se guarda
     entero (`_orden-fila`), con el botón dentro de su formulario. --}}
<li class="orden-fila" data-codigo="{{ $item['codigo'] }}">
    <span class="orden-fila-datos">
        <span class="orden-nombre">{{ $item['nombre'] }}</span>
        <span class="texto-tenue small d-block">{{ $item['aviso'] ?? $item['tipo'] }}</span>
    </span>

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
