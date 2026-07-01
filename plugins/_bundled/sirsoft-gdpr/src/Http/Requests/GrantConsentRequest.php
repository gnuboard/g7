<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;

/**
 * 회원 동의 부여(재동의/신규 동의) 요청 검증
 *
 * POST /api/plugins/sirsoft-gdpr/consent/grant
 *
 * 마이페이지 「내 동의 현황」 에서 「다시 동의」 / 「동의」 버튼 클릭 시 사용.
 * Art.7(3) 자유 변경권의 부여 방향 — 이미 철회한 항목을 다시 부여하거나, 카탈로그에 있지만
 * user_consents 에 row 없는 항목을 신규로 동의.
 *
 * 필수(strictly necessary) 카테고리는 부여 의미가 없지만(이미 항상 활성), 차단까지는 안 함 —
 * Art.7(3) 의 "자유 부여" 권리를 임의 차단하지 않기 위함. 결과적으로 noop 처리됨.
 */
class GrantConsentRequest extends FormRequest
{
    /**
     * GrantConsentRequest 생성자
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
     * 검증 후 동의 키 화이트리스트 검사.
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
            }
        });
    }
}
