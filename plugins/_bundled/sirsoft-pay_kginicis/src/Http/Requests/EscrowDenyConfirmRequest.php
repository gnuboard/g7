<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 에스크로 구매거절확인 요청 검증
 *
 * dcnf_name(확인자명)은 KG 이니시스 구매거절확인 API 로 전송되고 payment_meta 로
 * 영속된다. 상한 근거: 이니시스 확인자명 20자. 미입력 시 기본값은 다국어 키
 * (escrow.default_confirmer)로 채운다 — 종전 한글 하드코딩('관리자') 이관.
 */
class EscrowDenyConfirmRequest extends FormRequest
{
    /**
     * 요청 권한 — 라우트 permission 미들웨어 체인이 담당.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 구매거절확인 요청 검증 규칙을 반환합니다.
     *
     * @return array<string, array<int, string>> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'dcnf_name' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * 확인자명을 반환합니다 (미입력 시 다국어 기본값).
     *
     * @return string 확인자명
     */
    public function confirmerName(): string
    {
        $name = trim((string) ($this->validated('dcnf_name') ?? ''));

        return $name !== '' ? $name : __('sirsoft-pay_kginicis::messages.escrow.default_confirmer');
    }
}
