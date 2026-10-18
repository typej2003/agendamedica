@extends('clinica.layout')

@section('titulo', 'Consulta')

@section('contenido')
    {{-- **La fila del encabezado de la consulta** (A-11, decisión de Alexander): una sola línea con lo que identifica la
         atención —el nombre del paciente, su historia, la consulta y la fecha (wiki 04-historia-clinica.md)—, el
         navegador 1…N entre las consultas de la historia y las dos acciones de la pantalla. Antes había tres bloques
         (la barra de contexto del armazón, el encabezado del paciente y la barra de la consulta) que ocupaban ~185 px
         en una pantalla de 768; ahora es una fila de ~48 px.

         - **«Volver a la agenda» es la flecha** del principio: el enlace guarda lo escrito antes de salir
           (`consulta-barra.js`, `data-barra-salir`).
         - **El nombre ya no es un título grande**: tipografía normal y una línea; si no entra se recorta con puntos
           suspensivos y el `title` lleva el texto completo.
         - **Las acciones son Guardar y Terminar la consulta.** «Guardar y salir» y «Salir» se fueron: el autoguardado
           (`consulta-barra.js`) guarda al pausar la escritura y al cambiar de sección o de consulta, y «Terminar la
           consulta» cierra la atención y vuelve a la agenda. «Ver resumen» también se fue: el resumen del paciente se ve
           desde su ficha (Pacientes).
         - **El indicador de cambios sin guardar** vive en esta misma fila (`data-barra-estado`). --}}
    <div class="consulta-encabezado" data-consulta-barra data-tour="consulta-encabezado">
        @if ($vm->urlVolver())
            <a class="consulta-volver" href="{{ $vm->urlVolver() }}" data-barra-salir
               title="Volver a la agenda" aria-label="Volver a la agenda">
                <i class="bi bi-arrow-left" aria-hidden="true"></i>
            </a>
        @endif

        <p class="consulta-identidad" title="{{ $vm->lineaDeCabecera() }}">
            <span class="consulta-paciente">{{ $vm->nombre() }}</span>
            <span class="consulta-datos"><span aria-hidden="true">·</span> {{ $vm->subtitulo() }}</span>
        </p>

        {{-- El navegador 1…N (WEB-11.5), compacto: menos ancho, sin el rótulo «CONSULTA» y con los números solos. Cambiar
             de consulta guarda antes lo escrito (`consulta-barra.js`, `[data-chips]`). --}}
        @if ($vm->hayNavegador())
            <nav class="consulta-barra-navegador" aria-label="Consultas del paciente" data-tour="consulta-navegador">
                <x-ui.chips :opciones="$vm->navegador()" etiqueta="Consultas de este paciente" />
            </nav>
        @endif

        <div class="consulta-barra-acciones">
            <span class="consulta-barra-estado texto-tenue small" data-barra-estado role="status" aria-live="polite"></span>
            <button type="button" class="btn btn-outline-secondary" data-barra-guardar data-tour="consulta-guardar">
                <i class="bi bi-save" aria-hidden="true"></i> Guardar
            </button>
            {{-- La **acción primaria** de la pantalla (guía de UI §1: un solo botón sólido): terminar la atención. Es lo
                 que el escritorio hace al cerrar `w_consulta_principal` —la cita ya había quedado `atendido` en su
                 `open`—, así que no hay ningún estado nuevo que inventar: la consulta se cierra, la cita de hoy queda
                 atendida y se vuelve a la agenda del día. Pide confirmación con `data-confirmar` de `shell.js` (la
                 acción es irreversible) y `consulta-barra.js` guarda lo escrito antes de enviarlo. --}}
            @if ($vm->urlTerminar())
                <form method="POST" action="{{ $vm->urlTerminar() }}" data-confirmar="{{ $vm->avisoTerminar() }}"
                      data-barra-terminar>
                    @csrf
                    <button class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Terminar la consulta
                    </button>
                </form>
            @endif
            {{-- **La ayuda de la pantalla** (O-31): el botón redondo con «?» que reabre el tour, al final de la
                 fila y junto a Guardar y Terminar. No tiene fila propia: la de ~49 px de §9.5 no crece, que es
                 justo el alto que se ganó al quitar la barra de contexto. El tour sigue abriéndose solo la
                 primera vez (`tours_vistos`). `$tour` lo deja `TourDeLaPantallaComposer`, que también compone
                 esta vista para que el botón exista. --}}
            @if (! empty($tour) && $tour->hayPasos() && $tour->enElEncabezado())
                @include('clinica._tour-boton', ['redondo' => true])
            @endif
        </div>
    </div>

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

    {{-- El aviso de vacunas pendientes (WEB-7.4.4d), sólo pediatría: discreto —no es la alerta de antecedentes— y sin diálogo. --}}
    @if ($avisoVacunas)
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 py-2" role="status" id="consulta-aviso-vacunas">
            <i class="bi bi-capsule" aria-hidden="true"></i>
            <span>{{ $avisoVacunas['texto'] }}</span>
            <a href="{{ $avisoVacunas['url'] }}" class="ms-auto">Ver el calendario</a>
        </div>
    @endif

    {{-- La vista previa («Visualizar», WEB-11.24): un solo diálogo para las solicitudes, los récipes y los ecos. --}}
    @include('clinica.consulta._visualizar')

    <div class="row g-3">
        {{-- El área de trabajo ocupa **todo el ancho** (revisión de Alexander): los ítems de la consulta se eligen en el
             grupo «Consulta» del menú lateral y la tarjeta «Secciones de la consulta» ya no existe. El contenido llega
             por los parciales —`_motivos` y `_enfermedad` (WEB-4.2), `_evaluacion` (WEB-4.3), `_antecedentes` (WEB-4.4),
             `_diagnostico` (WEB-4.5), `_evolucion` (WEB-4.6), `_recipes` (WEB-5.1), etc.— y el
             nombre del parcial lo resuelve el ViewModel a partir del catálogo de secciones. --}}
        <div class="col-12">
            @if ($contenido && $vm->parcial())
                @include($vm->parcial(), ['seccion' => $contenido, 'catalogo' => $catalogo ?? null, 'imagenes' => $imagenes ?? null, 'imagen' => $imagen ?? null])
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
    {{-- La máscara de los números de la evaluación física (O-08): la talla, el peso, las circunferencias, las
         frecuencias y la tensión. Va **antes** que `consulta.js` a propósito: ese script toma el valor inicial de
         cada control para saber si hay cambios sin guardar, y lo tiene que tomar ya formateado (`1,62`, no el
         `1.62` de la base) o el formulario aparecería sucio apenas se abre. --}}
    <script src="{{ asset('js/clinica/mascara-numero.js') }}"></script>
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
    {{-- Las mediciones de pediatría (WEB-7.4.3a): el IMC y el AC se recalculan al escribir el peso o la talla. --}}
    <script src="{{ asset('js/clinica/consulta-mediciones.js') }}"></script>
    {{-- El panel lateral de plantillas con buscador (WEB-11.2): evolución, solapas de texto del examen, dieta e informe. --}}
    <script src="{{ asset('js/clinica/plantillas-panel.js') }}"></script>
    {{-- Las plantillas del **formato** del estudio (O-17): completan sólo los campos vacíos del eco y guardan
         los campos descriptivos como plantilla, sin pisar lo escrito. --}}
    <script src="{{ asset('js/clinica/plantillas-formato.js') }}"></script>
    {{-- Las «dos listas» (WEB-11.3): el doble clic en el catálogo pasa el elemento a la consulta. --}}
    <script src="{{ asset('js/clinica/dos-listas.js') }}"></script>
    {{-- «Imprimir la ficha» de los datos personales (WEB-11.25). --}}
    <script src="{{ asset('js/clinica/consulta-datos.js') }}"></script>
    {{-- El diálogo de alertas (WEB-11.6): se abre solo cuando toca avisar. --}}
    <script src="{{ asset('js/clinica/consulta-alertas.js') }}"></script>
    {{-- «Actualizar» del examen (WEB-11.8): arma los resúmenes físico y ginecológico. --}}
    <script src="{{ asset('js/clinica/consulta-resumen.js') }}"></script>
    {{-- El motor de los lienzos (A-13): la misma herramienta de dibujo para las mamas (WEB-11.10) y la imagen del examen
         (colposcopía e histeroscopía, O-09). Las pantallas lo inicializan con sus selectores; acá va antes que sus
         envoltorios. --}}
    <script src="{{ asset('js/clinica/lienzo.js') }}"></script>
    <script src="{{ asset('js/clinica/mamas-lienzo.js') }}"></script>
    <script src="{{ asset('js/clinica/imagen-del-examen.js') }}"></script>
    <script src="{{ asset('js/clinica/consulta-visualizar.js') }}"></script>
    {{-- La fila del encabezado (A-11): Guardar, el autoguardado y el aviso al cambiar de sección o de consulta. --}}
    <script src="{{ asset('js/clinica/consulta-barra.js') }}"></script>
    {{-- Las fechas que se teclean (O-18 y O-19): la máscara de la FUR pone las barras y no deja escribir un
         mes 13 ni un día 32. El dato viaja y se guarda en `AAAA-MM-DD`. --}}
    <script src="{{ asset('js/clinica/mascara-fecha.js') }}"></script>
@endsection
