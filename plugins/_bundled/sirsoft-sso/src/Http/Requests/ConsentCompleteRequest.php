<?php

namespace Plugins\Sirsoft\Sso\Http\Requests;

use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Helpers\ResponseHelper;
use App\Http\Requests\Concerns\UsesMemberFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Plugins\Sirsoft\Sso\Services\LinkVerificationService;

/**
 * 처음 로그인 약관 동의 완료 요청 — 이용약관, 개인정보 처리방침 동의가 둘 다 있어야 한다.
 *
 * 동의 창이 받는 칸은 두 가지다.
 *
 * | 요청 | 규칙 | 회원 |
 * | --- | --- | --- |
 * | `fields_version` 을 실은 요청 (동의 창이 코어 가입 화면과 같은 회원 항목을 그렸다) | 약관 + 운영자가 환경설정 > 회원에서 정한 가입 항목 규칙. 코어 가입 필터 `core.auth.register_validation_rules` 도 타되 본인인증 확인 토큰 규칙은 뺀다 | 받은 항목 값을 그대로 저장한다 |
 * | `fields_version` 이 없는 요청 (약관만 받는 창, 관리자 로그인 화면의 동의 창) | 약관만 (종전과 같다) | 빠진 필수 항목은 첫 로그인 뒤 추가 정보 입력 화면이 받는다 |
 *
 * 실어 보낸 정의 버전이 지금과 다르면(창을 연 뒤 운영자가 항목을 바꿨다) 코어 가입과 같은 409(`fields_changed`)로 답한다 —
 * 동의 대기는 그대로 남는다. IdP 가 값을 주는 칸은 규칙에서 빠져 창이 무엇을 보냈든 무시되고, 회원 생성이 IdP 값을 쓴다.
 *
 * @since 1.0.0
 */
class ConsentCompleteRequest extends FormRequest
{
    use UsesMemberFields;

    /** @var array<int, string> 회원 항목이 아닌 칸 — 항목 값으로 넘기지 않는다 */
    private const NON_FIELD_KEYS = ['agree_terms', 'agree_privacy', 'fields_version', 'verification_token'];

    /** 코어 가입 요청과 같은 규칙 키 순서 — 오류가 여럿일 때 응답 `message` 에 실리는 첫 문구를 맞춘다 */
    private const RULE_ORDER = ['name', 'nickname', 'mobile', 'phone', 'language', 'agree_terms', 'agree_privacy'];

    /** @var array<int, string>|null IdP 가 값을 주어 잠긴 항목 키 (요청당 한 번 읽어 둔다) */
    private ?array $lockedFieldKeys = null;

    /**
     * 손님 요청이다 — 대기 토큰과 브라우저 묶음 쿠키가 곧 권한이다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙.
     *
     * @return array<string, mixed> 규칙
     */
    public function rules(): array
    {
        $fixed = [
            'agree_terms' => ['accepted'],
            'agree_privacy' => ['accepted'],
        ];

        if (! $this->usesMemberFieldSet()) {
            return $fixed;
        }

        // 회원 항목(이름, 닉네임, 연락처, 운영자 추가 항목, 확장이 선언한 항목)의 규칙은 운영자 설정에서 온다.
        $rules = $this->withMemberFieldRules($fixed + [
            // 동의 창이 받은 회원 항목의 정의 버전 — 낡은 창 판정과 「항목을 받는 창」 표시에만 쓰고 저장하지 않는다.
            'fields_version' => ['nullable', 'string', 'max:64'],
        ], self::RULE_ORDER);

        $filtered = HookManager::applyFilters('core.auth.register_validation_rules', $rules, $this);
        $rules = is_array($filtered) ? $filtered : $rules;

        // 이 라우트에는 코어 가입의 본인인증 미들웨어가 없고 동의 창은 확인 토큰을 보내지 않는다 — 본인인증 가입 정책을 켠
        // 사이트에서 토큰 필수 규칙이 붙으면 늘 422 가 되므로 뺀다(회사 계정의 본인 확인은 IdP 가 했다).
        unset($rules['verification_token']);

        // IdP 가 값을 주는 칸은 창에서 읽기 전용이다. 규칙에서 빼면 창이 무엇을 보냈든 검증된 입력에 들어가지 않고(값을 보내도 무시),
        // 회원 생성이 IdP 값을 쓴다. IdP 값이 닉네임 정책에 걸려도 고칠 수 없는 칸의 오류로 창이 막히지 않는다(생성이 닉네임만 비운다).
        foreach ($this->lockedFieldKeys() as $locked) {
            $rules = array_filter(
                $rules,
                fn (string $path) => $path !== $locked && ! str_starts_with($path, $locked.'.'),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $rules;
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
     * 이 요청이 회원 항목을 받는 동의 창에서 왔는지 반환합니다 (`fields_version` 을 실었는지).
     *
     * @return bool 항목을 받는 창이면 true
     */
    public function usesMemberFieldSet(): bool
    {
        $version = $this->input('fields_version');

        return is_string($version) && $version !== '';
    }

    /**
     * 동의 창이 받은 회원 항목 값을 반환합니다 (검증된 입력에서 약관과 정의 버전을 뺀 것).
     *
     * @return array<string, mixed>|null 항목 값. 항목을 받지 않는 창이면 null
     */
    public function memberFieldInput(): ?array
    {
        return $this->usesMemberFieldSet() ? Arr::except($this->validated(), self::NON_FIELD_KEYS) : null;
    }

    /**
     * 검증 전에 낡은 동의 창을 가려냅니다.
     *
     * 실어 보낸 정의 버전이 지금과 다르면 검증 오류보다 먼저 409 로 알린다. 동의 대기는 건드리지 않으므로 창이 항목을 다시
     * 받아 이어 갈 수 있다.
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
     * IdP 가 값을 주어 동의 창에서 잠긴 회원 항목 키를 반환합니다 (요청당 한 번만 읽는다).
     *
     * @return array<int, string> 잠긴 항목 키 (대기가 없거나 다른 브라우저면 빈 배열 — 그 요청은 서비스가 거절한다)
     */
    private function lockedFieldKeys(): array
    {
        return $this->lockedFieldKeys ??= app(LinkVerificationService::class)
            ->consentFieldValues((string) $this->route('token'), $this)['locked'] ?? [];
    }

    /**
     * 칸 이름.
     *
     * @return array<string, string> 칸 => 이름
     */
    public function attributes(): array
    {
        $own = [
            'agree_terms' => __('sirsoft-sso::validation.attributes.agree_terms'),
            'agree_privacy' => __('sirsoft-sso::validation.attributes.agree_privacy'),
            'fields_version' => __('fields.attributes.fields_version'),
        ];

        // 항목을 받는 창이면 회원 항목의 이름(운영자가 고친 이름 포함)을 오류 문구에 쓴다.
        return $this->usesMemberFieldSet() ? $this->withMemberFieldAttributes($own) : $own;
    }

    /**
     * 검증 오류 문구 — 항목을 받는 창이면 운영자가 적은 형식 오류 문구를 싣는다.
     *
     * @return array<string, string> 규칙 => 문구
     */
    public function messages(): array
    {
        return $this->usesMemberFieldSet() ? $this->withMemberFieldMessages() : [];
    }
}
