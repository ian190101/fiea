<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PasswordInputPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_limite_bcrypt_admite_72_bytes_y_rechaza_exceso_unicode_sin_cambiar_clave(): void
    {
        $user=User::factory()->create(['password'=>'AnteriorSegura123!','is_active'=>true,'must_change_password'=>false]);
        $valid=str_repeat('A',68).'a1!B';
        self::assertSame(72,strlen($valid));
        $this->actingAs($user)->put(route('password.update'),['current_password'=>'AnteriorSegura123!',
            'password'=>$valid,'password_confirmation'=>$valid])->assertRedirect()->assertSessionHasNoErrors();
        self::assertTrue(Hash::check($valid,$user->fresh()->password));
        self::assertFalse(Hash::check(substr($valid,0,71).'Z',$user->fresh()->password));
        $before=$user->fresh()->password;
        $invalid=str_repeat('ñ',37).'1!';
        self::assertGreaterThan(72,strlen($invalid));
        $this->actingAs($user->fresh())->putJson(route('password.update'),['current_password'=>$valid,
            'password'=>$invalid,'password_confirmation'=>$invalid])->assertUnprocessable()->assertJsonValidationErrors('password');
        self::assertSame($before,$user->fresh()->password);
    }

    public function test_identificador_unicode_mixto_se_autentica_mediante_su_indice_normalizado(): void
    {
        $user=User::factory()->create(['username'=>'USUARIO_Ñ','password'=>'AnteriorSegura123!','is_active'=>true,'must_change_password'=>false]);
        self::assertSame('usuario_ñ',$user->fresh()->getRawOriginal('username_normalized'));
        $this->post('/login',['username'=>'Usuario_Ñ','password'=>'AnteriorSegura123!'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
    }
}
