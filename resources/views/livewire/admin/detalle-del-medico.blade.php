<div>
    @php
        $nombre = $medico->nombre_mostrar;
        $fecha = fn ($f, $conHora = true) => $f ? ($conHora ? \App\Support\HoraLocal::fechaHora($f) : \Illuminate\Support\Carbon::parse($f)->format(\App\Support\Regionalizacion::formatoActual())) : null;
    @endphp

    @include('livewire.admin.sedes._migas', ['migas' => [
        ['Médicos', route('admin.cuentas')],
        [$nombre, null],
    ]])

    <x-ui.page-head :titulo="$nombre" subtitulo="Detalle del usuario. Todo es de sólo lectura; cada pestaña enlaza a donde se administra.">
        <x-slot name="acciones">
            <a href="{{ route('admin.cuentas') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver
            </a>
            {{-- Abre el diálogo de edición de Médicos, ya con este médico cargado. --}}
            <a href="{{ route('admin.cuentas', ['editar' => $medico->id]) }}" class="btn btn-primary">
                <i class="bi bi-pencil-square" aria-hidden="true"></i> Editar
            </a>
        </x-slot>
    </x-ui.page-head>

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
                @forelse ($datos['sedes'] as $sede)
                    @if ($loop->first)
                        <div class="table-responsive"><table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Sede</th><th>Ciudad</th><th class="text-end">Consultorios</th></tr></thead><tbody>
                    @endif
                    <tr wire:key="sede-{{ $sede->id }}">
                        <td>{{ $sede->name }}<small class="text-muted d-block">{{ $sede->address }}</small></td>
                        <td>{{ $sede->city->name ?? '—' }}</td>
                        <td class="text-end">{{ $sede->consultorios_activos }}@if ($sede->consultorios_total > $sede->consultorios_activos) <small class="text-muted">(+{{ $sede->consultorios_total - $sede->consultorios_activos }} de baja)</small>@endif</td>
                    </tr>
                    @if ($loop->last) </tbody></table></div> @endif
                @empty
                    <x-ui.vacio icono="bi-geo-alt" titulo="Sin sedes">No tiene consultorios registrados.</x-ui.vacio>
                @endforelse
                <div class="mt-3">
                    <a href="{{ route('admin.medicos.sedes', $medico) }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i> Administrar sedes
                    </a>
                </div>

            {{-- ============================ Roles ============================ --}}
            @elseif ($pestana === 'roles')
                @forelse ($datos['roles'] as $rol)
                    <span class="badge bg-info text-dark me-1" wire:key="rol-{{ $rol }}">{{ $rol }}</span>
                @empty
                    <x-ui.vacio icono="bi-shield-lock" titulo="Sin roles">{{ $cuenta ? 'La cuenta no tiene roles.' : 'No tiene cuenta de acceso.' }}</x-ui.vacio>
                @endforelse
                <p class="text-muted small mt-3 mb-0">Los roles se cambian desde Usuarios › Médicos › menú de la fila › Roles.</p>

            {{-- ============================ API Keys ============================ --}}
            @elseif ($pestana === 'api-keys')
                @php $sync = $datos['sync']; @endphp
                <dl class="datos-rejilla mb-3">
                    <x-ui.dato etiqueta="Credenciales activas" :valor="(string) ($sync['activas'] ?? 0)" />
                    <x-ui.dato etiqueta="Carga inicial" :valor="isset($sync['carga']) && $sync['carga'] ? ucfirst((string) $sync['carga']->estado) : 'Sin carga'" />
                    <x-ui.dato etiqueta="Última sincronización del escritorio" :valor="$fecha($sync['ultima_escritorio'] ?? null) ?? 'Sin registro'" />
                </dl>
                @forelse ($sync['credenciales'] ?? [] as $credencial)
                    @if ($loop->first)
                        <div class="table-responsive"><table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Equipo</th><th>Vence</th><th>Último uso</th><th>Estado</th></tr></thead><tbody>
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
                    </tr>
                    @if ($loop->last) </tbody></table></div> @endif
                @empty
                    <x-ui.vacio icono="bi-key" titulo="Sin credenciales">Todavía no se emitió una API key.</x-ui.vacio>
                @endforelse
                <div class="mt-3"><a href="{{ route('admin.api-keys') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-key me-1" aria-hidden="true"></i> Ir a API Keys</a></div>

            {{-- ============================ Especialidades ============================ --}}
            @elseif ($pestana === 'especialidades')
                @forelse ($datos['especialidades'] as $especialidad)
                    <div class="mb-2" wire:key="esp-{{ $especialidad->id }}">
                        <strong>{{ $especialidad->name }}</strong>
                        @foreach ($datos['subespecialidades']->where('specialty_id', $especialidad->id) as $sub)
                            <span class="badge bg-light text-dark border ms-1">{{ $sub->nombre }}</span>
                        @endforeach
                    </div>
                @empty
                    <x-ui.vacio icono="bi-heart-pulse" titulo="Sin especialidades">Todavía no se le asignó ninguna.</x-ui.vacio>
                @endforelse
                <div class="mt-3"><a href="{{ route('admin.especialidades') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-heart-pulse me-1" aria-hidden="true"></i> Ir a Especialidades</a></div>

            {{-- ============================ Planes ============================ --}}
            @elseif ($pestana === 'planes')
                @forelse ($datos['estados'] as $estado)
                    <dl class="datos-rejilla mb-3" wire:key="plan-{{ $estado['reg_medico'] }}">
                        <x-ui.dato etiqueta="Registro" :valor="$estado['reg_medico']" />
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

                @if ($datos['historial']->isNotEmpty())
                    <h2 class="h6 mt-3">Historial de contrataciones</h2>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Plan</th><th>Origen</th><th>Inicio</th><th>Vence</th><th>Estado</th></tr></thead>
                        <tbody>
                            @foreach ($datos['historial'] as $servicio)
                                <tr wire:key="servicio-{{ $servicio->id }}">
                                    <td>{{ $servicio->plan_nombre }}</td>
                                    <td>{{ ucfirst($servicio->origen) }}</td>
                                    <td>{{ $fecha($servicio->inicia_el, false) }}</td>
                                    <td>{{ $fecha($servicio->vence_el, false) }}</td>
                                    <td>{{ ucfirst($servicio->estado) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
                <div class="mt-3"><a href="{{ route('admin.servicios') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-card-checklist me-1" aria-hidden="true"></i> Ir a Planes y servicios</a></div>

            {{-- ============================ Secretarias ============================ --}}
            @elseif ($pestana === 'secretarias')
                @forelse ($datos['secretarias'] as $secretaria)
                    @if ($loop->first)
                        <div class="table-responsive"><table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Nombre</th><th>Correo</th><th>Estado</th></tr></thead><tbody>
                    @endif
                    <tr wire:key="secretaria-{{ $secretaria->id }}">
                        <td>{{ $secretaria->name }}</td>
                        <td>{{ $secretaria->email }}</td>
                        <td>
                            @if ($secretaria->is_active === false) <x-ui.estado tono="peligro">Bloqueada</x-ui.estado>
                            @else <x-ui.estado tono="exito">Activa</x-ui.estado> @endif
                        </td>
                    </tr>
                    @if ($loop->last) </tbody></table></div> @endif
                @empty
                    <x-ui.vacio icono="bi-people" titulo="Sin secretarias">Todavía no tiene asistentes con acceso a su consultorio.</x-ui.vacio>
                @endforelse

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
                @foreach ($datos['solicitudes'] as $solicitud)
                    <dl class="datos-rejilla mb-3" wire:key="solicitud-{{ $solicitud->id }}">
                        <x-ui.dato etiqueta="Estado" :valor="ucfirst($solicitud->estado)" />
                        <x-ui.dato etiqueta="Recibida" :valor="$fecha($solicitud->created_at)" />
                        <x-ui.dato etiqueta="Revisada" :valor="$fecha($solicitud->revisada_el)" />
                        <x-ui.dato etiqueta="Cédula" :valor="$solicitud->cedula" />
                        <x-ui.dato etiqueta="MPPS" :valor="$solicitud->mpps" />
                        <x-ui.dato etiqueta="Colegio" :valor="$solicitud->colegio" />
                        <x-ui.dato etiqueta="Especialidad" :valor="$solicitud->especialidad?->name" />
                        <x-ui.dato etiqueta="Ciudad" :valor="$solicitud->ciudad" />
                    </dl>
                @endforeach
                <a href="{{ route('admin.solicitudes-medicos') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-badge me-1" aria-hidden="true"></i> Ir a Solicitudes de médicos</a>

            {{-- ============================ Ayúdanos a mejorar ============================ --}}
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
                <div class="mt-3"><a href="{{ route('admin.comentarios') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-chat-heart me-1" aria-hidden="true"></i> Ir a Ayúdanos a mejorar</a></div>
            @endif

        </div>
    </div>
</div>
