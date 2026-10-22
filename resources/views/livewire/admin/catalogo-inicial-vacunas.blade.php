<div>
    <x-ui.page-head titulo="Catálogo inicial · Vacunas"
                    subtitulo="Las vacunas y dosis con las que parte cada médico de pediatría. Cada médico recibe una copia y la ajusta a su manera: lo que cambies aquí no modifica lo que ya copiaron.">
        <x-slot name="acciones">
            <button type="button" class="btn btn-primary" wire:click="abrirVacuna">
                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva vacuna
            </button>
        </x-slot>
    </x-ui.page-head>


    <x-ui.avisos />

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-header bg-white py-3">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <label class="visually-hidden" for="buscar-vacunas">Buscar vacuna</label>
                    <input type="text" id="buscar-vacunas" wire:model.debounce.400ms="search" class="form-control"
                           placeholder="Buscar por nombre o código...">
                </div>
                <div class="col-md-7 text-md-end mt-2 mt-md-0 text-muted small">{{ $total }} {{ $total === 1 ? 'vacuna inicial' : 'vacunas iniciales' }}</div>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Código</th>
                            <th>Vacuna</th>
                            <th>Edad inicial</th>
                            <th>Tipo</th>
                            <th>Dosis</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($vacunas as $v)
                            @php $dosisDeEsta = $dosisPorVacuna[$v->codi_vacuna] ?? collect(); @endphp
                            <tr wire:key="vacuna-{{ $v->id }}">
                                <td><code>{{ $v->codi_vacuna }}</code></td>
                                <td>
                                    <div class="fw-semibold">{{ $v->vacuna }}</div>
                                    @if ($v->cobertura)
                                        <small class="text-muted">{{ $v->cobertura }}</small>
                                    @endif
                                    @if ($v->sexo && $v->sexo !== 'A')
                                        <span class="badge bg-light text-dark border ms-1">{{ \App\Clinica\Vacunas\ConvencionDeVacunas::sexo($v->sexo) }}</span>
                                    @endif
                                </td>
                                <td>{{ $v->edad_inicial_meses === null ? '—' : $v->edad_inicial_meses . ' ' . ($v->edad_inicial_meses === 1 ? 'mes' : 'meses') }}</td>
                                <td>
                                    <span class="badge {{ $v->fija === 'O' ? 'bg-primary' : 'bg-secondary' }}">{{ \App\Clinica\Vacunas\ConvencionDeVacunas::fija($v->fija) }}</span>
                                </td>
                                <td>
                                    @forelse ($dosisDeEsta as $d)
                                        <span class="badge bg-light text-dark border me-1 mb-1">
                                            {{ $d->dosis_descri ?: $d->dosis . 'ª dosis' }}@if ($d->meses !== null) · {{ rtrim(rtrim(number_format((float) $d->meses, 2, '.', ''), '0'), '.') }} m @endif
                                        </span>
                                    @empty
                                        <small class="text-muted">Sin dosis: se usa la edad inicial como única dosis.</small>
                                    @endforelse
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirDosis({{ $v->id }})">
                                        <i class="bi bi-list-ol me-1" aria-hidden="true"></i> Dosis
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirVacuna({{ $v->id }})" aria-label="Editar {{ $v->vacuna }}">
                                        <i class="bi bi-pencil" aria-hidden="true"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="abrirBorrar({{ $v->id }})" aria-label="Eliminar {{ $v->vacuna }}">
                                        <i class="bi bi-trash" aria-hidden="true"></i>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    {{ trim($search) === '' ? 'Todavía no hay vacunas en el catálogo inicial. Agrega la primera con «Nueva vacuna».' : 'No se encontraron vacunas.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($vacunas->hasPages())
            <div class="card-footer bg-white">{{ $vacunas->links() }}</div>
        @endif
    </div>

    {{-- ============================ Alta y edición de la vacuna ============================ --}}
    @if ($modal === 'vacuna')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-vacuna" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarVacuna">
                        <div class="modal-header">
                            <h5 class="modal-title" id="titulo-vacuna">{{ $vacunaId ? 'Editar la vacuna inicial' : 'Nueva vacuna inicial' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label" for="vac-codigo">Código</label>
                                    <input type="text" id="vac-codigo" class="form-control text-uppercase" maxlength="4" wire:model.defer="codi_vacuna">
                                    @error('codi_vacuna') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-9">
                                    <label class="form-label" for="vac-nombre">Vacuna</label>
                                    <input type="text" id="vac-nombre" class="form-control" maxlength="100" wire:model.defer="vacuna">
                                    @error('vacuna') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="vac-edad">Edad inicial (meses)</label>
                                    <input type="number" min="0" id="vac-edad" class="form-control" wire:model.defer="edad_inicial_meses">
                                    @error('edad_inicial_meses') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="vac-fija">Tipo</label>
                                    <select id="vac-fija" class="form-select" wire:model.defer="fija">
                                        @foreach (\App\Clinica\Vacunas\ConvencionDeVacunas::FIJA as $codigo => $texto)
                                            <option value="{{ $codigo }}">{{ $texto }}</option>
                                        @endforeach
                                    </select>
                                    @error('fija') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="vac-sexo">Aplica a</label>
                                    <select id="vac-sexo" class="form-select" wire:model.defer="sexo">
                                        @foreach (\App\Clinica\Vacunas\ConvencionDeVacunas::SEXO as $codigo => $texto)
                                            <option value="{{ $codigo }}">{{ $texto }}</option>
                                        @endforeach
                                    </select>
                                    @error('sexo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="vac-cobertura">Cobertura</label>
                                    <input type="text" id="vac-cobertura" class="form-control" maxlength="200" wire:model.defer="cobertura"
                                           placeholder="Contra qué enfermedades protege">
                                    @error('cobertura') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrar">Cancelar</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardarVacuna">
                                <i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Dosis de una vacuna ============================ --}}
    @if ($modal === 'dosis' && $seleccionada)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-dosis" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-dosis">Dosis de {{ $seleccionada->vacuna }}</h5>
                        <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <x-ui.avisos />

                        <p class="text-muted small">
                            El rótulo de la dosis es lo que la identifica: un <strong>refuerzo</strong> se escribe en el rótulo (por ejemplo «Refuerzo»).
                            Sin dosis, la pantalla del médico usa la edad inicial de la vacuna como única dosis.
                        </p>

                        <div class="table-responsive mb-3">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr><th>N.º</th><th>Rótulo</th><th>Edad (meses)</th><th class="text-end">Acciones</th></tr>
                                </thead>
                                <tbody>
                                    @forelse ($dosisDeLaSeleccionada as $d)
                                        <tr wire:key="dosis-{{ $d->id }}">
                                            <td>{{ $d->dosis }}</td>
                                            <td>{{ $d->dosis_descri ?: '—' }}</td>
                                            <td>{{ $d->meses === null ? '—' : rtrim(rtrim(number_format((float) $d->meses, 2, '.', ''), '0'), '.') }}</td>
                                            <td class="text-end text-nowrap">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="editarDosis({{ $d->id }})" aria-label="Editar la dosis {{ $d->dosis }}">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" wire:click="quitarDosis({{ $d->id }})" aria-label="Eliminar la dosis {{ $d->dosis }}">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="text-center text-muted py-3">Esta vacuna todavía no tiene dosis.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <form wire:submit.prevent="guardarDosis" class="border rounded p-3">
                            <h6 class="mb-3">{{ $dosisId ? 'Editar la dosis' : 'Agregar una dosis' }}</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label" for="dos-numero">N.º de dosis</label>
                                    <input type="number" min="1" id="dos-numero" class="form-control" wire:model.defer="dosis_numero">
                                    @error('dosis_numero') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="dos-rotulo">Rótulo</label>
                                    <input type="text" id="dos-rotulo" class="form-control" maxlength="50" wire:model.defer="dosis_descri" placeholder="1era. Dosis, Refuerzo…">
                                    @error('dosis_descri') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="dos-meses">Edad (meses)</label>
                                    <input type="number" min="0" step="any" id="dos-meses" class="form-control" wire:model.defer="meses">
                                    @error('meses') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                @if ($dosisId)
                                    <button type="button" class="btn btn-outline-secondary" wire:click="nuevaDosis">Cancelar edición</button>
                                @endif
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="guardarDosis">
                                    <i class="bi bi-check2 me-1" aria-hidden="true"></i> {{ $dosisId ? 'Guardar la dosis' : 'Agregar la dosis' }}
                                </button>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrar">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================ Eliminar una vacuna ============================ --}}
    @if ($modal === 'borrar' && $seleccionada)
        @php $cuantas = $dosisDeLaSeleccionada->count(); @endphp
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="titulo-borrar" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="titulo-borrar">Eliminar «{{ $seleccionada->vacuna }}»</h5>
                        <button type="button" class="btn-close" wire:click="cerrar" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p>Se quita del catálogo inicial. Los consultorios que ya la copiaron la conservan; los que no, ya no la recibirán.</p>
                        @if ($cuantas > 0)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirma-borrado" wire:model="confirmaBorrado">
                                <label class="form-check-label" for="confirma-borrado">
                                    Entiendo que se borran también sus {{ $cuantas }} {{ $cuantas === 1 ? 'dosis' : 'dosis' }}.
                                </label>
                            </div>
                            @error('confirmaBorrado') <span class="text-danger small d-block mt-2">{{ $message }}</span> @enderror
                        @endif
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="cerrar">Cancelar</button>
                        <button type="button" class="btn btn-danger" wire:click="borrarVacuna" wire:loading.attr="disabled" wire:target="borrarVacuna">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i> Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
