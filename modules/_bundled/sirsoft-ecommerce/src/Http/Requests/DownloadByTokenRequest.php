<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 일회용 다운로드 토큰으로 파일 받기 요청
 *
 * 브라우저가 인증 헤더 없이 여는 주소라 로그인 확인은 토큰이 대신한다 — 토큰 주인의 권한·범위는 컨트롤러가 다시 본다.
 * 여기서는 토큰 형식(64자 영숫자)만 검증한다.
 */
class DownloadByTokenRequest extends FormRequest
{
    /**
     * 권한 확인 — 토큰 주인 기준으로 컨트롤러가 담당한다
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 경로의 토큰을 검증 대상에 싣습니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['token' => (string) $this->route('token')]);
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        return HookManager::applyFilters('sirsoft-ecommerce.export.download_by_token_validation_rules', [
            'token' => ['required', 'string', 'size:64', 'alpha_num'],
        ], $this);
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 → 라벨
     */
    public function attributes(): array
    {
        return [
            'token' => __('sirsoft-ecommerce::validation.export_attributes.token'),
        ];
    }
}
