<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 개인정보 통지 이력의 페이지 범위를 검증합니다. */
class IndexNoticeLogRequest extends FormRequest
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
     * @return array 처리 결과
     */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
    }

    /**
     * 검증 문구의 :attribute 자리에 넣을 운영자용 라벨을 반환합니다.
     *
     * 없으면 Laravel 이 키를 풀어쓴 영문("per page")을 그대로 노출합니다.
     *
     * @return array 필드별 라벨
     */
    public function attributes(): array
    {
        return [
            'page' => __('sirsoft-gdpr::messages.annual_notice.attributes.page'),
            'per_page' => __('sirsoft-gdpr::messages.annual_notice.attributes.per_page'),
        ];
    }
}
