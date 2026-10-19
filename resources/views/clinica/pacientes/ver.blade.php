@extends('clinica.layout')

@section('titulo', 'Ficha del paciente')

@section('contenido')
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()"
                    :volver="route('clinica.pacientes')" volver-texto="Pacientes">
        <x-slot name="acciones">
            {{-- El resumen del paciente (WEB-3.5): sus consultas, antecedentes y documentos. Es la otra
                 mitad de la ficha —lo que pasó en las consultas— y se ofrece siempre, también al paciente
                 sin número, que ahí ve el estado vacío y el camino para asignárselo. --}}
            <a class="btn btn-outline-secondary" href="{{ route('clinica.pacientes.historia', $vm->id()) }}">
                <i class="bi bi-journal-medical" aria-hidden="true"></i> Ver resumen
            </a>
            @if ($urlHistoriaPediatrica)
                <a class="btn btn-outline-secondary" href="{{ $urlHistoriaPediatrica }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Historia pediátrica
                </a>
            @endif
            @if ($urlVacunas)
                <a class="btn btn-outline-secondary" href="{{ $urlVacunas }}">
                    <i class="bi bi-capsule" aria-hidden="true"></i> Vacunas
                </a>
            @endif
            @if ($urlCrecimiento)
                <a class="btn btn-outline-secondary" href="{{ $urlCrecimiento }}">
                    <i class="bi bi-graph-up" aria-hidden="true"></i> Crecimiento
                </a>
            @endif
            {{-- Cuando falta la historia, **esa** es la acción primaria de la pantalla: es lo que hay que
                 hacer para poder atender al paciente (wiki `04-historia-clinica.md`: "No se atiende sin
                 historia"). Editar la ficha queda al lado, en secundario. Con la historia puesta, la
                 única acción vuelve a ser editar. Una sola acción sólida por pantalla (guía de UI §1). --}}
            <a class="btn {{ $vm->puedeAsignarHistoria() ? 'btn-outline-primary' : 'btn-primary' }}"
               href="{{ route('clinica.pacientes.editar', $vm->id()) }}">
                <i class="bi bi-pencil" aria-hidden="true"></i> Editar la ficha
            </a>
        </x-slot>
    </x-ui.page-head>

    {{-- Los errores de validación del representante se muestran acá arriba y además abren su diálogo
         (`data-abrir-al-cargar`): el campo que falló está adentro y, sin eso, el aviso quedaría detrás de
         una ventana cerrada (misma decisión que el cambio de cédula, WEB-2.15). El texto del error lo
         dibuja cada `<x-ui.campo>` debajo de su control. --}}
    @if ($errors->any())
        <x-ui.errores class="mb-3" />
    @endif

    @php $rep = $representante; @endphp

    {{-- Cabecera de la ficha: quién es y cómo está de un vistazo. En un adulto el representante (los pocos
         casos de autismo o discapacidad) no merece un bloque propio: va aquí, a la derecha. --}}
    <x-ui.tarjeta class="mb-3">
        <div class="ficha-cabecera">
            <x-ui.avatar :nombre="$vm->nombre()" tamano="lg" />
            <div class="ficha-cabecera-texto">
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($vm->estados() as $estado)
                        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']">{{ $estado['texto'] }}</x-ui.estado>
                    @endforeach
                </div>
                <div class="ficha-cabecera-contacto">
                    @if ($vm->telefono())
                        <span><i class="bi bi-telephone" aria-hidden="true"></i> {{ $vm->telefono() }}</span>
                    @endif
                    @if ($vm->correo())
                        <span class="text-break"><i class="bi bi-envelope" aria-hidden="true"></i> {{ $vm->correo() }}</span>
                    @endif
                    @unless ($vm->telefono() || $vm->correo())
                        <span class="texto-tenue">Sin teléfono ni correo registrados</span>
                    @endunless
                </div>
            </div>
            @if (! $vm->esMenorDeEdad() && $rep->puedeEditar())
                <div class="ficha-cabecera-representante" id="ficha-representante">
                    @if ($rep->cargado())
                        <span class="small">
                            <i class="bi bi-person-hearts" aria-hidden="true"></i>
                            <strong>Representante:</strong> {{ $rep->nombre() ?? 'sin nombre' }}@if ($rep->parentesco()) ({{ $rep->parentesco() }})@endif
                            @if ($rep->telefono()) · {{ $rep->telefono() }}@endif
                        </span>
                    @endif
                    <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-dialogo="dialogo-representante">
                        <i class="bi bi-person-hearts" aria-hidden="true"></i> {{ $rep->cargado() ? 'Editar representante' : 'Agregar representante' }}
                    </button>
                </div>
            @endif
        </div>
    </x-ui.tarjeta>

    {{-- Llenar la historia (WEB-3.3): aparece si el paciente **no** tiene número y también cuando el vínculo
         ya lo dice pero su fila de `historias` falta (trampa 143) —ahí la acción la registra con ese mismo
         número, que es el único que deja coherentes la ficha, la agenda y la cita—. Con la historia
         registrada la ficha no ofrece nada de esto, sólo la muestra en sus datos y en el estado de arriba.
         El bloque explica el efecto porque el número no se puede elegir ni adelantar: lo calcula el
         servidor con el correlativo del consultorio, y la acción no se puede deshacer. --}}
    @if ($vm->puedeAsignarHistoria())
        @php $sinHistoria = $vm->sinHistoria(); @endphp
        <x-ui.tarjeta id="ficha-sin-historia" :titulo="$sinHistoria['titulo']" icono="bi-journal-medical" class="mb-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <p class="mb-0 texto-tenue">{{ $sinHistoria['texto'] }}</p>
                <form method="POST" action="{{ $vm->urlAsignarHistoria() }}"
                      data-confirmar="{{ $vm->confirmarAsignarHistoria() }}">
                    @csrf
                    <button class="btn btn-primary text-nowrap">
                        <i class="bi bi-journal-medical" aria-hidden="true"></i> {{ $vm->botonAsignarHistoria() }}
                    </button>
                </form>
            </div>
        </x-ui.tarjeta>
    @endif

    {{-- Representante del paciente (WEB-3.4): el adulto que responde por él —pediatría y casos
         generales— y el que presta su teléfono para el recordatorio (PLAN-WEB.md §6). Es **uno** por
         paciente: en el legado la tabla `representante` tiene `PRIMARY KEY (numhistoria)`, así que se
         guarda con el número de historia y no es una lista. Por eso, sin historia, el bloque no ofrece
         el formulario: dice el motivo y manda a asignarla (WEB-3.3), que es la acción de arriba. --}}
    @if ($vm->esMenorDeEdad())
    <x-ui.tarjeta id="ficha-representante" titulo="Representante" icono="bi-person-hearts" class="mb-3">
        <x-slot name="acciones">
            @if ($rep->puedeEditar())
                <button type="button" class="btn btn-outline-primary" data-dialogo="dialogo-representante">
                    <i class="bi bi-pencil" aria-hidden="true"></i> {{ $rep->botonEditar() }}
                </button>
            @endif
        </x-slot>

        @if ($rep->cargado())
            <div class="representante-datos">
                <div class="representante-cabeza">
                    <span class="representante-nombre">{{ $rep->nombre() ?? 'Representante sin nombre' }}</span>
                    @if ($rep->parentesco())
                        <x-ui.estado tono="neutro" icono="bi-people">{{ $rep->parentesco() }}</x-ui.estado>
                    @endif
                </div>
                <dl class="datos-rejilla">
                    <x-ui.dato etiqueta="Cédula" :valor="$rep->cedula()" />
                    <x-ui.dato etiqueta="Teléfono" :valor="$rep->telefono()" />
                    <x-ui.dato etiqueta="Correo" :valor="$rep->correo()" />
                    <x-ui.dato etiqueta="Dirección" :valor="$rep->direccion()" />
                </dl>
            </div>
        @else
            @php $sinRepresentante = $rep->vacio(); @endphp
            <x-ui.vacio icono="bi-person-hearts" :titulo="$sinRepresentante['titulo']">
                {{ $sinRepresentante['texto'] }}
            </x-ui.vacio>
        @endif
    </x-ui.tarjeta>
    @endif

    @include('clinica.pacientes._familia', ['familia' => $familia])

    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <x-ui.tarjeta titulo="Datos del paciente" icono="bi-person-vcard" class="h-100">
                <dl class="datos-rejilla">
                    <x-ui.dato etiqueta="Cédula" :valor="$vm->cedula()" />
                    <x-ui.dato etiqueta="N.º de historia" :valor="$vm->numeroDeHistoria()" />
                    <x-ui.dato etiqueta="Nacimiento" :valor="$vm->nacimiento()" />
                    <x-ui.dato etiqueta="Edad" :valor="$vm->edad()" />
                    <x-ui.dato etiqueta="Sexo" :valor="$vm->sexo()" />
                    <x-ui.dato etiqueta="Teléfono" :valor="$vm->telefono()" />
                    <x-ui.dato etiqueta="Correo" :valor="$vm->correo()" />
                    <x-ui.dato etiqueta="Dirección" :valor="$vm->direccion()" />
                    <x-ui.dato etiqueta="Profesión" :valor="$vm->profesion()" />
                    <x-ui.dato etiqueta="Ocupación" :valor="$vm->ocupacion()" />
                    <x-ui.dato etiqueta="Seguro / empresa" :valor="$vm->seguro()" />
                </dl>
            </x-ui.tarjeta>
        </div>

        <div class="col-12 col-lg-6">
            <x-ui.tarjeta titulo="Consultas" icono="bi-journal-text" sin-relleno class="h-100">
                @if ($vm->totalConsultas() === 0)
                    @php $sin = $vm->sinConsultas(); @endphp
                    <x-ui.vacio :icono="$sin['icono']" :titulo="$sin['titulo']">{{ $sin['texto'] }}</x-ui.vacio>
                @else
                    <ol class="linea-tiempo">
                        @foreach ($vm->consultas() as $consulta)
                            <li>
                                <div class="linea-tiempo-cabeza">
                                    <strong>{{ $consulta['fecha'] }}</strong>
                                    <span class="texto-tenue small">{{ $consulta['numero'] }}</span>
                                </div>
                                @if ($consulta['resumen'])
                                    <p class="linea-tiempo-texto">{{ $consulta['resumen'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.tarjeta>
        </div>
    </div>

    {{-- El formulario del representante, en un diálogo: la ficha no cambia de pantalla para cargar un dato
         de contacto. El botón del bloque lo abre (`public/js/clinica/representante.js`) y, si la validación
         falló, la página lo abre sola para que el error se lea (guía de UI §3). --}}
    @include('clinica.pacientes._dialogo-representante', ['rep' => $representante])
    @if ($familia->cargada() && $familia->puedeEditar())
        @include('clinica.pacientes._dialogo-familia', ['familia' => $familia])
    @endif

    {{-- «¿Atender ahora?» (O-05): sólo en el momento en que el paciente acaba de quedar atendible —después del
         alta o de asignarle la historia— y sólo para quien trabaja la consulta. La pregunta va después de la
         acción que la provoca y no antes: el paciente ya tiene su historia. --}}
    @if ($vm->ofreceAtender())
        @include('clinica.pacientes._dialogo-atender', ['vm' => $vm])
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/representante.js') }}"></script>
    @if ($familia->cargada() && $familia->puedeEditar())
        <script src="{{ asset('js/clinica/familia.js') }}"></script>
    @endif
    @if ($vm->ofreceAtender())
        <script src="{{ asset('js/clinica/atender-paciente.js') }}"></script>
    @endif
@endsection
