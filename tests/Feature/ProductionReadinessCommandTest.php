<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_check_command_outputs_readable_status(): void
    {
        $this->artisan('fiea:production-check')
            ->expectsOutputToContain('Revision operativa FIEA')
            ->expectsOutputToContain('Estado general:')
            ->assertExitCode(0);
    }

    public function test_production_check_command_outputs_json_status(): void
    {
        $exitCode = Artisan::call('fiea:production-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('overall', $payload);
        $this->assertArrayHasKey('checks', $payload);
    }
}
