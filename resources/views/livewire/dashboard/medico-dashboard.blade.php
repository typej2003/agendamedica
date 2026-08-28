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
            <!-- Indicadores / Cards Estadísticas Principales -->
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

            <!-- Indicadores Simbólicos de Citas Agendadas (Cola en Desarrollo) -->
            <div class="row g-3 mb-4">
                <!-- Citas Totales -->
                <div class="col-12 col-sm-4">
                    <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => $fechaHoy]) }}" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-3 bg-dark text-white h-100 position-relative overflow-hidden">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Citas Agendadas Totales</span>
                                        <h2 class="display-6 fw-bold mb-0">{{ number_format($citasTotales) }}</h2>
                                    </div>
                                    <div class="bg-white bg-opacity-10 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                        <i class="bi bi-calendar-check fs-3 text-white"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-black bg-opacity-25 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                                <small class="text-white-50">Ver agenda global</small>
                                <i class="bi bi-chevron-right text-white small"></i>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Citas Hoy -->
                <div class="col-12 col-sm-4">
                    <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => $fechaHoy]) }}" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-3 bg-info text-white h-100 position-relative overflow-hidden">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-white-50 text-uppercase fw-semibold small d-block mb-1">Citas para Hoy</span>
                                        <h2 class="display-6 fw-bold mb-0">{{ number_format($citasHoy) }}</h2>
                                    </div>
                                    <div class="bg-white bg-opacity-25 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                        <i class="bi bi-calendar-day fs-3 text-white"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                                <small class="text-white-50">Ver agendamiento de hoy</small>
                                <i class="bi bi-chevron-right text-white small"></i>
                            </div>
                        </div>
                    </a>
                </div>

                <!-- Citas Mañana -->
                <div class="col-12 col-sm-4">
                    <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => $fechaManana]) }}" class="text-decoration-none">
                        <div class="card border-0 shadow-sm rounded-3 bg-warning text-dark h-100 position-relative overflow-hidden">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div>
                                        <span class="text-dark-50 text-uppercase fw-semibold small d-block mb-1">Citas para Mañana</span>
                                        <h2 class="display-6 fw-bold mb-0">{{ number_format($citasManana) }}</h2>
                                    </div>
                                    <div class="bg-dark bg-opacity-10 rounded-circle p-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                        <i class="bi bi-calendar-plus fs-3 text-dark"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-black bg-opacity-10 border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                                <small class="text-dark">Ver agendamiento de mañana</small>
                                <i class="bi bi-chevron-right text-dark small"></i>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Tabla Detallada de Citas / Turnos en Cola del Médico (Simbólica / Estado Pendiente) -->
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 border-bottom-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="bi bi-calendar2-range text-primary me-2"></i>Mis Citas Agendadas Recientes
                    </h6>
                    <span class="badge bg-warning-subtle text-warning border border-warning px-2 py-1">
                        Módulo en desarrollo
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha y Hora</th>
                                    <th>Paciente</th>
                                    <th>Cédula</th>
                                    <th>Estado</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($proximasCitas as $item)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold text-dark">
                                                <i class="bi bi-clock me-1 text-muted"></i>
                                                {{ isset($item->fecha) ? \Carbon\Carbon::parse($item->fecha)->format('d/m/Y h:i A') : 'N/A' }}
                                            </div>
                                        </td>
                                        <td class="fw-semibold text-primary">
                                            {{ optional($item->paciente)->nombre ?? 'N/A' }} {{ optional($item->paciente)->apellido ?? '' }}
                                        </td>
                                        <td class="text-muted">
                                            {{ optional($item->paciente)->cedula ?? 'Sin Cédula' }}
                                        </td>
                                        <td>
                                            @php
                                                $estado = strtolower($item->estado ?? 'pendiente');
                                                $badgeClass = match($estado) {
                                                    'atendido', 'completada' => 'bg-success-subtle text-success border-success',
                                                    'cancelado', 'cancelada' => 'bg-danger-subtle text-danger border-danger',
                                                    'en_proceso', 'llamado' => 'bg-info-subtle text-info border-info',
                                                    default => 'bg-warning-subtle text-warning border-warning'
                                                };
                                            @endphp
                                            <span class="badge {{ $badgeClass }} border px-2 py-1">
                                                {{ ucfirst($item->estado ?? 'Pendiente') }}
                                            </span>
                                        </td>
                                        <td>
                                            <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => isset($item->fecha) ? \Carbon\Carbon::parse($item->fecha)->toDateString() : $fechaHoy]) }}" class="btn btn-sm btn-outline-primary rounded-2">
                                                <i class="bi bi-eye me-1"></i>Ver en Agenda
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            <i class="bi bi-clock-history fs-4 d-block mb-1"></i>
                                            La consulta de la cola de atención está en proceso de configuración.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
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