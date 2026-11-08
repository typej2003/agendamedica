@extends('clinica.layout')

@section('titulo', 'Textos de los informes')

@section('contenido')
    <x-ui.page-head titulo="Textos de los informes"
                    subtitulo="Lo que el cardiólogo escribe una vez y se precarga en sus estudios." />

    <x-ui.errores />

    @if (session('estado'))
        <div class="alert alert-success" role="status">{{ session('estado') }}</div>
    @endif

    <x-ui.tarjeta titulo="Textos por defecto" icono="bi-file-earmark-text">
        <p class="texto-tenue">
            Son los textos con los que empieza cada informe: el del <strong>Doppler de miembros superiores</strong>, el
            <strong>protocolo del marcapasos</strong> y su <strong>texto de marca</strong>. Al abrir el estudio sin datos,
            la web los pone en el campo y el médico los puede corregir.
        </p>

        <form method="POST" action="{{ route('clinica.configuracion.textos-cardiacos.guardar') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label" for="duplex_superior">Doppler de miembros superiores</label>
                <textarea class="form-control" id="duplex_superior" name="duplex_superior" rows="4" maxlength="5000">{{ old('duplex_superior', $fila->duplex_superior ?? '') }}</textarea>
            </div>

            <div class="mb-3">
                <label class="form-label" for="protocolo_marcapaso">Protocolo del marcapasos</label>
                <textarea class="form-control" id="protocolo_marcapaso" name="protocolo_marcapaso" rows="4" maxlength="5000">{{ old('protocolo_marcapaso', $fila->protocolo_marcapaso ?? '') }}</textarea>
            </div>

            <div class="mb-3">
                <label class="form-label" for="texto_marca">Texto de marca del marcapasos</label>
                <textarea class="form-control" id="texto_marca" name="texto_marca" rows="4" maxlength="5000">{{ old('texto_marca', $fila->texto_marca ?? '') }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar los textos
            </button>
        </form>
    </x-ui.tarjeta>
@endsection
