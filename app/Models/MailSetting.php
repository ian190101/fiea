<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MailSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['app_password'];

    protected function casts(): array
    {
        return ['app_password' => 'encrypted', 'enabled' => 'boolean', 'version' => 'integer'];
    }
}
