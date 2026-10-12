<?php

namespace App\Http\Requests\Extension;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 미배치 공식 번들 안내 조회 요청 (입력 없음, 공개 #146).
 */
class UnplacedBundledListRequest extends FormRequest
{
    /**
     * 권한 체크는 라우트의 permission 미들웨어와 컨트롤러의 유형별 권한 필터에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 입력 없음 (전체 안내 반환).
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return [];
    }
}
