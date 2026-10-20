<div>
    <x-ui.page-head titulo="Ayúdanos a mejorar"
                    subtitulo="Lo que nos mandan desde el consultorio: errores, sugerencias y cosas que faltan. Respondé o descartá." />

    <x-ui.avisos />

    {{-- Las pestañas por estado son lo que permite ir marcando lo atendido sin perder de vista lo que falta:
         abre en «Nuevos», que es lo que espera respuesta. --}}
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="btn-group" role="group" aria-label="Filtrar por estado">
                @foreach (['nuevo' => 'Nuevos', 'respondido' => 'Respondidos', 'descartado' => 'Descartados', 'todos' => 'Todos'] as $clave => $texto)
                    <button type="button"
                            class="btn btn-sm {{ $estado === $clave ? 'btn-primary' : 'btn-outline-secondary' }}"
                            wire:click="$set('estado', '{{ $clave }}')">
                        {{ $texto }}
                        <span class="badge text-bg-light">
                            {{ $clave === 'todos' ? array_sum($conteos) : ($conteos[$clave] ?? 0) }}
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @if ($seleccionado)
        <div class="card mb-3 border-primary">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold">{{ $seleccionado->tipoLegible() }}</div>
                    <small class="text-muted">
                        {{ $seleccionado->usuario?->name ?? 'Cuenta borrada' }}
                        @if ($seleccionado->rol) · {{ $seleccionado->rol }} @endif
                        @if ($seleccionado->medico) · {{ trim($seleccionado->medico->name . ' ' . $seleccionado->medico->lastname) }} ({{ $seleccionado->reg_medico }}) @endif
                        · {{ \App\Support\HoraLocal::fechaHora($seleccionado->created_at) }}
                    </small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cerrar">Cerrar</button>
            </div>

            <div class="card-body">
                <p class="mb-3" style="white-space: pre-line">{{ $seleccionado->mensaje }}</p>

                {{-- Las capturas adjuntas (O-25): se ven acá y se abren a tamaño real. La ruta del disco privado
                     no se expone: el archivo lo sirve la ruta autorizada de la bandeja. --}}
                @if ($seleccionado->imagenes->isNotEmpty())
                    <div class="adjuntos mb-3">
                        <p class="text-muted small mb-2">
                            <i class="bi bi-images" aria-hidden="true"></i>
                            {{ $seleccionado->imagenes->count() === 1 ? 'Una captura adjunta' : $seleccionado->imagenes->count() . ' capturas adjuntas' }}
                        </p>
                        <ul class="adjuntos-lista">
                            @foreach ($seleccionado->imagenes as $imagen)
                                @php($urlImagen = route('admin.comentarios.imagen', ['comentario' => $seleccionado->id, 'imagen' => $imagen->id]))
                                <li class="adjunto">
                                    <a href="{{ $urlImagen }}" target="_blank" rel="noopener"
                                       aria-label="Abrir la captura {{ $loop->iteration }} de {{ $seleccionado->imagenes->count() }}">
                                        <img class="adjunto-miniatura" src="{{ $urlImagen }}"
                                             alt="Captura adjunta {{ $loop->iteration }}: {{ $imagen->nombreVisible() }}">
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($seleccionado->fueRespondido() && $seleccionado->respondido_el)
                    <p class="text-muted small mb-3">
                        Respondido por {{ $seleccionado->respondedor?->name ?? '—' }}
                        el {{ \App\Support\HoraLocal::fechaHora($seleccionado->respondido_el) }}.
                    </p>
                @endif

                @if ($seleccionado->contexto)
                    <details class="mb-3">
                        <summary class="text-muted small">Lo que mandó de contexto</summary>
                        <pre class="small mb-0">{{ json_encode($seleccionado->contexto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                @endif

                <div class="mb-2">
                    <label for="respuesta" class="form-label">Respuesta (la ve el usuario en su pantalla)</label>
                    <textarea id="respuesta" rows="4" maxlength="2000"
                              class="form-control @error('respuesta') is-invalid @enderror"
                              wire:model.defer="respuesta"
                              placeholder="Contale qué pasó, qué hicimos, o qué va a pasar con esto."></textarea>
                    @error('respuesta')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" wire:click="responder" wire:loading.attr="disabled">
                        Enviar respuesta
                    </button>
                    <button type="button" class="btn btn-outline-secondary"
                            wire:click="descartar({{ $seleccionado->id }})" wire:loading.attr="disabled">
                        Descartar
                    </button>
                </div>
            </div>
        </div>
    @endif

    <div class="card">
        <x-ui.cargando cubre wire:loading.delay.flex />

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Quién</th>
                            <th>Tipo</th>
                            <th>Comentario</th>
                            <th>Cuándo</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($comentarios as $comentario)
                            <tr wire:key="comentario-{{ $comentario->id }}">
                                <td>
                                    <div class="fw-semibold">{{ $comentario->usuario?->name ?? 'Cuenta borrada' }}</div>
                                    <small class="text-muted">
                                        {{ $comentario->rol ?? '—' }}
                                        @if ($comentario->medico) · {{ $comentario->reg_medico }} @endif
                                    </small>
                                </td>
                                <td>{{ $comentario->tipoLegible() }}</td>
                                <td>
                                    <div>{{ \Illuminate\Support\Str::limit($comentario->mensaje, 90) }}</div>
                                    {{-- Miniaturas de lo que adjuntó (O-25): la captura es la mitad del reporte, así
                                         que se ve en la propia fila sin abrir el comentario. --}}
                                    @if ($comentario->imagenes->isNotEmpty())
                                        <ul class="adjuntos-lista adjuntos-lista-chica mb-0 mt-1">
                                            @foreach ($comentario->imagenes as $imagen)
                                                @php($urlImagen = route('admin.comentarios.imagen', ['comentario' => $comentario->id, 'imagen' => $imagen->id]))
                                                <li class="adjunto">
                                                    <a href="{{ $urlImagen }}" target="_blank" rel="noopener"
                                                       aria-label="Abrir la captura {{ $loop->iteration }} del comentario">
                                                        <img class="adjunto-miniatura" src="{{ $urlImagen }}"
                                                             alt="Captura adjunta {{ $loop->iteration }}">
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                                <td class="fecha-celda">
                                    <div>{{ \App\Support\HoraLocal::instante($comentario->created_at)?->format('d/m/Y') }}</div>
                                    <small class="text-muted">{{ $comentario->created_at?->diffForHumans() }}</small>
                                </td>
                                <td>
                                    @if ($comentario->fueRespondido())
                                        <x-ui.estado tono="exito">Respondido</x-ui.estado>
                                    @elseif ($comentario->fueDescartado())
                                        <x-ui.estado tono="neutro">Descartado</x-ui.estado>
                                    @else
                                        <x-ui.estado tono="aviso">Nuevo</x-ui.estado>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            wire:click="abrir({{ $comentario->id }})">
                                        {{ $comentario->estaNuevo() ? 'Responder' : 'Ver' }}
                                    </button>
                                    @unless ($comentario->estaNuevo())
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                                wire:click="reabrir({{ $comentario->id }})">
                                            Reabrir
                                        </button>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    {{ $estado === 'nuevo' ? 'No hay comentarios sin responder.' : 'No hay comentarios en esta lista.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($comentarios->hasPages())
            <div class="card-footer bg-white">
                {{ $comentarios->links() }}
            </div>
        @endif
    </div>
</div>
