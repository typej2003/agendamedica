<div class="container py-3">
    <!-- Ficha Reducida del Médico -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3 d-flex align-items-center">
            <img src="{{ $medico->photo_path ? asset('storage/' . $medico->photo_path) : 'https://via.placeholder.com/150' }}" 
                 alt="{{ $medico->name }}" class="rounded-circle me-3" style="width: 50px; height: 50px; object-fit: cover;">
            <div>
                <h5 class="fw-bold mb-0">Dr(a). {{ $medico->name }} {{ $medico->lastname }}</h5>
                <small class="text-muted">
                    <i class="bi bi-hospital me-1"></i>
                    {{ $medicalCenter->name ?? 'Centro Médico' }}
                </small>
            </div>
        </div>
    </div>

    <!-- Calendario de Tamaño Reducido -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-capitalize text-primary">
                {{ \Carbon\Carbon::parse($currentDate)->translatedFormat('F Y') }}
            </h6>
            <button class="btn btn-primary btn-sm px-3" wire:click="nextMonth">
                Mes Siguiente <i class="bi bi-chevron-right ms-1"></i>
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered text-center m-0 align-middle small">
                    <thead class="table-light">
                        <tr class="text-uppercase extra-small">
                            <th style="width: 14.28%;">Lun</th>
                            <th style="width: 14.28%;">Mar</th>
                            <th style="width: 14.28%;">Mié</th>
                            <th style="width: 14.28%;">Jue</th>
                            <th style="width: 14.28%;">Vie</th>
                            <th style="width: 14.28%;">Sáb</th>
                            <th style="width: 14.28%;">Dom</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($calendarWeeks as $week)
                            <tr>
                                @foreach($week as $day)
                                    <td class="{{ !$day['isCurrentMonth'] ? 'bg-light text-muted opacity-50' : $day['bgColor'] }}" 
                                        style="height: 55px; vertical-align: top; cursor: pointer;"
                                        onclick="window.location.href='{{ route('agendar.dia', ['medicoId' => $medicoId, 'fecha' => $day['dateString']]) }}'">
                                        
                                        <div class="d-flex justify-content-between align-items-center p-1">
                                            <span class="fw-bold {{ $day['isToday'] ? 'text-primary' : '' }}">{{ $day['date']->day }}</span>
                                            @if($day['citasCount'] > 0)
                                                <span class="badge {{ $day['badgeColor'] }}" style="font-size: 0.65rem;">
                                                    {{ $day['citasCount'] }}
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