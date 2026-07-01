<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;

/**
 * 회원 동의 철회 요청 검증
 *
 * POST /api/plugins/sirsoft-gdpr/consent/revoke
 *
 * 마이페이지에서 본인 동의를 철회할 때 사용. 필수 카테고리는 422로 거부.
 */
class RevokeConsentRequest extends FormRequest
{
    /**
     * RevokeConsentRequest 생성자
     *
     * @param CookieCategoryService $categoryService 쿠키 카테고리 설정 서비스
     */
    public function __construct(private readonly CookieCategoryService $categoryService)
    {
        parent::__construct();
    }

    /**
     * 권한 확인 (sanctum 인증은 미들웨어에서 처리)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'consent_key' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * 검증 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consent_key.required' => __('sirsoft-gdpr::messages.consent.invalid_key'),
            'consent_key.string' => __('sirsoft-gdpr::messages.consent.invalid_key'),
            'consent_key.max' => __('sirsoft-gdpr::messages.consent.invalid_key'),
        ];
    }

    /**
     * 검증 후 동의 키 화이트리스트 + 필수 항목 차단.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $consentKey = (string) $this->input('consent_key', '');

            if ($consentKey === '') {
                return;
            }

            $allowedKeys = $this->categoryService->getAllConsentKeys();

            if (! in_array($consentKey, $allowedKeys, true)) {
                $validator->errors()->add(
                    'consent_key',
                    __('sirsoft-gdpr::messages.consent.invalid_key')
                );

                return;
            }

            if ($this->categoryService->isRequired($consentKey)) {
                $validator->errors()->add(
                    'consent_key',
                    __('sirsoft-gdpr::messages.consent.required_cannot_revoke')
                );
            }
        });
    }
}
