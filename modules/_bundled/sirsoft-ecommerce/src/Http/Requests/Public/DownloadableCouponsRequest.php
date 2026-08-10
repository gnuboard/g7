<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품별 다운로드 가능 쿠폰 목록 조회 요청을 검증합니다.
 *
 * 상품은 라우트 모델 바인딩으로 해석되고 그 밖의 쿼리 파라미터는 사용하지 않으므로 규칙이
 * 비어 있습니다. 로그인 여부는 optional.sanctum 미들웨어가 해석한 사용자로 판단합니다.
 */
class DownloadableCouponsRequest extends FormRequest
{
    /**
     * 요청 권한 — 공개 엔드포인트이므로 true 고정.
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
     * @return array<string, array<int, mixed>> 검증 규칙 (사용 파라미터 없음)
     */
    public function rules(): array
    {
        return [];
    }
}
