<?php

namespace Plugins\Sirsoft\Koreaexim\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RunTrigger;

class IndexRateRunRequest extends FormRequest
{
    /**
     * 권한 검사를 라우트 미들웨어에 위임합니다.
     *
     * @return bool 처리 결과
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 입력 검증 규칙을 반환합니다.
     *
     * @return array 처리 결과
     */
    public function rules(): array
    {
        return ['status' => ['nullable', Rule::enum(RunStatus::class)], 'trigger' => ['nullable', Rule::enum(RunTrigger::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100']];
    }

    /**
     * 검증 오류 문구에 쓸 필드 이름을 반환합니다.
     *
     * 지정하지 않으면 「date to 필드는 date from 이후…」처럼 파라미터 원문이 문구에 드러난다.
     *
     * @return array<string, string> 필드 이름
     */
    public function attributes(): array
    {
        return collect(['status', 'trigger', 'date_from', 'date_to', 'page', 'per_page'])
            ->mapWithKeys(fn (string $field) => [$field => __('sirsoft-koreaexim::validation.attributes.'.$field)])
            ->all();
    }
}
