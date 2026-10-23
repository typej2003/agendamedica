<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un reporte de fallo que mandó una PC del consultorio (ver `reportes_escritorio`).
 *
 * No es de la historia clínica ni viaja por el sync: es diagnóstico, para poder arreglar sin pedirle
 * al médico que copie el error.
 */
class ReporteEscritorio extends Model
{
    protected $table = 'reportes_escritorio';

    public const INSTALADOR = 'instalador';
    public const LAUNCHER = 'launcher';
    public const SINCRONIZACION = 'sincronizacion';
    public const IMAGENES = 'imagenes';

    public const TIPOS = [
        self::INSTALADOR,
        self::LAUNCHER,
        self::SINCRONIZACION,
        self::IMAGENES,
    ];

    public const ETIQUETAS = [
        self::INSTALADOR     => 'Instalador',
        self::LAUNCHER       => 'Launcher',
        self::SINCRONIZACION => 'Sincronización',
        self::IMAGENES       => 'Imágenes',
    ];

    protected $fillable = [
        'reg_medico',
        'tipo',
        'etapa',
        'aplicacion',
        'equipo',
        'version_canal',
        'windows',
        'mensaje',
        'detalle',
        'ip',
    ];

    public function etiquetaDelTipo(): string
    {
        return self::ETIQUETAS[$this->tipo] ?? $this->tipo;
    }
}
