<?php

namespace Plugins\Sirsoft\SocialLogin\Http\Requests;

use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Helpers\ResponseHelper;
use App\Http\Requests\Concerns\UsesMemberFields;
use App\Rules\NicknamePolicy;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 소셜 가입 완료 요청.
 *
 * 비밀번호는 받지 않는다(제공자 검수 기준 — 소셜 가입에서 비밀번호를 요구하지 않는다). 이메일은 가입 대기가 「입력」
 * 단계일 때만 의미가 있고, 인증번호로 확인한 이메일과 같아야 한다(서비스가 대조). 본인인증 가입 정책이 켜진 사이트는
 * 라우트 미들웨어(`identity.policy:core.auth.signup_before_submit`)가 확인 토큰을 요구한다.
 *
 * 가입 창이 받는 칸은 두 가지다.
 *
 * | 요청 | 규칙 | 회원 |
 * | --- | --- | --- |
 * | `fields_version` 을 실은 요청 (가입 창이 코어 가입 화면과 같은 회원 항목을 그렸다) | 고정 규칙 + 운영자가 환경설정 > 회원에서 정한 가입 항목 규칙. 코어 가입 필터 `core.auth.register_validation_rules` 도 탄다 | 받은 항목 값을 그대로 저장한다 |
 * | `fields_version` 이 없는 요청 (이름, 닉네임, 약관만 받는 창) | 고정 규칙만 (종전과 같다) | 빠진 필수 항목은 첫 로그인 뒤 추가 정보 입력 화면이 받는다 |
 *
 * 실어 보낸 정의 버전이 지금과 다르면(창을 연 뒤 운영자가 항목을 바꿨다) 코어 가입과 같은 409(`fields_changed`)로 답한다.
 *
 * @since 1.0.0
 */
class CompleteSignupRequest extends PendingSignupRequest
{
    use UsesMemberFields;

    /** 코어 가입 요청과 같은 규칙 키 순서 — 오류가 여럿일 때 응답 `message` 에 실리는 첫 문구를 맞춘다 */
    private const RULE_ORDER = ['pending_token', 'name', 'nickname', 'email', 'mobile', 'phone', 'language', 'agree_terms', 'agree_privacy'];

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        $fixed = parent::rules() + [
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'agree_terms' => ['accepted'],
            'agree_privacy' => ['accepted'],
            'verification_token' => ['nullable', 'string', 'max:255'],
            // 가입 창이 받은 회원 항목의 정의 버전 — 낡은 창 판정과 「항목을 받는 창」 표시에만 쓰고 저장하지 않는다.
            'fields_version' => ['nullable', 'string', 'max:64'],
        ];

        if (! $this->usesMemberFieldSet()) {
            return $fixed + [
                'name' => ['nullable', 'string', 'max:255'],
                // 닉네임은 사이트의 닉네임 정책(중복 금지, 금지어)을 따른다 — 걸리면 닉네임 칸 아래에 오류가 뜬다.
                'nickname' => ['nullable', 'string', 'max:50', ...NicknamePolicy::rules(null, FieldContext::SURFACE_SIGNUP)],
            ];
        }

        // 회원 항목(이름, 닉네임, 연락처, 운영자 추가 항목, 확장이 선언한 항목)의 규칙은 운영자 설정에서 온다.
        // 닉네임 정책 규칙도 항목 규칙에 들어 있다. 이 라우트는 코어 가입과 같은 본인인증 미들웨어를 가지므로 코어 가입 필터를 그대로 태운다.
        $rules = $this->withMemberFieldRules($fixed, self::RULE_ORDER);
        $filtered = HookManager::applyFilters('core.auth.register_validation_rules', $rules, $this);

        return is_array($filtered) ? $filtered : $rules;
    }

    /**
     * 가입 표면의 회원 항목 문맥을 반환합니다.
     *
     * @return FieldContext 문맥
     */
    protected function memberFieldContext(): FieldContext
    {
        return FieldContext::for(FieldContext::SURFACE_SIGNUP);
    }

    /**
     * 이 요청이 회원 항목을 받는 가입 창에서 왔는지 반환합니다 (`fields_version` 을 실었는지).
     *
     * @return bool 항목을 받는 창이면 true
     */
    public function usesMemberFieldSet(): bool
    {
        $version = $this->input('fields_version');

        return is_string($version) && $version !== '';
    }

    /**
     * 검증 전에 낡은 가입 창을 가려냅니다.
     *
     * 실어 보낸 정의 버전이 지금과 다르면 검증 오류보다 먼저 409 로 알린다 — 운영자가 항목을 바꾼 뒤 낡은 창에서 제출하면
     * 「없는 칸이 필수」 같은 엉뚱한 오류가 뜨기 때문이다. 가입 대기는 그대로 남아 창이 항목을 다시 받아 이어 갈 수 있다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        if (! $this->usesMemberFieldSet()) {
            return;
        }

        $current = app(FieldRegistry::class)->version(FieldSet::CORE_USER);
        if (! hash_equals($current, (string) $this->input('fields_version'))) {
            $response = ResponseHelper::error('fields.errors.signup_fields_changed', 409, null, [], 'core', ['fields_version' => $current]);
            $response->setData([...$response->getData(true), 'error_code' => 'fields_changed']);

            throw new HttpResponseException($response);
        }
    }

    /**
     * 검증 항목 라벨을 반환합니다.
     *
     * @return array<string, string> 필드 => 라벨
     */
    public function attributes(): array
    {
        $own = parent::attributes() + [
            'name' => __('sirsoft-social_login::validation.attributes.name'),
            'nickname' => __('sirsoft-social_login::validation.attributes.nickname'),
            'email' => __('sirsoft-social_login::validation.attributes.email'),
            'agree_terms' => __('sirsoft-social_login::validation.attributes.agree_terms'),
            'agree_privacy' => __('sirsoft-social_login::validation.attributes.agree_privacy'),
            'verification_token' => __('sirsoft-social_login::validation.attributes.verification_token'),
            'fields_version' => __('fields.attributes.fields_version'),
        ];

        // 항목을 받는 창이면 운영자 추가 항목과 확장 항목의 이름을 오류 문구에 쓴다.
        return $this->usesMemberFieldSet() ? $this->withMemberFieldAttributes($own) : $own;
    }

    /**
     * 검증 오류 문구를 반환합니다 — 항목을 받는 창이면 운영자가 적은 형식 오류 문구를 싣는다.
     *
     * @return array<string, string> 규칙 => 문구
     */
    public function messages(): array
    {
        return $this->usesMemberFieldSet() ? $this->withMemberFieldMessages() : [];
    }
}
