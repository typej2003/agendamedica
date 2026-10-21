<?php

namespace App\Http\Livewire\Admin;

use App\Models\ConfiguracionPago;
use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\TasaDeCambio;
use App\Pagos\CatalogoDePasarelas;
use App\Pagos\Centavos;
use App\Pagos\ClienteSyPago;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * **Métodos de pago de la plataforma** (`/admin/metodos-pago`): lo que usa Doctorisimo para cobrar los
 * planes y, a futuro, para prestarle un canal al médico que no tiene el suyo.
 *
 * Dos pestañas: `metodos` (SyPago y manual, con sus credenciales cifradas y su cuenta) y `tasa` (la
 * VES/USD manual, con historial). El cobro en sí no vive acá: esto sólo deja el método configurado y
 * probado.
 *
 * Como `Servicios` y `ApiKeys`, `hydrate()` vuelve a comprobar el permiso en cada acción de Livewire.
 */
class MetodosPago extends Component
{
    public $pestana = 'metodos'; // metodos | tasa

    public $modal = null; // metodo | tasa | null

    // Método
    public $metodoId = null;
    public $pasarela = CatalogoDePasarelas::SYPAGO;
    public $variante = 'debito_inmediato';
    public $nombre = '';
    public $activo = true;
    public $orden = 0;
    public $entorno = MetodoPago::PRODUCCION;
    public $disponiblePara = MetodoPago::AMBOS;
    public $comisionPorcentaje = '';
    public $host = '';
    // Cómo se autentica en SyPago: API Key dinámica (client_id + secret) o JWT fijo.
    public $tipoCredencial = 'api_key';
    public $clientId = '';
    public $secret = '';
    public $accessToken = '';

    // Cuenta que recibe (la del comercio en SyPago)
    public $bancoCodigo = '';
    public $cuentaNumero = '';
    public $cuentaTitular = '';
    public $cuentaDocumentoTipo = 'J';
    public $cuentaDocumentoNumero = '';

    // Resultado de "probar conexión"
    public $resultadoPrueba = null;
    public $bancos = [];

    // Tasa
    public $tasa = '';

    public function mount()
    {
        $this->autorizar();
        $this->host = (string) config('pagos.sypago.host');
        $this->tasa = (string) (TasaDeCambio::actual()?->tasa ?? '');
    }

    public function hydrate()
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->esAdministrador() && $usuario->is_active !== false, 403);
    }

    public function cambiarPestana(string $pestana): void
    {
        $this->pestana = $pestana === 'tasa' ? 'tasa' : 'metodos';
        $this->cerrarModal();
    }

    /* ------------------------------------------------------------------ */
    /* Métodos                                                             */
    /* ------------------------------------------------------------------ */

    public function nuevoMetodo(): void
    {
        $this->resetErrorBag();
        $this->reset([
            'metodoId', 'nombre', 'comisionPorcentaje', 'clientId', 'secret', 'accessToken',
            'bancoCodigo', 'cuentaNumero', 'cuentaTitular', 'cuentaDocumentoNumero', 'resultadoPrueba', 'bancos',
        ]);
        $this->pasarela = CatalogoDePasarelas::SYPAGO;
        $this->variante = 'debito_inmediato';
        $this->tipoCredencial = 'api_key';
        $this->nombre = CatalogoDePasarelas::etiqueta($this->pasarela, $this->variante);
        $this->activo = true;
        $this->entorno = MetodoPago::PRODUCCION;
        $this->disponiblePara = MetodoPago::AMBOS;
        $this->orden = (int) MetodoPago::deLaPlataforma()->max('orden') + 1;
        $this->host = (string) config('pagos.sypago.host');
        $this->cuentaDocumentoTipo = 'J';
        $this->modal = 'metodo';
    }

    public function editarMetodo(int $metodoId): void
    {
        $metodo = MetodoPago::deLaPlataforma()->findOrFail($metodoId);
        $cuenta = $metodo->cuentaBancaria;

        $this->resetErrorBag();
        $this->reset(['resultadoPrueba', 'bancos']);
        $this->metodoId = $metodo->id;
        $this->pasarela = $metodo->pasarela;
        $this->variante = $metodo->variante;
        $this->nombre = $metodo->nombre;
        $this->activo = (bool) $metodo->activo;
        $this->orden = (int) $metodo->orden;
        $this->entorno = $metodo->entorno;
        $this->disponiblePara = $metodo->disponible_para;
        // Los puntos base se muestran como porcentaje: 1000 bp = "10.00".
        $this->comisionPorcentaje = $metodo->comision_bp === null ? '' : Centavos::aDecimal($metodo->comision_bp);
        $this->host = (string) (($metodo->credenciales ?? [])['host'] ?? config('pagos.sypago.host'));
        // El secreto y el token no se mandan a la vista: si se dejan vacíos, se conservan los que ya estaban.
        $this->tipoCredencial = (string) (($metodo->credenciales ?? [])['tipo']
            ?? (trim((string) (($metodo->credenciales ?? [])['client_id'] ?? '')) !== '' ? 'api_key' : 'jwt'));
        $this->clientId = (string) (($metodo->credenciales ?? [])['client_id'] ?? '');
        $this->secret = '';
        $this->accessToken = '';
        $this->bancoCodigo = (string) ($cuenta->banco_codigo ?? '');
        $this->cuentaNumero = (string) ($cuenta->numero ?? '');
        $this->cuentaTitular = (string) ($cuenta->titular ?? '');
        $this->cuentaDocumentoTipo = (string) ($cuenta->documento_tipo ?? 'J');
        $this->cuentaDocumentoNumero = (string) ($cuenta->documento_numero ?? '');
        $this->modal = 'metodo';
    }

    public function updatedPasarela(): void
    {
        $variantes = CatalogoDePasarelas::variantesDe($this->pasarela);
        if (! array_key_exists($this->variante, $variantes)) {
            $this->variante = array_key_first($variantes) ?: '';
        }
        $this->nombre = CatalogoDePasarelas::etiqueta($this->pasarela, $this->variante);
    }

    public function guardarMetodo(): void
    {
        // El formulario es numérico, pero aceptamos la coma como separador decimal.
        $this->comisionPorcentaje = str_replace(',', '.', trim((string) $this->comisionPorcentaje));

        $this->validate([
            'pasarela' => ['required', Rule::in(array_keys(CatalogoDePasarelas::PASARELAS))],
            'variante' => ['required', Rule::in(array_keys(CatalogoDePasarelas::variantesDe($this->pasarela)))],
            'nombre' => 'required|string|max:100',
            'entorno' => ['required', Rule::in([MetodoPago::PRODUCCION, MetodoPago::PRUEBAS])],
            'disponiblePara' => ['required', Rule::in(array_keys(CatalogoDePasarelas::USOS))],
            'orden' => 'required|integer|min:0|max:9999',
            'comisionPorcentaje' => 'nullable|numeric|min:0|max:100',
            'host' => 'nullable|url|max:255',
            'tipoCredencial' => ['required', Rule::in(['api_key', 'jwt'])],
            'clientId' => 'nullable|string|max:100',
            'secret' => 'nullable|string|max:500',
            'accessToken' => 'nullable|string|max:500',
            'bancoCodigo' => 'required|digits:4',
            'cuentaNumero' => 'required|string|min:10|max:30',
            'cuentaTitular' => 'required|string|max:120',
            'cuentaDocumentoTipo' => ['required', Rule::in(CuentaBancaria::DOCUMENTOS)],
            'cuentaDocumentoNumero' => 'required|string|max:20',
        ], [
            'bancoCodigo.digits' => 'El código del banco son 4 dígitos (por ejemplo 0172).',
            'cuentaNumero.required' => 'Falta el número de cuenta donde se recibe el dinero.',
            'cuentaTitular.required' => 'Falta el titular de la cuenta.',
        ], [
            'pasarela' => 'pasarela', 'variante' => 'variante', 'nombre' => 'nombre',
            'tipoCredencial' => 'tipo de credencial', 'clientId' => 'usuario SyPago',
            'secret' => 'API Key', 'accessToken' => 'JWT', 'bancoCodigo' => 'código del banco',
            'cuentaNumero' => 'número de cuenta', 'cuentaTitular' => 'titular',
            'cuentaDocumentoNumero' => 'documento del titular', 'comisionPorcentaje' => 'comisión',
        ]);

        $metodo = $this->metodoId ? MetodoPago::deLaPlataforma()->findOrFail($this->metodoId) : null;
        $credencialesExistentes = $metodo?->credenciales ?? [];

        if ($this->pasarela === CatalogoDePasarelas::SYPAGO) {
            if ($this->tipoCredencial === 'api_key') {
                if (trim((string) $this->clientId) === '') {
                    $this->addError('clientId', 'Falta el usuario SyPago (client_id).');

                    return;
                }
                if (trim((string) $this->secret) === ''
                    && trim((string) ($credencialesExistentes['secret'] ?? '')) === '') {
                    $this->addError('secret', 'Falta la API Key (secret) de SyPago.');

                    return;
                }
            } elseif (trim((string) $this->accessToken) === ''
                && trim((string) ($credencialesExistentes['access_token'] ?? '')) === '') {
                $this->addError('accessToken', 'Falta el JWT fijo de SyPago.');

                return;
            }
        }

        DB::transaction(function () use ($metodo) {
            $cuenta = $metodo?->cuentaBancaria ?: new CuentaBancaria();
            $cuenta->fill([
                'propietario' => CuentaBancaria::PLATAFORMA,
                'reg_medico' => null,
                'moneda' => 'VES',
                'tipo' => CuentaBancaria::CUENTA,
                'banco_codigo' => trim((string) $this->bancoCodigo),
                'numero' => trim((string) $this->cuentaNumero),
                'telefono' => null,
                'titular' => trim((string) $this->cuentaTitular),
                'documento_tipo' => $this->cuentaDocumentoTipo,
                'documento_numero' => trim((string) $this->cuentaDocumentoNumero),
                'predeterminada' => true,
                'activa' => true,
            ])->save();

            $credenciales = $metodo?->credenciales ?? [];
            if ($this->pasarela === CatalogoDePasarelas::SYPAGO) {
                $credenciales['tipo'] = $this->tipoCredencial;
                $credenciales['host'] = trim((string) ($this->host ?: config('pagos.sypago.host')));

                if ($this->tipoCredencial === 'api_key') {
                    $credenciales['client_id'] = trim((string) $this->clientId);
                    if (trim((string) $this->secret) !== '') {
                        $credenciales['secret'] = trim((string) $this->secret);
                    }
                    unset($credenciales['access_token']);
                } else {
                    if (trim((string) $this->accessToken) !== '') {
                        $credenciales['access_token'] = trim((string) $this->accessToken);
                    }
                    unset($credenciales['client_id'], $credenciales['secret']);
                }
            } else {
                $credenciales = [];
            }

            $datos = [
                'propietario' => MetodoPago::PLATAFORMA,
                'reg_medico' => null,
                'pasarela' => $this->pasarela,
                'variante' => $this->variante,
                'nombre' => trim((string) $this->nombre),
                'activo' => (bool) $this->activo,
                'orden' => (int) $this->orden,
                'entorno' => $this->entorno,
                'credenciales' => $credenciales,
                'cuenta_bancaria_id' => $cuenta->id,
                // "10.00" (%) → 1000 puntos base; vacío = usa la regla global.
                'comision_bp' => $this->comisionPorcentaje === '' || $this->comisionPorcentaje === null
                    ? null
                    : Centavos::deDecimal($this->comisionPorcentaje),
                'disponible_para' => $this->disponiblePara,
            ];

            if ($metodo) {
                $metodo->update($datos);
            } else {
                MetodoPago::create($datos);
            }
        });

        session()->flash('message', 'Método de pago guardado.');
        $this->cerrarModal();
    }

    public function alternarActivo(int $metodoId): void
    {
        $metodo = MetodoPago::deLaPlataforma()->findOrFail($metodoId);
        $metodo->update(['activo' => ! $metodo->activo]);

        session()->flash('message', 'Método "' . $metodo->etiqueta() . '" ' . ($metodo->activo ? 'activado' : 'desactivado') . '.');
    }

    public function probarConexion(int $metodoId): void
    {
        $metodo = MetodoPago::deLaPlataforma()->findOrFail($metodoId);

        if ($metodo->pasarela !== CatalogoDePasarelas::SYPAGO) {
            session()->flash('error', 'Sólo SyPago tiene una conexión que probar.');

            return;
        }

        $resultado = ClienteSyPago::para($metodo)->probar();

        $metodo->update([
            'verificado_el' => $resultado['ok'] ? now() : null,
            'ultimo_error' => $resultado['ok'] ? null : $resultado['mensaje'],
        ]);

        $this->resultadoPrueba = $resultado;
        $this->bancos = $resultado['bancos'];

        session()->flash($resultado['ok'] ? 'message' : 'error', $resultado['mensaje']);
    }

    /* ------------------------------------------------------------------ */
    /* Tasa                                                                */
    /* ------------------------------------------------------------------ */

    public function nuevaTasa(): void
    {
        $this->resetErrorBag();
        $this->tasa = (string) (TasaDeCambio::actual()?->tasa ?? '');
        $this->modal = 'tasa';
    }

    public function guardarTasa(): void
    {
        $this->tasa = str_replace(',', '.', trim((string) $this->tasa));

        $this->validate([
            'tasa' => 'required|numeric|min:0.000001|max:' . (int) config('pagos.maximo_tasa', 10000),
        ], [
            'tasa.required' => 'Falta la tasa VES por USD.',
            'tasa.numeric' => 'La tasa tiene que ser un número (por ejemplo 36,5000).',
            'tasa.min' => 'La tasa tiene que ser mayor que cero.',
            'tasa.max' => 'La tasa supera el máximo permitido.',
        ], ['tasa' => 'tasa']);

        $nueva = TasaDeCambio::fijar($this->tasa, TasaDeCambio::MANUAL, auth()->id());

        session()->flash('message', 'Tasa actualizada: 1 USD = ' . $nueva->aTexto() . ' VES.');
        $this->cerrarModal();
    }

    /* ------------------------------------------------------------------ */
    /* Ventanas y render                                                   */
    /* ------------------------------------------------------------------ */

    public function cerrarModal(): void
    {
        $this->modal = null;
        $this->metodoId = null;
        $this->resultadoPrueba = null;
        $this->bancos = [];
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.admin.metodos-pago', [
            'metodos' => MetodoPago::deLaPlataforma()->orderBy('orden')->orderBy('id')->get(),
            'tasaActual' => TasaDeCambio::actual(),
            'historialTasas' => TasaDeCambio::orderByDesc('vigente_desde')->orderByDesc('id')->limit(15)->get(),
            'configuracion' => ConfiguracionPago::actual(),
            'variantes' => CatalogoDePasarelas::variantesDe($this->pasarela),
            'pasarelas' => CatalogoDePasarelas::PASARELAS,
            'usos' => CatalogoDePasarelas::USOS,
        ]);
    }
}
