{{-- **Borrar la cuenta de un doctor** (2026-11-10): la ventana que comparten «Usuarios · Médicos» y el
     detalle del médico. La baja es lógica —se archiva— y lo que se libera es el correo, el teléfono y el carnet;
     lo clínico se conserva. La acción vive en el concern `ArchivaLaCuenta`. --}}
@if ($modal === 'archivar' && $medicoId)
    @php $archivando = \App\Models\Medico::find($medicoId); @endphp
    @if ($archivando)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="confirmarArchivo">
                        <div class="modal-header">
                            <h5 class="modal-title">Borrar la cuenta de {{ $archivando->nombre_mostrar }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarArchivo" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            @error('archivo') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                            <p class="mb-2">
                                La cuenta deja de verse y de poder entrar, pero <strong>no se borra nada de lo clínico</strong>:
                                sus pacientes, historias, citas y récipes siguen guardados en su consultorio.
                            </p>
                            <p class="mb-2">Quedan libres para otra cuenta:</p>
                            <ul class="mb-2">
                                <li>el correo con el que entra: <strong>{{ $archivando->email ?: ($archivando->user?->email ?: '—') }}</strong></li>
                                <li>el teléfono: <strong>{{ $archivando->phone ?: '—' }}</strong></li>
                                <li>el carnet: <strong>{{ $archivando->license_number ?: '—' }}</strong></li>
                            </ul>
                            <p class="text-muted small">
                                Se cierran sus sesiones del app y de la web. La sincronización del escritorio del consultorio
                                no se corta: es una credencial de la PC, no de la cuenta. Puedes restaurarla después desde
                                «Archivados».
                            </p>

                            <label class="form-label" for="confirmacion-archivo">
                                Escribe <strong>{{ $archivando->nombre_mostrar }}</strong> para confirmar
                            </label>
                            <input type="text" id="confirmacion-archivo" class="form-control" wire:model="confirmacionArchivo"
                                   autocomplete="off" placeholder="{{ $archivando->nombre_mostrar }}">
                            @error('confirmacionArchivo') <span class="text-danger small d-block">{{ $message }}</span> @enderror

                            <label class="form-label mt-3" for="motivo-archivo">Motivo (opcional)</label>
                            <input type="text" id="motivo-archivo" class="form-control" wire:model="motivoArchivo"
                                   maxlength="255" autocomplete="off" placeholder="Ej.: el doctor dejó de trabajar en el consultorio">
                            @error('motivoArchivo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarArchivo">Cancelar</button>
                            <button type="submit" class="btn btn-danger" wire:loading.attr="disabled">
                                Borrar la cuenta
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endif
