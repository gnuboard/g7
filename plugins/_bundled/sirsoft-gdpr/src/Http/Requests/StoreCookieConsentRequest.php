<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\Gdpr\Enums\ConsentSource;
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
     * @param  CookieCategoryService  $categoryService  쿠키 카테고리 설정 서비스
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
            // 허용 어휘의 SSoT 는 ConsentSource enum 이다 (화면 필터·라벨도 같은 목록에서 파생)
            'source' => ['required', 'string', Rule::in(ConsentSource::requestSelectableValues())],
            // 명시적 거부 신호 (이슈 #430). 배너 "동의하지 않고 계속하기" 버튼이 전송.
            // 미전송(null) 시 일반 저장(기존 동작). 값은 'reject' 만 허용.
            'intent' => ['sometimes', 'nullable', 'string', 'in:reject'],
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
     * @param  Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
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
