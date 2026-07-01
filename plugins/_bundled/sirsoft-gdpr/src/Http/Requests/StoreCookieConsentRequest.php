<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;

/**
 * 쿠키 동의 저장 요청 검증
 *
 * POST /api/plugins/sirsoft-gdpr/consent/cookie
 *
 * 게스트(미인증) / 회원(sanctum) 모두 호출 가능한 공개 엔드포인트.
 * 게스트는 session_id 기반 history INSERT, 회원은 status upsert + history INSERT.
 */
class StoreCookieConsentRequest extends FormRequest
{
    /**
     * StoreCookieConsentRequest 생성자
     *
     * @param CookieCategoryService $categoryService 쿠키 카테고리 설정 서비스
     */
    public function __construct(private readonly CookieCategoryService $categoryService)
    {
        parent::__construct();
    }

    /**
     * 권한 확인 (공개 엔드포인트 — 인증 불필요)
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
            'consents' => ['required', 'array', 'min:1'],
            'consents.*' => ['required', 'boolean'],
            'source' => ['required', 'string', 'in:banner,preference_center,register,mypage'],
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
            'consents.required' => __('sirsoft-gdpr::messages.consent.invalid_key'),
            'consents.array' => __('sirsoft-gdpr::messages.consent.invalid_key'),
            'consents.*.boolean' => __('sirsoft-gdpr::messages.consent.invalid_key'),
            'source.required' => __('sirsoft-gdpr::messages.consent.invalid_key'),
            'source.in' => __('sirsoft-gdpr::messages.consent.invalid_key'),
        ];
    }

    /**
     * 검증 후 동의 키 화이트리스트 + 필수 항목 false 차단.
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $consents = (array) $this->input('consents', []);
            $allowedKeys = $this->categoryService->getAllConsentKeys();

            foreach ($consents as $key => $value) {
                if (! in_array($key, $allowedKeys, true)) {
                    $validator->errors()->add(
                        "consents.{$key}",
                        __('sirsoft-gdpr::messages.consent.invalid_key')
                    );

                    continue;
                }

                if ($value === false && $this->categoryService->isRequired($key)) {
                    $validator->errors()->add(
                        "consents.{$key}",
                        __('sirsoft-gdpr::messages.consent.required_cannot_revoke')
                    );
                }
            }
        });
    }
}
