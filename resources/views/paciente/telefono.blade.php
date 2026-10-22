@extends('paciente.layout')

@section('titulo', 'Confirma tu teléfono · Doctorísimo')

@section('contenido')
    <a href="{{ route('paciente.citas') }}" class="paciente-volver">
        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a mis citas
    </a>

    <div class="paciente-tarjeta">
        <h1 class="paciente-titulo">Confirma tu teléfono</h1>
        <p class="paciente-bajada">
            @if ($vigente)
                Tu teléfono está confirmado. Puedes cambiarlo si es otro.
            @else
                Pasó más de un año desde la última vez que confirmaste tu teléfono. Confírmalo con un código, o cámbialo si ahora es otro, para poder reservar.
            @endif
        </p>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($enCurso)
            <p>Enviamos un código de 6 dígitos a <strong>{{ $telefonoVisible }}</strong>. Escríbelo para continuar.</p>

            @if ($codigoDesarrollo)
                <div class="alert alert-warning" role="status">
                    <strong>Modo desarrollo:</strong> el código es <span class="paciente-codigo-dev">{{ $codigoDesarrollo }}</span>.
                </div>
            @endif

            <form method="POST" action="{{ route('paciente.telefono.confirmar') }}">
                @csrf
                <div class="mb-3">
                    <label for="codigo" class="form-label">Código de 6 dígitos</label>
                    <input type="text" class="form-control form-control-lg paciente-codigo" id="codigo" name="codigo"
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">Confirmar teléfono</button>
            </form>

            <form method="POST" action="{{ route('paciente.telefono.codigo') }}" class="mt-3">
                @csrf
                <input type="hidden" name="telefono" value="{{ $enCurso['telefono'] }}">
                <input type="hidden" name="reenviar" value="1">
                <button type="submit" class="btn btn-link p-0">No me llegó: reenviar código</button>
            </form>
        @else
            <form method="POST" action="{{ route('paciente.telefono.codigo') }}">
                @csrf
                <div class="mb-3">
                    <label for="telefono" class="form-label">Teléfono</label>
                    <input type="tel" class="form-control form-control-lg @error('telefono') is-invalid @enderror" id="telefono" name="telefono"
                           value="{{ old('telefono', $telefonoActual) }}" placeholder="0412-1234567" required>
                    <div class="form-text">Déjalo como está para confirmarlo, o escribe el nuevo para cambiarlo. Te enviamos un código ahí.</div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg w-100">Enviar código</button>
            </form>
        @endif
    </div>
@endsection
