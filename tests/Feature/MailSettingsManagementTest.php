<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MailSetting;
use App\Services\MailConfiguration;
use App\Services\SmtpConnectionVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ExplicitAccess;
use Tests\TestCase;

final class MailSettingsManagementTest extends TestCase
{
    use ExplicitAccess;
    use RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return array_replace(['account' => 'cuenta@gmail.com', 'from_name' => 'FIEA',
            'app_password' => 'abcd efgh ijkl mnop', 'enabled' => true, 'version' => 0], $overrides);
    }

    public function test_guarda_cifrada_sin_exponer_credencial_en_interfaz_auditoria_ni_modelo(): void
    {
        $this->mock(SmtpConnectionVerifier::class)->shouldReceive('verify')->once()->with('cuenta@gmail.com', 'abcdefghijklmnop');
        $actor = $this->authorizedUser(['settings.manage']);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('mail-settings.update'), $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $settings = MailSetting::query()->firstOrFail();
        self::assertSame('abcdefghijklmnop', $settings->app_password);
        self::assertNotSame('abcdefghijklmnop', DB::table('mail_settings')->value('app_password'));
        self::assertArrayNotHasKey('app_password', $settings->toArray());
        self::assertStringNotContainsString('abcdefghijklmnop', AuditLog::query()->latest('id')->firstOrFail()->toJson());
        $response = $this->get(route('system-settings.edit'))->assertOk();
        $response->assertDontSee('abcdefghijklmnop');
        self::assertTrue(app(MailConfiguration::class)->publicStatus()['password_configured']);
    }

    public function test_campo_vacio_conserva_clave_y_cuenta_distinta_exige_una_nueva(): void
    {
        $this->mock(SmtpConnectionVerifier::class)->shouldReceive('verify')->twice();
        $actor = $this->authorizedUser(['settings.manage']);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post(route('mail-settings.update'), $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('mail-settings.update'), $this->data(['version' => 1, 'app_password' => '', 'from_name' => 'Fundación']))
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('abcdefghijklmnop', MailSetting::query()->findOrFail(1)->app_password);
        $this->post(route('mail-settings.update'), $this->data(['version' => 2, 'app_password' => '', 'account' => 'otra@gmail.com']))
            ->assertSessionHasErrors('app_password');
        self::assertSame('cuenta@gmail.com', MailSetting::query()->findOrFail(1)->account);
    }

    public function test_fallo_de_autenticacion_no_guarda_ni_reenvia_clave_en_sesion(): void
    {
        $this->mock(SmtpConnectionVerifier::class)->shouldReceive('verify')->once()
            ->andThrow(ValidationException::withMessages(['app_password' => 'Credencial no aceptada.']));
        $actor = $this->authorizedUser(['settings.manage']);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('mail-settings.update'), $this->data())->assertSessionHasErrors('app_password');
        $this->assertDatabaseCount('mail_settings', 0);
        self::assertArrayNotHasKey('app_password', session('_old_input', []));
    }

    public function test_requiere_permiso_y_confirmacion_de_contrasena(): void
    {
        $actor = $this->authorizedUser(['settings.manage']);
        $this->actingAs($actor)->post(route('mail-settings.update'), $this->data())
            ->assertRedirect(route('password.confirm'));
        $other = $this->authorizedUser(['dashboard.view']);
        $this->actingAs($other)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('mail-settings.update'), $this->data())->assertForbidden();
        $this->post(route('mail-settings.test'))->assertForbidden();
    }

    public function test_version_obsoleta_no_sobrescribe_otra_configuracion(): void
    {
        $this->mock(SmtpConnectionVerifier::class)->shouldReceive('verify')->once();
        $actor = $this->authorizedUser(['settings.manage']);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post(route('mail-settings.update'), $this->data())->assertRedirect();
        $this->post(route('mail-settings.update'), $this->data(['from_name' => 'Cambio obsoleto']))->assertConflict();
        self::assertSame('FIEA', MailSetting::query()->findOrFail(1)->from_name);
    }

    public function test_prueba_solo_envia_a_la_cuenta_guardada_y_lleva_aviso_de_prueba(): void
    {
        $this->mock(SmtpConnectionVerifier::class)->shouldReceive('verify')->once();
        $actor = $this->authorizedUser(['settings.manage']);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post(route('mail-settings.update'), $this->data())->assertRedirect();
        $transport = new ArrayTransport;
        Mail::mailer('smtp')->setSymfonyTransport($transport);
        $this->post(route('mail-settings.test'), ['to' => 'ajeno@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        self::assertCount(1, $transport->messages());
        $message = $transport->messages()->first()->getOriginalMessage();
        self::assertSame('cuenta@gmail.com', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('[PRUEBA]', $message->getSubject());
        self::assertStringContainsString('TEST EMAIL', $message->getHtmlBody());
    }

    public function test_worker_recarga_la_cuenta_al_cambiar_la_version_guardada(): void
    {
        $settings = new MailSetting;
        $settings->forceFill(['id' => 1, 'account' => 'primera@gmail.com', 'from_name' => 'FIEA',
            'app_password' => 'abcdefghijklmnop', 'enabled' => true, 'version' => 1])->save();
        $configuration = app(MailConfiguration::class);
        $configuration->apply();
        self::assertSame('primera@gmail.com', config('mail.from.address'));
        $settings->forceFill(['account' => 'segunda@gmail.com', 'app_password' => 'ponmlkjihgfedcba', 'version' => 2])->save();
        $configuration->apply();
        self::assertSame('segunda@gmail.com', config('mail.from.address'));
        self::assertSame('ponmlkjihgfedcba', config('mail.mailers.smtp.password'));
    }
}
