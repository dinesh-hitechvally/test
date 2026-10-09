<?php

namespace App\Http\Requests\Auth;

use App\Services\Analysis\Signals\SignalConditionScorer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSignalSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'min_pct' => ['required', 'integer', 'between:40,90'],
            'margin' => ['required', 'integer', 'between:0,50'],
            'guard_extremes' => ['required', 'boolean'],
        ];

        foreach (SignalConditionScorer::CATEGORIES as $category) {
            $rules["weights.{$category}"] = ['required', 'integer', 'between:0,100'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $weights = $this->input('weights', []);

            if (is_array($weights) && ! $validator->errors()->hasAny(array_map(fn ($c) => "weights.{$c}", SignalConditionScorer::CATEGORIES)) && array_sum($weights) !== 100) {
                $validator->errors()->add('weights', 'The weights must add up to 100 (they add up to '.array_sum($weights).').');
            }
        }];
    }
}
