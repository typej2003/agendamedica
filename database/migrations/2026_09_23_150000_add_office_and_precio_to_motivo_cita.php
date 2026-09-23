<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `motivo_cita.office_id` y `motivo_cita.precio` — el motivo deja de ser solo del médico y pasa a
 * ser **de una sede**.
 *
 * Decisión del usuario (no una regla heredada del legado): tener varias sedes es la minoría de los
 * casos, pero cuando pasa, un motivo puede no estar disponible en todas —por equipo que solo hay en
 * un consultorio— y el precio puede variar entre una y otra. `Manual agenda.pdf` (pág. 7) confirma
 * el mismo motivo con precios distintos según la "Agenda" (sede) elegida.
 *
 * `office_id` nullable a propósito, por dos razones: las filas que ya trae el sync del PowerBuilder
 * (`gineco-00001`, códigos 01/02/03) no conocen el concepto de sede y van a seguir llegando sin
 * ella; y un motivo sin sede asignada se sigue ofreciendo en el picker de cualquier sede como
 * fallback, en vez de desaparecer.
 *
 * `precio` con `double`, igual que `cola.monto`/`motivo_factura.monto`: es la convención de dinero
 * ya establecida en este esquema.
 */
class AddOfficeAndPrecioToMotivoCita extends Migration
{
    public function up()
    {
        Schema::table('motivo_cita', function (Blueprint $table) {
            if (!Schema::hasColumn('motivo_cita', 'office_id')) {
                $table->unsignedBigInteger('office_id')->nullable()->after('reg_medico');
                $table->foreign('office_id')->references('id')->on('offices')->onDelete('cascade');
            }
            if (!Schema::hasColumn('motivo_cita', 'precio')) {
                $table->double('precio')->nullable()->after('tipo_atencion');
            }
        });
    }

    public function down()
    {
        if (Schema::hasColumn('motivo_cita', 'precio')) {
            Schema::table('motivo_cita', function (Blueprint $table) {
                $table->dropColumn('precio');
            });
        }

        if (Schema::hasColumn('motivo_cita', 'office_id')) {
            Schema::table('motivo_cita', function (Blueprint $table) {
                $table->dropForeign(['office_id']);
                $table->dropColumn('office_id');
            });
        }
    }
}
