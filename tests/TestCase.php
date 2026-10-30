<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        // Cambiar de identidad en una fixture representa una autenticación nueva, no reutilizar otra contraseña de sesión.
        $this->withSession(['password_hash_'.($guard??'web')=>$user->getAuthPassword()]);
        return parent::actingAs($user,$guard);
    }
}
