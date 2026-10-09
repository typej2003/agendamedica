<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'reg_medico',
        'must_change_password',
        'is_active',
        'blocked_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'must_change_password' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Roles Spatie que dan acceso al dashboard de administración. */
    public const ROLES_ADMIN = ['Root', 'Administrador'];

    /** Ficha de médico de esta cuenta (vínculo `medicos.user_id`). */
    public function medico(): HasOne
    {
        return $this->hasOne(Medico::class, 'user_id');
    }

    /**
     * Usuarios con rol Root o Administrador. No usa el scope `role()` de Spatie a propósito: ese lanza
     * `RoleDoesNotExist` si algún rol de la lista todavía no existe (por ejemplo `Administrador` en un
     * servidor donde aún no se corrió el seeder).
     */
    public function scopeAdministradores($query)
    {
        return $query->whereHas('roles', fn ($roles) => $roles->whereIn('name', self::ROLES_ADMIN));
    }

    /** Administrador = rol Root o Administrador. Un médico puede serlo también (una cuenta, varios roles). */
    public function esAdministrador(): bool
    {
        return $this->hasAnyRole(self::ROLES_ADMIN);
    }

    /**
     * Nombre de la ruta donde este usuario trabaja, y por lo tanto a dónde entra al iniciar sesión.
     *
     * El panel (`/dashboard`) quedó para administración; el médico y la secretaría trabajan en el
     * consultorio (`/clinica`), que es el mismo flujo con o sin clave temporal (lo usan el login y el
     * cambio de contraseña, para no repetir la regla en cada uno).
     */
    public function rutaDeInicio(): string
    {
        if (! $this->esAdministrador() && $this->hasAnyRole(['Medico', 'Secretaria'])) {
            return 'clinica.inicio';
        }

        // TODO (/consultorio): cuando exista el área del paciente, acá va su ruta.
        return 'dashboard';
    }

    /**
     * Comprueba si el usuario tiene una sesión activa en los últimos 5 minutos.
     */
    public function isOnline(): bool
    {
        return Cache::has('user-is-online-' . $this->id);
    }
}