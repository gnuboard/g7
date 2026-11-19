<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldRuleFactory;
use App\Fields\FieldSet;
use App\Models\User;
use App\Services\RequiredFieldsChecker;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 추가 정보 입력 화면의 저장 요청.
 *
 * 받는 것은 그 회원에게 비어 있는 필수 항목뿐이다. 이미 값이 있는 항목과 그 밖의 칸(이메일, 비밀번호 등)은 규칙에 없어
 * 보내도 검증된 데이터에 들어가지 않는다 — 기존 값을 바꾸지 못하므로 재확인(비밀번호 확인)을 요구하지 않는다.
 *
 * @since 7.2.0
 */
class CompleteRequiredFieldsRequest extends FormRequest
{
    /** 요청 한 번에서 판정을 다시 하지 않도록 잡아 둔 문맥 */
    private ?FieldContext $fieldContext = null;

    /**
     * 요청 권한 — 본인 정보라 인증만 되면 허용한다(인증은 라우트 미들웨어).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다 — 비어 있는 필수 항목의 규칙만.
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = app(FieldRuleFactory::class)->rules(FieldSet::CORE_USER, $this->memberFieldContext());

        return HookManager::applyFilters('core.user.complete_required_fields_validation_rules', $rules, $this);
    }

    /**
     * 검증 속성명 — 항목 이름을 요청 로케일로 싣는다.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return app(FieldRuleFactory::class)->attributes(FieldSet::CORE_USER, $this->memberFieldContext());
    }

    /**
     * 운영자가 적은 형식 오류 문구를 반환합니다.
     *
     * @return array<string, string> 규칙 경로 => 문구
     */
    public function messages(): array
    {
        return app(FieldRuleFactory::class)->messages(FieldSet::CORE_USER, $this->memberFieldContext());
    }

    /**
     * 추가 정보 입력 표면의 문맥을 반환합니다 (대상은 그 회원에게 비어 있는 필수 항목뿐).
     *
     * @return FieldContext 문맥
     */
    protected function memberFieldContext(): FieldContext
    {
        if ($this->fieldContext !== null) {
            return $this->fieldContext;
        }

        $user = $this->user();
        $user = $user instanceof User ? $user : null;

        return $this->fieldContext = new FieldContext(
            surface: FieldContext::SURFACE_REQUIRED_INFO,
            owner: $user,
            actor: $user,
            // 캐시가 아니라 지금 값으로 판정한다 — 방금 채운 항목을 다시 받지 않는다.
            onlyKeys: $user !== null ? app(RequiredFieldsChecker::class)->pendingKeys($user, fresh: true) : [],
        );
    }
}
