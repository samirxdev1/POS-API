<?php

namespace Api\Controllers;

use Api\Core\Request;
use Api\Core\Response;
use App\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends BaseController
{
    public function index(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $query = User::where('business_id', $businessId)->with('roles:id,name');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                  ->orWhere('last_name', 'LIKE', "%{$search}%")
                  ->orWhere('username', 'LIKE', "%{$search}%")
                  ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $result = $this->paginate($query, $request);
        return Response::success($result['data'], 'Users retrieved successfully', $result['meta']);
    }

    public function show(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $user = User::where('business_id', $businessId)->where('id', $id)->with('roles')->first();
        if (!$user) {
            return Response::notFound("User with ID {$id} not found");
        }

        return Response::success($user, 'User details retrieved successfully');
    }

    public function store(Request $request): Response
    {
        $businessId = $request->getBusinessId();

        $errors = $this->validate($request, [
            'first_name' => 'required|string|max:191',
            'last_name' => 'nullable|string|max:191',
            'email' => 'required|email|max:191',
            'username' => 'required|string|max:191',
            'password' => 'required|string|min:6',
            'role_id' => 'nullable|integer',
        ]);
        if ($errors) {
            return Response::validationError($errors);
        }

        // Check unique username / email
        $exists = User::where('business_id', $businessId)
            ->where(function ($q) use ($request) {
                $q->where('username', $request->input('username'))
                  ->orWhere('email', $request->input('email'));
            })->exists();

        if ($exists) {
            return Response::validationError(['username_or_email' => 'Username or email already exists in this business.']);
        }

        $user = User::create([
            'business_id' => $businessId,
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => $request->input('email'),
            'username' => $request->input('username'),
            'password' => Hash::make($request->input('password')),
            'status' => 'active',
            'user_type' => 'user',
        ]);

        if ($roleId = $request->input('role_id')) {
            $role = Role::where('business_id', $businessId)->find($roleId);
            if ($role) {
                $user->assignRole($role->name);
            }
        }

        $user->load('roles');
        return Response::created($user, 'User created successfully');
    }

    public function update(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $id = (int)$request->param('id');

        $user = User::where('business_id', $businessId)->where('id', $id)->first();
        if (!$user) {
            return Response::notFound("User with ID {$id} not found");
        }

        $fields = ['first_name', 'last_name', 'email', 'status'];
        foreach ($fields as $f) {
            if ($request->input($f) !== null) {
                $user->{$f} = $request->input($f);
            }
        }

        if ($password = $request->input('password')) {
            $user->password = Hash::make($password);
        }

        $user->save();

        if ($roleId = $request->input('role_id')) {
            $role = Role::where('business_id', $businessId)->find($roleId);
            if ($role) {
                $user->syncRoles([$role->name]);
            }
        }

        $user->load('roles');
        return Response::success($user, 'User updated successfully');
    }

    public function roles(Request $request): Response
    {
        $businessId = $request->getBusinessId();
        $roles = Role::where('business_id', $businessId)->with('permissions:id,name')->get();

        return Response::success($roles, 'Roles retrieved successfully');
    }
}

