<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Country;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ExplicitAccess;
use Tests\TestCase;

final class OptionLookupTest extends TestCase
{
    use RefreshDatabase;
    use ExplicitAccess;

    public function test_busqueda_y_cursor_recuperan_opciones_mas_alla_de_25_sin_duplicados(): void
    {
        $actor=$this->authorizedUser(['projects.manage']);
        for($i=1;$i<=31;$i++) Country::query()->create(['name'=>sprintf('País %02d',$i)]);
        $first=$this->actingAs($actor)->getJson(route('options.lookup','countries'))->assertOk()->json();
        self::assertCount(25,$first['data']);
        $second=$this->getJson($first['next_page_url'])->assertOk()->json();
        self::assertCount(31,array_unique(array_merge(array_column($first['data'],'id'),array_column($second['data'],'id'))));
        $search=$this->getJson(route('options.lookup',['kind'=>'countries','q'=>'País 31']))->assertOk()->json('data');
        self::assertSame(['País 31'],array_column($search,'name'));
        self::assertArrayNotHasKey('password',$search[0]);
    }
    public function test_consulta_remota_conserva_alcance_incluso_con_busqueda_exacta(): void
    {
        $actor=$this->authorizedUser(['trip_phases.manage']);
        $actor->forceFill(['scope_all'=>false])->save();
        $country=Country::query()->create(['name'=>'País']);
        $own=Project::query()->create(['country_id'=>$country->id,'code'=>'VISIBLE','name'=>'Visible']);
        $hidden=Project::query()->create(['country_id'=>$country->id,'code'=>'OCULTO','name'=>'Oculto']);
        $actor->projectGrants()->attach($own->id);
        $rows=$this->actingAs($actor)->getJson(route('options.lookup','projects'))->assertOk()->json('data');
        self::assertSame([$own->id],array_column($rows,'id'));
        self::assertSame([],$this->getJson(route('options.lookup',['kind'=>'projects','q'=>$hidden->name]))->assertOk()->json('data'));
    }
    public function test_endpoint_rechaza_modulos_sin_permiso_tipos_desconocidos_e_inputs_invalidos(): void
    {
        $actor=$this->authorizedUser(['dashboard.view']);
        $this->actingAs($actor)->getJson(route('options.lookup','countries'))->assertForbidden();
        $this->getJson(route('options.lookup','secretos'))->assertNotFound();
        $actor=$this->authorizedUser(['projects.manage']);
        $this->actingAs($actor)->getJson(route('options.lookup',['kind'=>'countries','q'=>str_repeat('x',121)]))
            ->assertUnprocessable()->assertJsonValidationErrors('q');
    }
}
