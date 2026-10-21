{{-- "Ayúdanos a mejorar" (ROADMAP §9): acá el médico o su secretaría nos manda un comentario —un error, una
     sugerencia o algo que le falta— y ve los suyos con nuestra respuesta. La bandeja donde los recibimos
     nosotros es otra pantalla (el panel, /admin/comentarios).

     Desde O-25 el comentario puede llevar **hasta 3 imágenes**: se pega una captura con Ctrl+V en el campo
     (el camino principal) o se eligen archivos (la alternativa). La vista no lleva lógica ni estilos: el
     comportamiento está en `public/js/clinica/ayuda.js` y las clases, en `doctorisimo-ui.css`. --}}
@extends('clinica.layout')

@section('titulo', 'Ayúdanos a mejorar')

@section('contenido')
    <x-ui.page-head titulo="Ayúdanos a mejorar"
                    subtitulo="Contanos si algo no funciona, si te falta algo o si se te ocurre cómo mejorarlo. Te respondemos acá mismo." />

    {{-- El aviso de «¡Gracias!» y el de error los pinta el armazón (`session('estado')` y `session('error')`):
         no se repiten acá. --}}

    {{-- `multipart/form-data` es obligatorio para que viajen las capturas. El nombre de cada archivo lo pone el
         servidor: el del cliente no se usa. --}}
    <form method="POST" action="{{ route('clinica.ayuda.guardar') }}" class="card mb-4"
          enctype="multipart/form-data" id="ayuda-form" data-ayuda>
        @csrf
        {{-- Lo llena el JS al enviar: si el servidor devuelve el formulario con errores, el navegador ya vació el
             campo de archivo y hay que avisarlo (los archivos no se pueden devolver al formulario). --}}
        <input type="hidden" name="con_capturas" value="" data-ayuda-con-capturas>
        <div class="card-body">
            @if ($errors->any() && (int) old('con_capturas') > 0)
                <p class="alert alert-warning d-flex align-items-center gap-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span>Tus capturas no se conservaron: vuelve a adjuntarlas antes de enviar.</span>
                </p>
            @endif
            <div class="mb-3">
                <label for="tipo" class="form-label">¿Qué nos quieres contar?</label>
                <select name="tipo" id="tipo" class="form-select @error('tipo') is-invalid @enderror" required>
                    @foreach ($vm->tipos() as $valor => $etiqueta)
                        <option value="{{ $valor }}" {{ old('tipo') === $valor ? 'selected' : '' }}>{{ $etiqueta }}</option>
                    @endforeach
                </select>
                @error('tipo')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="mensaje" class="form-label">Tu comentario</label>
                <textarea name="mensaje" id="mensaje" rows="5" maxlength="2000"
                          class="form-control @error('mensaje') is-invalid @enderror"
                          placeholder="Contanos qué pasó, en qué pantalla, y qué esperabas que pasara.">{{ old('mensaje') }}</textarea>
                @error('mensaje')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Las capturas (O-25). El campo de archivo es el que viaja al servidor: el JS le carga lo que se
                 pega, así que una captura pegada se envía igual que una elegida. --}}
            <div class="mb-3" data-ayuda-adjuntos>
                <label for="ayuda-imagenes" class="form-label">Capturas (opcional)</label>
                <input type="file" name="imagenes[]" id="ayuda-imagenes" multiple
                       class="form-control" accept="image/png,image/jpeg,image/webp"
                       data-ayuda-archivo aria-describedby="ayuda-adjuntos-ayuda ayuda-adjuntos-estado">
                <p class="texto-tenue small mt-2 mb-0" id="ayuda-adjuntos-ayuda">
                    Pega una captura con <kbd>Ctrl</kbd>+<kbd>V</kbd> en el comentario (en un equipo Apple,
                    <kbd>⌘</kbd>+<kbd>V</kbd>) o elige los archivos acá: hasta {{ $vm->maximoDeImagenes() }} imágenes,
                    {{ $vm->formatosAceptados() }} de hasta {{ $vm->tamanoMaximo() }} cada una.
                </p>

                <ul class="adjuntos-lista" data-ayuda-lista aria-live="polite" aria-label="Capturas adjuntas"></ul>

                <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" data-ayuda-aviso
                   id="ayuda-adjuntos-estado" hidden role="alert">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span></span>
                </p>

                @error('imagenes')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                @foreach ($vm->erroresDeImagenes($errors) as $mensajeImagen)
                    <div class="invalid-feedback d-block">{{ $mensajeImagen }}</div>
                @endforeach
            </div>

            <button type="submit" class="btn btn-primary">Enviar</button>
        </div>
    </form>

    <h2 class="h5">Mis comentarios</h2>

    @forelse ($vm->filas() as $fila)
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <strong>{{ $fila['tipo'] }}</strong>
                    <x-ui.estado tono="{{ $fila['tono'] }}">{{ $fila['estado'] }}</x-ui.estado>
                </div>

                <p class="mb-2">{{ $fila['mensaje'] }}</p>

                {{-- Las capturas que mandó, con la ruta autorizada del autor: el archivo vive en el disco privado
                     y no hay ninguna URL pública que lo alcance. --}}
                @if ($fila['imagenes'] !== [])
                    <ul class="adjuntos-lista mt-2 mb-2">
                        @foreach ($fila['imagenes'] as $imagen)
                            <li class="adjunto">
                                <a href="{{ $imagen['url'] }}" target="_blank" rel="noopener"
                                   aria-label="Abrir la captura {{ $loop->iteration }} de {{ count($fila['imagenes']) }}">
                                    <img class="adjunto-miniatura" src="{{ $imagen['url'] }}"
                                         alt="Captura adjunta {{ $loop->iteration }}: {{ $imagen['nombre'] }}">
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <p class="texto-tenue small mb-0">{{ $fila['cuando'] }}</p>

                @if ($fila['respuesta'])
                    <div class="border-start border-4 ps-3 mt-3">
                        <p class="texto-tenue small mb-1">{{ $fila['respondido'] }}</p>
                        <p class="mb-0">{{ $fila['respuesta'] }}</p>
                    </div>
                @elseif ($fila['explicacion'])
                    <p class="texto-tenue small mt-3 mb-0">{{ $fila['explicacion'] }}</p>
                @endif
            </div>
        </div>
    @empty
        <p class="texto-tenue">Todavía no mandaste ningún comentario.</p>
    @endforelse
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/ayuda.js') }}"></script>
@endsection
