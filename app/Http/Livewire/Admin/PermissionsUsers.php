<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class PermissionsUsers extends Component
{
    public $users;
    public $permissions;
    public $selectedUserId = '';
    public $userPermissions = []; // Almacenará los permisos del usuario seleccionado

    /**
     * Se ejecuta una vez, cuando el componente se monta.
     * Carga los datos iniciales.
     */
    public function mount()
    {
        $this->users = User::orderBy('name')->get();
        $this->permissions = Permission::orderBy('name')->get();
    }

    /**
     * Hook de Livewire que se ejecuta automáticamente
     * cuando la propiedad $selectedUserId cambia.
     */
    public function updatedSelectedUserId($userId)
    {
        if ($userId) {
            $user = User::findOrFail($userId);
            // Obtenemos los NOMBRES de los permisos directos que tiene el usuario
            $this->userPermissions = $user->getDirectPermissions()->pluck('name')->toArray();
        } else {
            // Si no hay usuario seleccionado, reseteamos la lista
            $this->userPermissions = [];
        }
    }

    /**
     * Sincroniza los permisos para el usuario seleccionado.
     */
    public function syncPermissions()
    {
        $this->validate([
            'selectedUserId' => 'required|exists:users,id',
        ], [
            'selectedUserId.required' => 'Por favor, selecciona un usuario.',
        ]);

        $user = User::find($this->selectedUserId);

        // syncPermissions asigna solo los permisos directos al usuario
        $user->syncPermissions($this->userPermissions);

        session()->flash('message', 'Permisos actualizados correctamente para ' . $user->name);
    }

    public function render()
    {
        return view('livewire.admin.permissions-users');
    }
}
