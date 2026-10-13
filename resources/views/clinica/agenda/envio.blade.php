@extends('clinica.layout')

@section('titulo', 'Enviar a todos')

@section('contenido')
    <x-ui.page-head titulo="Enviar a todos"
                    :subtitulo="$vm->titulo() . ' · ' . $vm->sede() . ' · ' . $vm->total()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
    </x-ui.page-head>

    <p class="texto-tenue small mb-3">
        No se listan las citas ya confirmadas ni las atendidas: no hace falta recordárselas.
    </p>

    <form method="POST" action="{{ $vm->accion() }}" id="envio-form">
        @csrf
        @foreach ($vm->ocultos() as $nombre => $valor)
            <input type="hidden" name="{{ $nombre }}" value="{{ $valor }}">
        @endforeach

        <x-ui.tarjeta class="mb-3">
            <div class="row g-3">
                <div class="col-md-4">
                    <span class="etiqueta d-block mb-2">Por dónde</span>
                    <div class="d-flex flex-wrap gap-3">
                        @foreach ($vm->canales() as $canal)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="canal"
                                       id="envio-canal-{{ $canal['clave'] }}" value="{{ $canal['clave'] }}"
                                       data-canal="{{ $canal['clave'] }}" {{ $canal['elegido'] ? 'checked' : '' }}>
                                <label class="form-check-label" for="envio-canal-{{ $canal['clave'] }}">{{ $canal['etiqueta'] }}</label>
                            </div>
                        @endforeach
                    </div>
                    <p class="form-text mb-0">Los que no tengan el dato del canal elegido quedan fuera de la selección.</p>
                </div>

                <div class="col-md-8">
                    <label class="etiqueta d-block mb-2" for="envio-mensaje">Mensaje</label>
                    <textarea name="mensaje" id="envio-mensaje" rows="3" maxlength="500"
                              class="form-control">{{ $vm->mensajePorDefecto() }}</textarea>
                    <p class="form-text mb-0" id="envio-contador"></p>
                </div>
            </div>
        </x-ui.tarjeta>

        <x-ui.tarjeta sin-relleno>
            @if ($vm->estaVacio())
                <x-ui.vacio icono="bi-send" titulo="No hay pacientes para recordar">
                    Ese día y sede no tienen citas pendientes de confirmar.
                </x-ui.vacio>
            @else
                <x-ui.tabla>
                    <thead>
                    <tr>
                        <th class="col-estrecha">
                            <input type="checkbox" class="form-check-input" id="envio-todos" checked aria-label="Marcar todos">
                        </th>
                        <th class="col-hora">Hora</th>
                        <th>Paciente</th>
                        <th>Celular</th>
                        <th>E-Mail</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($vm->filas() as $fila)
                        <tr data-telefono="{{ $fila['tieneTelefono'] ? '1' : '0' }}" data-correo="{{ $fila['tieneCorreo'] ? '1' : '0' }}">
                            <td class="celda-principal">
                                <input type="checkbox" class="form-check-input envio-cita" name="citas[]" value="{{ $fila['id'] }}" checked
                                       aria-label="Enviarle a {{ $fila['paciente'] }}">
                            </td>
                            <td data-etiqueta="Hora">{{ $fila['hora'] }}</td>
                            <td data-etiqueta="Paciente">
                                {{ $fila['paciente'] }}
                                @if ($fila['sinHistoria'])
                                    <x-ui.estado tono="aviso" icono="bi-exclamation-triangle">Sin historia</x-ui.estado>
                                @endif
                            </td>
                            <td data-etiqueta="Celular">
                                {{ $fila['telefono'] ?: '—' }}
                                @unless ($fila['tieneTelefono'])
                                    <x-ui.estado tono="aviso" icono="bi-telephone-x">Sin celular válido</x-ui.estado>
                                @endunless
                            </td>
                            <td data-etiqueta="E-Mail" class="text-break">
                                {{ $fila['correo'] ?: '—' }}
                                @unless ($fila['tieneCorreo'])
                                    <x-ui.estado tono="aviso" icono="bi-envelope-x">Sin correo</x-ui.estado>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </x-ui.tabla>
            @endif

            <div class="tarjeta-pie">
                <span class="texto-tenue small" id="envio-resumen"></span>
                {{-- `@disabled` no existe en Blade de Laravel 8 (llega en el 9): se escribe el atributo. --}}
                <button class="btn btn-primary" @if ($vm->estaVacio()) disabled @endif>
                    <i class="bi bi-send" aria-hidden="true"></i> Enviar a los seleccionados
                </button>
            </div>
        </x-ui.tarjeta>
    </form>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/envio.js') }}"></script>
@endsection
