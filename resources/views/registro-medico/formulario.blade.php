@extends('layouts.app')

@section('title', 'Registro de médicos · Doctorísimo')

@push('estilos')
    <link rel="stylesheet" href="{{ asset('css/paciente.css') }}">
@endpush

{{-- **Registro de médicos con aprobación** (ROADMAP §10.4, fase 2). Reusa los estilos del registro del paciente:
     es el mismo tipo de pantalla pública. No crea cuenta: deja una solicitud que revisa un administrador. --}}
@section('content')
    <div class="container paciente-contenido">
        <div class="paciente-registro">
            <div class="paciente-registro-encabezado">
                <h1>Regístrate como médico</h1>
                <p>Revisamos tus datos profesionales antes de activar tu cuenta. Te avisamos por correo cuando esté lista.</p>
            </div>

            <div class="paciente-tarjeta">
                @if (session('error'))
                    <div class="alert alert-warning" role="alert">{{ session('error') }}</div>
                @endif

                @if ($errors->any())
                    <div class="alert alert-danger" role="alert">
                        <strong>Revisa lo que marcamos:</strong>
                        <ul class="mb-0 mt-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('medico.registro.codigo.enviar') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-2">
                            <label for="prefijo" class="form-label">Trato</label>
                            <input type="text" class="form-control" id="prefijo" name="prefijo" maxlength="20"
                                   value="{{ old('prefijo', $previo['prefijo'] ?? '') }}" placeholder="Dr., Dra.">
                        </div>

                        <div class="col-md-5">
                            <label for="nombres" class="form-label">Nombre</label>
                            <input type="text" class="form-control @error('nombres') is-invalid @enderror" id="nombres" name="nombres"
                                   value="{{ old('nombres', $previo['nombres'] ?? '') }}" autocomplete="given-name" required>
                        </div>

                        <div class="col-md-5">
                            <label for="apellidos" class="form-label">Apellido</label>
                            <input type="text" class="form-control @error('apellidos') is-invalid @enderror" id="apellidos" name="apellidos"
                                   value="{{ old('apellidos', $previo['apellidos'] ?? '') }}" autocomplete="family-name" required>
                        </div>

                        <div class="col-md-4">
                            <label for="cedula" class="form-label">Cédula</label>
                            <input type="text" class="form-control @error('cedula') is-invalid @enderror" id="cedula" name="cedula"
                                   value="{{ old('cedula', $previo['cedula'] ?? '') }}" inputmode="numeric" placeholder="12345678" required>
                        </div>

                        <div class="col-md-4">
                            <label for="mpps" class="form-label">Registro MPPS</label>
                            <input type="text" class="form-control @error('mpps') is-invalid @enderror" id="mpps" name="mpps"
                                   value="{{ old('mpps', $previo['mpps'] ?? '') }}" inputmode="numeric" required>
                        </div>

                        <div class="col-md-4">
                            <label for="colegio" class="form-label">Colegio de Médicos <span class="text-muted">(opcional)</span></label>
                            <input type="text" class="form-control" id="colegio" name="colegio" maxlength="30"
                                   value="{{ old('colegio', $previo['colegio'] ?? '') }}">
                        </div>

                        <div class="col-md-6">
                            <label for="specialty_id" class="form-label">Especialidad</label>
                            <select class="form-select @error('specialty_id') is-invalid @enderror" id="specialty_id" name="specialty_id">
                                <option value="">Medicina general / otra</option>
                                @foreach ($especialidades as $especialidad)
                                    <option value="{{ $especialidad->id }}"
                                        @selected((string) old('specialty_id', $previo['specialty_id'] ?? '') === (string) $especialidad->id)>
                                        {{ $especialidad->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="ciudad" class="form-label">Ciudad <span class="text-muted">(opcional)</span></label>
                            <x-ui.selector-buscable name="ciudad" id="ciudad" libre maxlength="100" :value="$previo['ciudad'] ?? ''"
                                :url="route('medico.registro.ciudades')" placeholder="Escribe o elige tu ciudad" />
                        </div>

                        <div class="col-md-6">
                            <label for="telefono" class="form-label">Teléfono</label>
                            <input type="tel" class="form-control @error('telefono') is-invalid @enderror" id="telefono" name="telefono"
                                   value="{{ old('telefono', $previo['telefono'] ?? '') }}" autocomplete="tel" placeholder="0412-1234567" required>
                        </div>

                        <div class="col-md-6">
                            <label for="email" class="form-label">Correo</label>
                            <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
                                   value="{{ old('email', $previo['email'] ?? '') }}" autocomplete="email" placeholder="tucorreo@ejemplo.com" required>
                            <div class="form-text">Te enviamos un código para confirmarlo. Es con el que vas a iniciar sesión.</div>
                        </div>

                        <div class="col-12">
                            <label for="carnet" class="form-label">Foto del carnet <span class="text-muted">(opcional)</span></label>
                            <input type="file" class="form-control @error('carnet') is-invalid @enderror" id="carnet" name="carnet"
                                   accept="image/png,image/jpeg,image/webp"
                                   data-carnet-maximo="{{ $carnetMaximoBytes }}" aria-describedby="carnet-ayuda carnet-aviso">
                            <div class="form-text" id="carnet-ayuda">
                                @if (! empty($previo['carnet_ruta']))
                                    Ya adjuntaste una foto; elige otra solo si quieres reemplazarla.
                                @else
                                    Carnet del MPPS o del Colegio. {{ $carnetAceptado }}, hasta {{ $carnetMaximo }}. Acelera la revisión.
                                @endif
                            </div>
                            <div class="form-text" id="carnet-aviso" hidden></div>
                        </div>

                        <div class="col-12">
                            <label for="mensaje" class="form-label">Algo más que debamos saber <span class="text-muted">(opcional)</span></label>
                            <textarea class="form-control" id="mensaje" name="mensaje" rows="2" maxlength="1000"
                                      placeholder="Por ejemplo, si ya usas GinecoReport en tu consultorio.">{{ old('mensaje', $previo['mensaje'] ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Continuar y verificar mi correo</button>
                        <a href="{{ route('login') }}" class="btn btn-outline-secondary">Ya tengo cuenta</a>
                    </div>

                    <p class="paciente-letra-chica mt-3 mb-0">
                        Usamos estos datos solo para verificar que eres médico y para crear tu cuenta.
                    </p>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('js')
    <script src="{{ asset('js/registro-medico-carnet.js') }}"></script>
@endpush
