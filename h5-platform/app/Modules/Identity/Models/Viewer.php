<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Services\MailDeliveryUnconfirmed;
use App\Modules\Identity\Services\Verification;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class Viewer extends Authenticatable implements MustVerifyEmail
{
    use HasUuids, Notifiable;

    protected $table = 'identity_viewers';

    protected $attributes = ['active' => true, 'session_version' => 1];

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token', 'verification_nonce'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'immutable_datetime', 'password' => 'hashed', 'active' => 'boolean', 'session_version' => 'integer'];
    }

    public function sendPasswordResetNotification($token): void
    {
        try {
            parent::sendPasswordResetNotification($token);
        } catch (TransportExceptionInterface) {
            throw new MailDeliveryUnconfirmed;
        }
    }

    public function sendEmailVerificationNotification(): void
    {
        app(Verification::class)->send($this);
    }
}
