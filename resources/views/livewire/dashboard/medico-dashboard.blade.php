<div class="container-fluid py-3" wire:poll.10s>
    <div class="page-head">
        <div>
            <h1><i class="bi bi-speedometer2 me-2" style="color: var(--ds-violeta-700);"></i>Panel del médico</h1>
            <p class="page-head-sub">Pacientes asignados a tu consulta y tus citas.</p>
        </div>
        <span class="badge bg-light text-dark border">
            <i class="bi bi-arrow-repeat me-1"></i> Actualizando en tiempo real
        </span>
    </div>

    <div class="row g-3 mb-4">
        <!-- 1. Pacientes en línea -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-violeta">
                <div class="stat-card-cuerpo">
                    <div>
                        <span class="stat-etiqueta">Mis pacientes en línea</span>
                        <p class="stat-valor d-flex align-items-center gap-2">
                            {{ number_format($usuariosConectados) }}
                            <span class="spinner-grow spinner-grow-sm" role="status" aria-hidden="true"></span>
                        </p>
                    </div>
                    <span class="stat-icono"><i class="bi bi-wifi"></i></span>
                </div>
                <div class="stat-pie">
                    <span>Actividad en los últimos 5 min</span>
                    <i class="bi bi-circle-fill" style="font-size: .5rem; color: var(--ds-exito);"></i>
                </div>
            </div>
        </div>

        <!-- 2. Cuentas de pacientes -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-celeste">
                <div class="stat-card-cuerpo">
                    <div>
                        <span class="stat-etiqueta">Cuentas asignadas</span>
                        <p class="stat-valor">{{ number_format($totalUsuarios) }}</p>
                    </div>
                    <span class="stat-icono"><i class="bi bi-people-fill"></i></span>
                </div>
                <div class="stat-pie">
                    <span>Cuentas creadas de mis pacientes</span>
                </div>
            </div>
        </div>

        <!-- 3. Pacientes totales -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-exito">
                <div class="stat-card-cuerpo">
                    <div>
                        <span class="stat-etiqueta">Mis pacientes</span>
                        <p class="stat-valor">{{ number_format($totalPacientes) }}</p>
                    </div>
                    <span class="stat-icono"><i class="bi bi-person-heart"></i></span>
                </div>
                <div class="stat-pie">
                    <span>Pacientes asignados a mi consulta</span>
                </div>
            </div>
        </div>

        <!-- 4. Citas (tabla `cola`) -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card stat-tinta">
                <div class="stat-card-cuerpo">
                    <div>
                        <span class="stat-etiqueta">Mis citas</span>
                        <p class="stat-valor">{{ number_format($citasTotales) }}</p>
                        <span class="texto-tenue" style="font-size: .78rem;">Registradas en total</span>
                    </div>
                    <span class="stat-icono"><i class="bi bi-calendar-event"></i></span>
                </div>
                <a href="{{ route('agendar.dia', ['medicoId' => $medico->id ?? 1, 'fecha' => $fechaHoy]) }}" class="stat-pie">
                    <span class="d-flex gap-3">
                        <span><span class="texto-tenue">Hoy</span> <strong style="color: var(--ds-exito);">+{{ number_format($citasHoy) }}</strong></span>
                        <span><span class="texto-tenue">Mañana</span> <strong style="color: var(--ds-aviso);">+{{ number_format($citasManana) }}</strong></span>
                    </span>
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Pacientes activos -->
    <div class="card">
        <div class="card-header py-3 d-flex align-items-center gap-2">
            <i class="bi bi-broadcast" style="color: var(--ds-exito);"></i>
            <span>Mis pacientes activos en este momento</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Estado</th>
                        <th>Usuario</th>
                        <th>Correo</th>
                        <th>ID</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($listaUsuariosConectados as $user)
                        <tr>
                            <td>
                                <span class="badge bg-success">
                                    <i class="bi bi-circle-fill me-1" style="font-size: .5rem;"></i> En línea
                                </span>
                            </td>
                            <td class="fw-semibold">{{ $user->name }}</td>
                            <td class="text-muted">{{ $user->email }}</td>
                            <td><code>#{{ $user->id }}</code></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <i class="bi bi-person-x"></i>
                                    <p>No hay usuarios asignados a tu consulta activos en este momento.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
