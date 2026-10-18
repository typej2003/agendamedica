<?php

namespace App\Clinica\Historia\Vistas;

use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use App\Clinica\Crecimiento\Vistas\CrecimientoVista;
use App\Clinica\Pacientes\Vistas\FamiliaDePacienteVista;
use App\Clinica\Vacunas\Vistas\CarnetDeVacunacionVista;

/**
 * **La historia pediátrica imprimible** (WEB-12.5): la hoja A4 con el membrete del médico que reúne lo que define a un niño —sus datos y los de su **familia**,
 * sus **antecedentes** por categoría (Patológicos, C.A., Madre…), sus **mediciones** de crecimiento y las **vacunas aplicadas**—. Está inspirada en
 * `d_historia_clinica_dos` del escritorio de pediatría (motivo, antecedentes, enfermedad actual, inmunizaciones, examen físico, exámenes, diagnóstico y tratamiento
 * **de una consulta**); ésta es la del niño, y el resumen de cada consulta sigue siendo el *Resumen médico* (que ahora lleva mediciones y cuestionario).
 *
 * No consulta nada: compone los ViewModels que ya arman cada bloque (la familia de la ficha, los antecedentes de la consulta, el crecimiento y el carnet).
 */
final class HistoriaPediatricaVista
{
    public function __construct(
        private CarnetDeVacunacionVista $carnet,
        private FamiliaDePacienteVista $familia,
        private SeccionDeAntecedentesVista $antecedentes,
        private CrecimientoVista $crecimiento,
        private string $urlVolver,
        private bool $incrustado = false,
    ) {
    }

    public function titulo(): string
    {
        return 'Historia pediátrica';
    }

    public function nombre(): string
    {
        return $this->carnet->nombre();
    }

    public function resumen(): string
    {
        return $this->nombre();
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function incrustado(): bool
    {
        return $this->incrustado;
    }

    public function carnet(): CarnetDeVacunacionVista
    {
        return $this->carnet;
    }

    public function membrete()
    {
        return $this->carnet->membrete();
    }

    /** @return array<int,array{rotulo:string, valor:string}> los datos del niño y su fecha */
    public function datos(): array
    {
        $datos = $this->carnet->datos();

        // La cabecera del carnet trae a los padres; acá van en su propio bloque.
        return array_values(array_filter($datos, fn ($d) => $d['rotulo'] !== 'Padres'));
    }

    public function alergia(): ?string
    {
        return $this->familia->alergia();
    }

    /** @return array<int,array{rotulo:string, valor:string}> la familia: padres, ocupaciones, teléfonos, representante y dirección (sin los vacíos) */
    public function familia(): array
    {
        $filas = [];
        $madre = $this->familia->madre();
        $padre = $this->familia->padre();

        foreach ([['Madre', $madre], ['Padre', $padre]] as [$rotulo, $adulto]) {
            $texto = collect([$adulto['nombre'], $adulto['edad'] !== null ? $adulto['edad'] . ' años' : null, $adulto['ocupacion']])->filter()->implode(' · ');

            if ($texto !== '') {
                $filas[] = ['rotulo' => $rotulo, 'valor' => $texto];
            }
        }

        foreach ($this->familia->telefonos() as $telefono) {
            $filas[] = ['rotulo' => $telefono['rotulo'], 'valor' => $telefono['numero']];
        }

        foreach ([
            'Cédula del representante' => $this->familia->cedulaDelRepresentante(),
            'Dirección' => collect([$this->familia->direccion(), $this->familia->localidad()])->filter()->implode(', ') ?: null,
            'Correo' => $this->familia->correo(),
            'Dinámica familiar' => $this->familia->dinamica(),
        ] as $rotulo => $valor) {
            if ($valor !== null) {
                $filas[] = ['rotulo' => $rotulo, 'valor' => $valor];
            }
        }

        $hermanos = array_map(fn ($h) => $h['nombre'] . ($h['edad'] ? ' (' . $h['edad'] . ')' : ''), $this->familia->hermanos());
        if ($hermanos !== []) {
            $filas[] = ['rotulo' => 'Hermanos', 'valor' => implode(' · ', $hermanos)];
        }

        return $filas;
    }

    /** @return array<int,array{titulo:string, filas:array<int,array<string,mixed>>}> los antecedentes por categoría */
    public function antecedentes(): array
    {
        return $this->antecedentes->grupos();
    }

    /** @return array<int,string> */
    public function columnasDeMediciones(): array
    {
        return $this->crecimiento->columnas();
    }

    /** @return array<int,array{fecha:string, edad:string, celdas:array<int,array{valor:string, posicion:?string, fuera:bool}>}> */
    public function mediciones(): array
    {
        return $this->crecimiento->filas();
    }

    /** @return array<int,array<string,string>> */
    public function vacunasAplicadas(): array
    {
        return $this->carnet->aplicadas();
    }

    /** @return array<int,array<string,mixed>> */
    public function vacunasPorAplicar(): array
    {
        return $this->carnet->pendientes();
    }
}
