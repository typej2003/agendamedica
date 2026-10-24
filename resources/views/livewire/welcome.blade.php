@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/landing-por-que.css') }}">
@endpush

<div>
    <!-- Hero Section con Componente Livewire Integrado -->
    <section class="hero-section" id="inicio">
        <div class="container">
            <div class="text-center max-w-720 mx-auto mb-5">
                <span class="badge badge-gineco mb-3"><i class="bi bi-shield-check me-1"></i> Atención Especializada 24/7</span>
                <h1 class="display-4 hero-title mb-3">Encuentra a tu <span>Especialista</span> y Agenda en Minutos</h1>
                <p class="lead text-muted">Conectamos a pacientes con los mejores profesionales en ginecología, obstetricia y salud integral.</p>
                <a href="{{ route('reservas.catalogo') }}" class="btn btn-primary btn-lg rounded-pill px-4 mt-2">
                    <i class="bi bi-calendar2-check me-2" aria-hidden="true"></i>Reservar mi cita
                </a>
            </div>

            <!-- Invocación del Componente Livewire de Búsqueda -->
            <div id="busqueda">
                @livewire('components.search-specialties')
            </div>
        </div>
    </section>

    <!-- Sección de Especialidades en Cards -->
    <section class="py-5 bg-light" id="especialidades">
        <div class="container">
            <div class="text-center mb-5">
                <span class="text-primary fw-bold text-uppercase small">Nuestra Red</span>
                <h2 class="fw-bold display-6 mb-2">Especialidades Médicas</h2>
                <p class="text-muted">Selecciona una especialidad para conocer los médicos especialistas disponibles</p>
            </div>

            <!-- Cards de Especialidades dentro de Forms -->
            <div class="row g-4 mb-5">
                @foreach($especialidades as $esp)
                    @php
                        $esActiva = optional($especialidadSeleccionada)->id === $esp->id;
                        $slug = \Illuminate\Support\Str::slug($esp->name);
                        
                        // Selección de icono característico según el nombre de la especialidad
                        $icono = match (true) {
                            str_contains($slug, 'ginecolog') => 'bi-gender-female',
                            str_contains($slug, 'obstet')    => 'bi-heart-pulse',
                            str_contains($slug, 'pediatr')   => 'bi-emoji-smile',
                            str_contains($slug, 'cardiolog') => 'bi-activity',
                            str_contains($slug, 'dermatolog')=> 'bi-sun',
                            str_contains($slug, 'neurolog')  => 'bi-cpu',
                            str_contains($slug, 'oftalmolog')=> 'bi-eye',
                            default                          => 'bi-hospital',
                        };
                    @endphp
                    <div class="col-6 col-md-4 col-lg-3">
                        <form wire:submit.prevent="buscarPorEspecialidad({{ $esp->id }})">
                            <button type="submit" class="card h-100 border-0 shadow-sm text-center p-3 w-100 {{ $esActiva ? 'bg-primary text-white shadow' : 'bg-white text-dark' }}"
                                    style="cursor: pointer; border-radius: 1rem; transition: all 0.25s ease-in-out; text-decoration: none;">
                                <div class="card-body d-flex flex-column align-items-center justify-content-center p-2 w-100">
                                    <div class="rounded-circle p-3 mb-3 d-flex align-items-center justify-content-center {{ $esActiva ? 'bg-white text-primary' : 'bg-light text-primary' }}" style="width: 65px; height: 65px;">
                                        <i class="bi {{ $icono }} fs-2"></i>
                                    </div>
                                    <h5 class="fw-bold fs-6 mb-2 {{ $esActiva ? 'text-white' : 'text-dark' }}">{{ $esp->name }}</h5>
                                    <span class="badge {{ $esActiva ? 'bg-white text-primary' : 'bg-light text-secondary border' }} rounded-pill px-3 py-1">
                                        {{ $esp->medicos_count }} {{ Str::plural('médico', $esp->medicos_count) }}
                                    </span>
                                </div>
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>

            <!-- Despliegue de Médicos Filtrados por Especialidad -->
            @if($especialidadSeleccionada)
                <div class="bg-white p-4 rounded-4 shadow-sm border mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                        <div>
                            <span class="badge bg-primary-soft text-primary px-3 py-2 rounded-pill mb-2">Filtro Activo</span>
                            <h3 class="fw-bold mb-0 text-dark">Doctores en <span class="text-primary">{{ $especialidadSeleccionada->name }}</span></h3>
                        </div>
                        <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" wire:click="limpiarFiltro">
                            <i class="bi bi-x-circle me-1"></i> Mostrar todas las especialidades
                        </button>
                    </div>

                    @if($especialidadSeleccionada->medicos->count() > 0)
                        <div class="row g-4">
                            @foreach($especialidadSeleccionada->medicos as $medico)
                                <div class="col-md-6 col-lg-4">
                                    <div class="card h-100 border-0 shadow-sm rounded-4 text-center p-3">
                                        <div class="card-body p-3">
                                            <div class="avatar-container mb-3 mx-auto bg-light rounded-circle d-flex align-items-center justify-content-center text-secondary" style="width: 80px; height: 80px;">
                                                <i class="bi bi-person-badge fs-1"></i>
                                            </div>
                                            <h5 class="fw-bold mb-1 text-dark">{{ $medico->name }}</h5>
                                            <p class="text-primary fw-medium small mb-2">{{ $especialidadSeleccionada->name }}</p>
                                            
                                            @if($medico->medicalCenter)
                                                <p class="text-muted small mb-3">
                                                    <i class="bi bi-geo-alt-fill text-danger me-1"></i>{{ $medico->medicalCenter->name ?? 'Centro Médico' }}
                                                </p>
                                            @endif

                                            <a href="{{ route('reservas.catalogo', ['especialidad' => $especialidadSeleccionada->slug]) }}" class="btn btn-primary btn-sm rounded-pill w-100 fw-bold py-2" style="background-color: var(--purple-wifiexpres); border-color: var(--purple-wifiexpres);">
                                                <i class="bi bi-calendar-event me-1"></i> Ver horarios
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="alert alert-light border text-center py-4 rounded-3 mb-0">
                            <i class="bi bi-info-circle text-primary fs-3 d-block mb-2"></i>
                            <p class="mb-0 text-muted">No se encontraron especialistas registrados actualmente para <strong>{{ $especialidadSeleccionada->name }}</strong>.</p>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <!-- ¿Por qué usar Doctorísimo.App? -->
    <section class="porque" id="por-que" aria-labelledby="porque-titulo">
        <div class="container">
            <header class="porque-encabezado">
                <span class="porque-etiqueta">
                    <i class="bi bi-stars" aria-hidden="true"></i> Una plataforma, dos lados del consultorio
                </span>
                <h2 class="porque-titulo" id="porque-titulo">¿Por qué usar <span>Doctorísimo.App</span>?</h2>
                <p class="porque-intro">
                    Porque reservar una cita no debería costar una llamada, y atender no debería costar media hora
                    buscando una historia. Esto es lo que ganas, del lado que estés.
                </p>
            </header>

            <!-- Para pacientes -->
            <article class="porque-fila" aria-labelledby="porque-pacientes">
                <div class="porque-texto">
                    <span class="porque-publico porque-publico-paciente">
                        <i class="bi bi-person-heart" aria-hidden="true"></i> Para pacientes
                    </span>
                    <h3 id="porque-pacientes">Encuentra a tu especialista y reserva sin llamadas.</h3>
                    <p class="porque-bajada">
                        Tu salud no tiene horario de oficina. Busca, elige y reserva cuando te convenga,
                        desde tu celular.
                    </p>
                    <ul class="porque-lista">
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-search-heart"></i></span>
                            <div>
                                <strong>El especialista que necesitas</strong>
                                <span class="porque-detalle">Explora el catálogo por especialidad y conoce a los médicos y sus consultorios antes de reservar.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-calendar2-check"></i></span>
                            <div>
                                <strong>Agenda en el horario que te convenga</strong>
                                <span class="porque-detalle">Elige entre los cupos disponibles y confirma en minutos, sin llamar ni esperar.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-bell"></i></span>
                            <div>
                                <strong>Recordatorios a tiempo</strong>
                                <span class="porque-detalle">Te avisamos antes de tu cita para que llegues a tiempo y no se te pase.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-credit-card-2-front"></i></span>
                            <div>
                                <strong>Paga en línea, sin efectivo</strong>
                                <span class="porque-detalle">Cancela desde la plataforma y llega a tu consulta solo a atenderte.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-calendar2-week"></i></span>
                            <div>
                                <strong>Tus citas, en un solo lugar</strong>
                                <span class="porque-detalle">Consulta, reprograma o cancela desde tu cuenta, cuando lo necesites.</span>
                            </div>
                        </li>
                    </ul>
                    <div class="porque-acciones">
                        <a class="btn btn-primary btn-lg rounded-pill px-4" href="{{ route('reservas.catalogo') }}">
                            <i class="bi bi-calendar2-check me-2" aria-hidden="true"></i>Buscar mi especialista
                        </a>
                        <a class="porque-enlace" href="{{ route('paciente.citas') }}">
                            Ver mis citas <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <p class="porque-nota">
                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                        Para reservar no necesitas crear una cuenta: confirmas con un código en tu teléfono.
                    </p>
                </div>

                <div class="porque-visual">
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
            </article>

            <!-- Para médicos -->
            <article class="porque-fila porque-fila-inversa" aria-labelledby="porque-medicos">
                <div class="porque-texto">
                    <span class="porque-publico porque-publico-medico">
                        <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i> Para médicos
                    </span>
                    <h3 id="porque-medicos">Tu consultorio en orden, en la nube y en el bolsillo.</h3>
                    <p class="porque-bajada">
                        Menos tiempo buscando papeles, más tiempo con tus pacientes. Y una agenda que también
                        trabaja para ti.
                    </p>
                    <ul class="porque-lista">
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-journal-medical"></i></span>
                            <div>
                                <strong>Historia clínica digital</strong>
                                <span class="porque-detalle">Toda la información de cada paciente, ordenada y disponible cuando la necesitas.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-phone"></i></span>
                            <div>
                                <strong>Tu consultorio en el celular</strong>
                                <span class="porque-detalle">Accede a las historias y a los datos de tus pacientes desde el móvil, estés donde estés.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-file-earmark-medical"></i></span>
                            <div>
                                <strong>Récipes, informes y reportes en segundos</strong>
                                <span class="porque-detalle">Genera los documentos de la consulta sin volver a escribir lo que ya está en la historia.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-lightning-charge"></i></span>
                            <div>
                                <strong>La historia de un paciente, en 1 clic</strong>
                                <span class="porque-detalle">Encuentra lo que buscas al instante y dedica esos minutos a lo que importa: atender.</span>
                            </div>
                        </li>
                        <li>
                            <span class="porque-icono" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></span>
                            <div>
                                <strong>Más citas, sin perseguirlas</strong>
                                <span class="porque-detalle">Publica tu agenda en el catálogo y recibe pacientes nuevos que reservan solos.</span>
                            </div>
                        </li>
                    </ul>
                    <div class="porque-acciones">
                        <a class="btn btn-accent-gineco btn-lg rounded-pill px-4" href="{{ route('medico.registro') }}">
                            <i class="bi bi-person-plus me-2" aria-hidden="true"></i>Sumar mi consultorio
                        </a>
                        <a class="porque-enlace" href="{{ route('login') }}">
                            Ya tengo cuenta <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <p class="porque-nota">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        Registro con aprobación del equipo. Configura tu agenda y empieza a recibir citas.
                    </p>
                </div>

                <div class="porque-visual">
                    <div class="porque-lienzo porque-lienzo-medico">
                        <div class="porque-ventana">
                            <div class="porque-ventana-barra" aria-hidden="true">
                                <span></span><span></span><span></span>
                                <em>Consultorio · Doctorísimo.App</em>
                            </div>
                            <img src="{{ asset('img/landing/agenda-consultorio.webp') }}"
                                 alt="Agenda del consultorio en Doctorísimo.App: citas del día, pacientes, estado y acciones."
                                 width="1600" height="982" loading="lazy" decoding="async">
                        </div>
                        <div class="porque-telefono-app">
                            <img src="{{ asset('img/landing/agenda-movil.webp') }}"
                                 alt="La misma agenda en la app móvil de Doctorísimo.App: citas del día con paciente, motivo y estado."
                                 width="540" height="1140" loading="lazy" decoding="async">
                        </div>
                    </div>
                </div>
            </article>

            <!-- Galería: lo que produce el consultorio -->
            <section class="porque-galeria" aria-labelledby="porque-galeria-titulo">
                <header class="porque-galeria-encabezado">
                    <span class="porque-etiqueta">
                        <i class="bi bi-collection" aria-hidden="true"></i> Por dentro
                    </span>
                    <h3 id="porque-galeria-titulo">Lo que sale de Doctorísimo.App</h3>
                    <p class="porque-galeria-intro">
                        No es solo la agenda: son las curvas de crecimiento, los récipes y los informes que el
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

    <!-- Banner Telemedicina / Llamado a la Acción -->
    <section class="py-5 bg-white" id="servicios">
        <div class="container">
            <div class="banner-telemedicina p-4 p-md-5">
                <div class="row align-items-center gy-4">
                    <div class="col-md-8">
                        <span class="badge badge-gineco mb-2">Consulta Médica Online</span>
                        <h3 class="fw-bold">¿Tienes dudas o necesitas orientaciones rápidas?</h3>
                        <p class="text-muted mb-0">Conéctate por chat o videollamada de forma segura con doctores certificados sin salir de casa.</p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="#" class="btn btn-accent-gineco btn-lg">Iniciar Consulta</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer del sitio público -->
    <footer class="footer-gineco py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-heart-pulse-fill me-2" style="color: #cfb0d4;"></i>Doctorísimo<span style="color: #cfb0d4;">.App</span></h5>
                    <p class="small text-muted mb-3">Plataforma integral para la gestión y reserva de citas médicas especializadas.</p>
                </div>
                <div class="col-lg-2 col-6">
                    <h6 class="fw-bold mb-3">Plataforma</h6>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="{{ url('/') }}#inicio">Inicio</a></li>
                        <li class="mb-2"><a href="{{ url('/') }}#especialidades">Especialidades</a></li>
                        <li class="mb-2"><a href="{{ url('/') }}#por-que">Por qué Doctorísimo</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-6">
                    <h6 class="fw-bold mb-3">Para Médicos</h6>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="{{ route('medico.registro') }}">Unirse como Doctor</a></li>
                        <li class="mb-2"><a href="{{ url('/') }}#por-que">Conocer la plataforma</a></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h6 class="fw-bold mb-3">Contacto</h6>
                    <p class="small text-muted mb-1"><i class="bi bi-envelope me-2"></i><a href="mailto:soporte@ginecoreport.com">soporte@ginecoreport.com</a></p>
                </div>
            </div>
            <hr class="my-4 border-secondary">
            <div class="text-center small text-muted">
                <p class="mb-0">&copy; {{ date('Y') }} Doctorísimo<span style="color: #cfb0d4;">.App</span> Todos los derechos reservados.</p>
            </div>
        </div>
    </footer>
</div>