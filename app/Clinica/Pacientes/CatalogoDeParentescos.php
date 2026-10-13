<?php

namespace App\Clinica\Pacientes;

use App\Models\Parentesco;
use Illuminate\Support\Collection;

/**
 * El **catálogo de parentescos** del representante del paciente (WEB-3.4): lo que llena el `<select>` de
 * "Parentesco" y lo que traduce el `codeparentesco` guardado a un nombre para mostrar.
 *
 * **Es de AppDDR, no del legado** (ver `ParentescoSeeder`): `representante.codeparentesco` es `char(1)` y
 * en las fuentes del PowerBuilder la columna sólo aparece declarada —ningún DataWindow la lista, ninguna
 * ventana la escribe—, así que **los códigos del legado son desconocidos** y los de acá son una decisión
 * de producto. Por eso el catálogo **no se llama "el catálogo del legado" en ningún lado** y por eso un
 * código que no esté en él se muestra **tal cual**, igual que hace la ficha con un `codesegemp` que no
 * está en `seg_emp`: la web no inventa el nombre de un dato que no entiende.
 *
 * No lleva `reg_medico`: es un catálogo único, no del consultorio. Se lee entero una vez por pantalla.
 */
final class CatalogoDeParentescos
{
    /** @return Collection<int,Parentesco> ordenados como se muestran en el `<select>` */
    public function todos(): Collection
    {
        return Parentesco::orderBy('orden')->orderBy('nombre')->get(['codigo', 'nombre']);
    }

    /**
     * El nombre del parentesco de un código, o `null` si no está en el catálogo.
     *
     * @param  Collection<int,Parentesco>  $catalogo  el catálogo ya leído (`todos()`)
     */
    public function nombreDe(Collection $catalogo, ?string $codigo): ?string
    {
        $codigo = trim((string) $codigo);

        if ($codigo === '') {
            return null;
        }

        $nombre = $catalogo->firstWhere('codigo', $codigo)?->nombre;

        return $nombre !== null ? trim((string) $nombre) : null;
    }

    /**
     * Los códigos válidos del catálogo, para la validación del formulario: un código inventado no se
     * guarda. Se leen de la tabla —y no de la constante del seeder— porque es la tabla la que manda si
     * alguien agregó un parentesco a mano.
     *
     * @return array<int,string>
     */
    public function codigos(): array
    {
        return $this->todos()->pluck('codigo')->all();
    }
}
