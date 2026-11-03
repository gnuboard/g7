<?php

namespace Plugins\Sirsoft\Popup\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 공개 팝업 조회 요청 — 회원 여부는 optional.sanctum 이, 언어는 요청 로케일이 정하므로 파라미터를 읽지 않는다.
 */
class PublicPopupRequest extends FormRequest
{
    /**
     * 공개 엔드포인트이므로 true 고정입니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 검증 규칙 (사용 파라미터 없음)
     */
    public function rules(): array
    {
        return [];
    }
}
