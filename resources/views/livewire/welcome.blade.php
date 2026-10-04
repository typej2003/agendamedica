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

                                            <a href="{{ route('login') }}" class="btn btn-primary btn-sm rounded-pill w-100 fw-bold py-2" style="background-color: var(--purple-wifiexpres); border-color: var(--purple-wifiexpres);">
                                                <i class="bi bi-calendar-event me-1"></i> Agendar Cita
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
                        <li class="mb-2"><a href="{{ url('/') }}#inicio">Inicio</a></li>
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