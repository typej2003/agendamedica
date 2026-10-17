<?php

namespace App\Clinica\Listados;

/**
 * **Los listados clínicos entre fechas de la web** (WEB-6.8) y la clave con la que se elige uno.
 *
 * La pantalla es **una sola y parametrizada** (`/clinica/listados`): un rango, unos filtros y una grilla. No
 * es una pantalla por reporte. El escritorio los tiene como ventanas sueltas dentro de
 * `w_reportes_consultas.srw` —de ahí salen los `s_tipo` `21` (por rango) y `22` (por semana) que abren
 * `w_reporte_consulta_partos.srw`—, con su filtro de fechas y de edad repetido en cada uno; la web los
 * reúne en el mismo módulo (`app/Clinica/Listados/`, sus ViewModels en `Vistas/` y las vistas en
 * `resources/views/clinica/listados/`).
 *
 * **Hoy están los tres del alcance de WEB-6.8**: *Embarazos / partos* (el *Listado de Partos* del escritorio),
 * *Pacientes por rango* (*Indicadores pacientes*, `w_reprotes_pacientes.srw`) y *Consultas por rango* (*Indicadores
 * de Consultas*, `w_reporte_consulta_barbaro.srw`). Uno nuevo entra acá —una clave más, con su lectura y su
 * ViewModel— sin tocar la ruta ni la pantalla.
 *
 * Una clave que no esté en la lista vuelve al listado por defecto en vez de mostrar una pantalla a medias,
 * con la misma vara que `FiltroDePacientes::normalizar` (trampa: un `?historia=` que no se entiende vuelve a
 * "Todos").
 */
final class ListadosDisponibles
{
    /** El listado de embarazos / partos: el *Listado de Partos* del escritorio. */
    public const EMBARAZOS = 'embarazos';

    /**
     * El listado de pacientes ingresados entre dos fechas: los *Indicadores pacientes* del escritorio
     * (`w_reprotes_pacientes.srw`).
     */
    public const PACIENTES = 'pacientes-por-rango';

    /**
     * El listado de consultas entre dos fechas, con motivo, diagnóstico, medicamento y antecedente opcionales: los
     * *Indicadores de Consultas* del escritorio (`w_reporte_consulta_barbaro.srw`).
     */
    public const CONSULTAS = 'consultas-por-rango';

    /**
     * Las claves con pantalla, en el orden en que se ofrecen: `clave => título de la pantalla`.
     *
     * ⚠️ Agregar una acá **no alcanza**: cada listado necesita su lectura y su ViewModel. El título es el
     * que muestra el encabezado de la pantalla y el que va en la hoja impresa.
     */
    private const LISTADOS = [
        self::EMBARAZOS => 'Embarazos y partos',
        self::PACIENTES  => 'Pacientes por rango',
        self::CONSULTAS  => 'Consultas por rango',
    ];

    /** El listado que se muestra cuando no se pidió ninguno. */
    public static function porDefecto(): string
    {
        return self::EMBARAZOS;
    }

    /** @return array<int,string> las claves con pantalla, en el orden en que se ofrecen */
    public static function claves(): array
    {
        return array_keys(self::LISTADOS);
    }

    public static function existe(?string $clave): bool
    {
        return $clave !== null && array_key_exists($clave, self::LISTADOS);
    }

    /** La clave pedida, si existe; la del listado por defecto en cualquier otro caso. */
    public static function normalizar(?string $clave): string
    {
        return self::existe($clave) ? $clave : self::porDefecto();
    }

    public static function titulo(string $clave): string
    {
        return self::LISTADOS[self::normalizar($clave)];
    }
}
