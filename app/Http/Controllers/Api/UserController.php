<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Menampilkan daftar pegawai.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // ==============================
        // SEARCH
        // ==============================

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // ==============================
        // FILTER ROLE
        // ==============================

        if ($request->filled('role')) {
            $query->where(
                'role',
                $request->role
            );
        }

        // ==============================
        // FILTER STATUS
        // ==============================

        if ($request->filled('is_active')) {
            $query->where(
                'is_active',
                $request->is_active
            );
        }

        // ==============================
        // PAGINATION
        // ==============================

        $users = $query
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'message' => 'Data pegawai berhasil diambil',
            'data' => $users,
        ]);
    }

    /**
     * Menampilkan detail pegawai.
     */
    public function show(User $user)
    {
        return response()->json([
            'success' => true,
            'message' => 'Detail pegawai berhasil diambil',
            'data' => $user,
        ]);
    }

    /**
     * Menambahkan pegawai baru.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
            ],

            'role' => [
                'required',
                Rule::in([
                    'admin',
                    'kasir',
                ]),
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $user = User::create([
            'name' => $validated['name'],

            'email' => $validated['email'],

            'password' => Hash::make(
                $validated['password']
            ),

            'role' => $validated['role'],

            'is_active' =>
                $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pegawai berhasil ditambahkan',
            'data' => $user,
        ], 201);
    }

    /**
     * Mengubah data pegawai.
     */
    public function update(
        Request $request,
        User $user
    ) {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(
                    'users',
                    'email'
                )->ignore($user->id),
            ],

            'password' => [
                'nullable',
                'string',
                'min:6',
            ],

            'role' => [
                'required',
                Rule::in([
                    'admin',
                    'kasir',
                ]),
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ]);

        $user->name =
            $validated['name'];

        $user->email =
            $validated['email'];

        $user->role =
            $validated['role'];

        $user->is_active =
            $validated['is_active'];

        // Password hanya diubah
        // jika dikirim.
        if (
            !empty(
                $validated['password']
            )
        ) {
            $user->password =
                Hash::make(
                    $validated['password']
                );
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Data pegawai berhasil diperbarui',
            'data' => $user,
        ]);
    }

    /**
     * Menonaktifkan pegawai.
     */
    public function destroy(User $user)
    {
        // Jangan hapus user secara permanen.
        // Cukup nonaktifkan akun.

        $user->update([
            'is_active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pegawai berhasil dinonaktifkan',
            'data' => $user,
        ]);
    }
}