<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 운영자 수동 정책 버전 발행 요청 검증
 *
 * POST /api/plugins/sirsoft-gdpr/admin/policy-versions
 *
 * 운영자가 정책 본문 외부 수정 등 *자동 감지 영역 밖* 의 변경을 인지하고
 * 명시적으로 새 정책 버전을 발행할 때 사용. memo 필수 (감사 추적).
 */
class PublishPolicyVersionRequest extends FormRequest
{
    /**
     * 권한 확인 (permission 미들웨어에서 처리)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'memo' => ['required', 'string', 'min:1', 'max:500'],
        ];
    }

    /**
     * 검증 메시지의 :attribute placeholder 사용자 친화 라벨.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'memo' => __('sirsoft-gdpr::messages.settings.policy_version.material_modal.memo_label'),
        ];
    }
}
