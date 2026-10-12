<?php

namespace App\Clinica\Impresion;

use App\Clinica\FechaDelConsultorio;

/**
 * **El récipe de ejemplo** de la hoja de prueba (WEB-8.2): un paciente inventado con tres medicamentos y una próxima cita
 * de ejemplo, para ver cómo queda el formato del médico sin elegir a ningún paciente real.
 *
 * Es el "Imprimir Formatos" del escritorio y el `recipe_de_ejemplo.dart` del móvil. Los medicamentos son de muestra: ninguna
 * de estas filas existe en el vademécum de nadie ni se guarda en ninguna parte. Sin base de datos.
 */
final class RecipeDeEjemplo
{
    /** Los días que faltan para la "próxima cita" de ejemplo. */
    private const DIAS_A_LA_PROXIMA_CITA = 15;

    /** El récipe del paciente de prueba, con la fecha de hoy (la del consultorio). */
    public static function documento(): DocumentoDeRecipe
    {
        return new DocumentoDeRecipe(
            DocumentoDeRecipe::PACIENTE,
            'Récipe de ejemplo',
            1,
            FechaDelConsultorio::hoy()->toDateString(),
            [
                'nombre' => 'Paciente De Prueba',
                'cedula' => 'V-12345678',
                'edad' => 36,
                'direccion' => 'Av. Principal, Edificio Ejemplo, piso 1',
            ],
            [
                new ItemImpreso('EJ-1', 1, 'Tomar 1 tableta vía oral cada 24 horas por 30 días.', 'FOLIFER', 'ÁCIDO FÓLICO', 'Tabletas 5 mg', null),
                new ItemImpreso('EJ-2', 2, 'Tomar 1 cápsula vía oral cada 8 horas por 7 días.', 'AMOXAL', 'AMOXICILINA', 'Cápsulas 500 mg', null),
                new ItemImpreso('EJ-3', 1, 'Aplicar una capa fina en la zona afectada dos veces al día.', 'CICATRIL', 'CLOSTEBOL', 'Crema 15 g', null),
            ],
            // El QR de ejemplo (WEB-5.6): rotulado «EJEMPLO», no apunta a ningún récipe real.
            QrDelRecipe::deEjemplo(),
        );
    }

    /**
     * La "próxima cita" de ejemplo: dentro de quince días. Sin hora, como sale en un consultorio por orden de llegada.
     *
     * @return array{fecha:string, hora:?string}
     */
    public static function proximaCita(): array
    {
        return [
            'fecha' => FechaDelConsultorio::hoy()->addDays(self::DIAS_A_LA_PROXIMA_CITA)->toDateString(),
            'hora' => null,
        ];
    }
}
