<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class ListUsers extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Propiedades del formulario
    public $user_id;
    public $name;
    public $email;
    public $password;
    public $role;

    // Control de modales y filtros
    public $search = '';
    public $isEditMode = false;
    public $userToDeleteId = null;

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . $this->user_id,
            'password' => $this->isEditMode ? 'nullable|min:6' : 'required|min:6',
            'role' => 'required|exists:roles,name',
        ];
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $users = User::with(['roles', 'permissions'])
            ->where('name', 'like', '%' . $this->search . '%')
            ->orWhere('email', 'like', '%' . $this->search . '%')
            ->orderBy('id', 'desc')
            ->paginate(8);

        $roles = Role::all();

        return view('livewire.admin.list-users', [
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    public function resetFields()
    {
        $this->user_id = null;
        $this->name = '';
        $this->email = '';
        $this->password = '';
        $this->role = '';
        $this->isEditMode = false;
        $this->userToDeleteId = null;
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatchBrowserEvent('open-user-modal');
    }

    public function store()
    {
        $this->validate();

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        $user->assignRole($this->role);

        $this->dispatchBrowserEvent('close-user-modal');
        session()->flash('message', 'Usuario creado correctamente.');
        $this->resetFields();
    }

    public function edit($id)
    {
        $this->resetFields();
        $this->isEditMode = true;

        $user = User::findOrFail($id);
        $this->user_id = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->roles->pluck('name')->first() ?? '';

        $this->dispatchBrowserEvent('open-user-modal');
    }

    public function update()
    {
        $this->validate();

        $user = User::findOrFail($this->user_id);
        $data = [
            'name' => $this->name,
            'email' => $this->email,
        ];

        if (!empty($this->password)) {
            $data['password'] = Hash::make($this->password);
        }

        $user->update($data);
        $user->syncRoles([$this->role]);

        $this->dispatchBrowserEvent('close-user-modal');
        session()->flash('message', 'Usuario actualizado correctamente.');
        $this->resetFields();
    }

    public function confirmDelete($id)
    {
        $this->userToDeleteId = $id;
        $this->dispatchBrowserEvent('open-delete-modal');
    }

    public function delete()
    {
        if ($this->userToDeleteId) {
            $user = User::findOrFail($this->userToDeleteId);
            
            // Evitar que el usuario logueado o el root principal se elimine
            if ($user->id === auth()->id()) {
                session()->flash('error', 'No puedes eliminar tu propio usuario.');
            } else {
                $user->delete();
                session()->flash('message', 'Usuario eliminado correctamente.');
            }
        }

        $this->dispatchBrowserEvent('close-delete-modal');
        $this->resetFields();
    }
}