<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChapterType;
use App\Models\Community;
use App\Models\Country;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\University;
use App\Models\User;
use App\Services\Security\AuditWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CatalogService
{
    private const CATALOGS = [
        'chapter-types' => ChapterType::class, 'countries' => Country::class,
        'communities' => Community::class, 'universities' => University::class,
        'expense-categories' => ExpenseCategory::class,
    ];

    public function save(User $actor, string $catalog, ?int $id, array $data): Model
    {
        return DB::transaction(function () use ($actor, $catalog, $id, $data): Model {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->authorize($actor);
            $class = $this->model($catalog);
            $model = $id ? $class::query()->lockForUpdate()->findOrFail($id) : new $class;
            $before = $model->only(['name', 'description', 'country_id', 'fund_type', 'service_fee_percentage']);
            if ($model instanceof Community && $model->exists && isset($data['country_id'])
                && (int) $model->country_id !== (int) $data['country_id']) {
                // El país del proyecto es una identidad propia: no se cambia indirectamente desde un catálogo.
                if (Project::query()->where('community_id', $model->id)->exists()) {
                    throw ValidationException::withMessages(['country_id' => 'La comunidad está vinculada a proyectos; desvincúlalos antes de cambiar su país.']);
                }
            }
            $model->fill($data)->save();
            app(AuditWriter::class)->record($actor, 'catalog_saved', $model, null, $before,
                $model->only(array_keys($before)), (string) request()->attributes->get('correlation_id', Str::uuid()));

            return $model;
        }, 3);
    }

    public function destroy(User $actor, string $catalog, int $id): void
    {
        DB::transaction(function () use ($actor, $catalog, $id): void {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->authorize($actor);
            $class = $this->model($catalog);
            $model = $class::query()->lockForUpdate()->findOrFail($id);
            $before = $model->only(['name', 'description', 'country_id', 'fund_type', 'service_fee_percentage']);
            $model->delete();
            app(AuditWriter::class)->record($actor, 'catalog_deleted', $model, null, $before, [],
                (string) request()->attributes->get('correlation_id', Str::uuid()));
        }, 3);
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->scope_all && $actor->hasPermission('catalogs.manage'), 403);
    }

    private function model(string $catalog): string
    {
        abort_unless(isset(self::CATALOGS[$catalog]), 404);

        return self::CATALOGS[$catalog];
    }
}
