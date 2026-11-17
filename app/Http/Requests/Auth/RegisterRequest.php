<?php

namespace App\Http\Requests\Auth;

use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Helpers\ResponseHelper;
use App\Http\Requests\Concerns\UsesMemberFields;
use App\Http\Requests\Concerns\ValidatesDisplayNameChoice;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\IdentityClaimsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    use UsesMemberFields;
    use ValidatesDisplayNameChoice;

    /** 종전 규칙의 키 순서 — 오류가 여럿일 때 응답에 실리는 첫 문구가 달라지지 않게 유지한다 */
    private const LEGACY_RULE_ORDER = ['name', 'nickname', 'email', 'password', 'mobile', 'phone', 'language', 'agree_terms', 'agree_privacy'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // 회원 항목(이름, 닉네임, 휴대폰 등과 운영자 추가 항목)의 규칙은 운영자 설정에서 온다 — 아래는 항목이 아닌 고정 규칙이다.
        $rules = $this->withMemberFieldRules([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            // 작성 규칙(조합·추측 방지)까지 함께 — 아이디 판정은 입력한 이메일로 한다.
            'password' => ['required', 'string', 'confirmed', ...PasswordPolicy::rules($this->identifierEmail())],
            'agree_terms' => 'accepted',
            'agree_privacy' => 'accepted',
            // 화면이 받은 가입 항목의 정의 버전 — 낡은 화면 판정에만 쓰고 저장하지 않는다.
            'fields_version' => ['nullable', 'string', 'max:64'],
            // 본인인증을 마치고 온 가입의 토큰 — 가입 전 본인인증 정책을 켠 사이트에서는 아래 필터가 필수 규칙으로 바꾼다.
            // 정책을 켜지 않은 사이트에서도 받아 두어, 인증 값으로 채운 항목의 잠금 기록이 가입 뒤에 남게 한다.
            'verification_token' => ['nullable', 'string', 'max:255'],
        ], self::LEGACY_RULE_ORDER);

        // 표시 이름 종류 선택(선택 칸) — 권한이 없거나 허용하지 않은 종류면 검증 오류. 닉네임이 필요한 종류를 고르면 닉네임이 필수가 된다.
        $rules = $this->withDisplayNameChoiceRules($rules, null);

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.auth.register_validation_rules', $rules, $this);
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
     * 옛 화면 호환을 받는다 — 정의 버전(`fields_version`)을 싣지 않은 가입은 회원 항목 조각이 없는 옛 화면(7.1.x 템플릿)에서 왔다고 본다.
     *
     * 그 가입은 옛 화면에 입력칸이 없던 항목(운영자 추가 항목, 확장 선언 항목, 7.1.x 가입 화면에 없던 코어 칸)의 필수 규칙을
     * 「값을 보냈을 때만」으로 늦춰 통과시킨다. 그 회원에게 추가 정보 입력 대기 표시는 남기지 않는다 — 빈 항목은 템플릿을 7.2.0 으로
     * 올린 뒤 운영자가 채운다.
     *
     * @return bool 항상 true
     */
    protected function acceptsLegacyMemberFieldScreen(): bool
    {
        return true;
    }

    /**
     * 검증을 마친 뒤 옛 화면 호환으로 건너뛴 항목을 로그에 남깁니다.
     *
     * @return void 반환값 없음
     */
    protected function passedValidation(): void
    {
        $this->noteLegacyMemberFieldScreen();
    }

    /**
     * 검증 전에 낡은 화면을 가려내고 옛 요청 키를 정식 키로 옮깁니다.
     *
     * - 화면이 실어 보낸 정의 버전이 지금과 다르면 검증 오류보다 먼저 409 로 알린다 — 운영자가 항목을 바꾼 뒤
     *   낡은 화면에서 제출하면 「없는 칸이 필수」 같은 엉뚱한 오류가 뜨기 때문이다. 버전을 싣지 않은 요청은 그대로 받는다.
     * - 확장이 선언한 옛 요청 키(`legacy_keys`)는 정식 키가 비어 있을 때만 정식 키로 복사한다.
     * - 본인인증 토큰이 실려 있으면 「인증 값으로 채우고 잠금」 항목의 입력을 인증 값으로 덮어쓴다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $registry = app(FieldRegistry::class);

        $version = $this->input('fields_version');
        if (is_string($version) && $version !== '') {
            $current = $registry->version(FieldSet::CORE_USER);
            if (! hash_equals($current, $version)) {
                $response = ResponseHelper::error('fields.errors.signup_fields_changed', 409, null, [], 'core', ['fields_version' => $current]);
                $response->setData([...$response->getData(true), 'error_code' => 'fields_changed']);

                throw new HttpResponseException($response);
            }
        }

        $copied = [];
        foreach ($registry->forSurface(FieldSet::CORE_USER, $this->memberFieldContext()) as $definition) {
            $attribute = $registry->attributeFor($definition);
            if ($this->filled($attribute)) {
                continue;
            }
            foreach ($definition->legacy_keys ?? [] as $legacyKey) {
                if (is_string($legacyKey) && $this->has($legacyKey)) {
                    data_set($copied, $attribute, $this->input($legacyKey));
                    break;
                }
            }
        }
        if ($copied !== []) {
            $this->merge(array_replace_recursive($this->only(array_keys($copied)), $copied));
        }

        // 본인인증을 마치고 온 가입 — 「인증 값으로 채우고 잠금」 항목의 입력을 인증 값으로 덮어쓴다.
        // 입력이 인증 값과 달라도 오류가 아니라 인증 값이 원본이다(화면을 고쳐 보낸 값을 받지 않는다).
        $token = $this->input('verification_token');
        if (is_string($token) && $token !== '') {
            $overrides = [];
            foreach (app(IdentityClaimsService::class)->signupOverrides($token) as $attribute => $value) {
                data_set($overrides, $attribute, $value);
            }
            if ($overrides !== []) {
                $this->merge(array_replace_recursive($this->only(array_keys($overrides)), $overrides));
            }
        }
    }

    /**
     * 아이디(이메일 앞부분) 포함 판정에 쓸 이메일을 반환합니다.
     *
     * @return string|null 입력 이메일 (없으면 null)
     */
    private function identifierEmail(): ?string
    {
        $email = $this->input('email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->withMemberFieldMessages([
            'name.required' => __('validation.auth.name.required'),
            'name.max' => __('validation.auth.name.max'),
            'nickname.max' => __('validation.auth.nickname.max'),
            'email.required' => __('validation.auth.email.required'),
            'email.email' => __('validation.auth.email.email'),
            'email.unique' => __('validation.auth.email.unique'),
            'password.required' => __('validation.auth.password.required'),
            'password.min' => __('validation.auth.password.min'),
            'password.confirmed' => __('validation.auth.password.confirmed'),
            'mobile.max' => __('validation.auth.mobile.max'),
            'mobile.regex' => __('validation.auth.mobile.regex'),
            'phone.max' => __('validation.auth.phone.max'),
            'phone.regex' => __('validation.auth.phone.regex'),
            'agree_terms.accepted' => __('validation.auth.agree_terms.accepted'),
            'agree_privacy.accepted' => __('validation.auth.agree_privacy.accepted'),
        ]);
    }

    /**
     * 검증 속성명 — 운영자 추가 항목과 확장 항목의 이름을 요청 로케일로 싣는다.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return $this->withMemberFieldAttributes(['display_name_kind' => __('validation.attributes.display_name_kind')]);
    }
}
