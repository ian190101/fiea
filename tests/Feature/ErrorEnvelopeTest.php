<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ErrorEnvelopeTest extends TestCase
{
    public function test_json_errors_share_a_safe_and_consistent_structure(): void
    {
        config()->set('app.debug', false);

        $this->getJson('/ruta-inexistente')->assertStatus(404)->assertJson([
            'success' => false,
            'errorCode' => 'HTTP_404',
            'fullError' => ['status' => 404],
        ]);
        $this->getJson('/dashboard')->assertStatus(401)->assertJson([
            'success' => false,
            'errorCode' => 'HTTP_401',
            'fullError' => ['status' => 401],
        ]);
        $this->postJson('/login', ['username' => 'ian'])->assertStatus(422)->assertJson([
            'success' => false,
            'errorCode' => 'VALIDATION_FAILED',
            'fullError' => ['status' => 422],
        ]);

        Route::get('/__error-envelope-test', fn () => throw new \RuntimeException('fallo de prueba'));
        $this->getJson('/__error-envelope-test')->assertStatus(500)->assertJson([
            'success' => false,
            'errorCode' => 'HTTP_500',
            'fullError' => ['status' => 500],
        ]);
    }

    public function test_direct_browser_errors_have_a_glass_shell_and_safe_diagnostic(): void
    {
        config()->set('app.debug', false);
        $this->get('/pagina-inexistente')->assertNotFound()->assertSee('error-diagnostic', false)->assertSee('HTTP_404')->assertDontSee('trace');
        $this->get('/pagina-inexistente', ['X-Inertia' => 'true'])->assertNotFound()->assertJson(['errorCode' => 'HTTP_404']);
    }
}
