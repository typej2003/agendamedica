@php($esEdicion = $cuenta !== null)
@php($seguras = $esEdicion ? $cuenta->credencialesSeguras() : ['consumer_key' => '', 'consumer_secret' => '', 'token_url' => '', 'consulta_url' => '', 'tiene_secret' => false])
@php($conciliacion = old('conciliacion', $esEdicion ? $cuenta->conciliacion : \App\Models\CuentaBancaria::CONCILIACION_MANUAL))

<form method="POST" action="{{ route('clinica.cobros.cuentas.guardar') }}" class="row g-3">
    @csrf
    @if ($esEdicion)
        <input type="hidden" name="id" value="{{ $cuenta->id }}">
    @endif

    <div class="col-12 col-md-4">
        <label class="form-label" for="banco_codigo-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Banco</label>
        <select name="banco_codigo" id="banco_codigo-{{ $esEdicion ? $cuenta->id : 'nuevo' }}" class="form-select" required>
            <option value="">Elige el banco</option>
            @foreach ($bancos as $codigo => $nombre)
                <option value="{{ $codigo }}" @selected(old('banco_codigo', $esEdicion ? $cuenta->banco_codigo : '') === (string) $codigo)>
                    {{ $codigo }} — {{ $nombre }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label" for="telefono-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Teléfono del pago móvil</label>
        <input type="text" name="telefono" id="telefono-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
               class="form-control" maxlength="15" inputmode="numeric" placeholder="04141234567"
               value="{{ old('telefono', $esEdicion ? $cuenta->telefono : '') }}">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label" for="numero-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Número de cuenta (transferencia)</label>
        <input type="text" name="numero" id="numero-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
               class="form-control" maxlength="30" inputmode="numeric" placeholder="01140000000000000000"
               value="{{ old('numero', $esEdicion ? $cuenta->numero : '') }}">
    </div>

    <div class="col-12 col-md-4">
        <label class="form-label" for="titular-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Titular</label>
        <input type="text" name="titular" id="titular-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
               class="form-control" maxlength="120" required
               value="{{ old('titular', $esEdicion ? $cuenta->titular : '') }}">
    </div>

    <div class="col-6 col-md-2">
        <label class="form-label" for="documento_tipo-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Tipo</label>
        <select name="documento_tipo" id="documento_tipo-{{ $esEdicion ? $cuenta->id : 'nuevo' }}" class="form-select" required>
            @foreach (\App\Models\CuentaBancaria::DOCUMENTOS as $tipo)
                <option value="{{ $tipo }}" @selected(old('documento_tipo', $esEdicion ? $cuenta->documento_tipo : 'V') === $tipo)>{{ $tipo }}</option>
            @endforeach
        </select>
    </div>

    <div class="col-6 col-md-3">
        <label class="form-label" for="documento_numero-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Documento del titular</label>
        <input type="text" name="documento_numero" id="documento_numero-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
               class="form-control" maxlength="20" required
               value="{{ old('documento_numero', $esEdicion ? $cuenta->documento_numero : '') }}">
    </div>

    <div class="col-12 col-md-3">
        <label class="form-label" for="conciliacion-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Confirmación de los pagos</label>
        <select name="conciliacion" id="conciliacion-{{ $esEdicion ? $cuenta->id : 'nuevo' }}" class="form-select">
            <option value="manual" @selected($conciliacion === 'manual')>Manual (la confirmo yo)</option>
            <option value="automatica" @selected($conciliacion === 'automatica')>Automática (la consulta el banco)</option>
        </select>
        <div class="form-text">Sólo Bancaribe y Bancamiga tienen consulta automática.</div>
    </div>

    <div class="col-12">
        <details>
            <summary class="texto-tenue">Credenciales del banco para la confirmación automática</summary>
            <div class="row g-3 mt-1">
                <div class="col-12 col-md-6">
                    <label class="form-label" for="consumer_key-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Consumer key</label>
                    <input type="text" name="consumer_key" id="consumer_key-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
                           class="form-control" maxlength="120" autocomplete="off"
                           value="{{ old('consumer_key', $seguras['consumer_key']) }}">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="consumer_secret-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">Consumer secret</label>
                    <input type="password" name="consumer_secret" id="consumer_secret-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
                           class="form-control" maxlength="200" autocomplete="new-password"
                           placeholder="{{ $seguras['tiene_secret'] ? $seguras['consumer_secret'] . ' (guardado)' : '' }}">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="token_url-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">URL del token</label>
                    <input type="url" name="token_url" id="token_url-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
                           class="form-control" maxlength="200"
                           value="{{ old('token_url', $seguras['token_url']) }}">
                    <div class="form-text">Si la dejas vacía se usa la del servidor.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label" for="consulta_url-{{ $esEdicion ? $cuenta->id : 'nuevo' }}">URL de la consulta</label>
                    <input type="url" name="consulta_url" id="consulta_url-{{ $esEdicion ? $cuenta->id : 'nuevo' }}"
                           class="form-control" maxlength="200"
                           value="{{ old('consulta_url', $seguras['consulta_url']) }}">
                </div>
            </div>
        </details>
    </div>

    <div class="col-12">
        <button class="btn btn-primary">{{ $esEdicion ? 'Guardar los cambios' : 'Agregar la cuenta' }}</button>
    </div>
</form>
