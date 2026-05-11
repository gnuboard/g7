<?php

namespace App\Http\Requests\Identity;

use App\Enums\IdentityOriginType;
use App\Enums\IdentityPolicySourceType;
use App\Enums\IdentityVerificationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 관리자 IDV 이력 목록 조회 검증.
 *
 * S2 관리자 인증 이력 목록의 필터 쿼리 파라미터를 화이트리스트로 제한합니다.
 */
class AdminIdentityLogIndexRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, mixed>> 검증 규칙
     */
    public function rules(): array
    {
        return [
            // 단일값 호환 — 외부 링크/북마크 (?status=verified) 회귀 차단
            'provider_id' => ['nullable', 'string', 'max:64'],
            'purpose' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::enum(IdentityVerificationStatus::class)],
            'channel' => ['nullable', 'string', 'max:16'],
            'origin_type' => ['nullable', Rule::enum(IdentityOriginType::class)],
            'source_type' => ['nullable', Rule::enum(IdentityPolicySourceType::class)],
            'source_identifier' => ['nullable', 'string', 'max:100'],

            // 다중값 array — 신규 다중선택 필터
            'provider_ids' => ['nullable', 'array'],
            'provider_ids.*' => ['string', 'max:64'],
            'purposes' => ['nullable', 'array'],
            'purposes.*' => ['string', 'max:64'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::enum(IdentityVerificationStatus::class)],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'max:16'],
            'origin_types' => ['nullable', 'array'],
            'origin_types.*' => [Rule::enum(IdentityOriginType::class)],

            // 감사 로그 — 삭제된 user_id 도 조회 가능해야 하므로 exists 검증 사용 안 함.
            'user_id' => ['nullable', 'integer', 'min:1'],
            'target_hash' => ['nullable', 'string', 'size:64'],
            'search' => ['nullable', 'string', 'max:64'],
            'search_type' => ['nullable', 'string', 'in:auto,user_id,target_hash,ip_address,policy_key'],
            'sort_by' => ['nullable', 'string', 'in:created_at,attempts'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
