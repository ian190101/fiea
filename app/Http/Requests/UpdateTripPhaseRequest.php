<?php
declare(strict_types=1);
namespace App\Http\Requests;

use App\Services\Security\ScopeService;

final class UpdateTripPhaseRequest extends StoreTripPhaseRequest
{
    public function authorize(): bool
    {
        $phase=$this->route('tripPhase');
        return $phase && parent::authorize()
            && app(ScopeService::class)->permits($this->user(),(int)$phase->id);
    }
    public function rules(): array
    {
        return parent::rules()+['version'=>['required','integer','min:1']];
    }
}
