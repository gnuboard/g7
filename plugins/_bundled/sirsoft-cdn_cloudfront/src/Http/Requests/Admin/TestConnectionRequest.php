<?php

namespace Plugins\Sirsoft\CdnCloudfront\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 연결 시험 — 저장된 설정으로만 시험하므로 입력은 받지 않는다. 권한은 라우트 permission 미들웨어가 맡는다.
 */
class TestConnectionRequest extends FormRequest
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
