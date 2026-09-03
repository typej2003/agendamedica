<div>
    <!-- Hero Section con Componente Livewire Integrado -->
    <section class="hero-section" id="inicio">
        <div class="container">
            <div class="text-center max-w-720 mx-auto mb-5">
                <span class="badge badge-gineco mb-3"><i class="bi bi-shield-check me-1"></i> Atención Especializada 24/7</span>
                <h1 class="display-4 hero-title mb-3">Encuentra a tu <span>Especialista</span> y Agenda en Minutos</h1>
                <p class="lead text-muted">Conectamos a pacientes con los mejores profesionales en ginecología, obstetricia y salud integral.</p>
            </div>

            <!-- Invocación del Componente Livewire de Búsqueda -->
            <div id="busqueda">
                @livewire('components.search-specialties')
            </div>
        </div>
    </section>

    <!-- Sección de Especialidades y Especialistas -->
    <section class="py-5 bg-light" id="especialidades">
        <div class="container">
            <div class="text-center mb-4">
                <h2 class="fw-bold">Nuestras Especialidades Médicas</h2>
                <p class="text-muted">Selecciona una especialidad para conocer a los profesionales disponibles</p>
            </div>

            <!-- Chips de Especialidades -->
            <div class="d-flex flex-wrap justify-content-center gap-2 mb-5">
                <button type="button" 
                        wire:click="limpiarFiltro" 
                        class="btn btn-sm {{ is_null($especialidadSeleccionada) ? 'btn-primary' : 'btn-outline-primary' }} rounded-pill px-3">
                    Todas
                </button>
                @foreach($especialidades as $esp)
                    <button type="button" 
                            wire:click="seleccionarEspecialidad('{{ $esp->slug }}')" 
                            class="btn btn-sm {{ optional($especialidadSeleccionada)->id === $esp->id ? 'btn-primary' : 'btn-outline-primary' }} rounded-pill px-3">
                        {{ $esp->name }}
                        @if($esp->medicos_count > 0)
                            <span class="badge bg-white text-primary ms-1">{{ $esp->medicos_count }}</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <!-- Resultados de Médicos por Especialidad -->
            @if($especialidadSeleccionada)
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h4 class="fw-bold mb-0">Especialistas en {{ $especialidadSeleccionada->name }}</h4>
                    <button class="btn btn-link text-decoration-none" wire:click="limpiarFiltro">
                        <i class="bi bi-x-circle me-1"></i> Quitar filtro
                    </button>
                </div>

                @if($especialidadSeleccionada->medicos->count() > 0)
                    <div class="row g-4">
                        @foreach($especialidadSeleccionada->medicos as $medico)
                            <div class="col-md-6 col-lg-4">
                                <div class="card h-100 border-0 shadow-sm rounded-3">
                                    <div class="card-body p-4 text-center">
                                        <div class="avatar-container mb-3 mx-auto">
                                            <i class="bi bi-person-badge display-4 text-secondary"></i>
                                        </div>
                                        <h5 class="fw-bold mb-1">{{ $medico->name }}</h5>
                                        <p class="text-primary small mb-2">{{ $especialidadSeleccionada->name }}</p>
                                        
                                        @if($medico->medicalCenter)
                                            <p class="text-muted small mb-3">
                                                <i class="bi bi-geo-alt me-1"></i>{{ $medico->medicalCenter->name ?? 'Centro Médico' }}
                                            </p>
                                        @endif

                                        <a href="{{ route('login') }}" class="btn btn-outline-primary btn-sm rounded-pill w-100">
                                            Agendar Cita
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="alert alert-info text-center py-4">
                        <i class="bi bi-info-circle me-2"></i> No hay especialistas registrados actualmente en {{ $especialidadSeleccionada->name }}.
                    </div>
                @endif
            @endif
        </div>
    </section>

    <!-- Características Rápidas -->
    <section class="py-5 bg-white">
        <div class="container">
            <div class="row g-4 text-center">
                <div class="col-md-4">
                    <div class="p-3">
                        <div class="service-icon mx-auto">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <h5 class="fw-bold">Cita Directa</h5>
                        <p class="text-muted small mb-0">Reserva turnos en tiempo real según la disponibilidad del especialista.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3">
                        <div class="service-icon accent-icon mx-auto">
                            <i class="bi bi-camera-video"></i>
                        </div>
                        <h5 class="fw-bold">Telemedicina</h5>
                        <p class="text-muted small mb-0">Videollamadas en vivo y privadas con encriptación para tu seguridad.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3">
                        <div class="service-icon mx-auto">
                            <i class="bi bi-file-earmark-medical"></i>
                        </div>
                        <h5 class="fw-bold">Historial Digital</h5>
                        <p class="text-muted small mb-0">Accede a tus recetas y resultados médicos de forma instantánea.</p>
                    </div>
                </div>
            </div>
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

    <!-- Footer de GinecoReport -->
    <footer class="footer-gineco py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-heart-pulse-fill me-2" style="color: #c28fd6;"></i>GinecoReport</h5>
                    <p class="small text-muted mb-3">Plataforma integral para la gestión y reserva de citas médicas especializadas.</p>
                </div>
                <div class="col-lg-2 col-6">
                    <h6 class="fw-bold mb-3">Plataforma</h6>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="{{ url('/') }}#busqueda">Buscar Doctores</a></li>
                        <li class="mb-2"><a href="{{ url('/') }}#especialidades">Especialidades</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-6">
                    <h6 class="fw-bold mb-3">Para Médicos</h6>
                    <ul class="list-unstyled small">
                        <li class="mb-2"><a href="#">Unirse como Doctor</a></li>
                        <li class="mb-2"><a href="#">Gestión de Agenda</a></li>
                    </ul>
                </div>
                <div class="col-lg-4">
                    <h6 class="fw-bold mb-3">Contacto</h6>
                    <p class="small text-muted mb-1"><i class="bi bi-envelope me-2"></i>soporte@ginecoreport.com</p>
                </div>
            </div>
            <hr class="my-4 border-secondary">
            <div class="text-center small text-muted">
                <p class="mb-0">&copy; {{ date('Y') }} GinecoReport. Todos los derechos reservados.</p>
            </div>
        </div>
    </footer>
</div>