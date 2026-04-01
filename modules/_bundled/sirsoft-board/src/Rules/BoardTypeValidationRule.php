<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Board\Models\BoardType;

class BoardTypeValidationRule implements ValidationRule
{
    /**
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $validTypes = BoardType::pluck('slug')->toArray();

        if (! in_array($value, $validTypes, true)) {
            $fail(__('sirsoft-board::validation.board_type_invalid', [
                'types' => implode(', ', $validTypes),
            ]));
        }
    }
}
