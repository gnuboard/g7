<?php

namespace App\Http\Requests\Extension;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 미배치 공식 번들 안내 닫기 요청 (입력 없음 — 유형은 라우트 파라미터, 공개 #146).
 */
class DismissUnplacedBundledRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어(`core.{type}s.read`)에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 입력 없음 (라우트 파라미터 type 은 라우트 where 제약으로 검증).
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [];
    }
}
