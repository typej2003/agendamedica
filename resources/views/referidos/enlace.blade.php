@extends('layouts.app')

@section('title', 'Invitación a Doctorísimo')
@section('description', 'Un colega te invitó a Doctorísimo. Descarga la app o crea tu cuenta para gestionar tu consultorio.')
@section('robots', 'noindex, nofollow')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/paciente.css') }}">
@endpush

@section('content')
    <div class="container paciente-contenido py-5">
        <div class="row justify-content-center">
            <div class="col-12 col-lg-7">
                <div class="paciente-tarjeta">
                    @if ($codigo)
                        <div class="paciente-registro-encabezado">
                            <h1>Un colega te invitó a Doctorísimo</h1>
                            <p>
                                Gestiona tu consultorio, tu agenda y tus pacientes desde la web y desde la app.
                                Cuando tu registro sea aprobado, los dos reciben Latidos de bienvenida.
                            </p>
                        </div>

                        <div class="border rounded p-3 bg-body-tertiary mb-4 text-center">
                            <div class="small text-muted mb-1">Tu código de invitación</div>
                            <div class="fs-4 fw-semibold"><code>{{ $codigo }}</code></div>
                            <div class="small text-muted">Escríbelo al registrarte si no llegas desde este link.</div>
                        </div>

                        <div class="d-grid gap-2">
                            @if ($urlDescarga)
                                <a href="{{ $urlDescarga }}" class="btn btn-primary btn-lg" rel="noopener">
                                    <i class="bi bi-phone me-1" aria-hidden="true"></i> Descargar la app
                                </a>
                            @else
                                <div class="alert alert-secondary mb-0" role="status">
                                    La app estará disponible pronto. Mientras tanto, puedes crear tu cuenta en la web.
                                </div>
                            @endif

                            <a href="{{ $urlRegistro }}" class="btn btn-outline-primary">
                                <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Crear mi cuenta
                            </a>
                        </div>

                        <p class="paciente-letra-chica mt-4 mb-0">
                            El código queda guardado en este navegador por 90 días para que tu registro quede
                            asociado a la invitación.
                        </p>
                    @else
                        <div class="paciente-registro-encabezado">
                            <h1>Esta invitación no está vigente</h1>
                            <p>El link que abriste ya no está activo. Puedes crear tu cuenta igual; el registro
                                funciona sin invitación.</p>
                        </div>

                        <a href="{{ $urlRegistro }}" class="btn btn-primary">
                            <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Crear mi cuenta
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
