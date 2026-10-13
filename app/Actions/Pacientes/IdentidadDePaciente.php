<?php

namespace App\Actions\Pacientes;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Paciente;

/**
 * La **identidad** del paciente: quién es dueño de una cédula.
 *
 * Es la regla que comparten las tres Actions que escriben `pacientes` —`CrearPaciente` (alta y enlace),
 * `CambiarCedula` (WEB-2.15) y `GuardarPaciente` (WEB-2.3.2, alta y edición de la ficha)— y existe para
 * que no haya dos versiones de la misma decisión: **una cédula que ya es de otro paciente se rechaza**,
 * nombrando al otro.
 *
 * Por qué se rechaza y no se escribe igual, como hace el escritorio
 * (`w_listado_pacientes_a_citas.srw:255-294`, que hace el `UPDATE` sin validar nada): la cédula es la
 * clave con la que el legado reconoce a **la misma persona** entre consultorios
 * (`CrearPaciente`, `App\Sync\Escritorio\PacientesLegado`) y con la que enlaza la ficha que sube el
 * escritorio. Dos fichas con la misma cédula significan que el próximo `pacientes` que suba puede
 * **fusionar dos personas**.
 *
 * La otra mitad de la regla es la del "sin cédula": `S.C.` (o vacío) **no es una identidad**, así que no
 * se usa para buscar ni para enlazar. Dos pacientes distintos sin cédula no se pisan la ficha.
 */
final class IdentidadDePaciente
{
    /**
     * ¿El texto es el marcador de "sin cédula" del alta (`S.C.`) o está vacío?
     *
     * Es la definición que ya vive en `CamposDePaciente` (la que usa la ficha para mostrar `—`): acá se
     * consulta, no se copia, para que "sin cédula" signifique lo mismo en las dos capas.
     */
    public static function esSinCedula($valor): bool
    {
        $texto = trim((string) $valor);

        return $texto === '' || CamposDePaciente::esSinCedula($valor);
    }

    /**
     * El paciente que ya tiene esa cédula, o `null`. `$excepto` es el paciente que se está editando: su
     * propia cédula no cuenta como "de otro".
     *
     * Devuelve `null` para el "sin cédula" (no identifica a nadie) y para la cédula del propio paciente.
     */
    public static function duenoDe(string $cedula, ?Paciente $excepto = null): ?Paciente
    {
        if (self::esSinCedula($cedula)) {
            return null;
        }

        $consulta = Paciente::where('cedula', trim($cedula));

        if ($excepto !== null && $excepto->exists) {
            $consulta->where('id', '!=', $excepto->getKey());
        }

        return $consulta->first();
    }

    /**
     * El nombre del otro paciente, para el mensaje de rechazo. Si la ficha está vacía se nombra la
     * historia, que es lo único que la distingue.
     */
    public static function nombreDe(Paciente $paciente): string
    {
        $nombre = trim(($paciente->apellidos ?? '') . ', ' . ($paciente->nombres ?? ''), ', ');

        return $nombre !== ''
            ? $nombre
            : 'otro paciente (historia ' . ($paciente->numhistoria ?? 'sin número') . ')';
    }

    /**
     * El mensaje con el que se rechaza una cédula ajena. Es **el mismo** en el alta, en la edición y en
     * el cambio de cédula: la regla es una y se lee igual desde cualquier pantalla.
     */
    public static function cedulaOcupada(Paciente $otro, string $cedula): string
    {
        return 'La cédula ' . $cedula . ' ya es de ' . self::nombreDe($otro)
            . '. Si son la misma persona, corrige la ficha que quedó repetida en vez de duplicar la cédula.';
    }
}
