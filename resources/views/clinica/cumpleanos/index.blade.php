@extends('clinica.layout')

@section('titulo', 'Cumpleaños')

@section('contenido')
    {{-- El nombre del médico viaja en un `data-*` para que la vista previa del JS sustituya `{doctor}` con
         el mismo valor que va a usar el servidor (no se adivina leyendo el subtítulo). --}}
    <div data-doctor="{{ $vm->doctor() }}">
    <x-ui.page-head titulo="Cumpleaños" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
        <x-slot name="acciones">
            <x-ui.estado :tono="$vm->envioActivo() ? 'exito' : 'neutro'"
                         :icono="$vm->envioActivo() ? 'bi-send-check' : 'bi-send-slash'">
                {{ $vm->envioActivo() ? 'Envío automático a las ' . $vm->hora() : 'Envío automático apagado' }}
            </x-ui.estado>
        </x-slot>
    </x-ui.page-head>

    <x-ui.errores />

    <div class="alert alert-info py-2 d-flex flex-wrap align-items-center gap-2 mb-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>
            Esta configuración es <strong>provisional</strong>: se muda a la configuración de notificaciones cuando
            llegue esa pantalla (WEB-8.2). El envío automático necesita el <strong>cron del servidor</strong>; sin él
            sólo funciona el envío a mano.
        </span>
    </div>

    {{-- La configuración del envío automático: interruptor, canal y hora, con el mensaje que sale. Es una
         sola acción —guarda todo junto— porque la configuración es una fila por médico. --}}
    <form method="POST" action="{{ $vm->urlGuardarConfiguracion() }}">
        @csrf
        @method('PATCH')

        <x-ui.tarjeta titulo="Envío automático" icono="bi-send" class="mb-3">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="enviar" id="enviar" value="1"
                               {{ $vm->envioActivo() ? 'checked' : '' }}>
                        <label class="form-check-label" for="enviar">
                            Mandar el saludo solo, todos los días
                        </label>
                    </div>
                    <p class="form-text mb-0">
                        Apagado, el saludo no sale nunca por su cuenta: los cumpleaños del día igual se listan acá
                        para mandarlos a mano.
                    </p>
                </div>

                <x-ui.campo etiqueta="Hora" for="hora" class="col-md-4"
                            ayuda="La hora del consultorio a la que sale el saludo.">
                    <input type="time" name="hora" id="hora" class="form-control" value="{{ $vm->hora() }}" required>
                </x-ui.campo>

                <div class="col-md-4">
                    <span class="etiqueta d-block mb-2">Por dónde</span>
                    @foreach ($vm->opcionesDeCanal() as $canal)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="canal"
                                   id="canal-{{ $canal['valor'] }}" value="{{ $canal['valor'] }}"
                                   {{ $canal['elegido'] ? 'checked' : '' }}>
                            <label class="form-check-label" for="canal-{{ $canal['valor'] }}">
                                {{ $canal['texto'] }}@if ($canal['porDefecto']) <span class="texto-tenue small">(recomendado)</span>@endif
                            </label>
                        </div>
                    @endforeach
                    <p class="form-text mb-0">
                        El correo es el recomendado para un envío que nadie mira: no se cobra por mensaje y lleva el
                        texto tal cual. {{ $vm->ayudaDelCanal('whatsapp') }}
                    </p>
                </div>
            </div>

            <hr class="my-3">

            <div class="row g-3">
                <x-ui.campo etiqueta="Mensaje" for="plantilla" class="col-md-7"
                            ayuda="Las etiquetas {paciente} y {doctor} se reemplazan al enviar. Es la misma plantilla que edita el móvil.">
                    <textarea name="plantilla" id="plantilla" rows="3" maxlength="500" class="form-control">{{ $vm->plantilla() }}</textarea>
                </x-ui.campo>

                <div class="col-md-5">
                    <span class="etiqueta d-block mb-2">Vista previa</span>
                    {{-- El nombre del primer cumpleañero de hoy viaja en un `data-*` para que el JS de la
                         pantalla arme la vista previa con **ese** nombre y no con uno de ejemplo: el que se
                         manda es el de la plantilla con los datos de cada paciente. --}}
                    <div class="border rounded p-2 bg-body-tertiary" id="cumple-preview"
                         data-paciente="{{ $vm->primerCumpleanero() }}">{{ $vm->vistaPrevia() }}</div>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
                <button class="btn btn-primary">
                    <i class="bi bi-check2" aria-hidden="true"></i> Guardar la configuración
                </button>
            </div>
        </x-ui.tarjeta>
    </form>

    {{-- Los que cumplen años hoy, del médico del contexto. La acción primaria de la pantalla es **enviar**:
         el listado es lo que se usa todos los días y la configuración se toca una vez. --}}
    <x-ui.tarjeta sin-relleno titulo="Cumplen años hoy" icono="bi-balloon">
        @if ($vm->estaVacio())
            <x-ui.vacio icono="bi-balloon" :titulo="$vm->textoVacio()">{{ $vm->ayudaVacio() }}</x-ui.vacio>
        @else            <x-ui.tabla>
                <thead>
                <tr>
                    <th>Paciente</th>
                    <th>Cumple</th>
                    <th>Contacto</th>
                    <th>Saludo</th>
                    <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($vm->filas() as $fila)
                    <tr>
                        <td class="celda-principal">
                            <x-ui.identidad :nombre="$fila['paciente']"
                                            :detalle="$fila['edad'] ? 'Cumple ' . $fila['edad'] : null" tamano="sm" />
                            @if ($fila['adelantado'])
                                <x-ui.estado tono="info" icono="bi-calendar-event" class="mt-1"
                                             title="Nació un 29 de febrero y este año no es bisiesto">
                                    Nació el 29/02, se saluda el 28
                                </x-ui.estado>
                            @endif
                        </td>

                        <td data-etiqueta="Cumple">{{ $fila['edad'] ?? '—' }}</td>

                        <td class="celda-apilada" data-etiqueta="Contacto">
                            <span class="d-block">
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                {{ $fila['telefono'] ?: '—' }}
                            </span>
                            <span class="d-block text-break">
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                {{ $fila['correo'] ?: '—' }}
                            </span>
                            @if ($fila['falta'])
                                <x-ui.estado tono="aviso" icono="bi-exclamation-triangle" class="mt-1">
                                    Sin {{ $fila['falta'] }}: se saltea
                                </x-ui.estado>
                            @endif
                        </td>

                        <td data-etiqueta="Saludo">
                            <x-ui.estado :tono="$fila['estado']['tono']" :icono="$fila['estado']['icono']">{{ $fila['estado']['texto'] }}</x-ui.estado>
                        </td>

                        <td class="celda-acciones text-end">
                            {{-- Una sola acción por fila: mandarle el saludo. El diálogo de la página se llena
                                 con los `data-*` de este botón (`public/js/clinica/cumpleanos.js`). --}}
                            <button type="button" class="btn btn-sm btn-primary"
                                    data-dialogo="cumple"
                                    data-paciente-id="{{ $fila['pacienteId'] }}"
                                    data-paciente="{{ $fila['paciente'] }}"
                                    data-mensaje="{{ $fila['mensaje'] }}"
                                    data-tiene-telefono="{{ $fila['tieneTelefono'] ? '1' : '0' }}"
                                    data-tiene-correo="{{ $fila['tieneCorreo'] ? '1' : '0' }}"
                                    data-enviado="{{ $fila['enviado'] ? '1' : '0' }}"
                                    @if (! $fila['puedeEnviar']) disabled @endif
                                    title="{{ $fila['puedeEnviar'] ? 'Mandar el saludo de cumpleaños' : 'Al paciente le falta el dato del canal configurado' }}">
                                <i class="bi bi-send" aria-hidden="true"></i> Enviar
                            </button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </x-ui.tabla>

            <div class="tarjeta-pie">
                <span class="texto-tenue small">{{ $vm->conteo() }}</span>
            </div>
        @endif
    </x-ui.tarjeta>

    {{-- Un solo diálogo para toda la página: el botón de cada fila lo llena con sus datos. --}}
    @include('clinica.cumpleanos._dialogo-envio')
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/cumpleanos.js') }}"></script>
@endsection
