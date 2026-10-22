<div>
    <x-ui.page-head titulo="Métodos de pago"
                    subtitulo="Con qué cobra Doctorisimo: los planes de los médicos y, a futuro, los cobros de los pacientes cuando el médico usa el canal de la plataforma." />

    <x-ui.avisos />

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <button type="button" class="nav-link {{ $pestana === 'metodos' ? 'active' : '' }}" wire:click="cambiarPestana('metodos')">
                <i class="bi bi-credit-card me-1"></i> Métodos de la plataforma
            </button>
        </li>
        <li class="nav-item">
            <button type="button" class="nav-link {{ $pestana === 'tasa' ? 'active' : '' }}" wire:click="cambiarPestana('tasa')">
                <i class="bi bi-currency-exchange me-1"></i> Tasa de cambio
            </button>
        </li>
    </ul>

    {{-- ============================ Métodos ============================ --}}
    @if ($pestana === 'metodos')
        <div class="alert alert-light border small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Los planes se cobran en <strong>VES</strong> con la tasa vigente y se acreditan en <strong>USD</strong>,
            que es la moneda de la plataforma. Las credenciales se guardan cifradas y nunca se muestran completas.
        </div>

        <div class="card">
            <x-ui.cargando cubre wire:loading.delay.flex />
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="text-muted small">Un método por pasarela. El webhook se genera solo.</span>
                <button wire:click="nuevoMetodo" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> Nuevo método
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Método</th>
                                <th>Cuenta que recibe</th>
                                <th>Estado</th>
                                <th>Verificación</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($metodos as $metodo)
                                <tr wire:key="metodo-{{ $metodo->id }}" class="{{ $metodo->activo ? '' : 'text-muted' }}">
                                    <td>
                                        <div class="fw-semibold">{{ $metodo->etiqueta() }}</div>
                                    </td>
                                    <td>
                                        @if ($metodo->cuentaBancaria)
                                            <span title="{{ $metodo->cuentaBancaria->descripcion() }} · {{ $metodo->cuentaBancaria->titular }}">Banco {{ $metodo->cuentaBancaria->banco_codigo }}</span>
                                        @else
                                            <span class="text-danger">Sin cuenta</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($metodo->activo)
                                            <span class="badge bg-success">Activo</span>
                                        @else
                                            <span class="badge bg-secondary">Inactivo</span>
                                        @endif
                                        @unless ($metodo->estaConfigurado())
                                            <div><small class="text-danger">Falta configurar</small></div>
                                        @endunless
                                    </td>
                                    <td>
                                        @if ($metodo->verificado_el)
                                            <span class="badge bg-success">Probado</span>
                                            <div><small class="text-muted">{{ \App\Support\HoraLocal::fechaHora($metodo->verificado_el) }}</small></div>
                                        @elseif ($metodo->ultimo_error)
                                            <span class="badge bg-danger">Con error</span>
                                            <div><small class="text-danger" title="{{ $metodo->ultimo_error }}">{{ \Illuminate\Support\Str::limit($metodo->ultimo_error, 45) }}</small></div>
                                        @else
                                            <span class="badge bg-light text-dark">Sin probar</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button wire:click="editarMetodo({{ $metodo->id }})" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i> Editar
                                        </button>
                                        @if ($metodo->pasarela === 'sypago')
                                            <button wire:click="probarConexion({{ $metodo->id }})" class="btn btn-sm btn-outline-secondary"
                                                    wire:loading.attr="disabled" wire:target="probarConexion">
                                                <i class="bi bi-plug"></i> Probar
                                            </button>
                                        @endif
                                        <button wire:click="alternarActivo({{ $metodo->id }})" class="btn btn-sm btn-outline-{{ $metodo->activo ? 'danger' : 'success' }}">
                                            {{ $metodo->activo ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <p class="mb-2 text-muted">Todavía no hay ningún método de pago configurado.</p>
                                        <button wire:click="nuevoMetodo" class="btn btn-primary btn-sm">
                                            <i class="bi bi-plus-lg me-1"></i> Configurar SyPago
                                        </button>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @if ($bancos)
            <div class="card mt-3">
                <div class="card-header bg-white py-3">
                    <strong>Bancos que devolvió SyPago</strong>
                    <span class="text-muted small ms-2">Los que tienen OTP sirven para débito inmediato; los activos, para pago móvil.</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 18rem; overflow-y: auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Código</th><th>Banco</th><th>Activo</th><th>Débito con OTP</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($bancos as $banco)
                                    <tr wire:key="banco-{{ $banco['codigo'] }}">
                                        <td><code>{{ $banco['codigo'] }}</code></td>
                                        <td>{{ $banco['nombre'] }}</td>
                                        <td>{{ $banco['activo'] ? 'Sí' : 'No' }}</td>
                                        <td>{{ $banco['debito_otp'] ? 'Sí' : 'No' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ============================== Tasa ============================== --}}
    @if ($pestana === 'tasa')
        <div class="card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <strong>Tasa vigente</strong>
                <div class="d-flex gap-2">
                    <button wire:click="nuevaTasa" class="btn btn-primary btn-sm" title="Nueva tasa" aria-label="Nueva tasa">
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                    </button>
                    <button wire:click="sincronizarTasaBcv" class="btn btn-outline-secondary btn-sm"
                            title="Forzar actualización desde el BCV" aria-label="Forzar actualización desde el BCV"
                            wire:loading.attr="disabled" wire:target="sincronizarTasaBcv">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-6">
                        @if ($tasaActual)
                            <div class="display-6 fw-semibold">1 USD = {{ $tasaActual->aTexto() }} VES</div>
                            <p class="text-muted small mb-1">
                                {{ \App\Models\TasaDeCambio::ORIGENES[$tasaActual->origen] ?? $tasaActual->origen }}
                                · desde el {{ \App\Support\HoraLocal::fechaHora($tasaActual->vigente_desde) }}
                            </p>
                            <p class="small mb-0">
                                Un plan de <strong>USD 20,00</strong> se cobra como
                                <strong>VES {{ \App\Pagos\Centavos::aTexto($tasaActual->convertirUsdAVes(2000)) }}</strong>.
                            </p>
                        @else
                            <div class="alert alert-warning mb-0">
                                No hay ninguna tasa cargada. Sin tasa no se puede cobrar un plan en VES.
                            </div>
                        @endif
                    </div>

                    <div class="col-lg-6 border-start">
                        <div class="text-muted small fw-semibold mb-2">Sincronización con el BCV</div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="tasa_automatica" wire:model="tasaAutomatica" {{ $tasaAutomatica ? 'checked' : '' }}>
                            <label class="form-check-label" for="tasa_automatica">Sincronizar automáticamente desde el BCV</label>
                        </div>
                        <p class="form-text mb-3">Cada 30 minutos, de 5:00 a 21:00 (hora de Caracas).</p>

                        <div class="row g-3 small">
                            <div class="col-sm-6">
                                <div class="text-muted">Última sincronización</div>
                                @if ($configuracion->tasa_sincronizada_el)
                                    <div>
                                        {{ \App\Support\HoraLocal::fechaHora($configuracion->tasa_sincronizada_el) }}
                                        <span class="badge bg-{{ $configuracion->tasa_sincronizacion_estado === \App\Models\ConfiguracionPago::SINCRONIZACION_OK ? 'success' : 'danger' }}">
                                            {{ $configuracion->estadoSincronizacionLegible() }}
                                        </span>
                                    </div>
                                    @if ($configuracion->tasa_sincronizacion_error)
                                        <div class="text-danger">{{ $configuracion->tasa_sincronizacion_error }}</div>
                                    @endif
                                @else
                                    <div class="text-muted">Todavía no se intentó sincronizar.</div>
                                @endif
                            </div>
                            <div class="col-sm-6">
                                <div class="text-muted">Última actualización de la tasa</div>
                                @if ($tasaActual)
                                    <div>{{ \App\Support\HoraLocal::fechaHora($tasaActual->created_at ?? $tasaActual->vigente_desde) }}</div>
                                    <div class="text-muted">{{ \App\Models\TasaDeCambio::ORIGENES[$tasaActual->origen] ?? $tasaActual->origen }}</div>
                                @else
                                    <div class="text-muted">Sin tasa cargada.</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header bg-white py-3">
                <strong>Historial</strong>
                <span class="text-muted small ms-2">La tasa que se aplica es la última con fecha de vigencia. Se muestran las 7 más recientes.</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>VES por USD</th><th>Origen</th><th>Vigente desde</th><th>Cargada</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($historialTasas as $fila)
                                <tr wire:key="tasa-{{ $fila->id }}">
                                    <td class="fw-semibold">{{ $fila->aTexto() }}</td>
                                    <td>{{ \App\Models\TasaDeCambio::ORIGENES[$fila->origen] ?? $fila->origen }}</td>
                                    <td>{{ \App\Support\HoraLocal::fechaHora($fila->vigente_desde) }}</td>
                                    <td><small class="text-muted">{{ \App\Support\HoraLocal::fechaHora($fila->created_at) }}</small></td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">Sin tasas cargadas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alert alert-light border small mt-3 mb-0">
            La plataforma lleva sus libros en <strong>{{ $configuracion->moneda_plataforma }}</strong> y liquida a los
            médicos en <strong>{{ $configuracion->moneda_liquidacion }}</strong>.
            La comisión de cita online se calcula sobre lo que queda después de la comisión del método de pago
            (cascada), no sobre el bruto.
        </div>

        {{-- Al encender la sincronización se pregunta si traer la tasa ahora. --}}
        @if ($modal === 'sincronizar')
            <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
                <div class="modal-dialog modal-sm">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">¿Sincronizar ahora?</h5>
                            <button type="button" class="btn-close" wire:click="noSincronizarAhora" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-0">Activaste la sincronización automática. ¿Quieres traer la tasa del BCV ahora mismo?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="noSincronizarAhora">Ahora no</button>
                            <button type="button" class="btn btn-primary btn-sm" wire:click="sincronizarTasaBcv"
                                    wire:loading.attr="disabled" wire:target="sincronizarTasaBcv">
                                Sincronizar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- ===================== Ventana: método ===================== --}}
    @if ($modal === 'metodo')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarMetodo">
                        <div class="modal-header">
                            <h5 class="modal-title">{{ $metodoId ? 'Editar método de pago' : 'Nuevo método de pago' }}</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Pasarela <span class="text-danger">*</span></label>
                                    <select class="form-select" wire:model="pasarela">
                                        @foreach ($pasarelas as $clave => $etiqueta)
                                            <option value="{{ $clave }}">{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                    @error('pasarela') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Variante <span class="text-danger">*</span></label>
                                    <select class="form-select" wire:model.defer="variante">
                                        @foreach ($variantes as $clave => $etiqueta)
                                            <option value="{{ $clave }}">{{ $etiqueta }}</option>
                                        @endforeach
                                    </select>
                                    @error('variante') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Entorno</label>
                                    <select class="form-select" wire:model.defer="entorno">
                                        <option value="produccion">Producción</option>
                                        <option value="pruebas">Pruebas</option>
                                    </select>
                                    @error('entorno') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nombre visible <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" wire:model.defer="nombre" maxlength="100" autocomplete="off">
                                <div class="form-text">Es lo que ve el médico o el paciente al elegir cómo pagar.</div>
                                @error('nombre') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>

                            @if ($pasarela === 'sypago')
                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label">Host de SyPago</label>
                                        <input type="url" class="form-control" wire:model.defer="host" autocomplete="off"
                                               placeholder="https://app.sypago.net:8086/api/v1/">
                                        <div class="form-text">Producción o certificación (<code>https://pruebas.api.sypago.net/api/v1/</code>).</div>
                                        @error('host') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label">Tipo de credencial</label>
                                        <select class="form-select" wire:model="tipoCredencial">
                                            <option value="api_key">API Key dinámica</option>
                                            <option value="jwt">JWT fijo</option>
                                        </select>
                                        <div class="form-text">La API Key dinámica pide el token a SyPago; el JWT fijo ya es el token.</div>
                                        @error('tipoCredencial') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                @if ($tipoCredencial === 'api_key')
                                    <div class="row">
                                        <div class="col-md-5 mb-3">
                                            <label class="form-label">Usuario SyPago (client_id) <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" wire:model.defer="clientId" autocomplete="off"
                                                   placeholder="ddrs">
                                            @error('clientId') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="col-md-7 mb-3">
                                            <label class="form-label">API Key (secret) <span class="text-danger">*</span></label>
                                            <input type="password" class="form-control" wire:model.defer="secret" autocomplete="new-password"
                                                   placeholder="{{ $metodoId ? 'Dejar vacío para conservar' : 'La API Key de SyPago' }}">
                                            <div class="form-text">Se guarda cifrada. Con ella se pide el access token a SyPago.</div>
                                            @error('secret') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                        </div>
                                    </div>
                                @else
                                    <div class="mb-3">
                                        <label class="form-label">JWT fijo <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" wire:model.defer="accessToken" autocomplete="new-password"
                                               placeholder="{{ $metodoId ? 'Dejar vacío para conservar' : 'El token que ya es el Bearer' }}">
                                        <div class="form-text">Se guarda cifrado. Sólo si SyPago te entregó un token sin expiración.</div>
                                        @error('accessToken') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                    </div>
                                @endif
                            @endif

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <span class="form-label d-block">Para qué se ofrece</span>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ofrece-planes" value="planes"
                                               wire:model.defer="disponiblePara">
                                        <label class="form-check-label" for="ofrece-planes">Planes de médicos</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ofrece-cobros" value="cobros"
                                               wire:model.defer="disponiblePara">
                                        <label class="form-check-label" for="ofrece-cobros">Cobros a pacientes</label>
                                    </div>
                                    @error('disponiblePara') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Comisión por prestar el método (%)</label>
                                    <input type="number" min="0" max="100" step="0.01" class="form-control"
                                           wire:model.defer="comisionPorcentaje" placeholder="Global">
                                    <div class="form-text">Vacío = la regla global. Ejemplo: 10. Sólo se descuenta cuando un médico cobra a un paciente con este método; los planes no llevan comisión.</div>
                                    @error('comisionPorcentaje') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Orden</label>
                                    <input type="number" min="0" class="form-control" wire:model.defer="orden">
                                    @error('orden') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>

                            <hr>
                            <p class="fw-semibold mb-2">Cuenta donde se recibe el dinero</p>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Código del banco <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model.defer="bancoCodigo" maxlength="4" placeholder="0172" autocomplete="off">
                                    @error('bancoCodigo') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label class="form-label">Número de cuenta <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model.defer="cuentaNumero" maxlength="30" autocomplete="off">
                                    @error('cuentaNumero') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Titular <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model.defer="cuentaTitular" maxlength="120" autocomplete="off">
                                    @error('cuentaTitular') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Tipo de documento</label>
                                    <select class="form-select" wire:model.defer="cuentaDocumentoTipo">
                                        @foreach (\App\Models\CuentaBancaria::DOCUMENTOS as $tipo)
                                            <option value="{{ $tipo }}">{{ $tipo }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-5 mb-3">
                                    <label class="form-label">Documento del titular <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" wire:model.defer="cuentaDocumentoNumero" maxlength="20" autocomplete="off">
                                    @error('cuentaDocumentoNumero') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                                </div>
                                <div class="col-md-4 pt-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="metodo-activo" wire:model.defer="activo">
                                        <label class="form-check-label" for="metodo-activo">Activo</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ====================== Ventana: tasa ====================== --}}
    @if ($modal === 'tasa')
        <div class="modal fade show d-block" tabindex="-1" role="dialog" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form wire:submit.prevent="guardarTasa">
                        <div class="modal-header">
                            <h5 class="modal-title">Nueva tasa VES/USD</h5>
                            <button type="button" class="btn-close" wire:click="cerrarModal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">VES por 1 USD <span class="text-danger">*</span></label>
                                <input type="number" min="0.000001" step="0.000001" class="form-control" wire:model.defer="tasa"
                                       placeholder="36,5000">
                                @error('tasa') <span class="text-danger small d-block">{{ $message }}</span> @enderror
                            </div>
                            @if ($tasaAutomatica)
                                <div class="alert alert-danger py-2 small mb-3" role="alert">
                                    <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                                    La sincronización automática está activada: el BCV puede sobrescribir este valor en la
                                    próxima actualización. Si quieres fijarlo, apágala primero.
                                </div>
                            @endif
                            @if ($tasa !== '' && is_numeric($tasa) && \App\Pagos\Tasa::aEscala($tasa) > 0)
                                <p class="small mb-0">
                                    Un plan de <strong>USD 20,00</strong> se va a cobrar como
                                    <strong>VES {{ \App\Pagos\Centavos::aTexto(\App\Pagos\Tasa::usdAVes(2000, $tasa)) }}</strong>.
                                </p>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="cerrarModal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Guardar tasa</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
</div>
