<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasRoles, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    public const MASTER_ROLE_ALIASES = [
        'master admin',
        'master_admin',
        'master-admin',
    ];

    /**
     * Branch roles are stored internally as: role - Branch Name [branch:ID].
     * The public/base role name is used for authorization checks such as master admin.
     */
    public const BRANCH_ROLE_SUFFIX_PATTERN = '/\s-\s.*\s\[branch:\d+\]$/u';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'branch_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */

    public static function baseRoleName(string $role): string
    {
        return trim((string) preg_replace(self::BRANCH_ROLE_SUFFIX_PATTERN, '', $role));
    }

    public static function normalizeRoleName(string $role): string
    {
        $role = self::baseRoleName($role);
        $role = str_replace(['_', '-'], ' ', $role);
        $role = preg_replace('/\s+/', ' ', $role) ?: $role;

        return trim(strtolower($role));
    }

    public static function isMasterAdminRoleName(string $role): bool
    {
        return in_array(self::normalizeRoleName($role), array_map([self::class, 'normalizeRoleName'], self::MASTER_ROLE_ALIASES), true);
    }

    public function hasBaseRole(string $role): bool
    {
        if (!method_exists($this, 'getRoleNames')) {
            return false;
        }

        return $this->getRoleNames()->contains(fn ($name) => self::normalizeRoleName((string) $name) === self::normalizeRoleName($role));
    }

    public function isMasterAdmin(): bool
    {
        if (!method_exists($this, 'getRoleNames')) {
            return false;
        }

        return $this->getRoleNames()->contains(fn ($role) => self::isMasterAdminRoleName((string) $role));
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function deliveryBoyReceived()
    {
        return $this->hasMany(DeliveryBoyReceived::class, 'user_id');
    }

    public function deliveryOrders()
    {
        return $this->hasMany(Sale::class, 'delivery_boy_id');
    }
}
