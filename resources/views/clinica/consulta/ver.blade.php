@extends('clinica.layout')

@section('titulo', 'Consulta')

@section('contenido')
    {{-- El encabezado con lo que identifica la atención: el paciente, su historia, la consulta y el día
         (wiki 04-historia-clinica.md). El título es el paciente y no el módulo porque lo que el médico
         necesita al entrar es confirmar **a quién** está atendiendo. --}}
    <x-ui.page-head :titulo="$vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlVolver()" volver-texto="Volver a la agenda">
        <x-slot name="acciones">
            {{-- La barra tiene **dos** acciones (revisión de Alexander, 2026-10-17): el resumen del paciente
                 —a la izquierda, secundario— y "Terminar la consulta", que es la acción primaria y va a la
                 derecha (guía de UI §1). «Ver la ficha» se quitó: el médico llega a la ficha por el ítem
                 *Datos personales* del panel. --}}
            @if ($vm->urlHistoria())
                <a class="btn btn-outline-secondary" href="{{ $vm->urlHistoria() }}">
                    <i class="bi bi-journal-medical" aria-hidden="true"></i> Ver resumen
                </a>
            @endif
            {{-- La **acción primaria** de la pantalla (guía de UI §1: un solo botón sólido): terminar la
                 atención. Es lo que el escritorio hace al cerrar `w_consulta_principal` —la cita ya había
                 quedado `atendido` en su `open`—, así que no hay ningún estado nuevo que inventar: la
                 consulta se cierra, la cita de hoy queda atendida y se vuelve a la agenda del día. Pide
                 confirmación con el mecanismo de `shell.js` (`data-confirmar`), sin diálogo propio ni JS
                 nuevo: la acción es irreversible y la pantalla lo dice con el texto del ViewModel. --}}
            @if ($vm->urlTerminar())
                <form method="POST" action="{{ $vm->urlTerminar() }}" data-confirmar="{{ $vm->avisoTerminar() }}">
                    @csrf
                    <button class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Terminar la consulta
                    </button>
                </form>
            @endif
        </x-slot>
    </x-ui.page-head>

    {{-- Los errores de validación de la sección (el nombre del motivo nuevo) van arriba y no dentro del
         diálogo: el diálogo se vuelve a abrir solo, pero el aviso tiene que verse aunque no se abra. --}}
    <x-ui.errores />

    {{-- Las alertas del paciente (WEB-11.6): un aviso de seguridad clínica, fijo en todas las secciones, con **todas** las alertas. Al darle
         «Atender» se abre además un diálogo que se cierra con «Entendido» y no reaparece en cada sección. La secretaría no ve esto (la
         consulta es sólo del médico). --}}
    @if ($vm->hayAlertas())
        <div class="consulta-alertas" role="alert" id="consulta-alertas">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div>
                <strong>Alertas de este paciente</strong>
                <ul class="consulta-alertas-lista">
                    @foreach ($vm->alertas() as $alerta)
                        <li>{{ $alerta['nombre'] }}@if ($alerta['detalle']) <span class="texto-tenue">— {{ $alerta['detalle'] }}</span>@endif</li>
                    @endforeach
                </ul>
            </div>
        </div>

        <x-ui.dialogo id="dialogo-alertas" titulo="Alertas de este paciente" :data-abrir-al-cargar="$vm->avisarAlertas() ? '1' : null">
            <div class="dialogo-cuerpo">
                <p class="mb-2">Antes de empezar la consulta, ten presente:</p>
                <ul class="consulta-alertas-lista">
                    @foreach ($vm->alertas() as $alerta)
                        <li><strong>{{ $alerta['nombre'] }}</strong>@if ($alerta['detalle']) — {{ $alerta['detalle'] }}@endif</li>
                    @endforeach
                </ul>
            </div>
            <div class="dialogo-pie">
                <button type="button" class="btn btn-primary" data-cerrar-dialogo>Entendido</button>
            </div>
        </x-ui.dialogo>
    @endif

    {{-- La vista previa («Visualizar», WEB-11.24): un solo diálogo para las solicitudes, los récipes y los ecos. --}}
    @include('clinica.consulta._visualizar')

    {{-- La barra de la consulta (WEB-11.5): el navegador 1…N entre las consultas del paciente y, en un lugar fijo, Guardar / Guardar y salir /
         Salir con el indicador de guardado. Las secciones de texto se guardan solas al pausar la escritura y al cambiar de sección o de
         consulta (`consulta-barra.js`); las que cargan listas guardan al instante y no necesitan el botón. --}}
    <div class="consulta-barra" data-consulta-barra data-url-salir="{{ $vm->urlVolver() }}">
        @if ($vm->hayNavegador())
            <nav class="consulta-barra-navegador" aria-label="Consultas del paciente">
                <span class="etiqueta">Consulta</span>
                <x-ui.chips :opciones="$vm->navegador()" etiqueta="Consultas de este paciente" />
            </nav>
        @endif
        <div class="consulta-barra-acciones">
            <span class="consulta-barra-estado texto-tenue small" data-barra-estado role="status" aria-live="polite"></span>
            <button type="button" class="btn btn-outline-secondary" data-barra-guardar>
                <i class="bi bi-save" aria-hidden="true"></i> Guardar
            </button>
            <button type="button" class="btn btn-outline-secondary" data-barra-guardar-salir>
                <i class="bi bi-box-arrow-left" aria-hidden="true"></i> Guardar y salir
            </button>
            <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}" data-barra-salir>
                <i class="bi bi-x-lg" aria-hidden="true"></i> Salir
            </a>
        </div>
    </div>

    <div class="row g-3">
        {{-- El área de trabajo ocupa **todo el ancho** (revisión de Alexander): los ítems de la consulta se eligen en el
             grupo «Consulta» del menú lateral y la tarjeta «Secciones de la consulta» ya no existe. El contenido llega
             por los parciales —`_motivos` y `_enfermedad` (WEB-4.2), `_evaluacion` (WEB-4.3), `_antecedentes` (WEB-4.4),
             `_diagnostico` (WEB-4.5), `_evolucion` (WEB-4.6), `_examenes` (WEB-4.7), `_recipes` (WEB-5.1), etc.— y el
             nombre del parcial lo resuelve el ViewModel a partir del catálogo de secciones. --}}
        <div class="col-12">
            @if ($contenido && $vm->parcial())
                @include($vm->parcial(), ['seccion' => $contenido, 'catalogo' => $catalogo ?? null, 'imagenes' => $imagenes ?? null])
            @else
                <x-ui.tarjeta id="consulta-contenido" titulo="Contenido de la consulta" icono="bi-journal-medical">
                    @php $vacio = $vm->sinContenido(); @endphp
                    <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
                </x-ui.tarjeta>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/consulta.js') }}"></script>
    {{-- El editor de récipes (WEB-5.1): el buscador del vademécum y de los tratamientos, las filas del
         récipe y el aviso de cambios sin guardar. Se inicializa sólo si la sección está en pantalla. --}}
    <script src="{{ asset('js/clinica/consulta-recipes.js') }}"></script>
    {{-- El diálogo «Imprimir récipe» de la sección (WEB-5.2): sólo se inicializa si hay un botón que lo abra. --}}
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
    {{-- Las solicitudes de la consulta (WEB-5.3a): cambios sin guardar, plantillas, fechas del reposo y buscador de médicos. --}}
    <script src="{{ asset('js/clinica/consulta-solicitudes.js') }}"></script>
    {{-- Las órdenes de laboratorio y de estudios (WEB-5.4): la lista de la orden, el buscador del catálogo, las rutinas y el aviso de cambios sin guardar. --}}
    <script src="{{ asset('js/clinica/consulta-ordenes.js') }}"></script>
    {{-- El panel lateral de plantillas con buscador (WEB-11.2): evolución, solapas de texto del examen, dieta e informe. --}}
    <script src="{{ asset('js/clinica/plantillas-panel.js') }}"></script>
    {{-- Las «dos listas» (WEB-11.3): el doble clic en el catálogo pasa el elemento a la consulta. --}}
    <script src="{{ asset('js/clinica/dos-listas.js') }}"></script>
    {{-- «Imprimir la ficha» de los datos personales (WEB-11.25). --}}
    <script src="{{ asset('js/clinica/consulta-datos.js') }}"></script>
    {{-- El diálogo de alertas (WEB-11.6): se abre solo cuando toca avisar. --}}
    <script src="{{ asset('js/clinica/consulta-alertas.js') }}"></script>
    {{-- «Actualizar» del examen (WEB-11.8): arma los resúmenes físico y ginecológico. --}}
    <script src="{{ asset('js/clinica/consulta-resumen.js') }}"></script>
    <script src="{{ asset('js/clinica/mamas-lienzo.js') }}"></script>
    <script src="{{ asset('js/clinica/consulta-visualizar.js') }}"></script>
    {{-- La barra (WEB-11.5): Guardar, Guardar y salir, autoguardado y el aviso al cambiar de sección o de consulta. --}}
    <script src="{{ asset('js/clinica/consulta-barra.js') }}"></script>
@endsection
