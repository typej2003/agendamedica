{{-- Una sede con sus consultorios (`SedesYConsultoriosVista::tarjetas()`). Editar la sede recarga la pantalla con su
     panel abierto (`?sede=`); «Consultorio» abre el panel del consultorio con esta sede ya elegida. El lápiz y el tacho
     sólo se ofrecen si la sede es **administrable** (dueño o asignada): la que el médico ve por tener un consultorio en
     ella es de otro y su ficha no se toca. --}}
<x-ui.tarjeta class="sede-tarjeta" :titulo="$sede['nombre']" icono="bi-geo-alt">
    <x-slot name="acciones">
        @if ($sede['estado'])
            <x-ui.estado :tono="$sede['estado']['tono']" :icono="$sede['estado']['icono']">{{ $sede['estado']['texto'] }}</x-ui.estado>
        @endif
        @if ($sede['administrable'])
            <x-ui.acciones-fila etiqueta="Acciones de la sede {{ $sede['nombre'] }}">
                <a class="btn btn-sm btn-outline-secondary" href="{{ $sede['urlEditar'] }}"
                   title="Editar la sede" aria-label="Editar la sede {{ $sede['nombre'] }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </a>
                <form method="POST" action="{{ $sede['urlActivar'] }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">
                    <button class="btn btn-sm {{ $sede['activar']['peligro'] ? 'btn-outline-danger' : 'btn-outline-secondary' }}"
                            title="{{ $sede['activar']['texto'] }} la sede"
                            aria-label="{{ $sede['activar']['texto'] }} la sede {{ $sede['nombre'] }}">
                        <i class="bi {{ $sede['activar']['icono'] }}" aria-hidden="true"></i>
                    </button>
                </form>
            </x-ui.acciones-fila>
        @endif
    </x-slot>

    <p class="sede-datos">
        <span>{{ $sede['direccion'] }}</span>
        @if ($sede['ciudad'])<span>{{ $sede['ciudad'] }}</span>@endif
        @if ($sede['telefono'])<span><i class="bi bi-telephone" aria-hidden="true"></i> {{ $sede['telefono'] }}</span>@endif
    </p>

    @if ($vm->puedeCargarConsultorios())
        @if ($sede['consultorios'] === [])
            <p class="sede-sin-consultorios">Sin consultorios tuyos</p>
        @else
            <ul class="sede-consultorios">
                @foreach ($sede['consultorios'] as $consultorio)
                    <li class="sede-consultorio">
                        <div class="sede-consultorio-cabeza">
                            <div class="sede-consultorio-identidad">
                                <i class="bi bi-door-closed" aria-hidden="true"></i>
                                <strong class="sede-consultorio-nombre">{{ $consultorio['nombre'] }}</strong>
                                <x-ui.estado :tono="$consultorio['modalidad']['tono']" :icono="$consultorio['modalidad']['icono']">{{ $consultorio['modalidad']['texto'] }}</x-ui.estado>
                                @if ($consultorio['duracion'])
                                    <span class="texto-tenue small tnum">{{ $consultorio['duracion'] }}</span>
                                @endif
                                @if ($consultorio['estado'])
                                    <x-ui.estado :tono="$consultorio['estado']['tono']" :icono="$consultorio['estado']['icono']">{{ $consultorio['estado']['texto'] }}</x-ui.estado>
                                @endif
                            </div>

                            {{-- Tres acciones: menú «…» (pauta de Alexander), por uso y con desactivar al final, en rojo. --}}
                            <x-ui.menu etiqueta="Acciones del consultorio {{ $consultorio['nombre'] }}">
                                <a class="menu-item" href="{{ $consultorio['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar el consultorio
                                </a>
                                <a class="menu-item" href="{{ $consultorio['urlHorarios'] }}">
                                    <i class="bi bi-clock" aria-hidden="true"></i> Todos sus horarios
                                </a>
                                <form method="POST" action="{{ $consultorio['urlActivar'] }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">
                                    <button class="menu-item {{ $consultorio['activar']['peligro'] ? 'menu-item-peligro' : '' }}">
                                        <i class="bi {{ $consultorio['activar']['icono'] }}" aria-hidden="true"></i> {{ $consultorio['activar']['texto'] }}
                                    </button>
                                </form>
                            </x-ui.menu>
                        </div>

                        {{-- Los bloques de horario se ven y se cargan acá (A-10): el alta abre su panel, con las mismas
                             rutas y reglas de siempre (`GuardarBloqueHorario`). --}}
                        <div class="sede-consultorio-bloques" data-tour="consultorios-bloques">
                            @foreach ($consultorio['bloques'] as $bloque)
                                <span class="bloque-chip">
                                    <span><abbr title="{{ $bloque['diaCompleto'] }}">{{ $bloque['dia'] }}</abbr>
                                        <span class="tnum">{{ $bloque['horario'] }}</span>
                                        <span class="texto-tenue">· {{ $bloque['cupo'] }}</span></span>
                                    <form method="POST" action="{{ $bloque['urlEliminar'] }}" data-confirmar="{{ $bloque['confirmar'] }}">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="volver" value="sedes">
                                        <input type="hidden" name="ver_todas" value="{{ $vm->verTodas() }}">
                                        <button class="bloque-chip-quitar"
                                                title="Quitar el bloque del {{ $bloque['diaCompleto'] }} de {{ $bloque['horario'] }}"
                                                aria-label="Quitar el bloque del {{ $bloque['diaCompleto'] }} de {{ $bloque['horario'] }}">
                                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </span>
                            @endforeach
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-tour="consultorios-alta"
                                    data-panel-horario data-accion="{{ $consultorio['urlGuardarBloque'] }}"
                                    data-consultorio="{{ $consultorio['id'] }}" data-titulo="{{ $consultorio['tituloHorario'] }}"
                                    aria-label="Agregar un horario al consultorio {{ $consultorio['nombre'] }}">
                                <i class="bi bi-plus-lg" aria-hidden="true"></i> Horario
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($sede['puedeAgregar'])
            <div class="sede-pie">
                <a class="btn btn-sm btn-outline-primary" href="{{ $sede['urlNuevoConsultorio'] }}"
                   data-panel="dialogo-consultorio" data-sede="{{ $sede['id'] }}"
                   aria-label="Agregar un consultorio en {{ $sede['nombre'] }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Consultorio
                </a>
            </div>
        @endif
    @endif
</x-ui.tarjeta>
