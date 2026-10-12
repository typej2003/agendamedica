<?php

namespace App\Clinica\Verificacion;

/** Los estados de la página pública de verificación de un récipe (WEB-5.6). */
final class EstadoDeLaVerificacion
{
    /** El récipe existe, el QR está activo y el plazo de vigencia no pasó. */
    public const VIGENTE = 'vigente';

    /** El récipe existe y el QR está activo, pero ya pasó su vigencia. */
    public const VENCIDO = 'vencido';

    /** El médico anuló el QR. */
    public const ANULADO = 'anulado';

    /** El QR existe pero el médico eliminó el récipe. */
    public const ELIMINADO = 'eliminado';

    /** Ningún récipe tiene ese token (o no tiene forma de token). */
    public const NO_ENCONTRADO = 'no_encontrado';

    /** El QR de la hoja de prueba del formato del médico: no verifica ningún récipe. */
    public const EJEMPLO = 'ejemplo';
}
