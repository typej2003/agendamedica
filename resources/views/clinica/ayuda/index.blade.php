{{-- "Ayúdanos a mejorar" (ROADMAP §9): acá el médico o su secretaría nos manda un comentario —un error, una
     sugerencia o algo que le falta— y ve los suyos con nuestra respuesta. La bandeja donde los recibimos
     nosotros es otra pantalla (el panel, /admin/comentarios). --}}
@extends('clinica.layout')

@section('titulo', 'Ayúdanos a mejorar')

@section('contenido')
    <x-ui.page-head titulo="Ayúdanos a mejorar"
                    subtitulo="Contanos si algo no funciona, si te falta algo o si se te ocurre cómo mejorarlo. Te respondemos acá mismo." />

    @if (session('estado'))
        <x-ui.estado tono="exito" icono="bi-check2-circle">{{ session('estado') }}</x-ui.estado>
    @endif

    <form method="POST" action="{{ route('clinica.ayuda.guardar') }}" class="card mb-4">
        @csrf
        <div class="card-body">
            <div class="mb-3">
                <label for="tipo" class="form-label">¿Qué nos querés contar?</label>
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
