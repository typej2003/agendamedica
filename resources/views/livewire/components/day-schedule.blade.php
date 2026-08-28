<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">Agenda del {{ \Carbon\Carbon::parse($fecha)->translatedFormat('l d \d\e F \d\e Y') }}</h4>
            <span class="text-muted">Dr(a). {{ $medico->name }} {{ $medico->lastname }}</span>
        </div>
        <a href="{{ route('agendar.cita', ['medicoId' => $medicoId]) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver al Calendario
        </a>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Vista para Personal Autorizado (Root, Medico, Secretaria) -->
    @if($esPersonalAutorizado)
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white font-weight-bold">
                <i class="bi bi-shield-lock me-2"></i> Vista Detallada de Pacientes (Administración)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Hora</th>
                                <th>N° Orden</th>
                                <th>Historia</th>
                                <th>Paciente</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($horasDisponibles as $slot)
                                <tr>
                                    <td class="fw-bold">{{ $slot['display'] }}</td>
                                    @if($slot['ocupada'])
                                        <td><span class="badge bg-secondary">#{{ $slot['cita']['numorden'] ?? 'N/A' }}</span></td>
                                        <td>{{ $slot['cita']['numhistoria'] ?? 'N/A' }}</td>
                                        <td class="fw-bold text-primary">{{ $slot['cita']['numhistoria'] ?? 'Paciente Agendado' }}</td>
                                        <td>
                                            <span class="badge bg-warning text-dark">{{ $slot['cita']['estado'] ?? 'Pendiente' }}</span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-danger disabled">Ocupado</button>
                                        </td>
                                    @else
                                        <td colspan="4" class="text-muted italic">Horario Disponible</td>
                                        <td>
                                            <button wire:click="agendarHora('{{ $slot['hora_ini'] }}')" class="btn btn-sm btn-success">
                                                Agendar Aquí
                                            </button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
        <!-- Vista Pública para Pacientes -->
        <div class="card border-0 shadow-sm p-3">
            <h6 class="fw-bold mb-3 text-muted">Seleccione una hora disponible para agendar su consulta:</h6>
            <div class="row g-3">
                @foreach($horasDisponibles as $slot)
                    <div class="col-6 col-sm-4 col-md-3">
                        @if($slot['ocupada'])
                            <button class="btn btn-light border text-muted w-100 py-3 disabled" style="opacity: 0.6;">
                                <i class="bi bi-lock-fill me-1"></i> {{ $slot['display'] }}
                                <span class="d-block extra-small text-danger fw-bold">Ocupado</span>
                            </button>
                        @else
                            <button wire:click="agendarHora('{{ $slot['hora_ini'] }}')" 
                                    wire:confirm="¿Desea agendar la cita para las {{ $slot['display'] }}?"
                                    class="btn btn-outline-primary w-100 py-3 fw-bold">
                                <i class="bi bi-clock me-1"></i> {{ $slot['display'] }}
                                <span class="d-block extra-small text-success">Disponible</span>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>