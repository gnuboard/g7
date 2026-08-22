<?php

namespace App\Http\Requests\NotificationDefinition;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class NotificationDefinitionIndexRequest extends FormRequest
{
    /**
     * 권한 확인 (미들웨어에서 처리).
     *
     * @return bool 항상 true (권한은 라우트 permission 미들웨어가 담당)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'extension_type' => ['nullable', 'string', 'in:core,module,plugin'],
            'extension_identifier' => ['nullable', 'string', 'max:100'],
            'channel' => ['nullable', 'string', 'max:50'],
            // 목록에 실을 템플릿을 한 채널로 좁힌다. `channel` 과 다르다 — `channel` 은 정의 행
            // 자체를 거르지만(그 채널을 지원하지 않는 정의는 목록에서 사라진다), 이 값은 행은
            // 그대로 두고 각 행에 딸려오는 템플릿만 좁힌다. 화면이 한 번에 한 채널만 그리므로
            // 나머지 채널의 제목·본문은 전송할 이유가 없다.
            'template_channel' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', 'in:id,type,extension_type,is_active,created_at,updated_at'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ];

        return HookManager::applyFilters(
            'core.notification_definition.index_validation_rules',
            $rules
        );
    }

    /**
     * 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 검증 오류 메시지 배열
     */
    public function messages(): array
    {
        return [
            'per_page.min' => __('validation.min.numeric', ['attribute' => 'per_page', 'min' => 1]),
            'per_page.max' => __('validation.max.numeric', ['attribute' => 'per_page', 'max' => 100]),
        ];
    }
}
