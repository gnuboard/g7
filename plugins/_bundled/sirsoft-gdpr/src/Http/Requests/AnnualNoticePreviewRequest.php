<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 연간 통지 대상 건수 미리보기 요청 — 입력은 없고 목록 권한 판정에 요청 관리자가 필요하다. */
class AnnualNoticePreviewRequest extends FormRequest
{
    /**
     * 권한 검사는 라우트 미들웨어에 위임합니다.
     *
     * @return bool 처리 결과
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청 입력의 허용 형식과 범위를 반환합니다.
     *
     * @return array 처리 결과 (입력 없음)
     */
    public function rules(): array
    {
        return [];
    }
}
