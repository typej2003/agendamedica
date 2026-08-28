<div class="container py-4">
    <!-- Encabezado con información del Médico y Centro Médico -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex align-items-center">
            <img src="{{ $medico->photo_path ? asset('storage/' . $medico->photo_path) : 'https://via.placeholder.com/150' }}" 
                 alt="{{ $medico->name }}" class="rounded-circle me-3" style="width: 75px; height: 75px; object-fit: cover;">
            <div>
                <h4 class="fw-bold mb-1">Dr(a). {{ $medico->name }} {{ $medico->lastname }}</h4>
                <p class="text-muted mb-0 small">
                    <i class="bi bi-hospital me-1"></i>
                    {{ $medicalCenter->name ?? 'Centro Médico' }}
                </p>
            </div>
        </div>
    </div>

    <!-- Control del Calendario -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <button class="btn btn-outline-primary btn-sm" wire:click="previousMonth">
                <i class="bi bi-chevron-left me-1"></i> Mes Anterior
            </button>
            <h5 class="fw-bold mb-0 text-capitalize">
                {{ \Carbon\Carbon::parse($currentDate)->translatedFormat('F Y') }}
            </h5>
            <button class="btn btn-outline-primary btn-sm" wire:click="nextMonth">
                Mes Siguiente <i class="bi bi-chevron-right ms-1"></i>
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered text-center m-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 14.28%;">Lunes</th>
                            <th style="width: 14.28%;">Martes</th>
                            <th style="width: 14.28%;">Miércoles</th>
                            <th style="width: 14.28%;">Jueves</th>
                            <th style="width: 14.28%;">Viernes</th>
                            <th style="width: 14.28%;">Sábado</th>
                            <th style="width: 14.28%;">Domingo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($calendarWeeks as $week)
                            <tr>
                                @foreach($week as $day)
                                    <td class="{{ !$day['isCurrentMonth'] ? 'bg-light text-muted opacity-50' : '' }} {{ $day['isToday'] ? 'bg-warning bg-opacity-10 fw-bold' : '' }}" style="height: 100px; vertical-align: top;">
                                        <div class="d-flex justify-content-between align-items-start p-1">
                                            <span>{{ $day['date']->day }}</span>
                                            @if($day['citasCount'] > 0)
                                                <span class="badge bg-danger rounded-pill" title="Pacientes agendados">
                                                    {{ $day['citasCount'] }} {{ $day['citasCount'] === 1 ? 'paciente' : 'pacientes' }}
                                                </span>
                                            @else
                                                <span class="badge bg-light text-muted border">
                                                    0
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>