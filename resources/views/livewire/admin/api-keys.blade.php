<div class="mt-4">
    <div class="row mb-3 align-items-center">
        <div class="col-md-8">
            <h4 class="fw-bold mb-0">API Keys</h4>
            <small class="text-muted">
                Credencial con la que el escritorio de cada consultorio (GinecoReport) sincroniza con la nube, y cuándo lo hizo por última vez.
            </small>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('message') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    @endif

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <div class="row">
                <div class="col-md-5">
                    <input type="text" wire:model.debounce.400ms="search" class="form-control"
                           placeholder="Buscar por nombre o registro...">
                </div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Médico</th>
                            <th>Última sync del escritorio</th>
                            <th>Última actividad del app</th>
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
                                <td>
                                    <div class="fw-semibold">{{ trim($medico->name . ' ' . $medico->lastname) }}</div>
                                    <small class="text-muted">{{ $medico->reg_medico }}</small>
                                </td>
                                <td>
                                    @if ($r['ultima_escritorio'])
                                        <div>{{ $r['ultima_escritorio']->format('d/m/Y H:i') }}</div>
                                        <small class="text-muted">{{ $r['ultima_escritorio']->locale('es')->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted">Nunca</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($r['ultima_app'])
                                        <div>{{ $r['ultima_app']->format('d/m/Y H:i') }}</div>
                                        <small class="text-muted">{{ $r['ultima_app']->locale('es')->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted">Nunca</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($r['carga'] && $r['carga']->estado === 'completa')
                                        <span class="badge bg-success">Completa</span>
                                        @if ($r['carga']->finalizada_at)
                                            <div><small class="text-muted">{{ $r['carga']->finalizada_at->format('d/m/Y') }}</small></div>
                                        @endif
                                    @elseif ($r['carga'])
                                        <span class="badge bg-warning text-dark">En curso</span>
                                    @else
                                        <span class="badge bg-secondary">Sin carga</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format($r['pacientes'], 0, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($r['historias'], 0, ',', '.') }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $r['activas'] > 0 ? 'bg-primary' : 'bg-secondary' }}">{{ $r['activas'] }}</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($r['regs'])
                                        <button wire:click="abrirEmitir({{ $medico->id }})" class="btn btn-sm btn-primary">
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
                                <td colspan="8" class="text-center text-muted py-4">No se encontraron médicos.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($medicos->hasPages())
            <div class="card-footer bg-white">
                {{ $medicos->links() }}
            </div>
        @endif
    </div>

    {{-- ============================ Ventanas ============================ --}}

    {{-- Generar API key --}}
    @if ($modal === 'emitir' && $seleccionado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="emitir">
                        <div class="modal-header">
                            <h5 class="modal-title">Generar API key</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">
                                Para <strong>{{ trim($seleccionado->name . ' ' . $seleccionado->lastname) }}</strong>
                                <small class="text-muted">({{ $seleccionado->regMedicoPrincipal() }})</small>.
                                Es una por PC: se guarda en el equipo del consultorio y se puede revocar por separado.
                            </p>

                            <div class="mb-3">
                                <label class="form-label">Nombre del equipo <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model.defer="equipo" maxlength="60" autocomplete="off">
                                <div class="form-text">Para reconocerla después, por ejemplo <code>CONSULTORIO-1</code>.</div>
                                @error('equipo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Vigencia</label>
                                <select class="form-select" wire:model.defer="anios">
                                    <option value="1">1 año</option>
                                    <option value="2">2 años</option>
                                    <option value="3">3 años</option>
                                </select>
                                @error('anios') <span class="text-danger small">{{ $message }}</span> @enderror
                            </div>

                            @if ($mostrarReemplazo)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="reemplazar" wire:model.defer="reemplazar">
                                    <label class="form-check-label" for="reemplazar">
                                        Reemplazar: revocar la anterior de este equipo y emitir una nueva
                                    </label>
                                </div>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Generar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

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
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Equipo</th>
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
                                            <td class="fw-semibold">{{ $c->machine_label }}</td>
                                            <td>{{ $c->created_at?->format('d/m/Y') }}</td>
                                            <td>{{ $c->expires_at?->format('d/m/Y') }}</td>
                                            <td>{{ $c->last_used_at ? $c->last_used_at->format('d/m/Y H:i') : 'Nunca' }}</td>
                                            <td>
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
                                            <td class="text-end">
                                                @if (! $c->estaRevocada())
                                                    <button wire:click="revocar({{ $c->id }})" class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('¿Revocar la API key del equipo {{ e($c->machine_label) }}? Ese equipo dejará de sincronizar.')">
                                                        Revocar
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-center text-muted py-4">Este médico todavía no tiene API keys.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- API key generada: se muestra UNA sola vez --}}
    @if ($tokenGenerado)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="bi bi-check-circle me-1"></i> API key generada</h5>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning small">
                            Cópiala o descárgala <strong>ahora</strong>: no se vuelve a mostrar. Si se pierde, se genera otra y se revoca la anterior.
                        </div>

                        <dl class="row mb-3">
                            <dt class="col-sm-3">Médico</dt>
                            <dd class="col-sm-9">{{ $tokenGenerado['medico'] }} <small class="text-muted">({{ $tokenGenerado['reg_medico'] }})</small></dd>
                            <dt class="col-sm-3">Equipo</dt>
                            <dd class="col-sm-9">{{ $tokenGenerado['equipo'] }}</dd>
                            <dt class="col-sm-3">Vence</dt>
                            <dd class="col-sm-9">{{ $tokenGenerado['vence'] }}</dd>
                        </dl>

                        @if (! empty($tokenGenerado['revocadas']))
                            <div class="alert alert-info small py-2">Se revocó la API key anterior de este equipo.</div>
                        @endif

                        <label class="form-label fw-semibold">API key</label>
                        <textarea class="form-control font-monospace" rows="2" readonly onclick="this.select()">{{ $tokenGenerado['token'] }}</textarea>

                        <div class="mt-3 small">
                            <strong>En el PC del consultorio:</strong> guardarla en una sola línea, sin espacios, en
                            <code>C:\MICONSULTAGI\bridge\secrets\sync-token.txt</code> (crear la carpeta <code>secrets</code> si no existe)
                            y borrar la línea <code>"apiKey"</code> de <code>C:\MICONSULTAGI\bridge\config.json</code>.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-primary"
                                onclick="navigator.clipboard.writeText({{ json_encode($tokenGenerado['token']) }}); this.textContent = 'Copiada';">
                            <i class="bi bi-clipboard me-1"></i> Copiar
                        </button>
                        <button type="button" class="btn btn-outline-primary"
                                onclick="(function (t) { var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([t], { type: 'text/plain' })); a.download = 'sync-token.txt'; a.click(); URL.revokeObjectURL(a.href); })({{ json_encode($tokenGenerado['token']) }});">
                            <i class="bi bi-download me-1"></i> Descargar sync-token.txt
                        </button>
                        <button type="button" class="btn btn-primary" wire:click="cerrarToken">Listo</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
