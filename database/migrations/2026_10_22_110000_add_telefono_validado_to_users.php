<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.telefono_validado` y `users.telefono_validado_en`: el teléfono que la cuenta del paciente probó
 * controlar con el código de 6 dígitos y cuándo lo hizo (identidad por niveles, nivel 1). Nullable y aditivo.
 *
 * El teléfono sólo cuenta como «validado» mientras la validación esté vigente
 * (`config('identidad.telefono_validacion_meses')`).
 *
 * Relleno: el registro de la cuenta del paciente SIEMPRE exigió el código al teléfono (`ConfirmarRegistroDePaciente`),
 * así que las cuentas de paciente existentes quedan con el teléfono de su ficha (normalizado) y la fecha de alta
 * como validación. Es lo único que se puede afirmar con honestidad; el resto (cuentas sin ficha o sin teléfono
 * válido) queda sin validar.
 *
 * **Guarda de columnas y relleno**: MySQL no corre el `ALTER` dentro de una transacción, así que si la migración
 * falla después de agregar las columnas la fila de `migrations` no queda escrita y la siguiente corrida se topa
 * con «Column already exists» (pasó en QA con la 0.3.0). Por eso cada columna se agrega sólo si falta y el
 * relleno **no pisa** una validación que ya esté anotada. Es inocuo en la base que ya las tiene.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('users', 'telefono_validado')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('telefono_validado', 20)->nullable()->after('blocked_by');
            });
        }

        if (! Schema::hasColumn('users', 'telefono_validado_en')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('telefono_validado_en')->nullable()->after('telefono_validado');
            });
        }

        $cuentas = DB::table('users')
            ->join('pacientes', 'pacientes.user_id', '=', 'users.id')
            ->join('model_has_roles', function ($j) {
                $j->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', '=', 'App\Models\User');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Paciente')
            ->whereNull('users.telefono_validado')
            ->whereNull('users.telefono_validado_en')
            ->get(['users.id as user_id', 'users.created_at', 'pacientes.telefono']);

        foreach ($cuentas as $cuenta) {
            $telefono = \App\Notificaciones\Telefono::internacional($cuenta->telefono);

            if ($telefono !== null) {
                DB::table('users')->where('id', $cuenta->user_id)->update([
                    'telefono_validado' => $telefono,
                    'telefono_validado_en' => $cuenta->created_at,
                ]);
            }
        }
    }

    public function down()
    {
        foreach (['telefono_validado', 'telefono_validado_en'] as $columna) {
            if (Schema::hasColumn('users', $columna)) {
                Schema::table('users', function (Blueprint $table) use ($columna) {
                    $table->dropColumn($columna);
                });
            }
        }
    }
};
