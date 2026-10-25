@extends('layouts.app')

@section('title', 'Doctorísimo · Agenda, historia clínica y récipes para tu consultorio')

@push('meta')
    <meta name="description" content="Software para médicos: agenda con recordatorios, historia clínica, récipes e informes listos en segundos. Tú atiendes al paciente; del papeleo nos encargamos nosotros.">
@endpush

@push('estilos')
    {{-- `landing-por-que.css` trae los marcos de las capturas (teléfono, lámina de la galería). --}}
    <link rel="stylesheet" href="{{ asset('css/landing-por-que.css') }}">
    <link rel="stylesheet" href="{{ asset('css/portada.css') }}">
@endpush

@php
    // «Prueba gratis 1 mes» / «2 meses»; sin prueba configurada, el botón sólo invita a empezar.
    $prueba = $mesesDePrueba > 0
        ? $mesesDePrueba . ' ' . ($mesesDePrueba === 1 ? 'mes' : 'meses')
        : null;
    $textoCta = $prueba ? 'Prueba gratis ' . $prueba : 'Solicita tu cuenta';

    // En la portada el precio va en grande y sin decimales si no los tiene: $25, $19.90.
    $precio = function ($monto) {
        $monto = (float) $monto;
        return '$' . number_format($monto, fmod($monto, 1.0) == 0.0 ? 0 : 2, '.', ',');
    };
@endphp

@section('content')
    {{-- 1. Lo primero que ve el médico: qué le resolvemos y el botón para empezar. --}}
    <section class="portada-hero" id="inicio">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="portada-etiqueta"><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i> Para médicos y consultorios</span>
                    <h1 class="portada-titulo">Tú atiendes al paciente. <span>Del papeleo nos encargamos nosotros.</span></h1>
                    <p class="portada-bajada">
                        Agenda, historia clínica, récipes e informes en un solo lugar. Todo a un clic, para que la
                        consulta sea entre tú y tu paciente, no con la computadora.
                    </p>

                    <div class="portada-acciones">
                        <a href="{{ route('medico.registro') }}" class="btn btn-light btn-lg portada-cta">
                            {{ $textoCta }} <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
                        </a>
                        <a href="#funciones" class="btn btn-lg portada-cta-secundario">Ver cómo funciona</a>
                    </div>

                    <ul class="portada-garantias">
                        <li><i class="bi bi-check2" aria-hidden="true"></i> Sin tarjeta para empezar</li>
                        <li><i class="bi bi-check2" aria-hidden="true"></i> Computadora, tableta y celular</li>
                        <li><i class="bi bi-check2" aria-hidden="true"></i> Catálogo de tu especialidad listo</li>
                    </ul>
                </div>

                {{-- Capturas reales de la aplicación con data sintética (`FakeClinicalDataSeeder`): la agenda del
                     consultorio en la web y la misma agenda en la app móvil. --}}
                <div class="col-lg-6 d-none d-lg-block">
                    <div class="portada-vista">
                        <div class="portada-ventana">
                            <div class="portada-ventana-barra" aria-hidden="true">
                                <span></span><span></span><span></span>
                                <em>Consultorio · Doctorísimo.App</em>
                            </div>
                            <img src="{{ asset('img/landing/agenda-consultorio.webp') }}"
                                 alt="Agenda del consultorio en Doctorísimo.App: citas del día, pacientes, estado y acciones."
                                 width="1600" height="982" fetchpriority="high" decoding="async">
                        </div>
                        <div class="portada-movil">
                            <img src="{{ asset('img/landing/agenda-movil.webp') }}"
                                 alt="La misma agenda en la app móvil de Doctorísimo.App: citas del día con paciente, motivo y estado."
                                 width="540" height="1140" decoding="async">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 2. El dolor del médico y lo que le quitamos de encima. --}}
    <section class="portada-seccion" id="funciones" aria-labelledby="funciones-titulo">
        <div class="container">
            <div class="portada-encabezado">
                <span class="portada-antetitulo">Menos papeleo, más consulta</span>
                <h2 id="funciones-titulo">Lo que te quitamos de encima</h2>
                <p>Escribir, buscar y ordenar le quita minutos a cada paciente. Doctorísimo hace esa parte por ti.</p>
            </div>

            <div class="row g-4">
                <div class="col-md-4">
                    <article class="portada-beneficio">
                        <span class="portada-icono"><i class="bi bi-calendar2-week" aria-hidden="true"></i></span>
                        <h3>La agenda se organiza sola</h3>
                        <p>Tu asistente agenda, confirma y cobra. Tus pacientes reciben recordatorios por WhatsApp, SMS o correo, y pueden reservar en línea.</p>
                    </article>
                </div>
                <div class="col-md-4">
                    <article class="portada-beneficio">
                        <span class="portada-icono"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
                        <h3>La historia de un paciente, en 1 clic</h3>
                        <p>Motivo, antecedentes, evaluación, diagnósticos, exámenes e imágenes de cada consulta, ordenados y a un clic mientras hablas con tu paciente.</p>
                    </article>
                </div>
                <div class="col-md-4">
                    <article class="portada-beneficio">
                        <span class="portada-icono"><i class="bi bi-file-earmark-medical" aria-hidden="true"></i></span>
                        <h3>Récipes e informes en segundos</h3>
                        <p>Plantillas y medicamentos de tu especialidad. Récipes, constancias, reposos, referencias e informes con tu formato y un código QR para verificarlos.</p>
                    </article>
                </div>
            </div>

            <ul class="portada-funciones">
                <li>
                    <i class="bi bi-buildings" aria-hidden="true"></i>
                    <div><strong>Varios consultorios</strong><span>Atiende en varias sedes o centros médicos con una sola cuenta.</span></div>
                </li>
                <li>
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <div><strong>Tu equipo, con su rol</strong><span>Tu asistente maneja la agenda y los cobros; la consulta es solo tuya.</span></div>
                </li>
                <li>
                    <i class="bi bi-phone" aria-hidden="true"></i>
                    <div><strong>App móvil sin internet</strong><span>Atiende desde el celular aunque se caiga la conexión: se sincroniza sola.</span></div>
                </li>
                <li>
                    <i class="bi bi-heart-pulse" aria-hidden="true"></i>
                    <div><strong>Hecho para tu especialidad</strong><span>Ecografías y control prenatal en ginecología; crecimiento y vacunas en pediatría.</span></div>
                </li>
                <li>
                    <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
                    <div><strong>Más citas, sin perseguirlas</strong><span>Publica tu agenda en el catálogo y recibe pacientes nuevos que reservan solos.</span></div>
                </li>
                <li>
                    <i class="bi bi-shield-check" aria-hidden="true"></i>
                    <div><strong>Tus datos, solo tuyos</strong><span>Respaldados en la nube y visibles solo para ti y tu equipo.</span></div>
                </li>
            </ul>

            {{-- Lo que el consultorio produce todos los días: las mismas láminas de la landing anterior. --}}
            <section class="porque-galeria" aria-labelledby="galeria-titulo">
                <header class="porque-galeria-encabezado">
                    <span class="portada-antetitulo">Por dentro</span>
                    <h3 id="galeria-titulo">Lo que sale de Doctorísimo.App</h3>
                    <p class="porque-galeria-intro">
                        No es solo la agenda: son las curvas de crecimiento, los récipes y los informes que tu
                        consultorio produce todos los días, listos para imprimir o compartir.
                    </p>
                </header>

                <div class="porque-galeria-grid">
                    <figure class="porque-lamina">
                        <div class="porque-lamina-marco">
                            <img src="{{ asset('img/landing/crecimiento.webp') }}"
                                 alt="Curvas de crecimiento pediátrico con los percentiles de Fundacredesa."
                                 width="1016" height="1152" loading="lazy" decoding="async">
                        </div>
                        <figcaption>
                            <span>Pediatría</span>
                            <strong>Curvas de crecimiento</strong>
                            <p>Percentiles de Fundacredesa en pantalla y en la hoja impresa, con la medición de cada control.</p>
                        </figcaption>
                    </figure>

                    <figure class="porque-lamina">
                        <div class="porque-lamina-marco">
                            <img src="{{ asset('img/landing/recipe.webp') }}"
                                 alt="Récipe impreso con el membrete del médico, dos medicamentos y el QR de verificación."
                                 width="562" height="792" loading="lazy" decoding="async">
                        </div>
                        <figcaption>
                            <span>Récipes</span>
                            <strong>Récipes listos para imprimir</strong>
                            <p>Con membrete, indicaciones y un QR para verificar que el récipe es auténtico.</p>
                        </figcaption>
                    </figure>

                    <figure class="porque-lamina">
                        <div class="porque-lamina-marco">
                            <img src="{{ asset('img/landing/eco.webp') }}"
                                 alt="Informe de ecografía obstétrica del segundo trimestre con biometría, anatomía y conclusión."
                                 width="794" height="900" loading="lazy" decoding="async">
                        </div>
                        <figcaption>
                            <span>Ecografía</span>
                            <strong>Informes de ecografía</strong>
                            <p>Biometría, anatomía y conclusión en la hoja del segundo trimestre, lista para entregar.</p>
                        </figcaption>
                    </figure>
                </div>
            </section>
        </div>
    </section>

    {{-- 3. Cómo se empieza: tres pasos, sin sorpresas (el registro pasa por una aprobación). --}}
    <section class="portada-seccion portada-seccion-suave" id="como-funciona" aria-labelledby="como-funciona-titulo">
        <div class="container">
            <div class="portada-encabezado">
                <span class="portada-antetitulo">Empezar es fácil</span>
                <h2 id="como-funciona-titulo">De la solicitud a tu primera consulta</h2>
            </div>

            <ol class="portada-pasos">
                <li>
                    <span class="portada-paso-numero">1</span>
                    <h3>Envía tu solicitud</h3>
                    <p>Tus datos profesionales en unos minutos. Confirmas tu correo con un código.</p>
                </li>
                <li>
                    <span class="portada-paso-numero">2</span>
                    <h3>Activamos tu cuenta</h3>
                    <p>Revisamos tus datos y te avisamos por correo. Tu catálogo de especialidad ya viene cargado.</p>
                </li>
                <li>
                    <span class="portada-paso-numero">3</span>
                    <h3>Atiende</h3>
                    <p>Agenda tu primera cita y deja el papeleo al sistema.@if ($prueba) El primer {{ $mesesDePrueba === 1 ? 'mes es' : $prueba . ' son' }} gratis.@endif</p>
                </li>
            </ol>

            {{-- Los médicos del escritorio (GinecoReport, NeuroReport, Agenda Integral): su data sube a la nube y
                 reciben el año de cortesía (`ServicioService::otorgarCortesiaPowerBuilder`). --}}
            <div class="portada-migracion">
                <div>
                    <h3>¿Usas GinecoReport, NeuroReport o Agenda Integral?</h3>
                    <p>Tus pacientes y tus historias pasan a Doctorísimo sin perder nada, y tienes <strong>un año de cortesía</strong>.</p>
                </div>
                <a href="{{ route('medico.registro') }}" class="btn btn-primary">Solicita tu cuenta</a>
            </div>
        </div>
    </section>

    {{-- 4. Planes: los mismos de `/clinica/plan`. Sin planes visibles, la sección no sale. --}}
    @if ($planes->isNotEmpty())
        <section class="portada-seccion" id="planes" aria-labelledby="planes-titulo">
            <div class="container">
                <div class="portada-encabezado">
                    <span class="portada-antetitulo">Planes</span>
                    <h2 id="planes-titulo">Simple y sin sorpresas</h2>
                    @if ($prueba)
                        <p>El primer {{ $mesesDePrueba === 1 ? 'mes' : $prueba }} es gratis en cualquier plan. Pagas solo si te quedas.</p>
                    @endif
                </div>

                <div class="row g-4 justify-content-center">
                    @foreach ($planes as $plan)
                        @php
                            $ahorro = $plan->ahorroUsd();
                            $maxMedicos = $plan->restricciones->maxMedicos();
                            $maxHistorico = $plan->restricciones->maxHistoricoMeses();
                        @endphp
                        <div class="col-md-6 col-lg-4">
                            <article class="portada-plan {{ $ahorro > 0 ? 'portada-plan-destacado' : '' }}">
                                @if ($ahorro > 0)
                                    <span class="portada-plan-sello">Ahorras {{ $precio($ahorro) }}</span>
                                @endif
                                <h3>{{ $plan->nombre }}</h3>
                                @if ($plan->descripcion)
                                    <p class="portada-plan-descripcion">{{ $plan->descripcion }}</p>
                                @endif
                                <p class="portada-plan-precio">
                                    @if ($ahorro > 0)
                                        <del>{{ $precio($plan->precio_tachado_usd) }}</del>
                                    @endif
                                    <strong class="tnum">{{ $precio($plan->precio_usd) }}</strong>
                                    <span>USD / {{ $plan->frecuencia === \App\Models\Plan::ANUAL ? 'año' : 'mes' }}</span>
                                </p>
                                <ul>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> Agenda, historia clínica y récipes</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i> App móvil sin internet</li>
                                    <li><i class="bi bi-check2" aria-hidden="true"></i>
                                        {{ $maxMedicos ? 'Hasta ' . $maxMedicos . ' ' . ($maxMedicos === 1 ? 'médico' : 'médicos') : 'Médicos sin límite' }}
                                    </li>
                                    @if ($maxHistorico)
                                        <li><i class="bi bi-check2" aria-hidden="true"></i> Histórico de {{ $maxHistorico }} {{ $maxHistorico === 1 ? 'mes' : 'meses' }}</li>
                                    @endif
                                </ul>
                                <a href="{{ route('medico.registro') }}" class="btn {{ $ahorro > 0 ? 'btn-primary' : 'btn-outline-primary' }} w-100">{{ $textoCta }}</a>
                            </article>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- 5. Para el paciente (secundario): una línea y las especialidades, que llevan al portal de reservas. --}}
    <section class="portada-seccion portada-seccion-suave" id="pacientes" aria-labelledby="pacientes-titulo">
        <div class="container">
            <div class="portada-pacientes">
                <div>
                    <span class="portada-antetitulo">Para pacientes</span>
                    <h2 id="pacientes-titulo">¿Buscas médico?</h2>
                    <p>Encuentra a tu especialista y reserva sin llamadas: eliges horario y confirmas con un código en tu teléfono. Sin crear una cuenta.</p>
                    <ul class="portada-pacientes-lista">
                        <li><i class="bi bi-bell" aria-hidden="true"></i> Recordatorios antes de tu cita</li>
                        <li><i class="bi bi-credit-card-2-front" aria-hidden="true"></i> Paga en línea, sin efectivo</li>
                        <li><i class="bi bi-calendar2-week" aria-hidden="true"></i> Reprograma o cancela desde tu cuenta</li>
                    </ul>
                    <div class="portada-pacientes-acciones">
                        <a href="{{ route('reservas.catalogo') }}" class="btn btn-outline-primary">
                            <i class="bi bi-calendar2-check me-1" aria-hidden="true"></i> Reservar cita
                        </a>
                        <a href="{{ route('paciente.citas') }}" class="portada-enlace">Ver mis citas <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                    @if ($especialidades->isNotEmpty())
                        <nav class="portada-especialidades" aria-label="Reservar por especialidad">
                            @foreach ($especialidades as $especialidad)
                                <a href="{{ route('reservas.catalogo', ['especialidad' => $especialidad->slug]) }}">{{ $especialidad->name }}</a>
                            @endforeach
                        </nav>
                    @endif
                </div>
                <div class="porque-visual d-none d-md-flex">
                    <div class="porque-lienzo porque-lienzo-paciente">
                        <div class="porque-telefono">
                            <div class="porque-telefono-estado" aria-hidden="true">
                                <span>9:41</span>
                                <span class="porque-telefono-iconos">
                                    <i class="bi bi-reception-4"></i>
                                    <i class="bi bi-wifi"></i>
                                    <i class="bi bi-battery-full"></i>
                                </span>
                            </div>
                            <img src="{{ asset('img/landing/catalogo-movil.webp') }}"
                                 alt="Catálogo público de Doctorísimo.App en el celular: especialidades y médicos con agenda publicada."
                                 width="780" height="1688" loading="lazy" decoding="async">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- 6. Cierre: el mismo botón del principio. --}}
    <section class="portada-cierre">
        <div class="container">
            <h2>Dedica tu tiempo a lo que importa: <span>tus pacientes.</span></h2>
            <a href="{{ route('medico.registro') }}" class="btn btn-light btn-lg portada-cta">
                {{ $textoCta }} <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
            </a>
            <p>¿Ya tienes cuenta? <a href="{{ route('login') }}">Ingresa aquí</a></p>
        </div>
    </section>

    <footer class="footer-gineco py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-5">
                    <h2 class="h5 fw-bold mb-3"><i class="bi bi-heart-pulse-fill me-2" style="color: var(--ds-violeta-300);" aria-hidden="true"></i>Doctorísimo</h2>
                    <p class="small text-muted mb-0">Agenda, historia clínica y récipes para tu consultorio. Tú atiendes; del papeleo nos encargamos nosotros.</p>
                </div>
                <div class="col-6 col-lg-3">
                    <h3 class="h6 fw-bold mb-3">Para médicos</h3>
                    <ul class="list-unstyled small mb-0">
                        <li class="mb-2"><a href="{{ route('medico.registro') }}">Solicita tu cuenta</a></li>
                        <li class="mb-2"><a href="{{ route('login') }}">Ingresar</a></li>
                        <li class="mb-2"><a href="{{ url('/') }}#funciones">Funciones</a></li>
                        @if ($planes->isNotEmpty())
                            <li class="mb-2"><a href="{{ url('/') }}#planes">Planes</a></li>
                        @endif
                    </ul>
                </div>
                <div class="col-6 col-lg-4">
                    <h3 class="h6 fw-bold mb-3">Para pacientes</h3>
                    <ul class="list-unstyled small mb-0">
                        <li class="mb-2"><a href="{{ route('reservas.catalogo') }}">Reservar cita</a></li>
                        <li class="mb-2"><a href="{{ route('paciente.citas') }}">Mis citas</a></li>
                    </ul>
                    <h3 class="h6 fw-bold mb-2 mt-4">Contacto</h3>
                    <p class="small mb-0"><i class="bi bi-envelope me-2" aria-hidden="true"></i><a href="mailto:soporte@ginecoreport.com">soporte@ginecoreport.com</a></p>
                </div>
            </div>
            <hr class="my-4 border-secondary">
            <p class="text-center small text-muted mb-0">&copy; {{ date('Y') }} Doctorísimo · DDR Sistemas, C.A.</p>
        </div>
    </footer>
@endsection
