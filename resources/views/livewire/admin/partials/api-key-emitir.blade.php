{{-- Ventana "Generar API key". Requiere $modal, $seleccionado (Medico), $equipo, $mostrarReemplazo. Ver Concerns\EmiteApiKeys. --}}
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
