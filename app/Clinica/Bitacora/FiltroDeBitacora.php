<?php

namespace App\Clinica\Bitacora;

use App\Clinica\FechaDelConsultorio;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Lo que el médico pidió ver en la bitácora (WEB-8.5): un rango de fechas, quién lo hizo, qué tipo de dato y de qué paciente.
 * Es un objeto de valores sin base de datos: lo arma el controlador desde la URL y todo lo que no es válido se ignora (una fecha
 * mal escrita no rompe la pantalla, simplemente no filtra).
 */
final class FiltroDeBitacora
{
    /** El valor del filtro «usuario» para lo que no tiene usuario (el móvil, el escritorio, lo anterior a la bitácora). */
    public const SIN_USUARIO = 'sin';

    public function __construct(
        public readonly ?string $desde = null,
        public readonly ?string $hasta = null,
        public readonly string $usuario = '',
        public readonly string $tipo = '',
        public readonly string $paciente = '',
        public readonly int $pagina = 1,
    ) {
    }

    /** @param  array<string,mixed>  $consulta  los parámetros de la URL */
    public static function desde(array $consulta): self
    {
        $usuario = trim((string) ($consulta['usuario'] ?? ''));
        $tipo = trim((string) ($consulta['tipo'] ?? ''));
        $pagina = (int) ($consulta['page'] ?? 1);

        return new self(
            self::fecha($consulta['desde'] ?? null),
            self::fecha($consulta['hasta'] ?? null),
            ($usuario === self::SIN_USUARIO || ctype_digit($usuario)) ? $usuario : '',
            DiccionarioDeLaBitacora::existeElTipo($tipo) ? $tipo : '',
            mb_substr(trim((string) ($consulta['paciente'] ?? '')), 0, 100),
            max(1, min($pagina, 5000)),
        );
    }

    private static function fecha($valor): ?string
    {
        $valor = trim((string) $valor);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }

        $fecha = Carbon::createFromFormat('!Y-m-d', $valor);

        return $fecha && $fecha->format('Y-m-d') === $valor ? $valor : null;
    }

    /** ¿Hay algo que acote la lista? (la página no cuenta) */
    public function hayFiltro(): bool
    {
        return $this->desde !== null || $this->hasta !== null || $this->usuario !== '' || $this->tipo !== '' || $this->paciente !== '';
    }

    /** El principio del rango, en UTC (como se guarda `occurred_at`): el inicio del día `desde` en el consultorio. */
    public function desdeUtc(): ?CarbonInterface
    {
        return $this->desde === null ? null : $this->inicioDelDia($this->desde);
    }

    /** El final (exclusivo) del rango, en UTC: el inicio del día siguiente a `hasta` en el consultorio. */
    public function hastaUtc(): ?CarbonInterface
    {
        return $this->hasta === null ? null : $this->inicioDelDia($this->hasta)->addDay();
    }

    private function inicioDelDia(string $fecha): CarbonInterface
    {
        return Carbon::createFromFormat('!Y-m-d', $fecha, FechaDelConsultorio::zona())->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * Los parámetros para volver a esta misma lista (sin la página), sólo los que están puestos.
     *
     * @return array<string,string>
     */
    public function parametros(): array
    {
        return array_filter([
            'desde' => $this->desde,
            'hasta' => $this->hasta,
            'usuario' => $this->usuario,
            'tipo' => $this->tipo,
            'paciente' => $this->paciente,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    public function conPagina(int $pagina): self
    {
        return new self($this->desde, $this->hasta, $this->usuario, $this->tipo, $this->paciente, max(1, $pagina));
    }
}
