<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

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
        'role',
        'saras_user_id',
        'tenant_id',
        'tenant_name',
        'selected_saras_project_id',
        'saras_access_token',
        'saras_token_expires_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'saras_access_token',
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
            'two_factor_confirmed_at' => 'datetime',
            'saras_token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<FaceEnrollment, $this>
     */
    public function faceEnrollments(): HasMany
    {
        return $this->hasMany(FaceEnrollment::class);
    }

    /**
     * @return HasOne<FaceEnrollment, $this>
     */
    public function activeFaceEnrollment(): HasOne
    {
        return $this->hasOne(FaceEnrollment::class)
            ->where('status', 'active')
            ->latestOfMany();
    }

    /**
     * @return HasOne<FaceEnrollment, $this>
     */
    public function activeHypervergeFaceEnrollment(): HasOne
    {
        return $this->hasOne(FaceEnrollment::class)
            ->where('provider', 'hyperverge')
            ->where('status', 'active')
            ->latestOfMany();
    }
}
