<?php
declare(strict_types=1);
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool)$this->user()?->hasPermission('reports.view');
    }
    public function rules(): array
    {
        return [
            'year'=>['nullable','integer','between:2000,2100'],
            'cursor'=>['nullable','string','max:500'],
            'basis'=>['nullable','in:case_lifetime,period_cash'],
        ];
    }
    public function filters(): array
    {
        $data=$this->validated();
        return ['year'=>(int)($data['year']??date('Y')),
            'cursor'=>$data['cursor']??null,'basis'=>$data['basis']??'case_lifetime'];
    }
}
