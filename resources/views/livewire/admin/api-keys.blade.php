<div>
    <x-ui.page-head titulo="API Keys"
                    subtitulo="Credencial con la que el escritorio de cada consultorio (GinecoReport) sincroniza con la nube, y cuándo lo hizo por última vez." />

    <x-ui.avisos />

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row">
                <div class="col-md-5">
                    <input type="text" wire:model.live.debounce.400ms="search" class="form-control"
                           placeholder="Buscar por nombre o registro...">
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <x-ui.tabla class="mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Médico</th>
                            <th>Última sync del escritorio</th>
                            <th>Última actividad del app</th>
                            <th>Última actividad web</th>
                            <th>Carga inicial</th>
                            <th class="text-end">Pacientes</th>
                            <th class="text-end">Historias</th>
                            <th class="text-center">API keys activas</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($medicos as $medico)
                            @php $r = $resumen[$medico->id]; @endphp
                            <tr wire:key="medico-{{ $medico->id }}">
                                <td class="celda-principal">
                                    <div class="fw-semibold">{{ trim($medico->name . ' ' . $medico->lastname) }}</div>
                                    <small class="text-muted">{{ $medico->reg_medico }}</small>
                                    @if (count($r['regs']) > 1)
                                        {{-- El médico de varias sedes: cada una es un reg_medico y su propia credencial. --}}
                                        <small class="text-muted d-block">{{ count($r['regs']) }} consultorios</small>
                                    @endif
                                </td>
                                <td class="fecha-celda" data-etiqueta="Última sync del escritorio">
                                    @if ($r['ultima_escritorio'])
                                        <div>{{ \App\Support\HoraLocal::fechaHora($r['ultima_escritorio']) }}</div>
                                        <small class="text-muted">{{ $r['ultima_escritorio']->locale('es')->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted">Nunca</span>
                                    @endif
                                </td>
                                <td class="fecha-celda" data-etiqueta="Última actividad del app">
                                    @if ($r['ultima_app'])
                                        <div>{{ \App\Support\HoraLocal::fechaHora($r['ultima_app']) }}</div>
                                        <small class="text-muted">{{ $r['ultima_app']->locale('es')->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted">Nunca</span>
                                    @endif
                                </td>
                                <td class="fecha-celda" data-etiqueta="Última actividad web">
                                    @if ($r['ultima_web'])
                                        <div>{{ \App\Support\HoraLocal::fechaHora($r['ultima_web']) }}</div>
                                        <small class="text-muted">{{ $r['ultima_web']->locale('es')->diffForHumans() }}</small>
                                    @elseif ($medico->user_id)
                                        <span class="text-muted" title="Se registra desde el 20/10/2026: antes de esa fecha no hay dato.">Sin registro</span>
                                    @else
                                        <span class="text-muted">Sin cuenta</span>
                                    @endif
                                </td>
                                <td data-etiqueta="Carga inicial">
                                    @if ($r['carga'] && $r['carga']->estado === 'completa')
                                        <span class="badge bg-success">Completa</span>
                                        @if ($r['carga']->finalizada_at)
                                            <div><small class="text-muted">{{ \App\Support\HoraLocal::instante($r['carga']->finalizada_at)?->format(\App\Support\Regionalizacion::formatoActual()) }}</small></div>
                                        @endif
                                    @elseif ($r['carga'])
                                        <span class="badge bg-warning text-dark">En curso</span>
                                    @else
                                        <span class="badge bg-secondary">Sin carga</span>
                                    @endif
                                </td>
                                <td class="text-end" data-etiqueta="Pacientes">{{ number_format($r['pacientes'], 0, ',', '.') }}</td>
                                <td class="text-end" data-etiqueta="Historias">{{ number_format($r['historias'], 0, ',', '.') }}</td>
                                <td class="text-center" data-etiqueta="API keys activas">
                                    <span class="badge {{ $r['activas'] > 0 ? 'bg-primary' : 'bg-secondary' }}">{{ $r['activas'] }}</span>
                                </td>
                                <td class="text-end text-nowrap celda-acciones">
                                    @if ($r['regs'])
                                        <button wire:click="abrirEmitir({{ $medico->id }})" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-key-fill me-1"></i> Generar API key
                                        </button>
                                        <button wire:click="verCredenciales({{ $medico->id }})" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-list-ul me-1"></i> Ver ({{ $r['credenciales']->count() }})
                                        </button>
                                    @else
                                        <span class="text-muted small">Sin reg_medico</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No se encontraron médicos.</td>
                            </tr>
                        @endforelse
                    </tbody>
            </x-ui.tabla>
        </div>

        @if ($medicos->hasPages())
            <div class="card-footer bg-white">
                {{ $medicos->links() }}
            </div>
        @endif
    </div>

    {{-- ============================ Ventanas ============================ --}}

    @include('livewire.admin.partials.api-key-emitir')

    {{-- Credenciales de un médico --}}
    @if ($modal === 'credenciales' && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">API keys de {{ trim($seleccionado->name . ' ' . $seleccionado->lastname) }}</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body p-0">
                        <x-ui.tabla class="mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Equipo</th>
                                        <th>Consultorio</th>
                                        <th>Emitida</th>
                                        <th>Vence</th>
                                        <th>Último uso</th>
                                        <th>Estado</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($credenciales as $c)
                                        <tr wire:key="cred-{{ $c->id }}">
                                            <td class="fw-semibold celda-principal">{{ $c->machine_label }}</td>
                                            {{-- Con varias sedes el mismo nombre de equipo se repite: sin el registro no se sabe cuál revocar. --}}
                                            <td data-etiqueta="Consultorio"><code>{{ $c->reg_medico }}</code></td>
                                            <td class="fecha-celda" data-etiqueta="Emitida">{{ \App\Support\HoraLocal::instante($c->created_at)?->format(\App\Support\Regionalizacion::formatoActual()) }}</td>
                                            <td class="fecha-celda" data-etiqueta="Vence">{{ $c->expires_at?->format(\App\Support\Regionalizacion::formatoActual()) }}</td>
                                            <td class="fecha-celda" data-etiqueta="Último uso">{{ \App\Support\HoraLocal::fechaHora($c->last_used_at, 'Nunca') }}</td>
                                            <td data-etiqueta="Estado">
                                                @if ($c->estaRevocada())
                                                    <span class="badge bg-danger">Revocada</span>
                                                @elseif ($c->estaVencida())
                                                    <span class="badge bg-secondary">Vencida</span>
                                                @else
                                                    <span class="badge bg-success">Activa</span>
                                                    @if ($c->diasParaVencer() !== null && $c->diasParaVencer() <= 30)
                                                        <span class="badge bg-warning text-dark">Vence en {{ $c->diasParaVencer() }} días</span>
                                                    @endif
                                                @endif
                                            </td>
                                            <td class="text-end celda-acciones">
                                                @if (! $c->estaRevocada())
                                                    <button wire:click="revocar({{ $c->id }})" class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('¿Revocar la API key del equipo {{ e($c->machine_label) }} del consultorio {{ e($c->reg_medico) }}? Ese equipo dejará de sincronizar.')">
                                                        Revocar
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="text-center text-muted py-4">Este médico todavía no tiene API keys.</td></tr>
                                    @endforelse
                                </tbody>
                        </x-ui.tabla>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @include('livewire.admin.partials.api-key-generada')
</div>
