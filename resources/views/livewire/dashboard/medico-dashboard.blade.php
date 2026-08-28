<div>
    @section('content_header')
        <div class="d-flex align-items-center justify-content-between">
            <h1 class="h3 mb-0 text-gray-800 fw-bold">
                <i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard del Médico
            </h1>
            <span class="badge bg-light text-dark border px-3 py-2">
                <i class="bi bi-arrow-repeat spin me-1"></i> Actualizando en tiempo real
            </span>
        </div>
    @endsection

    @section('content')
        <div wire:poll.10s>
            <!-- Cards Estadísticas de Pacientes -->
            <div class="row g-3 mb-4">
                <!-- Mis Pacientes En Línea -->
                <div class="col-12 col-sm-6 col-xl-4">
                    <div class="card border-0 shadow-sm rounded-3 bg-danger text-white h-100">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Mis Pacientes En Línea</span>
                                    <h2 class="display-6 fw-bold mb-0 d-flex align-items-center gap-2">
                                        {{ number_format($usuariosConectados) }}
                                        <span class="spinner-grow spinner-grow-sm text-light" role="status" aria-hidden="true"></span>
                                    </h2>
                                </div>
                                <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                    <i class="bi bi-wifi fs-3 text-white"></i>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                            <small class="text-white-50">Actividad en los últimos 5 min</small>
                            <i class="bi bi-circle-fill text-warning fs-6"></i>
                        </div>
                    </div>
                </div>

                <!-- Mis Usuarios Pacientes -->
                <div class="col-12 col-sm-6 col-xl-4">
                    <div class="card border-0 shadow-sm rounded-3 bg-primary text-white h-100">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Usuarios Asignados</span>
                                    <h2 class="display-6 fw-bold mb-0">{{ number_format($totalUsuarios) }}</h2>
                                </div>
                                <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                    <i class="bi bi-people-fill fs-3 text-white"></i>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                            <small class="text-white-50">Cuentas creadas de mis pacientes</small>
                            <i class="bi bi-arrow-right-short text-white fs-5"></i>
                        </div>
                    </div>
                </div>

                <!-- Mis Pacientes Totales -->
                <div class="col-12 col-sm-6 col-xl-4">
                    <div class="card border-0 shadow-sm rounded-3 bg-success text-white h-100">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Mis Pacientes</span>
                                    <h2 class="display-6 fw-bold mb-0">{{ number_format($totalPacientes) }}</h2>
                                </div>
                                <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                    <i class="bi bi-person-heart fs-3 text-white"></i>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                            <small class="text-white-50">Pacientes asignados a mi consulta</small>
                            <i class="bi bi-arrow-right-short text-white fs-5"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TARJETA ÚNICA DE CITAS -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-body py-4 px-3">
                    <div class="row text-center g-3 align-items-center">
                        <!-- Citas Agendadas Totales -->
                        <div class="col-12 col-md-4">
                            <div class="p-2">
                                <span class="text-secondary fw-bold small d-block mb-2 text-uppercase" style="letter-spacing: 0.5px;">
                                    CITAS AGENDADAS TOTALES
                                </span>
                                <h1 class="display-4 fw-bold text-dark mb-2" style="font-weight: 800;">
                                    {{ number_format($citasTotales) }}
                                </h1>
                                <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => $fechaHoy]) }}" class="text-decoration-none text-secondary small">
                                    Ver historico completo <i class="bi bi-chevron-right small"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Citas para Hoy -->
                        <div class="col-12 col-md-4">
                            <div class="p-2">
                                <span class="fw-bold small d-block mb-2 text-uppercase" style="color: #00c0ef; letter-spacing: 0.5px;">
                                    CITAS PARA HOY
                                </span>
                                <h1 class="display-4 fw-bold mb-2" style="color: #00c0ef; font-weight: 800;">
                                    {{ number_format($citasHoy) }}
                                </h1>
                                <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => $fechaHoy]) }}" class="text-decoration-none small" style="color: #00c0ef;">
                                    Ver agenda de hoy <i class="bi bi-chevron-right small"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Citas para Mañana -->
                        <div class="col-12 col-md-4">
                            <div class="p-2">
                                <span class="fw-bold small d-block mb-2 text-uppercase" style="color: #f39c12; letter-spacing: 0.5px;">
                                    CITAS PARA MAÑANA
                                </span>
                                <h1 class="display-4 fw-bold mb-2" style="color: #f39c12; font-weight: 800;">
                                    {{ number_format($citasManana) }}
                                </h1>
                                <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => $fechaManana]) }}" class="text-decoration-none small" style="color: #f39c12;">
                                    Ver agenda de mañana <i class="bi bi-chevron-right small"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla Detallada de Mis Usuarios Activos -->
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="bi bi-broadcast text-success me-2"></i>Mis Usuarios Pacientes Activos en este Momento
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
                                            No hay usuarios asignados a su consulta activos en este momento.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endsection
</div>