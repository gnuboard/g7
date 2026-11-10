<?php

namespace Plugins\Sirsoft\CdnCloudfront\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 무효화 대기 현황 조회 — 권한은 라우트 permission 미들웨어가 맡고 입력은 받지 않는다.
 */
class PurgeStatusRequest extends FormRequest
{
    /**
     * {@inheritDoc}
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function rules(): array
    {
        return [];
    }
}
