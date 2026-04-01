<?php

namespace App\Http\Requests\Layout;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 레이아웃 조회 요청 검증
 *
 * 레이아웃 조회 권한을 확인합니다.
 * 조회 시에는 데이터 검증이 필요하지 않으며, 권한 체크만 수행합니다.
 */
class GetLayoutRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 조회 요청은 특별한 검증 규칙이 필요하지 않음
        // 라우트 파라미터 검증은 컨트롤러에서 처리
        $rules = [];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout.get_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [];

        // 모듈/플러그인이 validation messages를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.layout.get_validation_messages', $messages, $this);
    }
}
