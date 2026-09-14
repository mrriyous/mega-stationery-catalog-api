<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\AppActivityLog;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'normal_price_access', 'wholesale_price_access', 'offline_auth_version'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use AppActivityLog, HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * @return list<string>
     */
    protected function activityLogExcept(): array
    {
        return ['password', 'remember_token'];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSales(): bool
    {
        return $this->role === 'sales';
    }

    public function priceAccess(): array
    {
        return [
            'normal_price_access' => $this->isAdmin() || $this->isSales() || $this->normal_price_access,
            'wholesale_price_access' => $this->isAdmin() || $this->isSales() || $this->wholesale_price_access,
        ];
    }

    public function apiData(): array
    {
        return [
            ...$this->only('id', 'name', 'username', 'role', 'offline_auth_version'),
            ...$this->priceAccess(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'normal_price_access' => 'boolean',
            'wholesale_price_access' => 'boolean',
            'offline_auth_version' => 'integer',
        ];
    }
}
