<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'must_change_password',
        'theme_preference',
        'is_active',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'username_normalized',
    ];

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
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'scope_all' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function setUsernameAttribute(string $value): void
    {
        $this->attributes['username'] = $value;
        $this->attributes['username_normalized'] = Str::lower(trim($value));
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $code): bool
    {
        $this->loadMissing('roles');

        return $this->roles->contains('code', $code);
    }

    public function hasPermission(string $code): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->contains('code', $code);
    }

    /**
     * @param  array<int, string>  $codes
     */
    public function hasAnyPermission(array $codes): bool
    {
        if (! $this->is_active) {
            return false;
        }
        $this->loadMissing('roles.permissions');

        $permissionSet = array_flip($codes);

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions)
            ->contains(fn (Permission $permission) => isset($permissionSet[$permission->code]));
    }

    /**
     * @return array<int, string>
     */
    public function permissionCodes(): array
    {
        if (! $this->is_active) {
            return [];
        }
        $this->loadMissing('roles.permissions');

        return $this->roles
            ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
            ->unique()
            ->values()
            ->all();
    }

    public function assignedTripPhases()
    {
        return $this->hasMany(TripPhase::class, 'assigned_technician_id');
    }

    public function projectGrants(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'user_project_assignments');
    }

    public function chapterGrants(): BelongsToMany
    {
        return $this->belongsToMany(Chapter::class, 'user_chapter_assignments');
    }
}
