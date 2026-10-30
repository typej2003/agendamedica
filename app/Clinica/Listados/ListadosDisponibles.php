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
    /** La ruta de la pantalla de los listados: el `listado` viaja en la query, no en la ruta. */
    public const RUTA = 'clinica.listados';

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

    /**
     * **De qué especialidad es cada listado** (revisión del usuario, 2026-10-18): el de embarazos y partos es de obstetricia, así
     * que sólo lo ve ginecología (un fragmento del `slug`, el mismo criterio que `CatalogosDisponibles`).
     *
     * Los listados que no están acá los ve cualquier especialidad. **Sin especialidad en el contexto no se ofrece el de otra**
     * (2026-10-30): antes el `null` mostraba el catálogo entero, y el médico sin especialidad terminaba viendo el reporte de
     * partos. Sin dato no se presume especialidad: sólo quedan los listados generales.
     */
    private const ESPECIALIDAD = [
        self::EMBARAZOS => 'ginecolog',
    ];

    /**
     * El icono de cada listado en el menú lateral: el de su reporte en el escritorio. Se mantiene acá, junto a las
     * claves, para que el submenú y cualquier otro consumidor no tengan un mapa paralelo.
     */
    private const ICONOS = [
        self::EMBARAZOS => 'bi-heart-pulse',
        self::PACIENTES => 'bi-people',
        self::CONSULTAS => 'bi-clipboard2-data',
    ];

    /** El listado que se muestra cuando no se pidió ninguno: el primero que ofrece la especialidad. */
    public static function porDefecto(?string $especialidad = null): string
    {
        return self::claves($especialidad)[0] ?? self::EMBARAZOS;
    }

    /**
     * Las claves con pantalla **que ofrece la especialidad**, en el orden en que se ofrecen: las de `LISTADOS` menos las que son
     * de otra especialidad (`ESPECIALIDAD`). Sin especialidad en el contexto salen todas, y los listados que no declaran
     * especialidad los ve cualquiera.
     *
     * @return array<int,string>
     */
    public static function claves(?string $especialidad = null): array
    {
        return array_keys(self::ofrecidos($especialidad));
    }

    /** ¿La especialidad del contexto tiene este listado? (ver `claves`) */
    public static function seOfrece(string $clave, ?string $especialidad = null): bool
    {
        return array_key_exists($clave, self::ofrecidos($especialidad));
    }

    public static function existe(?string $clave): bool
    {
        return $clave !== null && array_key_exists($clave, self::LISTADOS);
    }

    /**
     * La clave pedida, si existe **y la especialidad la ofrece**; la del listado por defecto de esa especialidad en cualquier otro
     * caso.
     *
     * A diferencia de las secciones de la consulta —donde lo que se deja de ofrecer sigue abriendo por URL directa—, acá un
     * `?listado=` de otra especialidad **vuelve al listado por defecto**: la pantalla es de sólo lectura y mostrarle a un pediatra
     * el reporte de partos no aporta nada (ni hay gestas que listar).
     */
    public static function normalizar(?string $clave, ?string $especialidad = null): string
    {
        return self::seOfrece((string) $clave, $especialidad) ? (string) $clave : self::porDefecto($especialidad);
    }

    public static function titulo(string $clave): string
    {
        return self::LISTADOS[self::existe($clave) ? $clave : self::EMBARAZOS];
    }

    /**
     * El **submenú del menú lateral** para «Listados» (el grupo plegable): un hijo por reporte de la especialidad, con el activo
     * marcado.
     *
     * El listado no está en la URL de la ruta sino en la query (`?listado=…`), así que el activo se decide por la
     * ruta **y** por la clave pedida: en `/clinica/listados` sin `listado` está activo el de por defecto, que es el
     * que la pantalla muestra. `$rutaActual` se recibe por el mismo criterio que `CatalogosDisponibles::submenu()`.
     *
     * @param  string|null  $rutaActual  el nombre de la ruta de la petición (`clinica.listados`…)
     * @param  string|null  $listadoActual  el `?listado=` de la petición (se normaliza como la pantalla)
     * @param  string|null  $especialidad  el `slug` de la especialidad del contexto
     * @return array<int,array{texto:string, icono:string, url:string, activo:bool}>
     */
    public static function submenu(?string $rutaActual, ?string $listadoActual, ?string $especialidad = null): array
    {
        $enLosListados = $rutaActual !== null
            && ($rutaActual === self::RUTA || str_starts_with($rutaActual, self::RUTA . '.'));
        $actual = self::normalizar($listadoActual, $especialidad);
        $items = [];

        foreach (self::ofrecidos($especialidad) as $clave => $titulo) {
            $items[] = [
                'texto' => $titulo,
                'icono' => self::ICONOS[$clave] ?? 'bi-clipboard2-data',
                'url' => route(self::RUTA, ['listado' => $clave]),
                'activo' => $enLosListados && $clave === $actual,
            ];
        }

        return $items;
    }

    /**
     * Los listados que ofrece la especialidad, con su título, en el orden de `LISTADOS`.
     *
     * Sin especialidad (`null` o vacío) sólo quedan los que **no** declaran una: el de embarazos y partos es de
     * ginecología y no se ofrece (2026-10-30, antes salía todo).
     *
     * @return array<string,string>
     */
    private static function ofrecidos(?string $especialidad): array
    {
        $clave = mb_strtolower(trim((string) $especialidad));

        return array_filter(
            self::LISTADOS,
            fn (string $titulo, string $listado) => ! isset(self::ESPECIALIDAD[$listado])
                || ($clave !== '' && str_contains($clave, self::ESPECIALIDAD[$listado])),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
