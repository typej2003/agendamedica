{{-- Alta y edición de la sede. `data-modo` le dice a `sedes.js` si el botón «Nueva sede» puede abrirlo sin recargar
     (`nuevo`) o si tiene cargada otra sede y hay que pedir la pantalla de nuevo (`editar`). --}}
<x-ui.dialogo id="dialogo-sede" :titulo="$vm->tituloSede()" lateral data-modo="{{ $vm->editandoSede() ? 'editar' : 'nuevo' }}">
    <form method="POST" action="{{ $vm->urlGuardarSede() }}">
        @csrf
        <input type="hidden" name="formulario" value="sede">
        <input type="hidden" name="id" value="{{ $vm->idSede() }}">
        <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">

        <div class="dialogo-cuerpo">
            @include('clinica.sedes._campos-sede', ['prefijo' => 'sede-', 'conValores' => true])
        </div>

        <div class="dialogo-pie">
            @if ($vm->editandoSede())
                <a class="btn btn-outline-secondary" href="{{ $vm->url() }}">Cancelar</a>
            @else
                <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            @endif
            <button class="btn btn-primary">{{ $vm->botonSede() }}</button>
        </div>
    </form>
</x-ui.dialogo>
