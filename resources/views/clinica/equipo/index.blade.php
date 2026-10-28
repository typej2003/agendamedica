@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" />

    <x-ui.errores />

    @if ($vm->linkInvitacion)
        <div class="alert alert-success" role="status">
            <p class="mb-2">
                Invitación para <strong>{{ $vm->linkInvitacion['email'] }}</strong>. Si el correo no llega,
                comparte este enlace:
            </p>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <code id="enlace-invitacion" class="text-break">{{ $vm->linkInvitacion['url'] }}</code>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-copiar="enlace-invitacion">
                    <i class="bi bi-clipboard" aria-hidden="true"></i> Copiar
                </button>
            </div>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header bg-white py-3"><strong>{{ $vm->nombreEquipo() }}</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="texto-tenue small">Plan del consultorio</div>
                    <div class="fw-semibold">{{ $vm->planNombre() }}</div>
                </div>
                <div class="col-md-4">
                    <div class="texto-tenue small">Médicos</div>
                    <div class="fw-semibold">{{ $vm->cupoMedicos() }}</div>
                </div>
                <div class="col-md-4">
                    <div class="texto-tenue small">Secretarías</div>
                    <div class="fw-semibold">
                        {{ $vm->cupoSecretarias() }}
                        <a class="small ms-1" href="{{ route('clinica.usuarios') }}">Gestionar</a>
                    </div>
                </div>
            </div>

            @if ($vm->esCentro())
                <p class="small texto-tenue mt-3 mb-0">
                    Este registro es un centro médico: el centro no cuenta como médico. Cuentan los doctores vinculados.
                </p>
            @endif
        </div>
    </div>

    @if ($vm->aviso())
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>{{ $vm->aviso() }}</span>
            @if ($vm->puedeGestionar)
                <a class="alert-link" href="{{ route('clinica.plan') }}">Mejorar plan</a>
            @endif
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header bg-white py-3"><strong>Médicos del equipo</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Médico</th>
                            <th>Registro</th>
                            <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($vm->filasMedicos() as $fila)
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        {{ $fila['nombre'] }}
                                        @if ($fila['esDuenio'])<span class="badge bg-primary ms-1">Dueño</span>@endif
                                        @if ($fila['esTu'])<span class="badge bg-light text-dark border ms-1">Tú</span>@endif
                                    </div>
                                    <small class="text-muted">{{ $fila['correo'] }}</small>
                                </td>
                                <td><small class="text-muted">{{ $fila['registro'] }}</small></td>
                                <td class="text-end">
                                    @if ($fila['puedeQuitar'])
                                        <form method="POST" action="{{ route('clinica.equipo.quitar', $fila['id']) }}"
                                              data-confirmar="¿Quitar a {{ $fila['nombre'] }} del equipo? No se borra su ficha ni sus pacientes; sólo pierde el acceso a este consultorio.">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-person-x" aria-hidden="true"></i> Quitar del equipo
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-white py-3"><strong>Invitaciones pendientes</strong></div>
        <div class="card-body p-0">
            @if (empty($vm->filasInvitaciones()))
                <p class="text-muted p-3 mb-0">No hay invitaciones pendientes.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Correo</th>
                                <th>Enviada</th>
                                <th>Vence</th>
                                <th class="text-end"><span class="visually-hidden">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($vm->filasInvitaciones() as $fila)
                                <tr>
                                    <td>{{ $fila['email'] }}</td>
                                    <td><small class="text-muted">{{ $fila['enviada'] }}</small></td>
                                    <td><small class="text-muted">{{ $fila['expira'] }}</small></td>
                                    <td class="text-end text-nowrap">
                                        <code id="enlace-invitacion-{{ $fila['id'] }}" class="d-none">{{ $fila['url'] }}</code>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-copiar="enlace-invitacion-{{ $fila['id'] }}">
                                            <i class="bi bi-clipboard" aria-hidden="true"></i> Copiar enlace
                                        </button>
                                        @if ($vm->puedeGestionar)
                                            <form method="POST" action="{{ route('clinica.equipo.revocar', $fila['id']) }}" class="d-inline"
                                                  data-confirmar="¿Revocar la invitación de {{ $fila['email'] }}?">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-danger">Revocar</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if ($vm->puedeInvitar)
        <div class="card mb-4">
            <div class="card-header bg-white py-3"><strong>Invitar a un médico</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('clinica.equipo.invitar') }}" class="row g-2 align-items-end">
                    @csrf
                    <x-ui.campo etiqueta="Correo del médico" for="invitar-email" error="email" class="col-md-8" :requerido="true"
                                ayuda="Tiene que ser el correo con el que entra a Doctorísimo. Si todavía no tiene cuenta, que se registre como médico con ese mismo correo.">
                        <input type="email" name="email" id="invitar-email" class="form-control" maxlength="150" autocomplete="off" value="{{ old('email') }}">
                    </x-ui.campo>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100">
                            <i class="bi bi-envelope-paper me-1" aria-hidden="true"></i> Enviar invitación
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <p class="texto-tenue small">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        La invitación no da acceso hasta que el médico la acepta. Las invitaciones pendientes no ocupan cupo.
        Quitar a un médico sólo borra el vínculo con este consultorio: su ficha, su registro y sus pacientes siguen intactos.
    </p>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/usuarios.js') }}"></script>
@endsection
