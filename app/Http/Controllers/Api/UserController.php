<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->string('search')->trim()->toString(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('role')
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', 30), 100));
        $data = collect($users->items())->map(fn (User $user) => $user->apiData());
        $meta = [
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'total' => $users->total(),
        ];

        return response()->json(['data' => $data, 'meta' => $meta]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($data['role'] === 'user' && $data['normal_price_access'] === $data['wholesale_price_access']) {
            return response()->json(['message' => 'Pengguna harus memiliki tepat satu akses harga: Normal atau Grosir.'], 422);
        }
        $data = $this->normalizeAccess($data);
        $user = User::create([
            ...$data,
            'email' => $data['username'].'-'.Str::uuid().'@mega.local',
            'normal_price_access' => $data['normal_price_access'] ?? true,
            'wholesale_price_access' => $data['wholesale_price_access'] ?? false,
            'offline_auth_version' => 1,
        ]);

        $payload = $user->apiData();

        return response()->json(['data' => $payload], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);
        if ($data['role'] === 'user' && $data['normal_price_access'] === $data['wholesale_price_access']) {
            return response()->json(['message' => 'Pengguna harus memiliki tepat satu akses harga: Normal atau Grosir.'], 422);
        }
        if ($request->user()->is($user) && ($data['role'] ?? $user->role) !== 'admin') {
            return response()->json(['message' => 'Admin tidak dapat menurunkan akses akunnya sendiri.'], 422);
        }
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        } else {
            $data['offline_auth_version'] = $user->offline_auth_version + 1;
        }
        $data = $this->normalizeAccess($data);
        $user->update($data);
        $user = $user->fresh();
        $payload = $user->apiData();

        return response()->json(['data' => $payload]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->is($user)) {
            return response()->json(['message' => 'Admin tidak dapat menghapus akunnya sendiri.'], 422);
        }
        $user->tokens()->delete();
        $user->delete();

        return response()->json(status: 204);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:100',
                Rule::unique('users')->ignore($user?->id)->whereNull('deleted_at'),
            ],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:6'],
            'role' => ['required', Rule::in(['admin', 'sales', 'user'])],
            'normal_price_access' => ['required', 'boolean'],
            'wholesale_price_access' => ['required', 'boolean'],
        ]);
    }

    private function normalizeAccess(array $data): array
    {
        if (in_array($data['role'], ['admin', 'sales'], true)) {
            $data['normal_price_access'] = true;
            $data['wholesale_price_access'] = true;
        }

        return $data;
    }
}
