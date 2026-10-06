<?php

namespace App\Models;

use App\Events\MedicoRegistrado;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Medico extends Model
{
    use HasFactory;

    protected $table = 'medicos';

    protected $fillable = [
        'user_id', // Relación con la tabla de usuarios modelo User, cuando ya tiene un registro en User
        'name',
        'lastname',
        'prefix', // tratamiento libre y opcional ("Dr.", "Dra.", "Ing."...), 20 caracteres como máximo
        'license_number',
        'phone',
        'email',
        'password',
        'biography',
        'photo_path',
        'office_id',
        'consultation_fee',
        'is_active',
        'reg_medico', // temporal, tiende a cambiar no usar
    ];

    /** Al crearse un médico (por cualquier camino) recibe su mes de prueba: ver App\Listeners\OtorgarServicioDePrueba. */
    protected $dispatchesEvents = [
        'created' => MedicoRegistrado::class,
    ];

    /**
     * Deja el prefijo listo para guardar: sin espacios, `null` si quedó vacío y con punto final ("Dr" -> "Dr.").
     * Va como mutador para que valga igual en el panel de Usuarios, en el listado viejo y en cualquier alta.
     */
    public static function normalizarPrefijo($valor): ?string
    {
        $prefijo = trim((string) $valor);
        if ($prefijo === '') {
            return null;
        }

        return substr($prefijo, -1) === '.' ? $prefijo : $prefijo . '.';
    }

    public function setPrefixAttribute($valor): void
    {
        $this->attributes['prefix'] = self::normalizarPrefijo($valor);
    }

    public function registro(): HasOne
    {
        return $this->hasOne(MedicoRegistro::class, 'medico_id', 'id');
    }

    /**
     * Registro médico bajo el que se guardan las filas "una por médico" (`evolucion`,
     * `recipe_formatos`). Se prefiere la relación `registro()` sobre la columna `reg_medico`
     * porque esa está marcada como temporal ("tiende a cambiar, no usar").
     */
    public function regMedicoPrincipal(): ?string
    {
        return $this->registro?->reg_medico ?? $this->reg_medico;
    }

    /** Cuenta de acceso del médico (`medicos.user_id`); null si todavía no tiene. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'office_id');
    }

    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'medico_specialty', 'medico_id', 'specialty_id');
    }

    public function medicalCenters(): BelongsToMany
    {
        return $this->belongsToMany(MedicalCenter::class, 'medico_medical_center', 'medico_id', 'medical_center_id')
                    ->using(MedicoMedicalCenter::class)
                    ->withPivot('reg_medico')
                    ->withTimestamps();
    }

    public function pacientes(): BelongsToMany
    {
        return $this->belongsToMany(Paciente::class, 'medico_pacientes', 'medico_id', 'paciente_id')
                    ->using(MedicoPaciente::class)
                    ->withPivot('numhistoria') // 'medico_id' y 'paciente_id' se incluyen automáticamente
                    ->withTimestamps();
    }
}