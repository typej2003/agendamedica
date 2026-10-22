<?php

namespace App\Actions\Ciudades;

use App\Clinica\Ciudades\BuscadorDeCiudades;
use App\Models\City;
use App\Models\Estado;
use Illuminate\Validation\ValidationException;

/**
 * Crea una **ciudad** del catálogo (`cities`) para el selector con búsqueda. Sólo la usa un administrador
 * (`POST /admin/ciudades`); el médico y la secretaría eligen de la lista.
 *
 * `cities` exige un estado (`state_id`; el país sale del estado), así que el alta lo pide. Una ciudad es única **por
 * estado**, sin importar tildes ni mayúsculas (maracaibo = Maracaibo): si ya existe se devuelve esa y no se crea
 * nada. El nombre se limpia (espacios, y se capitaliza si vino todo en minúsculas o todo en mayúsculas).
 */
class CrearCiudad
{
    private const MINUSCULAS = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e'];

    /**
     * @return array{0: City, 1: bool}  la ciudad y si se creó ahora (`false` = ya existía)
     */
    public function ejecutar(string $nombre, int $estadoId): array
    {
        $nombre = $this->limpiar($nombre);

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100 || ! preg_match('/^\p{L}[\p{L}\p{M}\s.\-\x27\x{2019}()]*$/u', $nombre)) {
            throw ValidationException::withMessages([
                'nombre' => 'Escribe el nombre de la ciudad (de 2 a 100 letras, sin números).',
            ]);
        }

        $estado = Estado::find($estadoId);
        if (! $estado) {
            throw ValidationException::withMessages(['state_id' => 'Elige el estado de la ciudad.']);
        }

        $clave = BuscadorDeCiudades::normalizar($nombre);
        $existente = City::where('state_id', $estado->id)->get()
            ->first(fn (City $ciudad) => BuscadorDeCiudades::normalizar($ciudad->name) === $clave);

        if ($existente) {
            return [$existente, false];
        }

        return [City::create(['state_id' => $estado->id, 'name' => $nombre, 'capital' => false]), true];
    }

    private function limpiar(string $nombre): string
    {
        $nombre = trim((string) preg_replace('/\s+/u', ' ', $nombre));

        if ($nombre === mb_strtolower($nombre) || $nombre === mb_strtoupper($nombre)) {
            $palabras = explode(' ', mb_strtolower($nombre));
            $nombre = implode(' ', array_map(
                fn ($palabra, $i) => $i > 0 && in_array($palabra, self::MINUSCULAS, true) ? $palabra : mb_convert_case($palabra, MB_CASE_TITLE),
                $palabras,
                array_keys($palabras)
            ));
        }

        return $nombre;
    }
}
