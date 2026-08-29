<div class="container py-4">
    <!-- Indicador de Cita Agendada -->
    <div class="row mb-4">
        <div class="col-12">
            @if($proximaCita)
                @php
                    $fechaCita = \Carbon\Carbon::parse($proximaCita->fecha)->locale('es');
                    $esHoy = $fechaCita->isToday();
                    // Si hora_ini existe y no es la hora por defecto '08:00:00' sin orden estricto de cupos, determinamos modo
                    $esModoHorario = !empty($proximaCita->hora_ini) && $proximaCita->hora_ini !== '08:00:00';
                @endphp

                <div class="card border-0 shadow-sm border-start border-4 border-primary bg-white">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle bg-primary bg-opacity-10 p-3 text-primary d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                    <i class="bi bi-calendar-check-fill fs-3"></i>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-primary">Próxima Cita</span>
                                        @if($esHoy)
                                            <span class="badge bg-danger animate__animated animate__flash">¡Es Hoy!</span>
                                        @endif
                                    </div>
                                    <h5 class="fw-bold mb-1 text-capitalize text-dark">
                                        {{ $fechaCita->translatedFormat('l d \d\e F \d\e Y') }}
                                    </h5>
                                    <p class="text-muted mb-0 small">
                                        Dr(a). 
                                        @if(isset($proximaCita->medicoUser))
                                            {{ $proximaCita->medicoUser->name }} {{ $proximaCita->medicoUser->lastname }}
                                        @else
                                            {{ $proximaCita->medico }}
                                        @endif
                                    </p>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <div class="text-md-end border-start border-md-0 ps-3 ps-md-0">
                                    @if($esModoHorario)
                                        <div class="text-primary fw-bold fs-5">
                                            <i class="bi bi-clock me-1"></i>
                                            {{ \Carbon\Carbon::parse($proximaCita->hora_ini)->format('h:i A') }}
                                        </div>
                                        <span class="badge bg-info text-dark extra-small">Cita por Horario</span>
                                    @else
                                        <div class="text-success fw-bold fs-5">
                                            <i class="bi bi-list-ol me-1"></i>
                                            Cupo #{{ $proximaCita->numorden }}
                                        </div>
                                        <span class="badge bg-secondary extra-small">Orden de Llegada</span>
                                    @endif
                                </div>

                                {{-- Si tiene un registro activo en Cola, usamos el ID del médico guardado en el registro --}}
                                @if(!empty($proximaCita->medico_id))
                                    <a href="{{ route('agendar.cita', ['medicoId' => $proximaCita->medico_id]) }}" class="btn btn-outline-primary btn-sm px-3 fw-bold">
                                        <i class="bi bi-calendar-event me-1"></i> Ver / Reagendar Cita
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <!-- Estado sin cita agendada -->
                <div class="card border-0 shadow-sm bg-light text-center p-4">
                    <div class="card-body">
                        <div class="rounded-circle bg-secondary bg-opacity-10 text-secondary mx-auto mb-3 d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                            <i class="bi bi-calendar-plus fs-3"></i>
                        </div>
                        <h6 class="fw-bold text-dark mb-1">No tienes citas agendadas</h6>
                        <p class="text-muted small mb-3">Agenda una consulta médica con nuestros especialistas en pocos pasos.</p>
                        
                        {{-- Enviamos un valor neutro 0 para cumplir con la firma requerida por la ruta 'agendar.cita' --}}
                        <a href="{{ route('medicos.search', ['medicoId' => 0]) }}" class="btn btn-primary btn-sm px-4 fw-bold">
                            <i class="bi bi-plus-circle me-1"></i> Agendar Cita
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>