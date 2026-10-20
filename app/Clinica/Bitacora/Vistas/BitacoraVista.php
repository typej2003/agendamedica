<?php

namespace App\Clinica\Bitacora\Vistas;

use App\Clinica\Bitacora\DiccionarioDeLaBitacora;
use App\Clinica\Bitacora\FiltroDeBitacora;
use App\Clinica\FechaDelConsultorio;
use Carbon\Carbon;

/**
 * La pantalla **Bitácora** (`/clinica/bitacora`, WEB-8.5): qué cambió en el consultorio y quién lo hizo, en lenguaje claro y de
 * sólo lectura. Como todo ViewModel de la web clínica no consulta la base: recibe la página ya leída por `CambiosDelConsultorio`,
 * los nombres de los usuarios y las URLs que resolvió el controlador.
 *
 * Cada línea es **una acción** («Hoy 10:32 · María (secretaría) · Editó una cita»), con las columnas tocadas por su nombre
 * legible. **No muestra contenido clínico**: del valor de una columna sólo aparece lo que el diccionario declara visible (el monto
 * y la hora de una cita) y nada del texto de una consulta, un récipe o un diagnóstico.
 */
final class BitacoraVista
{
    /** Más columnas que esto en una línea se resumen («y 3 más»). */
    private const MAX_COLUMNAS = 8;

    /**
     * @param  array{grupos: array<int,array<string,mixed>>, hay_mas: bool, hay_anterior: bool, sin_pacientes: bool, demasiados_pacientes: bool}  $pagina
     * @param  array<int,array{nombre:string, rol:string}>  $usuarios  los de las líneas de la página, por id
     * @param  array<int,array{id:int, nombre:string, rol:string}>  $opcionesDeUsuario  para el filtro
     * @param  array<string,mixed>  $urls  `base` y, como funciones de id, `paciente` y `consulta`
     */
    public function __construct(
        private array $pagina,
        private array $usuarios,
        private array $opcionesDeUsuario,
        private FiltroDeBitacora $filtro,
        private array $urls,
    ) {
    }

    // -------------------------------------------------------------------------------------------- Encabezado

    public function subtitulo(): string
    {
        return 'Qué cambió en tu consultorio y quién lo hizo. Es de sólo lectura y no muestra el contenido de las consultas.';
    }

    public function urlBase(): string
    {
        return $this->urls['base'];
    }

    public function hayFiltro(): bool
    {
        return $this->filtro->hayFiltro();
    }

    // ------------------------------------------------------------------------------------------------ Filtros

    public function desde(): string
    {
        return (string) $this->filtro->desde;
    }

    public function hasta(): string
    {
        return (string) $this->filtro->hasta;
    }

    public function paciente(): string
    {
        return $this->filtro->paciente;
    }

    public function usuarioElegido(): string
    {
        return $this->filtro->usuario;
    }

    public function tipoElegido(): string
    {
        return $this->filtro->tipo;
    }

    /** @return array<int,array{valor:string, texto:string}> */
    public function opcionesDeUsuario(): array
    {
        $opciones = [
            ['valor' => '', 'texto' => 'Todos'],
            ['valor' => FiltroDeBitacora::SIN_USUARIO, 'texto' => 'Sin usuario (móvil, sistema anterior o previo a la bitácora)'],
        ];

        foreach ($this->opcionesDeUsuario as $usuario) {
            $opciones[] = ['valor' => (string) $usuario['id'], 'texto' => $usuario['nombre'] . ' (' . $usuario['rol'] . ')'];
        }

        return $opciones;
    }

    /** @return array<string,array<string,string>> `familia => [clave => etiqueta]` */
    public function opcionesDeTipo(): array
    {
        return DiccionarioDeLaBitacora::opcionesDeTipo();
    }

    public function ayudaDelPaciente(): string
    {
        return 'Por nombre, cédula o historia. Sólo se relacionan con un paciente las citas, consultas, récipes, documentos y datos del paciente: '
            . 'los catálogos y la configuración no entran en esta búsqueda, ni lo que ya se eliminó.';
    }

    // ------------------------------------------------------------------------------------------ Estados

    public function estaVacio(): bool
    {
        return $this->pagina['grupos'] === [];
    }

    public function avisoDeLimite(): ?string
    {
        return $this->pagina['demasiados_pacientes']
            ? 'Hay muchos pacientes con «' . $this->filtro->paciente . '»: se muestran los cambios de los primeros ' . \App\Clinica\Bitacora\CambiosDelConsultorio::MAX_PACIENTES . '. Escribe el nombre completo o la cédula para afinar.'
            : null;
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->pagina['sin_pacientes']) {
            return [
                'icono' => 'bi-person-x',
                'titulo' => 'No encontramos pacientes con «' . $this->filtro->paciente . '»',
                'texto' => 'Prueba con el apellido, la cédula o el número de historia. No importan las tildes ni las mayúsculas.',
            ];
        }

        if ($this->filtro->hayFiltro()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún cambio coincide con tu búsqueda',
                'texto' => 'Prueba con otro rango de fechas, otro usuario u otro tipo de dato.',
            ];
        }

        return [
            'icono' => 'bi-clock-history',
            'titulo' => 'Todavía no hay cambios registrados',
            'texto' => 'Aquí aparecerá lo que se agende, edite o elimine en tu consultorio, con el nombre de quien lo hizo.',
        ];
    }

    // ------------------------------------------------------------------------------------------------ Líneas

    /** @return array<int,array<string,mixed>> */
    public function lineas(): array
    {
        return array_map(fn (array $grupo) => $this->linea($grupo), $this->pagina['grupos']);
    }

    /** @param  array<string,mixed>  $grupo */
    private function linea(array $grupo): array
    {
        $momento = Carbon::parse($grupo['ultimo'], (string) config('app.timezone', 'UTC'))->setTimezone(FechaDelConsultorio::zona());
        $paciente = $grupo['paciente'];

        return [
            'cuando' => $this->cuando($momento),
            'cuandoCompleto' => $momento->format('d/m/Y H:i:s'),
            'quien' => $this->quien($grupo),
            'accion' => $this->accion($grupo),
            'detalle' => $this->detalle($grupo),
            'paciente' => $paciente['nombre'] ?? null,
            'urlPaciente' => $paciente ? ($this->urls['paciente'])((int) $paciente['id']) : null,
            'urlConsulta' => $grupo['consulta'] ? ($this->urls['consulta'])((int) $grupo['consulta']) : null,
            'tipo' => ucfirst(DiccionarioDeLaBitacora::etiquetaDeTabla($grupo['tabla'])),
        ];
    }

    private function cuando(Carbon $momento): string
    {
        $hoy = FechaDelConsultorio::ahora()->startOfDay();
        $dia = $momento->copy()->startOfDay();
        $hora = $momento->format('H:i');

        if ($dia->equalTo($hoy)) {
            return 'Hoy ' . $hora;
        }

        if ($dia->equalTo($hoy->copy()->subDay())) {
            return 'Ayer ' . $hora;
        }

        return $momento->format('d/m/Y') . ' ' . $hora;
    }

    /** @return array{nombre:string, rol:?string, sinUsuario:bool} */
    private function quien(array $grupo): array
    {
        if ($grupo['user_id'] !== null) {
            $usuario = $this->usuarios[$grupo['user_id']] ?? null;

            return $usuario
                ? ['nombre' => $usuario['nombre'], 'rol' => $usuario['rol'], 'sinUsuario' => false]
                : ['nombre' => 'Usuario eliminado', 'rol' => null, 'sinUsuario' => false];
        }

        return match ($grupo['origen']) {
            'mobile' => ['nombre' => 'App del móvil', 'rol' => null, 'sinUsuario' => true],
            'escritorio' => ['nombre' => 'Sistema anterior', 'rol' => null, 'sinUsuario' => true],
            default => ['nombre' => 'Sin usuario', 'rol' => 'anterior a la bitácora', 'sinUsuario' => true],
        };
    }

    /** «Editó una cita», «Creó un récipe», «Eliminó un diagnóstico»: el verbo lo dicta la operación más fuerte de la acción. */
    private function accion(array $grupo): string
    {
        $operaciones = $grupo['operaciones'];

        if (in_array('reorder', $operaciones, true) && ! in_array('deleted', $operaciones, true) && ! in_array('created', $operaciones, true)) {
            return 'Reordenó la cola de citas';
        }

        $verbo = match (true) {
            in_array('deleted', $operaciones, true) => 'Eliminó',
            in_array('created', $operaciones, true) => 'Creó',
            default => 'Editó',
        };

        return $verbo . ' ' . DiccionarioDeLaBitacora::conArticulo($grupo['tabla']);
    }

    /** «Cambió: monto 25,00 y hora»: los nombres de las columnas tocadas y, de las visibles, su valor. */
    private function detalle(array $grupo): ?string
    {
        if ($grupo['columnas'] === []) {
            return null;
        }

        $partes = [];
        foreach ($grupo['columnas'] as $columna => $valor) {
            $texto = DiccionarioDeLaBitacora::etiquetaDeColumna($grupo['tabla'], (string) $columna);
            $visible = DiccionarioDeLaBitacora::valorVisible($grupo['tabla'], (string) $columna, $valor === null ? null : (string) $valor);
            $partes[] = $visible !== null ? $texto . ': ' . $visible : $texto;
        }

        $sobran = max(0, count($partes) - self::MAX_COLUMNAS);
        $partes = array_slice($partes, 0, self::MAX_COLUMNAS);
        $lista = implode(', ', $partes) . ($sobran > 0 ? ' y ' . $sobran . ' más' : '');
        $prefijo = in_array('created', $grupo['operaciones'], true) ? 'Datos: ' : 'Cambió: ';

        return $prefijo . $lista;
    }

    // ----------------------------------------------------------------------------------------------- Páginas

    public function hayMas(): bool
    {
        return $this->pagina['hay_mas'];
    }

    public function hayAnterior(): bool
    {
        return $this->pagina['hay_anterior'];
    }

    public function urlMasAntiguas(): ?string
    {
        return $this->pagina['hay_mas'] ? $this->url($this->filtro->pagina + 1) : null;
    }

    public function urlMasRecientes(): ?string
    {
        return $this->pagina['hay_anterior'] ? $this->url($this->filtro->pagina - 1) : null;
    }

    /** «Mostrando 1–50»: sin total, porque contarlo recorrería toda la tabla. */
    public function rango(): string
    {
        $cantidad = count($this->pagina['grupos']);

        if ($cantidad === 0) {
            return '';
        }

        $primero = ($this->filtro->pagina - 1) * \App\Clinica\Bitacora\CambiosDelConsultorio::POR_PAGINA + 1;

        return 'Mostrando ' . $primero . '–' . ($primero + $cantidad - 1) . ($this->pagina['hay_mas'] ? ' (hay más)' : '');
    }

    private function url(int $pagina): string
    {
        $parametros = $this->filtro->parametros() + ($pagina > 1 ? ['page' => (string) $pagina] : []);

        return $parametros === [] ? $this->urls['base'] : $this->urls['base'] . '?' . http_build_query($parametros);
    }
}
