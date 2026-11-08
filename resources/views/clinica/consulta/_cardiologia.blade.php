{{-- **Los estudios cardiológicos de la consulta** (CAR-2, 2026-11-04): el ecocardiograma transtorácico y las pestañas
     que sumen CAR-3 a CAR-5. El formulario es el de las secciones del internista: pestañas + campos del
     `MapaDelCardiologo`, y el guardado es explícito. --}}
<div class="consulta-seccion" id="{{ $seccion->id() }}">
    @if ($seccion->pestanas() !== [])
        <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" :etiqueta="$seccion->titulo()" />
    @endif

    <x-ui.tarjeta :titulo="$seccion->titulo()" :icono="$seccion->icono()">
        @if ($seccion->esArchivos())
            {{-- **Archivos PDF** (CAR-5): la lista de la consulta y la subida, como el «Archivos-PDF» del escritorio. --}}
            <ul class="list-group list-group-flush mb-3">
                @forelse ($seccion->archivos() as $archivo)
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span class="text-truncate">
                            <i class="bi bi-file-earmark-pdf text-danger me-1" aria-hidden="true"></i>
                            {{ $archivo['nombre'] }}
                        </span>
                        <span class="d-flex gap-2">
                            @if ($archivo['url'])
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $archivo['url'] }}">Descargar</a>
                            @endif
                            @if ($archivo['quitables'])
                                <form method="POST" action="{{ $archivo['urlQuitar'] }}"
                                      onsubmit="return confirm('¿Quitar este PDF de la consulta?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Quitar</button>
                                </form>
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="list-group-item text-center texto-tenue">Todavía no hay PDF en esta consulta.</li>
                @endforelse
            </ul>

            <form method="POST" action="{{ $seccion->urlSubirArchivo() }}" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-end">
                @csrf
                <div class="flex-grow-1">
                    <label class="form-label" for="archivo-pdf">Subir un PDF</label>
                    <input class="form-control @error('archivo') is-invalid @enderror" type="file" id="archivo-pdf" name="archivo" accept="application/pdf">
                    @error('archivo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i> Subir
                </button>
            </form>
        @else
        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="cardiologia-form">
            @csrf
            <input type="hidden" name="pestana" value="{{ $seccion->pestana() }}">

            <div class="row g-3">
                @php $grupoActual = null; @endphp
                @foreach ($seccion->campos() as $campo)
                    @if ($campo['grupo'] !== $grupoActual)
                        @php $grupoActual = $campo['grupo']; @endphp
                        @if ($grupoActual !== null)
                            <div class="col-12"><h3 class="h6 mt-2 mb-0">{{ $grupoActual }}</h3></div>
                        @endif
                    @endif
                    <div class="{{ $campo['columna'] }}">
                        <label class="form-label" for="{{ $campo['id'] }}">
                            {{ $campo['etiqueta'] }}
                            @if ($campo['unidad'])<span class="texto-tenue small">({{ $campo['unidad'] }})</span>@endif
                        </label>

                        @if ($campo['tipo'] === 'si_no')
                            <input type="hidden" name="{{ $campo['clave'] }}" value="N">
                            <div class="form-check mt-md-4">
                                <input class="form-check-input" type="checkbox" value="S"
                                       id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}" @checked($campo['marcado'])>
                                <label class="form-check-label" for="{{ $campo['id'] }}">{{ $campo['etiqueta'] }}</label>
                            </div>
                        @elseif ($campo['tipo'] === 'parrafo')
                            <textarea class="form-control @error($campo['clave']) is-invalid @enderror" rows="4"
                                      id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}"
                                      @if ($campo['max']) maxlength="{{ $campo['max'] }}" @endif>{{ $campo['valor'] }}</textarea>
                        @elseif ($campo['tipo'] === 'opcion')
                            <select class="form-select @error($campo['clave']) is-invalid @enderror"
                                    id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}">
                                <option value="">— Sin dato —</option>
                                @foreach ($campo['opciones'] as $valor => $texto)
                                    <option value="{{ $valor }}" @selected($campo['valor'] === (string) $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        @else
                            <input type="text" class="form-control @error($campo['clave']) is-invalid @enderror"
                                   id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}" value="{{ $campo['valor'] }}"
                                   autocomplete="off" @if ($campo['tipo'] === 'numero') inputmode="decimal" @endif
                                   @if ($campo['max']) maxlength="{{ $campo['max'] }}" @endif>
                        @endif

                        @error($campo['clave'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i> {{ $seccion->textoGuardar() }}
                </button>
            </div>
        </form>
        @endif
    </x-ui.tarjeta>
</div>
