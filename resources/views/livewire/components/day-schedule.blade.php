<div class="container py-4" style="max-width: 850px;">
    <!-- Encabezado y Navegación -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h5 class="fw-bold mb-0 text-capitalize text-primary">
                Agenda del {{ \Carbon\Carbon::parse($fecha)->locale('es')->translatedFormat('l d \d\e F \d\e Y') }}
            </h5>
            <small class="text-muted">Dr(a). {{ $medico->name }} {{ $medico->lastname }}</small>
        </div>
        <a href="{{ route('agendar.cita', ['medicoId' => $medicoId]) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Volver al Calendario
        </a>
    </div>

    <!-- Mensajes de Alerta -->
    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show small" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if (session()->has('error'))
        <div class="alert alert-danger alert-dismissible fade show small" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- VISTA PARA PERSONAL AUTORIZADO (Root, Medico, Secretaria) -->
    @if($esPersonalAutorizado)
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white py-2 fw-bold small d-flex justify-content-between align-items-center">
                <span><i class="bi bi-shield-lock me-1"></i> Administración de Citas</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Cupo</th>
                                <th>N° Historia</th>
                                @if($esMedicoPropietario)
                                    <th>Cédula</th>
                                    <th>Paciente</th>
                                @endif
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($itemsSlots as $slot)
                                <tr>
                                    <td class="fw-bold">{{ $slot['numorden'] }}</td>
                                    <td>{{ $slot['display'] }}</td>
                                    @if($slot['ocupada'])
                                        <td class="fw-bold text-primary">Historia: {{ $slot['cita']['numhistoria'] }}</td>
                                        
                                        {{-- Mostrar cédula y nombre solo al médico de la consulta --}}
                                        @if($esMedicoPropietario)
                                            <td>{{ $slot['cita']['paciente_cedula'] ?? 'N/A' }}</td>
                                            <td class="fw-bold">{{ $slot['cita']['paciente_nombre'] ?? 'N/A' }}</td>
                                        @endif

                                        <td>
                                            @if($slot['cita']['atendido'] == 1)
                                                <span class="badge bg-success">Atendido</span>
                                            @else
                                                <span class="badge bg-warning text-dark">Pendiente</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <button wire:click="eliminarCita({{ $slot['cita']['id'] }})" 
                                                    wire:confirm="¿Está seguro de eliminar/cancelar esta cita?"
                                                    class="btn btn-sm btn-outline-danger py-0 px-2">
                                                Eliminar
                                            </button>
                                        </td>
                                    @else
                                        <td colspan="{{ $esMedicoPropietario ? '4' : '2' }}" class="text-muted fst-italic">Disponible</td>
                                        <td class="text-end">
                                            @if(!$slot['pasado'])
                                                <button wire:click="agendarCupo({{ $slot['numorden'] }}, '{{ $slot['hora_ini'] }}')" 
                                                        class="btn btn-sm btn-success py-0 px-2">
                                                    Agendar
                                                </button>
                                            @else
                                                <span class="badge bg-light text-muted border">Expirado</span>
                                            @endif
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
        <!-- VISTA PÚBLICA PARA PACIENTES (FIJO EN MODO CUPOS) -->
        <div class="card border-0 shadow-sm p-3">
            <h6 class="fw-bold mb-3 text-muted border-bottom pb-2">
                Seleccione un cupo disponible (Atención por Orden de Llegada):
            </h6>

            <div class="row g-2">
                @foreach($itemsSlots as $slot)
                    <div class="col-6 col-sm-4 col-md-3">
                        @if($slot['ocupada'])
                            @php
                                $esMiCita = Auth::check() && $slot['cita']['numhistoria'] == (Auth::user()->numhistoria ?? Auth::user()->id);
                                $esHoy = \Carbon\Carbon::parse($fecha)->isToday();
                            @endphp

                            @if($esMiCita)
                                <div class="card border-primary bg-primary bg-opacity-10 text-center p-2">
                                    <span class="fw-bold text-primary small d-block">{{ $slot['display'] }}</span>
                                    <span class="badge bg-primary my-1">Mi Cita</span>
                                    
                                    @if(!$esHoy)
                                        <button wire:click="eliminarCita({{ $slot['cita']['id'] }})" 
                                                wire:confirm="¿Desea cancelar su cita agendada?" 
                                                class="btn btn-xs btn-outline-danger mt-1 py-0 px-1 extra-small">
                                            Cancelar Cita
                                        </button>
                                    @else
                                        <span class="text-muted extra-small d-block" style="font-size: 0.65rem;">No cancelable hoy</span>
                                    @endif
                                </div>
                            @else
                                <button class="btn btn-light border text-muted w-100 py-2 disabled opacity-50">
                                    <i class="bi bi-lock-fill me-1"></i> {{ $slot['display'] }}
                                    <span class="d-block extra-small text-danger fw-bold">Ocupado</span>
                                </button>
                            @endif

                        @elseif($slot['pasado'])
                            <button class="btn btn-light border text-muted w-100 py-2 disabled opacity-50">
                                <i class="bi bi-clock-history me-1"></i> {{ $slot['display'] }}
                                <span class="d-block extra-small text-secondary">Expirado</span>
                            </button>
                        @else
                            <button wire:click="agendarCupo({{ $slot['numorden'] }}, '{{ $slot['hora_ini'] }}')" 
                                    wire:confirm="¿Confirma que desea agendar el {{ $slot['display'] }}?"
                                    class="btn btn-outline-primary w-100 py-2 fw-bold">
                                <i class="bi bi-calendar-check me-1"></i> {{ $slot['display'] }}
                                <span class="d-block extra-small text-success">Disponible</span>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>