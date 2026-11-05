<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * **El catálogo de seguros / empresas del consultorio** (`seg_emp`).
 *
 * La tabla del legado se llama `seg_emp` (singular, como la creó
 * `2026_09_27_110000_create_tablas_legado_faltantes`) y su clave es el `id` que puso el API: los códigos
 * (`codesegemp`) **no son únicos** entre consultorios, el mismo `02` es otra empresa en cada uno. El ancho de las
 * columnas es el del DataWindow del escritorio (`d_a_seguros.srd`: código 3, nombre 150, RIF 50, dirección 350,
 * teléfono 50).
 *
 * ⚠️ Hasta el catálogo de seguros del médico (O-62) este modelo apuntaba a `seg_emps`, que no existe, y por eso
 * la ficha del paciente leía el catálogo con el query builder (`App\Clinica\Pacientes\CatalogoDeSeguros`). Los
 * dos caminos son válidos; el ABM del catálogo usa el modelo porque la baja compartida
 * (`App\Actions\Catalogos\QuitarDelCatalogo`) trabaja sobre filas Eloquent.
 *
 * La relación `pacientes()` **no** está acotada por `reg_medico` (el catálogo es por consultorio): para resolver
 * el nombre de un seguro se usa `CatalogoDeSeguros`, que sí lo acota.
 */
class SegEmp extends Model
{
    use HasFactory;

    protected $table = 'seg_emp';

    protected $fillable = [
        'reg_medico',
        'codesegemp',
        'nombre',
        'rif',
        'direccion',
        'telef',
    ];

    public function pacientes(): HasMany
    {
        return $this->hasMany(Paciente::class, 'codesegemp', 'codesegemp');
    }
}
