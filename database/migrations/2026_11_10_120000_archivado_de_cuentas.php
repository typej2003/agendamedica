<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Archivar una cuenta de doctor** (2026-11-10): «borrarla» sin borrar nada.
 *
 * Es la decisión que ya estaba escrita («archivar, no eliminar»): un médico tiene historias, consultas y
 * citas colgando de su `reg_medico`, así que la cuenta se **oculta** y se conserva todo. Lo que sí se
 * libera es lo que identifica a la persona para poder volver a usarlo: el **correo** (con el que entra),
 * el **teléfono** y el **carnet**. Los tres se guardan en sus columnas `*_original` para poder restaurarlos
 * y para saber qué decían.
 *
 * `users.email` es único, así que el correo de la cuenta **se renombra** (ver `CuentaService::correoArchivado()`);
 * en la ficha `medicos.email` (que no es único) queda nulo y el original en `email_original`.
 *
 * Nullable y aditivo: las filas que ya existen quedan `archivado_en = null` (vigentes). MySQL no corre el
 * `ALTER` dentro de una transacción, así que cada columna se agrega sólo si falta: si la migración falla a
 * mitad, la siguiente corrida no se topa con «Duplicate column».
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->agregar('medicos', [
            'archivado_en'       => fn (Blueprint $table) => $table->timestamp('archivado_en')->nullable()->after('medico_padre_id'),
            'archivado_por_id'   => fn (Blueprint $table) => $table->unsignedBigInteger('archivado_por_id')->nullable()->after('archivado_en'),
            'archivado_motivo'   => fn (Blueprint $table) => $table->string('archivado_motivo')->nullable()->after('archivado_por_id'),
            'email_original'     => fn (Blueprint $table) => $table->string('email_original')->nullable()->after('archivado_motivo'),
            'telefono_original'  => fn (Blueprint $table) => $table->string('telefono_original')->nullable()->after('email_original'),
            'carnet_original'    => fn (Blueprint $table) => $table->string('carnet_original')->nullable()->after('telefono_original'),
        ]);

        $this->agregar('users', [
            'archivado_en'   => fn (Blueprint $table) => $table->timestamp('archivado_en')->nullable()->after('blocked_by'),
            'email_original' => fn (Blueprint $table) => $table->string('email_original')->nullable()->after('archivado_en'),
        ]);
    }

    public function down(): void
    {
        $this->quitar('medicos', ['archivado_en', 'archivado_por_id', 'archivado_motivo', 'email_original', 'telefono_original', 'carnet_original']);
        $this->quitar('users', ['archivado_en', 'email_original']);
    }

    /**
     * @param  array<string,callable(Blueprint):mixed>  $columnas  nombre => definición perezosa
     */
    private function agregar(string $tabla, array $columnas): void
    {
        $faltan = array_filter($columnas, fn (string $nombre) => ! Schema::hasColumn($tabla, $nombre), ARRAY_FILTER_USE_KEY);

        if ($faltan === []) {
            return;
        }

        Schema::table($tabla, function (Blueprint $table) use ($faltan) {
            foreach ($faltan as $definir) {
                $definir($table);
            }
        });
    }

    /** @param array<int,string> $columnas */
    private function quitar(string $tabla, array $columnas): void
    {
        $existen = array_values(array_filter($columnas, fn (string $columna) => Schema::hasColumn($tabla, $columna)));

        if ($existen === []) {
            return;
        }

        Schema::table($tabla, fn (Blueprint $table) => $table->dropColumn($existen));
    }
};
