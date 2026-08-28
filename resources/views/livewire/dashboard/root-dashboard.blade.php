<div class="container-fluid py-3" wire:poll.10s>
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold">
            <i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard del Administrador (Root)
        </h1>
        <span class="badge bg-light text-dark border px-3 py-2">
            <i class="bi bi-arrow-repeat spin me-1"></i> Actualizando en tiempo real
        </span>
    </div>

    <!-- Indicadores / Cards Estadísticas Principales -->
    <div class="row g-3 mb-4">
        <!-- 1. Usuarios Conectados Ahora -->
        <div class="col-12 col-sm-6 col-xl-2-4">
            <div class="card border-0 shadow-sm rounded-3 bg-danger text-white h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">En Línea Ahora</span>
                            <h2 class="display-6 fw-bold mb-0 d-flex align-items-center gap-2">
                                {{ number_format($usuariosConectados) }}
                                <span class="spinner-grow spinner-grow-sm text-light" role="status" aria-hidden="true"></span>
                            </h2>
                        </div>
                        <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <i class="bi bi-wifi fs-4 text-white"></i>
                        </div>
                    </div>
                </div>
                <a href="{{ route('admin.users') }}" class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center text-white text-decoration-none">
                    <small class="text-white-50">Ver usuarios activos</small>
                    <i class="bi bi-arrow-right-short text-white fs-4"></i>
                </a>
            </div>
        </div>

        <!-- 2. Usuarios Registrados -->
        <div class="col-12 col-sm-6 col-xl-2-4">
            <div class="card border-0 shadow-sm rounded-3 bg-primary text-white h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Usuarios Totales</span>
                            <h2 class="display-6 fw-bold mb-0">{{ number_format($totalUsuarios) }}</h2>
                        </div>
                        <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <i class="bi bi-people-fill fs-4 text-white"></i>
                        </div>
                    </div>
                </div>
                <a href="{{ route('admin.users') }}" class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center text-white text-decoration-none">
                    <small class="text-white-50">Gestionar usuarios</small>
                    <i class="bi bi-arrow-right-short text-white fs-4"></i>
                </a>
            </div>
        </div>

        <!-- 3. Pacientes Registrados -->
        <div class="col-12 col-sm-6 col-xl-2-4">
            <div class="card border-0 shadow-sm rounded-3 bg-success text-white h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Pacientes</span>
                            <h2 class="display-6 fw-bold mb-0">{{ number_format($totalPacientes) }}</h2>
                        </div>
                        <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <i class="bi bi-person-heart fs-4 text-white"></i>
                        </div>
                    </div>
                </div>
                <a href="{{ route('admin.pacientes') }}" class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center text-white text-decoration-none">
                    <small class="text-white-50">Gestionar pacientes</small>
                    <i class="bi bi-arrow-right-short text-white fs-4"></i>
                </a>
            </div>
        </div>

        <!-- 4. Médicos Registrados -->
        <div class="col-12 col-sm-6 col-xl-2-4">
            <div class="card border-0 shadow-sm rounded-3 bg-info text-white h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Médicos</span>
                            <h2 class="display-6 fw-bold mb-0">{{ number_format($totalMedicos) }}</h2>
                        </div>
                        <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <i class="bi bi-hospital fs-4 text-white"></i>
                        </div>
                    </div>
                </div>
                <a href="{{ route('admin.medicos') }}" class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center text-white text-decoration-none">
                    <small class="text-white-50">Gestionar médicos</small>
                    <i class="bi bi-arrow-right-short text-white fs-4"></i>
                </a>
            </div>
        </div>

        <!-- 5. Tarjeta Única Consolidada de Citas Globales (de la tabla 'cola') -->
        <div class="col-12 col-sm-6 col-xl-2-4">
            <div class="card border-0 shadow-sm rounded-3 bg-dark text-white h-100 d-flex flex-column justify-content-between">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="text-white-50 text-uppercase fw-semibold small">Citas Globales</span>
                        <div class="bg-white bg-opacity-10 rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="bi bi-calendar-event fs-5 text-white"></i>
                        </div>
                    </div>

                    <!-- Total de Citas -->
                    <div class="mb-2">
                        <h2 class="display-6 fw-bold mb-0">{{ number_format($citasTotales) }}</h2>
                        <span class="text-white-50 extra-small">Registradas en total</span>
                    </div>

                    <!-- Detalle Hoy / Mañana -->
                    <div class="row g-2 pt-2 border-top border-secondary border-opacity-50">
                        <div class="col-6">
                            <span class="text-white-50 d-block extra-small text-uppercase">Hoy</span>
                            <span class="fw-bold fs-6 text-success">+{{ number_format($citasHoy) }}</span>
                        </div>
                        <div class="col-6 border-start border-secondary border-opacity-50 ps-2">
                            <span class="text-white-50 d-block extra-small text-uppercase">Mañana</span>
                            <span class="fw-bold fs-6 text-warning">+{{ number_format($citasManana) }}</span>
                        </div>
                    </div>
                </div>
                <a href="#" class="card-footer bg-black bg-opacity-25 border-0 py-2 px-3 d-flex justify-content-between align-items-center text-white text-decoration-none">
                    <small class="text-white-50">Ver agendamiento</small>
                    <i class="bi bi-chevron-right text-white small"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Tabla Detallada de Usuarios Activos -->
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-header bg-white py-3 border-bottom-0">
            <h6 class="mb-0 fw-bold text-dark">
                <i class="bi bi-broadcast text-success me-2"></i>Usuarios Activos en este Momento
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Estado</th>
                            <th>Usuario</th>
                            <th>Email</th>
                            <th>ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($listaUsuariosConectados as $user)
                            <tr>
                                <td>
                                    <span class="badge bg-success-subtle text-success border border-success px-2 py-1">
                                        <i class="bi bi-circle-fill me-1 small"></i> En línea
                                    </span>
                                </td>
                                <td class="fw-semibold">{{ $user->name }}</td>
                                <td class="text-muted">{{ $user->email }}</td>
                                <td><code>#{{ $user->id }}</code></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <i class="bi bi-person-x fs-4 d-block mb-1"></i>
                                    No hay usuarios activos en este momento.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
/* Clase responsiva de Bootstrap para ajustar 5 columnas en monitores grandes */
@media (min-width: 1200px) {
    .col-xl-2-4 {
        flex: 0 0 auto;
        width: 20%;
    }
}
.extra-small {
    font-size: 0.75rem;
}
</style>