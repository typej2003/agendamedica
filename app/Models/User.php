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
        'tipo',
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

    public const TIPO_ADMINISTRADOR = 'administrador';
    public const TIPO_MEDICO = 'medico';
    public const TIPO_PACIENTE = 'paciente';

    /** Roles Spatie que dan acceso al dashboard de administración. */
    public const ROLES_ADMIN = ['Root', 'Administrador'];

    /**
     * Ficha de médico de esta cuenta (vínculo `medicos.user_id`). Un solo lugar para resolverla: antes cada
     * controlador hacía `user_id = ? OR email = ?`, y el fallback por correo permitía suplantar una ficha.
     */
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

    /** Administrador = rol Root o Administrador (no es un `tipo`: un médico puede serlo también). */
    public function esAdministrador(): bool
    {
        return $this->hasAnyRole(self::ROLES_ADMIN);
    }

    /**
     * Comprueba si el usuario tiene una sesión activa en los últimos 5 minutos.
     */
    public function isOnline(): bool
    {
        return Cache::has('user-is-online-' . $this->id);
    }
}