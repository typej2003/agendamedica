<?php

namespace App\Models;

use App\Pagos\Centavos;
use App\Pagos\Tasa;
use Illuminate\Database\Eloquent\Model;

/**
 * El **historial de la tasa VES/USD**. La que se usa es la última con `vigente_desde <= ahora`.
 *
 * La tasa es un factor, no un monto: se guarda en `DECIMAL(18,6)` y se opera con `App\Pagos\Tasa`
 * (entero escalado). Cambiar la tasa no reescribe los pagos: cada pago copia la suya.
 */
class TasaDeCambio extends Model
{
    public const MANUAL = 'manual';
    public const BCV = 'bcv';

    public const ORIGENES = [
        self::MANUAL => 'Manual',
        self::BCV => 'BCV',
    ];

    protected $table = 'tasas_de_cambio';

    protected $fillable = [
        'moneda_origen', 'moneda_destino', 'tasa', 'origen', 'vigente_desde', 'creado_por',
    ];

    protected $casts = [
        'tasa' => 'decimal:6',
        'vigente_desde' => 'datetime',
    ];

    /** La tasa vigente para USD→VES; `null` si todavía no se cargó ninguna. */
    public static function actual(string $origen = 'USD', string $destino = 'VES'): ?self
    {
        return static::where('moneda_origen', $origen)
            ->where('moneda_destino', $destino)
            ->where('vigente_desde', '<=', now())
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }

    /** Carga una tasa nueva; no borra ni pisa las anteriores. */
    public static function fijar(
        string|int|float $tasa,
        string $origen = self::MANUAL,
        ?int $creadoPor = null,
        ?\DateTimeInterface $vigenteDesde = null
    ): self {
        return static::create([
            'moneda_origen' => 'USD',
            'moneda_destino' => 'VES',
            'tasa' => Tasa::desdeEscala(Tasa::aEscala($tasa)),
            'origen' => $origen,
            'vigente_desde' => $vigenteDesde ?? now(),
            'creado_por' => $creadoPor,
        ]);
    }

    /** `"36,5000"` para una pantalla. */
    public function aTexto(): string
    {
        return number_format((float) $this->tasa, 4, ',', '.');
    }

    /** Cuántos centavos de VES son `$usdE2` centavos de USD con esta tasa. */
    public function convertirUsdAVes(int $usdE2): int
    {
        return Tasa::usdAVes($usdE2, $this->tasa);
    }

    /** Cuántos centavos de USD son `$vesE2` centavos de VES con esta tasa. */
    public function convertirVesAUsd(int $vesE2): int
    {
        return Tasa::vesAUsd($vesE2, $this->tasa);
    }

    /** El monto de un plan en USD (texto decimal) a VES, en centavos. */
    public function convertirPrecioUsdAVes(string $precioUsd): int
    {
        return $this->convertirUsdAVes(Centavos::deDecimal($precioUsd));
    }
}
