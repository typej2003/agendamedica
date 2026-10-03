{{-- Aviso con la API key recién generada (se muestra UNA sola vez). Requiere $tokenGenerado. --}}
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
