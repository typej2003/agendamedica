<div>
    @php
        $nombre = $medico->nombre_mostrar;
        $fecha = fn ($f, $conHora = true) => $f ? ($conHora ? \App\Support\HoraLocal::fechaHora($f) : \Illuminate\Support\Carbon::parse($f)->format(\App\Support\Regionalizacion::formatoActual())) : null;
    @endphp

    @include('livewire.admin.sedes._migas', ['migas' => [
        ['Médicos', route('admin.cuentas')],
        [$nombre, null],
    ]])

    <x-ui.page-head :titulo="$nombre" subtitulo="Detalle del usuario. Administra acá sus roles y accesos, sus especialidades, su plan, sus secretarías y la configuración de su consultorio.">
        <x-slot name="acciones">
            <a href="{{ route('admin.cuentas') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver
            </a>
            @if ($editando)
                <button type="button" class="btn btn-outline-secondary" wire:click="cancelarEdicion">
                    <i class="bi bi-x-lg" aria-hidden="true"></i> Cancelar edición
                </button>
            @else
                {{-- Abre el formulario de datos en la pestaña «Datos», sin salir de la ficha. --}}
                <button type="button" class="btn btn-primary" wire:click="abrirEdicion">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i> Editar
                </button>
            @endif
        </x-slot>
    </x-ui.page-head>

    <x-ui.avisos />

    <ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist" aria-label="Secciones del médico">
        @foreach ($pestanas as $clave => [$rotulo, $icono])
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" id="pestana-{{ $clave }}" aria-selected="{{ $pestana === $clave ? 'true' : 'false' }}"
                        aria-controls="panel-pestana" wire:click="irA('{{ $clave }}')" wire:key="pestana-{{ $clave }}"
                        class="nav-link text-nowrap {{ $pestana === $clave ? 'active' : '' }}">
                    <i class="bi {{ $icono }} me-1" aria-hidden="true"></i> {{ $rotulo }}
                </button>
            </li>
        @endforeach
    </ul>

    <div class="card" id="panel-pestana" role="tabpanel" aria-labelledby="pestana-{{ $pestana }}">
        <x-ui.cargando cubre wire:loading.delay.flex />
        <div class="card-body">

            {{-- ============================ Datos ============================ --}}
            @if ($pestana === 'datos')
                @if ($editando)
                    {{-- El mismo formulario que el diálogo de Usuarios, pero acá mismo. --}}
                    <h2 class="h6 mb-3">Editar los datos del médico</h2>
                    <x-admin.medico-datos-form :formatos="$formatosDeReporte" :inline="true"
                                               :es-centro="$es_centro" :reg-medico="$reg_medico"
                                               accion-guardar="guardarEdicion" accion-cancelar="cancelarEdicion" />
                @else
                    <dl class="datos-rejilla">
                        <x-ui.dato etiqueta="Nombre" :valor="$medico->name . ' ' . $medico->lastname" />
                        <x-ui.dato etiqueta="Prefijo" :valor="$medico->prefix" />
                        <x-ui.dato etiqueta="Correo" :valor="$medico->email" />
                        <x-ui.dato etiqueta="Teléfono" :valor="$medico->phone" />
                        <x-ui.dato etiqueta="Colegiatura" :valor="$medico->license_number" />
                        <x-ui.dato etiqueta="reg_medico" :valor="$medico->reg_medico" />
                        <x-ui.dato etiqueta="Tipo" :valor="$medico->es_centro ? 'Centro médico' : 'Médico'" />
                        @if ($medico->es_centro)
                            <x-ui.dato etiqueta="Membrete de los reportes" :valor="$medico->formato_reportes === 'medico' ? 'El del doctor' : 'El del centro'" />
                        @endif
                        <x-ui.dato etiqueta="Cuenta de acceso">
                            @if (! $cuenta)
                                <x-ui.estado tono="neutro">Sin acceso</x-ui.estado>
                            @elseif ($cuenta->is_active === false)
                                <x-ui.estado tono="peligro" icono="bi-slash-circle">Bloqueada</x-ui.estado>
                                @if ($cuenta->blocked_reason) <small class="text-muted d-block">{{ $cuenta->blocked_reason }}</small> @endif
                            @else
                                <x-ui.estado tono="exito" icono="bi-check-circle">Activa</x-ui.estado>
                                @if ($cuenta->must_change_password) <x-ui.estado tono="aviso">Clave temporal</x-ui.estado> @endif
                            @endif
                        </x-ui.dato>
                        <x-ui.dato etiqueta="Roles" :valor="$cuenta ? $cuenta->roles->pluck('name')->implode(', ') : null" />
                        <x-ui.dato etiqueta="Ficha médica activa" :valor="$medico->is_active === false || $medico->is_active === 0 ? 'No' : 'Sí'" />
                        <x-ui.dato etiqueta="Alta" :valor="$fecha($medico->created_at)" />
                        <x-ui.dato etiqueta="Última modificación" :valor="$fecha($medico->updated_at)" />
                        <x-ui.dato etiqueta="Último acceso a la web" :valor="$fecha($datos['sync']['ultima_web'] ?? null) ?? 'Sin registro'" />
                        <x-ui.dato etiqueta="Última actividad del app" :valor="$fecha($datos['sync']['ultima_app'] ?? null) ?? 'Sin registro'" />
                        <x-ui.dato etiqueta="Última sincronización del escritorio" :valor="$fecha($datos['sync']['ultima_escritorio'] ?? null) ?? 'Sin registro'" />
                    </dl>
                @endif

                <h2 class="h6 mt-4">Verificación</h2>
                <div class="d-flex flex-wrap gap-4">
                    @foreach ($datos['verificaciones'] as $tipo => $verificacion)
                        <div wire:key="verificacion-{{ $tipo }}">
                            <small class="text-muted d-block">{{ \App\Models\VerificacionDeMedico::nombre($tipo) }}</small>
                            <x-ui.estado :tono="\App\Models\VerificacionDeMedico::tono($verificacion->estado)">
                                {{ str_replace('_', ' ', $verificacion->estado) }}
                            </x-ui.estado>
                            @if ($verificacion->nota)
                                <small class="text-muted d-block">{{ $verificacion->nota }}</small>
                            @endif
                        </div>
                    @endforeach
                </div>
                @unless ($medico->es_centro)
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-success"
                                wire:click="marcarCarnet('verificado')" wire:loading.attr="disabled">
                            Carnet verificado
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-warning"
                                wire:click="marcarCarnet('pendiente')" wire:loading.attr="disabled">
                            Pendiente
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                wire:click="marcarCarnet('rechazado')" wire:loading.attr="disabled">
                            Rechazar carnet
                        </button>
                    </div>
                @endunless
                <p class="text-muted small mt-2 mb-0">
                    El carnet se valida acá o desde
                    <a href="{{ route('admin.solicitudes-medicos') }}">Solicitudes de médicos</a>.
                    El teléfono se verificará por WhatsApp o SMS desde el perfil del médico.
                </p>

                <h2 class="h6 mt-4">Registros de datos</h2>
                @if (count($registros))
                    <div class="d-flex flex-wrap gap-2">
                        @foreach ($registros as $reg)
                            <span class="badge bg-light text-dark border">{{ $reg }}</span>
                        @endforeach
                    </div>
                @else
                    <p class="text-muted mb-0">Esta ficha no tiene <code>reg_medico</code>.</p>
                @endif

                @if ($medico->es_centro)
                    <h2 class="h6 mt-4">Doctores del centro</h2>
                    @forelse ($datos['doctoresDelCentro'] as $doctor)
                        <div wire:key="doctor-{{ $doctor->id }}">
                            <a href="{{ route('admin.cuentas.medico', $doctor) }}">{{ $doctor->nombre_mostrar }}</a>
                        </div>
                    @empty
                        <p class="text-muted mb-0">Todavía no tiene doctores vinculados.</p>
                    @endforelse
                @endif

            {{-- ============================ Sedes ============================ --}}
            @elseif ($pestana === 'sedes')
                {{-- Un grupo por **consultorio del médico** (`reg_medico`): el principal y los que cuelgan de él
                     (2026-11-06). Cada uno administra sus propias sedes con su ficha. --}}
                @forelse ($datos['sedesPorConsultorio'] as $grupo)
                    @if (count($datos['sedesPorConsultorio']) > 1)
                        <h3 class="h6 d-flex align-items-center gap-2 {{ $loop->first ? '' : 'mt-4' }}">
                            <code>{{ $grupo['registro'] }}</code>
                            @if ($grupo['principal'])
                                <span class="badge bg-secondary">principal</span>
                            @elseif ($grupo['ficha']->esConsultorioHijo())
                                <span class="badge bg-primary">consultorio del médico</span>
                            @endif
                        </h3>
                    @endif
                    @forelse ($grupo['sedes'] as $sede)
                        @if ($loop->first)
                            <div class="table-responsive"><table class="table align-middle mb-0">
                                <thead class="table-light"><tr><th>Sede</th><th>Ciudad</th><th class="text-end">Consultorios</th></tr></thead><tbody>
                        @endif
                        <tr wire:key="sede-{{ $grupo['registro'] }}-{{ $sede->id }}">
                            <td>{{ $sede->name }}<small class="text-muted d-block">{{ $sede->address }}</small></td>
                            <td>{{ $sede->city->name ?? '—' }}</td>
                            <td class="text-end">{{ $sede->consultorios_activos }}@if ($sede->consultorios_total > $sede->consultorios_activos) <small class="text-muted">(+{{ $sede->consultorios_total - $sede->consultorios_activos }} de baja)</small>@endif</td>
                        </tr>
                        @if ($loop->last) </tbody></table></div> @endif
                    @empty
                        <x-ui.vacio icono="bi-geo-alt" titulo="Sin sedes">Este consultorio no tiene sedes registradas.</x-ui.vacio>
                    @endforelse
                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.medicos.sedes', $grupo['ficha']) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>
                            {{ count($datos['sedesPorConsultorio']) > 1 ? 'Administrar las sedes de ' . $grupo['registro'] : 'Administrar sedes y consultorios' }}
                        </a>
                    </div>
                @empty
                    <x-ui.vacio icono="bi-geo-alt" titulo="Sin registros de datos">Sin <code>reg_medico</code> no hay sedes que administrar.</x-ui.vacio>
                @endforelse

            {{-- ============================ Horarios ============================ --}}
            @elseif ($pestana === 'horarios')
                @if ($datos['consultorios']->isEmpty())
                    <x-ui.vacio icono="bi-clock" titulo="Sin consultorios">
                        Todavía no tiene sedes con consultorios. Primero carga una sede.
                    </x-ui.vacio>
                    <div class="mt-3">
                        <a href="{{ route('admin.medicos.sedes', $medico) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-geo-alt me-1" aria-hidden="true"></i> Administrar sedes y consultorios
                        </a>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-dark">
                                <tr><th>Consultorio</th><th>Modalidad</th><th>Horarios y cupos</th><th class="text-end">Acciones</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($datos['consultorios'] as $consultorio)
                                    <tr wire:key="horario-{{ $consultorio->id }}">
                                        <td>
                                            <div class="fw-semibold">{{ $consultorio->medicalCenter->name ?? 'Sede' }} · {{ $consultorio->office_number }}</div>
                                            <small class="text-muted">{{ $consultorio->medicalCenter->address ?? '' }}</small>
                                            @unless ($consultorio->activo) <span class="badge bg-secondary">De baja</span> @endunless
                                        </td>
                                        <td>
                                            {{ \App\Models\Office::modalidades()[$consultorio->modalidad] ?? $consultorio->modalidad }}
                                            @if ($consultorio->duracion_cita)
                                                <small class="text-muted d-block">{{ $consultorio->duracion_cita }} min por cita</small>
                                            @endif
                                        </td>
                                        <td>
                                            @forelse ($consultorio->schedules->sortBy(['dia_semana', 'hora_inicio']) as $bloque)
                                                <div class="small d-flex align-items-center gap-2">
                                                    <span>
                                                        {{ $datos['nombresDeDias'][$bloque->dia_semana] ?? $bloque->dia_semana }}
                                                        {{ substr((string) $bloque->hora_inicio, 0, 5) }}–{{ substr((string) $bloque->hora_fin, 0, 5) }}
                                                        @if ($bloque->cupo) <span class="text-muted">· cupo {{ $bloque->cupo }}</span> @endif
                                                    </span>
                                                    <button type="button" class="btn btn-link btn-sm p-0" wire:click="abrirHorarioEditar({{ $consultorio->id }}, {{ $bloque->id }})">Editar</button>
                                                    <button type="button" class="btn btn-link btn-sm p-0 text-danger"
                                                            wire:click="quitarHorario({{ $consultorio->id }}, {{ $bloque->id }})"
                                                            wire:confirm="¿Quitar el bloque {{ $bloque->nombreDelDia() }} {{ $bloque->rango() }}? Esas horas dejan de contarse como jornada.">
                                                        Quitar
                                                    </button>
                                                </div>
                                            @empty
                                                <span class="text-muted small">Sin horarios cargados.</span>
                                            @endforelse
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirHorarioNuevo({{ $consultorio->id }})">
                                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar bloque
                                            </button>
                                            <a href="{{ route('admin.medicos.sedes.consultorios.horarios', [$medico, $consultorio->medical_center_id, $consultorio->id]) }}"
                                               class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-clock me-1" aria-hidden="true"></i> Ver horarios
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($horarioAvisos !== [])
                        @foreach ($horarioAvisos as $avisoHorario)
                            <div class="alert alert-warning py-2 mt-3" role="status">{{ $avisoHorario }}</div>
                        @endforeach
                    @endif

                    @if ($horarioForm)
                        <div class="border rounded p-3 mt-3">
                            <h3 class="h6">{{ $horarioBloqueId ? 'Editar el bloque' : 'Agregar un bloque de horario' }}</h3>
                            <form wire:submit.prevent="guardarHorario">
                                <div class="row g-3">
                                    <div class="col-md-2">
                                        <label class="form-label" for="horario-dia">Día</label>
                                        <select class="form-select" id="horario-dia" wire:model="horarioDia">
                                            <option value="">Elige…</option>
                                            @foreach ($datos['nombresDeDias'] as $numeroDia => $nombreDia)
                                                <option value="{{ $numeroDia }}">{{ $nombreDia }}</option>
                                            @endforeach
                                        </select>
                                        @error('horarioDia') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="horario-inicio">Desde</label>
                                        <input type="time" class="form-control" id="horario-inicio" wire:model="horarioInicio">
                                        @error('horarioInicio') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="horario-fin">Hasta</label>
                                        <input type="time" class="form-control" id="horario-fin" wire:model="horarioFin">
                                        @error('horarioFin') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="horario-cupo">Cupo</label>
                                        <input type="number" min="1" class="form-control" id="horario-cupo" wire:model="horarioCupo" placeholder="Sin tope">
                                        @error('horarioCupo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" class="btn btn-secondary" wire:click="cerrarHorario">Cancelar</button>
                                    <button type="submit" class="btn btn-primary">Guardar bloque</button>
                                </div>
                            </form>
                        </div>
                    @endif

                    <div class="mt-3 d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.medicos.sedes', $medico) }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-geo-alt me-1" aria-hidden="true"></i> Sedes y consultorios
                        </a>
                    </div>
                @endif

            {{-- ============================ Roles ============================ --}}
            @elseif ($pestana === 'roles')
                @if (! $cuenta)
                    <x-ui.vacio icono="bi-shield-lock" titulo="Sin cuenta de acceso">
                        Este médico no tiene cuenta: los roles se asignan a una cuenta de acceso.
                    </x-ui.vacio>
                @else
                    <p class="text-muted small">
                        La cuenta de <strong>{{ $medico->email }}</strong> tiene hoy
                        @forelse ($datos['roles'] as $rol)
                            <span class="badge bg-info text-dark" wire:key="rol-actual-{{ $rol }}">{{ $rol }}</span>
                        @empty
                            <span class="text-muted">ningún rol</span>
                        @endforelse
                        . Marca los roles que debe tener y guarda.
                    </p>
                    <x-admin.roles-form :roles="$rolesDisponibles" :inline="true"
                                        accion-guardar="guardarRoles" accion-cancelar="cargarRoles"
                                        texto-cancelar="Descartar cambios" />
                @endif

            {{-- ============================ API Keys ============================ --}}
            @elseif ($pestana === 'api-keys')
                @php $sync = $datos['sync']; @endphp
                <dl class="datos-rejilla mb-3">
                    <x-ui.dato etiqueta="Credenciales activas" :valor="(string) ($sync['activas'] ?? 0)" />
                    <x-ui.dato etiqueta="Carga inicial" :valor="isset($sync['carga']) && $sync['carga'] ? ucfirst((string) $sync['carga']->estado) : 'Sin carga'" />
                    <x-ui.dato etiqueta="Última sincronización del escritorio" :valor="$fecha($sync['ultima_escritorio'] ?? null) ?? 'Sin registro'" />
                </dl>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" wire:click="abrirEmitir({{ $medico->id }})">
                        <i class="bi bi-key-fill me-1" aria-hidden="true"></i> Generar API key
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="verCredencialesDeEsteMedico">
                        <i class="bi bi-list-ul me-1" aria-hidden="true"></i> Ver credenciales ({{ $sync['activas'] ?? 0 }} activas)
                    </button>
                </div>

                @if ($verCredenciales)
                    @forelse ($sync['credenciales'] ?? [] as $credencial)
                        @if ($loop->first)
                            <div class="table-responsive"><table class="table align-middle mb-0">
                                <thead class="table-light"><tr><th>Equipo</th><th>Vence</th><th>Último uso</th><th>Estado</th><th></th></tr></thead><tbody>
                        @endif
                        <tr wire:key="credencial-{{ $credencial->id }}">
                            <td>{{ $credencial->machine_label }}</td>
                            <td>{{ $fecha($credencial->expires_at, false) ?? '—' }}</td>
                            <td>{{ $fecha($credencial->last_used_at) ?? 'Nunca' }}</td>
                            <td>
                                @if ($credencial->revoked_at) <x-ui.estado tono="neutro">Revocada</x-ui.estado>
                                @elseif ($credencial->estaActiva()) <x-ui.estado tono="exito">Activa</x-ui.estado>
                                @else <x-ui.estado tono="aviso">Vencida</x-ui.estado> @endif
                            </td>
                            <td class="text-end">
                                @unless ($credencial->revoked_at)
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            wire:click="revocarCredencial({{ $credencial->id }})"
                                            wire:confirm="¿Revocar la API key del equipo {{ $credencial->machine_label }}? Ese equipo dejará de sincronizar.">
                                        Revocar
                                    </button>
                                @endunless
                            </td>
                        </tr>
                        @if ($loop->last) </tbody></table></div> @endif
                    @empty
                        <x-ui.vacio icono="bi-key" titulo="Sin credenciales">Todavía no se emitió una API key.</x-ui.vacio>
                    @endforelse
                @elseif (($sync['credenciales'] ?? collect())->isNotEmpty())
                    <p class="text-muted small mb-0">
                        Hay {{ $sync['credenciales']->count() }} credencial(es) emitidas. Usa «Ver credenciales» para ver el detalle y revocarlas.
                    </p>
                @else
                    <x-ui.vacio icono="bi-key" titulo="Sin credenciales">Todavía no se emitió una API key.</x-ui.vacio>
                @endif

            {{-- ============================ Especialidades ============================ --}}
            @elseif ($pestana === 'especialidades')
                @if ($avisoEspecialidad)
                    <div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="status">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <span>{{ $avisoEspecialidad }}</span>
                    </div>
                @endif

                <div class="mb-3">
                    @forelse ($datos['especialidades'] as $especialidad)
                        <span class="badge {{ $especialidad->activo ? 'bg-light text-dark border' : 'bg-warning text-dark' }} me-1 mb-1"
                              wire:key="esp-{{ $especialidad->id }}"
                              @unless ($especialidad->activo) title="Inactiva: no le da módulos en el consultorio" @endunless>
                            {{ $especialidad->name }}@unless ($especialidad->activo) · inactiva @endunless
                        </span>
                    @empty
                        <x-ui.estado tono="peligro">Sin especialidad: su consultorio no muestra módulos</x-ui.estado>
                    @endforelse

                    @foreach ($datos['subespecialidades'] as $sub)
                        <span class="badge bg-light text-dark border ms-1" wire:key="sub-{{ $sub->id }}">{{ $sub->nombre }}</span>
                    @endforeach
                </div>

                <x-admin.especialidades-form :catalogo="$catalogoEspecialidades"
                                             :sin-activas="$catalogoEspecialidades->where('activo', false)->count()"
                                             :seleccionado="$medico" :inline="true"
                                             accion-guardar="guardarEspecialidades" accion-cancelar="cargarEspecialidades"
                                             texto-cancelar="Descartar cambios" />

            {{-- ============================ Planes ============================ --}}
            @elseif ($pestana === 'planes')
                @forelse ($datos['estados'] as $estado)
                    <dl class="datos-rejilla mb-3" wire:key="plan-{{ $estado['reg_medico'] }}">
                        <x-ui.dato etiqueta="Registro">
                            <code>{{ $estado['reg_medico'] }}</code>
                            @if ($estado['heredado_de'] ?? null)
                                {{-- El consultorio hijo no tiene plan propio: muestra el del padre (2026-11-06). --}}
                                <span class="badge bg-light text-dark border ms-1">usa el plan de {{ $estado['heredado_de'] }}</span>
                            @endif
                        </x-ui.dato>
                        <x-ui.dato etiqueta="Plan" :valor="$estado['plan'] ?? 'Sin servicio'" />
                        <x-ui.dato etiqueta="Estado">
                            @php
                                $tonos = ['vigente' => 'exito', 'gracia' => 'aviso', 'vencido' => 'peligro'];
                            @endphp
                            <x-ui.estado :tono="$tonos[$estado['estado']] ?? 'neutro'">{{ ucfirst(str_replace('_', ' ', $estado['estado'])) }}</x-ui.estado>
                        </x-ui.dato>
                        <x-ui.dato etiqueta="Inicio" :valor="$estado['inicia_el'] ? \Illuminate\Support\Carbon::parse($estado['inicia_el'])->format(\App\Support\Regionalizacion::formatoActual()) : null" />
                        <x-ui.dato etiqueta="Vence" :valor="$estado['vence_el'] ? \Illuminate\Support\Carbon::parse($estado['vence_el'])->format(\App\Support\Regionalizacion::formatoActual()) : null" />
                        <x-ui.dato etiqueta="Días restantes" :valor="$estado['dias_restantes'] !== null ? (string) $estado['dias_restantes'] : null" />
                    </dl>
                @empty
                    <x-ui.vacio icono="bi-card-checklist" titulo="Sin registros de datos">Sin <code>reg_medico</code> no hay plan.</x-ui.vacio>
                @endforelse

                @if ($medico->esConsultorioHijo())
                    {{-- El consultorio hijo no tiene plan propio: el del padre se renueva desde su ficha (2026-11-06). --}}
                    <p class="text-muted small mb-0">
                        Este consultorio usa el plan de <code>{{ $medico->regMedicoDelPadre() }}</code>:
                        el plan se renueva desde la ficha del consultorio principal.
                    </p>
                @elseif ($datos['estados'] !== [])
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirRenovarPlan">
                            <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i> Renovar
                        </button>
                        @if ($datos['actual'])
                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirReemplazarPlan">
                                <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i> Reemplazar
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="abrirQuitarPlan">
                                <i class="bi bi-x-circle me-1" aria-hidden="true"></i> Quitar plan
                            </button>
                        @endif
                    </div>

                    @if (in_array($planAccion, ['renovar', 'reemplazar'], true))
                        <div class="border rounded p-3 mb-3">
                            <h3 class="h6">{{ $planAccion === 'renovar' ? 'Renovar el servicio' : 'Reemplazar el plan' }}</h3>
                            <form wire:submit.prevent="{{ $planAccion === 'renovar' ? 'renovarPlan' : 'reemplazarPlan' }}">
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label" for="plan-elegido">Plan</label>
                                        <select class="form-select" id="plan-elegido" wire:model.live="planElegido">
                                            @foreach ($datos['planesActivos'] as $plan)
                                                <option value="{{ $plan->id }}"
                                                        @disabled(isset($datos['planesNoCubren'][$plan->id]) && (int) ($datos['actual']?->plan_id) !== (int) $plan->id)>
                                                    {{ $plan->nombre }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('planElegido') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                        @if (isset($datos['planesNoCubren'][$planElegido]))
                                            <span class="text-warning small d-block">{{ $datos['planesNoCubren'][$planElegido] }}</span>
                                        @endif
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="plan-meses">Meses</label>
                                        <input type="number" class="form-control" id="plan-meses" min="1" max="60" wire:model="meses">
                                        @error('meses') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label" for="plan-monto">Monto</label>
                                        <input type="number" step="0.01" class="form-control" id="plan-monto" min="0" wire:model="monto">
                                        @error('monto') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label" for="plan-nota">{{ $planAccion === 'renovar' ? 'Nota' : 'Motivo' }}</label>
                                        @if ($planAccion === 'renovar')
                                            <input type="text" class="form-control" id="plan-nota" wire:model="nota" maxlength="255">
                                            @error('nota') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                        @else
                                            <input type="text" class="form-control" id="plan-nota" wire:model="motivo" maxlength="150">
                                            @error('motivo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                        @endif
                                    </div>
                                </div>
                                @if ($planAccion === 'renovar' && ($datos['inicioRenovacion'] ?? null))
                                    <p class="form-text mb-0 mt-2">Empieza el {{ $datos['inicioRenovacion'] }}.</p>
                                @endif
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" class="btn btn-secondary" wire:click="cerrarPlan">Cancelar</button>
                                    <button type="submit" class="btn btn-primary">Guardar</button>
                                </div>
                            </form>
                        </div>
                    @elseif ($planAccion === 'quitar')
                        <div class="border rounded p-3 mb-3">
                            <h3 class="h6">Quitar el plan</h3>
                            <p class="text-muted small">Deja de tener servicio y deja de sincronizar. No se borra ningún dato clínico.</p>
                            <form wire:submit.prevent="quitarPlanDelMedico">
                                <label class="form-label" for="plan-quitar-motivo">Motivo</label>
                                <input type="text" class="form-control" id="plan-quitar-motivo" wire:model="motivo" maxlength="150">
                                @error('motivo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" class="btn btn-secondary" wire:click="cerrarPlan">Cancelar</button>
                                    <button type="submit" class="btn btn-danger">Quitar plan</button>
                                </div>
                            </form>
                        </div>
                    @endif
                @endif

                @if ($datos['historial']->isNotEmpty())
                    <h2 class="h6 mt-3">Historial de contrataciones</h2>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Plan</th><th>Origen</th><th>Inicio</th><th>Vence</th><th>Estado</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($datos['historial'] as $servicio)
                                <tr wire:key="servicio-{{ $servicio->id }}">
                                    <td>{{ $servicio->plan_nombre }}</td>
                                    <td>{{ ucfirst($servicio->origen) }}</td>
                                    <td>{{ $fecha($servicio->inicia_el, false) }}</td>
                                    <td>{{ $fecha($servicio->vence_el, false) }}</td>
                                    <td>{{ ucfirst($servicio->estado) }}</td>
                                    <td class="text-end">
                                        @if ($servicio->estado !== \App\Models\RegMedicoServicio::CANCELADO)
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="cancelarServicioDelMedico({{ $servicio->id }})"
                                                    wire:confirm="¿Cancelar este servicio? Ya no contará para el vencimiento.">
                                                Cancelar
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
                <div class="mt-3"><a href="{{ route('admin.servicios') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-card-checklist me-1" aria-hidden="true"></i> Ir a Planes y servicios</a></div>

            {{-- ============================ Métodos de pago ============================ --}}
            @elseif ($pestana === 'metodos-pago')
                <h2 class="h6">¿Quién cobra sus citas?</h2>
                <form wire:submit.prevent="guardarRecaudador" class="row g-2 align-items-end mb-2">
                    <div class="col-md-6">
                        <label class="form-label" for="recaudador">Cobrar sus citas con</label>
                        <select class="form-select" id="recaudador" wire:model="recaudador">
                            <option value="plataforma">La plataforma (por defecto)</option>
                            <option value="medico">Sus cuentas</option>
                        </select>
                        @error('recaudador') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-primary">Guardar</button>
                    </div>
                </form>
                <p class="text-muted small">
                    Si cobra con sus cuentas, el paciente paga directo a su banco y no hay liquidación.
                    La confirmación automática del banco se configura en la pantalla de Cobros del médico.
                </p>

                <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-2">
                    <h2 class="h6 mb-0">Sus cuentas de pago móvil</h2>
                    <button type="button" class="btn btn-sm btn-outline-primary" wire:click="abrirCuentaNueva">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Agregar cuenta
                    </button>
                </div>

                @if ($datos['cuentasDeCobro']->isEmpty())
                    <p class="text-muted">Todavía no tiene cuentas cargadas.</p>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Banco</th><th>Pago móvil</th><th>Cuenta</th><th>Confirmación</th><th>Estado</th><th class="text-end">Acciones</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($datos['cuentasDeCobro'] as $cuenta)
                                    <tr wire:key="cuenta-{{ $cuenta->id }}">
                                        <td>
                                            {{ $cuenta->bancoNombre() }}
                                            @if ($cuenta->predeterminada) <span class="badge bg-secondary">Predeterminada</span> @endif
                                        </td>
                                        <td>{{ $cuenta->telefono ?: '—' }}</td>
                                        <td>{{ $cuenta->numero ?: '—' }}</td>
                                        <td>
                                            @if ($cuenta->conciliaSola()) Automática
                                            @elseif ($cuenta->conciliacionAutomatica()) Automática sin verificar
                                            @else Manual
                                            @endif
                                        </td>
                                        <td>
                                            @if ($cuenta->activa) <x-ui.estado tono="exito">Activa</x-ui.estado>
                                            @else <x-ui.estado tono="neutro">Inactiva</x-ui.estado> @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirCuentaEditar({{ $cuenta->id }})">Editar</button>
                                            @unless ($cuenta->predeterminada)
                                                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="predeterminarCuenta({{ $cuenta->id }})">Predeterminar</button>
                                            @endunless
                                            <button type="button" class="btn btn-sm btn-outline-{{ $cuenta->activa ? 'danger' : 'success' }}"
                                                    wire:click="alternarCuenta({{ $cuenta->id }})">
                                                {{ $cuenta->activa ? 'Desactivar' : 'Activar' }}
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($cuentaForm)
                    <div class="border rounded p-3 mt-3">
                        <h3 class="h6">{{ $cuentaId ? 'Editar la cuenta' : 'Agregar una cuenta' }}</h3>
                        <form wire:submit.prevent="guardarCuenta">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="cta-banco">Banco</label>
                                    <select class="form-select" id="cta-banco" wire:model="ctaBanco">
                                        @foreach ($bancosDeCobro as $codigo => $banco)
                                            <option value="{{ $codigo }}">{{ $banco }}</option>
                                        @endforeach
                                    </select>
                                    @error('ctaBanco') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="cta-telefono">Teléfono del pago móvil</label>
                                    <input type="text" class="form-control" id="cta-telefono" wire:model="ctaTelefono" maxlength="15" placeholder="04141234567">
                                    @error('ctaTelefono') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="cta-numero">Número de cuenta (transferencia)</label>
                                    <input type="text" class="form-control" id="cta-numero" wire:model="ctaNumero" maxlength="30">
                                    @error('ctaNumero') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="cta-titular">Titular</label>
                                    <input type="text" class="form-control" id="cta-titular" wire:model="ctaTitular" maxlength="120">
                                    @error('ctaTitular') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="cta-doc-tipo">Documento</label>
                                    <select class="form-select" id="cta-doc-tipo" wire:model="ctaDocTipo">
                                        @foreach (\App\Models\CuentaBancaria::DOCUMENTOS as $tipo)
                                            <option value="{{ $tipo }}">{{ $tipo }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="cta-doc-numero">Número de documento</label>
                                    <input type="text" class="form-control" id="cta-doc-numero" wire:model="ctaDocNumero" maxlength="20">
                                    @error('ctaDocNumero') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                            <p class="form-text">Carga el teléfono del pago móvil o el número de cuenta: con eso alcanza para cobrar.</p>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" wire:click="cerrarCuentaForm">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Guardar cuenta</button>
                            </div>
                        </form>
                    </div>
                @endif

            {{-- ============================ Motivos de cita ============================ --}}
            @elseif ($pestana === 'motivos')
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <p class="text-muted small mb-0">Con estos motivos agenda su secretaría y su agenda pública. El código lo asigna el servidor.</p>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="abrirMotivoNuevo">
                        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nuevo motivo
                    </button>
                </div>

                @if ($motivoForm)
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6">{{ $motivoEditandoId ? 'Editar el motivo' : 'Nuevo motivo' }}</h3>
                        <form wire:submit.prevent="guardarMotivo">
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="form-label" for="motivo-razon">Nombre del motivo</label>
                                    <input type="text" class="form-control" id="motivo-razon" wire:model="motivoRazon" maxlength="300">
                                    @error('motivoRazon') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="motivo-precio">Precio</label>
                                    <input type="number" step="0.01" min="0" class="form-control" id="motivo-precio" wire:model="motivoPrecio">
                                    @error('motivoPrecio') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="motivo-sede">Sede</label>
                                    <select class="form-select" id="motivo-sede" wire:model="motivoOffice">
                                        <option value="">Todas las sedes</option>
                                        @foreach ($datos['sedesDeLosMotivos'] as $id => $nombreSede)
                                            <option value="{{ $id }}">{{ $nombreSede }}</option>
                                        @endforeach
                                    </select>
                                    @error('motivoOffice') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="motivo-varias" wire:model="motivoVarias">
                                        <label class="form-check-label" for="motivo-varias">Admite varias citas pendientes del mismo paciente</label>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" wire:click="cerrarMotivo">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Guardar motivo</button>
                            </div>
                        </form>
                    </div>
                @endif

                @if ($datos['motivos']->isEmpty())
                    <x-ui.vacio icono="bi-calendar-check" titulo="Sin motivos de cita">Todavía no tiene motivos cargados.</x-ui.vacio>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Código</th><th>Motivo</th><th>Sede</th><th class="text-end">Precio</th><th>Varias citas</th><th class="text-end">Acciones</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($datos['motivos'] as $motivo)
                                    <tr wire:key="motivo-{{ $motivo->id }}">
                                        <td><code>{{ trim((string) $motivo->codigo) }}</code></td>
                                        <td>{{ $motivo->tipo_atencion }}</td>
                                        <td>{{ \App\Clinica\Catalogos\MotivosDeCitaDelCatalogo::sedeDelMotivo($motivo) }}</td>
                                        <td class="text-end">{{ $motivo->precio === null ? '—' : number_format($motivo->precio, 2, ',', '.') }}</td>
                                        <td>
                                            @if ($motivo->admite_varias_citas_pendientes)
                                                <x-ui.estado tono="info">Sí</x-ui.estado>
                                            @else
                                                <span class="text-muted small">No</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="abrirMotivoEditar({{ $motivo->id }})">Editar</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="alternarMotivo({{ $motivo->id }})">
                                                {{ $motivo->admite_varias_citas_pendientes ? 'No permitir varias' : 'Permitir varias' }}
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="quitarMotivo({{ $motivo->id }})"
                                                    wire:confirm="¿Quitar «{{ $motivo->tipo_atencion }}» del catálogo? Si alguna cita lo usa, no se borra.">
                                                Quitar
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

            {{-- ============================ Cita online ============================ --}}
            @elseif ($pestana === 'cita-online')
                <form wire:submit.prevent="guardarCitaOnline">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="cita-porcentaje">Porcentaje que se cobra al reservar</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="cita-porcentaje" min="0" max="100" step="0.01"
                                       inputmode="decimal" wire:model="citaPorcentaje">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">El resto se cobra en el consultorio.</div>
                            @error('citaPorcentaje') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-8">
                            <div class="form-check form-switch mt-md-4">
                                <input class="form-check-input" type="checkbox" role="switch" id="cita-publicar" wire:model="citaPublicar">
                                <label class="form-check-label" for="cita-publicar">
                                    Mostrar su agenda en el portal de reservas y en el bot de WhatsApp
                                </label>
                            </div>
                            <p class="form-text mb-0">Apagada, su agenda deja de aparecer en el catálogo público; las citas que ya tiene no cambian.</p>
                        </div>

                        <div class="col-12"><hr class="my-2"></div>
                        <div class="col-12">
                            <h3 class="h6 mb-1">Lo que el paciente ve antes de venir</h3>
                            <p class="form-text mb-0">Se muestra en su ficha del portal de reservas. Si lo deja vacío, no aparece nada.</p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="cita-minutos">Minutos antes</label>
                            <input type="number" class="form-control" id="cita-minutos" min="0" max="240" wire:model="citaMinutosAntes">
                            @error('citaMinutosAntes') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="cita-acompanantes">Acompañantes</label>
                            <input type="number" class="form-control" id="cita-acompanantes" min="0" max="10" wire:model="citaAcompanantes">
                            @error('citaAcompanantes') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cita-indicaciones">Indicaciones</label>
                            <input type="text" class="form-control" id="cita-indicaciones" maxlength="255" wire:model="citaIndicaciones"
                                   placeholder="Por ejemplo: trae tus exámenes o tu cédula.">
                            @error('citaIndicaciones') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3">
                        @unless ($medico->es_centro)
                            <a href="{{ route('reservas.medico', ['medico' => $medico->id]) }}" target="_blank" rel="noopener"
                               class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Ver su página pública
                            </a>
                        @else
                            <span></span>
                        @endunless
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar
                        </button>
                    </div>
                </form>

            {{-- ============================ Secretarias ============================ --}}
            @elseif ($pestana === 'secretarias')
                @if ($claveSecretaria)
                    <div class="alert alert-success" role="status">
                        <p class="mb-1">
                            Clave temporal {{ $claveSecretaria['motivo'] }} de <strong>{{ $claveSecretaria['nombre'] }}</strong>
                            (<code>{{ $claveSecretaria['correo'] }}</code>). Se muestra <strong>una sola vez</strong>:
                            entrégasela y que la cambie al entrar.
                        </p>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <code class="fs-5">{{ $claveSecretaria['clave'] }}</code>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="navigator.clipboard.writeText({{ json_encode($claveSecretaria['clave']) }}); this.textContent = 'Copiada';">
                                <i class="bi bi-clipboard me-1"></i> Copiar
                            </button>
                            <button type="button" class="btn btn-sm btn-primary" wire:click="cerrarClaveSecretaria">Listo</button>
                        </div>
                    </div>
                @endif

                @error('secretaria') <div class="alert alert-danger py-2">{{ $message }}</div> @enderror

                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <p class="text-muted small mb-0">Las cuentas que solo son secretaría de este consultorio. Una cuenta compartida con otro consultorio no se toca desde acá.</p>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="abrirNuevaSecretaria">
                        <i class="bi bi-person-plus me-1" aria-hidden="true"></i> Nueva secretaria
                    </button>
                </div>

                @if ($secretariaAccion === 'nueva')
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6">Nueva secretaria</h3>
                        <form wire:submit.prevent="crearSecretaria">
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="form-label" for="secretaria-nombre">Nombre</label>
                                    <input type="text" class="form-control" id="secretaria-nombre" wire:model="secretariaNombre" maxlength="100">
                                    @error('secretariaNombre') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="secretaria-email">Correo con el que entra</label>
                                    <input type="email" class="form-control" id="secretaria-email" wire:model="secretariaEmail" maxlength="150" autocomplete="off">
                                    @error('secretariaEmail') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" wire:click="cerrarSecretaria">Cancelar</button>
                                <button type="submit" class="btn btn-primary">Crear cuenta</button>
                            </div>
                        </form>
                    </div>
                @elseif ($secretariaAccion === 'bloquear')
                    <div class="border rounded p-3 mb-3">
                        <h3 class="h6">Bloquear la secretaria</h3>
                        <form wire:submit.prevent="bloquearSecretaria">
                            <label class="form-label" for="secretaria-motivo">Motivo (opcional)</label>
                            <input type="text" class="form-control" id="secretaria-motivo" wire:model="secretariaMotivo" maxlength="200">
                            @error('secretariaMotivo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" wire:click="cerrarSecretaria">Cancelar</button>
                                <button type="submit" class="btn btn-danger">Bloquear</button>
                            </div>
                        </form>
                    </div>
                @endif

                @if ($datos['secretarias']->isEmpty())
                    <x-ui.vacio icono="bi-people" titulo="Sin secretarias">Todavía no tiene asistentes con acceso a su consultorio.</x-ui.vacio>
                @else
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Nombre</th><th>Correo</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                            <tbody>
                                @foreach ($datos['secretarias'] as $secretaria)
                                    <tr wire:key="secretaria-{{ $secretaria->id }}">
                                        <td>{{ $secretaria->name }}</td>
                                        <td>{{ $secretaria->email }}</td>
                                        <td>
                                            @if ($secretaria->is_active === false) <x-ui.estado tono="peligro">Bloqueada</x-ui.estado>
                                            @else <x-ui.estado tono="exito">Activa</x-ui.estado> @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="restablecerClaveSecretaria({{ $secretaria->id }})">Restablecer clave</button>
                                            @if ($secretaria->is_active === false)
                                                <button type="button" class="btn btn-sm btn-outline-success" wire:click="desbloquearSecretaria({{ $secretaria->id }})">Desbloquear</button>
                                            @else
                                                <button type="button" class="btn btn-sm btn-outline-warning" wire:click="abrirBloquearSecretaria({{ $secretaria->id }})">Bloquear</button>
                                            @endif
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    wire:click="quitarSecretaria({{ $secretaria->id }})"
                                                    wire:confirm="¿Quitarle a {{ $secretaria->name }} el acceso a este consultorio? No se borra ningún dato.">
                                                Quitar
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

            {{-- ============================ Pacientes ============================ --}}
            @elseif ($pestana === 'pacientes')
                <dl class="datos-rejilla">
                    <x-ui.dato etiqueta="Pacientes del consultorio" :valor="(string) $datos['pacientes']" />
                    <x-ui.dato etiqueta="Historias" :valor="(string) $datos['historias']" />
                </dl>
                @if ($datos['porRegistro']->count() > 1)
                    <h2 class="h6 mt-3">Por registro de datos</h2>
                    @foreach ($datos['porRegistro'] as $reg => $n)
                        <div wire:key="pac-{{ $reg }}"><code>{{ $reg }}</code>: {{ $n }}</div>
                    @endforeach
                @endif
                <p class="text-muted small mt-3 mb-0">Sólo el conteo administrativo: el contenido clínico es del consultorio.</p>

            {{-- ============================ Pagos ============================ --}}
            @elseif ($pestana === 'pagos')
                <div class="table-responsive"><table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Referencia</th><th>Concepto</th><th>Monto</th><th>Estado</th><th>Fecha</th></tr></thead>
                    <tbody>
                        @foreach ($datos['pagos'] as $pago)
                            <tr wire:key="pago-{{ $pago->id }}">
                                <td><code>{{ $pago->referencia }}</code></td>
                                <td>{{ $pago->concepto }}</td>
                                <td>{{ $pago->montoTexto() }}</td>
                                <td>{{ ucfirst(str_replace('_', ' ', $pago->estado)) }}</td>
                                <td>{{ $fecha($pago->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @if ($datos['total'] > count($datos['pagos']))
                    <p class="text-muted small mt-2 mb-0">Se muestran los {{ count($datos['pagos']) }} más recientes de {{ $datos['total'] }}.</p>
                @endif
                <div class="mt-3"><a href="{{ route('admin.pagos') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-cash-stack me-1" aria-hidden="true"></i> Ir a Pagos</a></div>

            {{-- ============================ Solicitud de registro ============================ --}}
            @elseif ($pestana === 'solicitud')
                @if ($claveSolicitud)
                    <div class="alert alert-success" role="status">
                        <p class="mb-1">
                            {{ $claveSolicitud['titulo'] }}. Correo <code>{{ $claveSolicitud['correo'] }}</code>.
                            @if ($claveSolicitud['clave'])
                                Clave temporal: <code class="fs-5">{{ $claveSolicitud['clave'] }}</code> (se muestra una sola vez).
                            @endif
                        </p>
                        <button type="button" class="btn btn-sm btn-primary" wire:click="cerrarClaveSolicitud">Listo</button>
                    </div>
                @endif

                @forelse ($datos['solicitudes'] as $solicitud)
                    <div class="border rounded p-3 mb-2" wire:key="solicitud-{{ $solicitud->id }}">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                            <div>
                                <strong class="text-capitalize">{{ $solicitud->estado }}</strong>
                                <span class="text-muted small">· Solicitud #{{ $solicitud->id }}</span>
                                <span class="text-muted small">· recibida {{ $fecha($solicitud->created_at) }}</span>
                                @if ($solicitud->cedula) <span class="text-muted small">· cédula {{ $solicitud->cedula }}</span> @endif
                                @if ($solicitud->mpps) <span class="text-muted small">· MPPS {{ $solicitud->mpps }}</span> @endif
                                @if ($solicitud->especialidad) <span class="text-muted small">· {{ $solicitud->especialidad->name }}</span> @endif
                            </div>
                            @if ($solicitud->estado === \App\Models\SolicitudMedico::PENDIENTE)
                                <button type="button" class="btn btn-sm btn-primary" wire:click="abrirSolicitud({{ $solicitud->id }})">
                                    Revisar y resolver
                                </button>
                            @endif
                        </div>
                        @if ($solicitud->motivo_rechazo)
                            <p class="small text-muted mb-0 mt-1">Motivo del rechazo: {{ $solicitud->motivo_rechazo }}</p>
                        @endif
                    </div>
                @empty
                    <x-ui.vacio icono="bi-person-badge" titulo="Sin solicitudes">Este médico no tiene solicitudes de registro.</x-ui.vacio>
                @endforelse

                @if ($solicitudAbiertaId)
                    <div class="border rounded p-3 mt-3" wire:key="resolver-solicitud-{{ $solicitudAbiertaId }}">
                        <h3 class="h6">Resolver la solicitud #{{ $solicitudAbiertaId }}</h3>

                        {{-- La decisión primero (guía de UI, §11): aprobar y rechazar piden datos distintos y
                             no se muestran los dos a la vez. Sin opción marcada. --}}
                        <div class="mb-3">
                            <span class="form-label d-block" id="sol-decision-titulo">¿Qué haces con la solicitud?</span>
                            <div class="d-flex flex-wrap gap-4" role="radiogroup" aria-labelledby="sol-decision-titulo">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" id="sol-decision-aprobar"
                                           value="aprobar" wire:model.live="solDecision">
                                    <label class="form-check-label" for="sol-decision-aprobar">Aprobar</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" id="sol-decision-rechazar"
                                           value="rechazar" wire:model.live="solDecision">
                                    <label class="form-check-label" for="sol-decision-rechazar">Rechazar</label>
                                </div>
                            </div>
                        </div>

                        <form wire:submit.prevent="{{ $solDecision === 'rechazar' ? 'rechazarSolicitud' : 'aprobarSolicitud' }}">
                            @if ($solDecision === 'aprobar')
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label" for="sol-reg">Registro con el que trabajará</label>
                                        <input type="text" class="form-control" id="sol-reg" wire:model="solRegMedico" maxlength="100">
                                        @error('solRegMedico') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="col-md-5">
                                        <label class="form-label" for="sol-especialidad">Especialidad</label>
                                        <select class="form-select" id="sol-especialidad" wire:model="solSpecialty">
                                            <option value="">— La del registro —</option>
                                            @foreach ($especialidadesSolicitud as $especialidad)
                                                <option value="{{ $especialidad->id }}">{{ $especialidad->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="sol-carnet" wire:model="solCarnet">
                                            <label class="form-check-label" for="sol-carnet">Revisé el carnet y lo doy por verificado</label>
                                        </div>
                                    </div>
                                </div>
                            @elseif ($solDecision === 'rechazar')
                                <div>
                                    <label class="form-label" for="sol-motivo">Motivo del rechazo (se le manda por correo)</label>
                                    <input type="text" class="form-control" id="sol-motivo" wire:model="solMotivo" maxlength="2000">
                                    @error('solMotivo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            @endif

                            <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                                <button type="button" class="btn btn-secondary" wire:click="cerrarSolicitud">Cerrar</button>
                                @if ($solDecision !== '')
                                    <button type="submit"
                                            class="btn {{ $solDecision === 'aprobar' ? 'btn-primary' : 'btn-outline-danger' }}">
                                        {{ $solDecision === 'aprobar' ? 'Aprobar' : 'Rechazar solicitud' }}
                                    </button>
                                @endif
                            </div>
                        </form>
                    </div>
                @endif

                <div class="mt-3">
                    <a href="{{ route('admin.solicitudes-medicos') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-person-badge me-1" aria-hidden="true"></i> Ir a Solicitudes de médicos
                    </a>
                </div>

            {{-- ============================ Soporte y Sugerencias ============================ --}}
            @elseif ($pestana === 'comentarios')
                <div class="table-responsive"><table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>Fecha</th><th>Tipo</th><th>Mensaje</th><th>Estado</th></tr></thead>
                    <tbody>
                        @foreach ($datos['comentarios'] as $comentario)
                            <tr wire:key="comentario-{{ $comentario->id }}">
                                <td class="text-nowrap">{{ $fecha($comentario->created_at) }}</td>
                                <td>{{ \App\Models\Comentario::TIPOS[$comentario->tipo] ?? $comentario->tipo }}</td>
                                <td>{{ \Illuminate\Support\Str::limit($comentario->mensaje, 120) }}</td>
                                <td>{{ ucfirst($comentario->estado) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @if ($datos['total'] > count($datos['comentarios']))
                    <p class="text-muted small mt-2 mb-0">Se muestran los {{ count($datos['comentarios']) }} más recientes de {{ $datos['total'] }}.</p>
                @endif
                <div class="mt-3"><a href="{{ route('admin.comentarios') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-chat-heart me-1" aria-hidden="true"></i> Ir a Soporte y Sugerencias</a></div>
            @endif

        </div>
    </div>

    {{-- Ventanas compartidas del emisor de API keys (generar y mostrar una sola vez). --}}
    @include('livewire.admin.partials.api-key-emitir')
    @include('livewire.admin.partials.api-key-generada')
</div>
