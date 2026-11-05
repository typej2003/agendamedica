{{-- Ventana "Generar API key". Requiere $modal, $seleccionado (Medico), $equipo, $registro, $mostrarReemplazo. Ver Concerns\EmiteApiKeys. --}}
{{-- Generar API key --}}
@if ($modal === 'emitir' && $seleccionado)
    @php
        $registros = $seleccionado->registrosDeTrabajo();
        $principal = $seleccionado->regMedicoPrincipal();
    @endphp
    <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
        <div class="modal-dialog modal-lateral">
            <div class="modal-content">
                <form wire:submit.prevent="emitir">
                    <div class="modal-header">
                        <h5 class="modal-title">Generar API key</h5>
                        <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-3">
                            Para <strong>{{ trim($seleccionado->name . ' ' . $seleccionado->lastname) }}</strong>.
                            Es una por PC: se guarda en el equipo del consultorio y se puede revocar por separado.
                        </p>

                        {{-- El registro es el **consultorio al que esa PC sube sus datos**. Con una sola sede no hay
                             nada que elegir; con varias (el médico que atiende en tres sitios, cada uno con su
                             reg_medico) hay que decir cuál, o la segunda PC subiría al tenant de la primera. --}}
                        @if (count($registros) > 1)
                            <div class="mb-3">
                                <label class="form-label" for="api-key-registro">Consultorio <span class="text-danger">*</span></label>
                                <select class="form-select" id="api-key-registro" wire:model="registro">
                                    @foreach ($registros as $r)
                                        <option value="{{ $r }}">{{ $r }}{{ $r === $principal ? ' · su consultorio' : ' · sede asignada' }}</option>
                                    @endforeach
                                </select>
                                <div class="form-text">
                                    La credencial queda atada a este registro: los datos de esa PC entran a ese consultorio.
                                    Elige el de la sede donde está el equipo.
                                </div>
                                @error('registro') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                        @else
                            <p class="mb-3 small text-muted">
                                Registro: <code>{{ $registros[0] ?? '—' }}</code>
                            </p>
                        @endif

                        <div class="mb-3">
                            <label class="form-label">Nombre del equipo <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" wire:model="equipo" maxlength="60" autocomplete="off">
                            <div class="form-text">Para reconocerla después, por ejemplo <code>CONSULTORIO-1</code>.</div>
                            @error('equipo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Vigencia</label>
                            <select class="form-select" wire:model="anios">
                                <option value="1">1 año</option>
                                <option value="2">2 años</option>
                                <option value="3">3 años</option>
                            </select>
                            @error('anios') <span class="text-danger small">{{ $message }}</span> @enderror
                        </div>

                        @if ($mostrarReemplazo)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="reemplazar" wire:model="reemplazar">
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
