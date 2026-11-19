<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use App\Fields\FieldContext;
use App\Http\Requests\Concerns\ChecksPasswordReuse;
use App\Http\Requests\Concerns\UsesMemberFields;
use App\Http\Requests\Concerns\ValidatesDisplayNameChoice;
use App\Models\User;
use App\Rules\PasswordPolicy;
use App\Services\UserLockedFieldsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * 사용자 프로필 업데이트 요청 클래스
 *
 * 일반 사용자가 자신의 프로필 정보를 수정할 때 사용되는 validation 규칙을 정의합니다.
 * 관리자용 UpdateUserRequest와 달리 사용자 본인만 수정할 수 있는 필드들로 제한됩니다.
 */
class UpdateProfileRequest extends FormRequest
{
    use ChecksPasswordReuse;
    use UsesMemberFields;
    use ValidatesDisplayNameChoice;

    /** 종전 규칙의 키 순서 — 오류가 여럿일 때 응답에 실리는 첫 문구가 달라지지 않게 유지한다 */
    private const LEGACY_RULE_ORDER = ['name', 'nickname', 'email', 'password', 'current_password', 'language', 'country', 'timezone', 'homepage', 'mobile', 'phone', 'zipcode', 'address', 'address_detail', 'signature', 'bio'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 항상 true (권한은 미들웨어에서 검증)
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
        $userId = Auth::id();

        // 회원 항목 가운데 본인이 고칠 수 있는 항목의 규칙은 운영자 설정에서 온다 — 아래는 항목이 아닌 고정 규칙이다.
        $rules = $this->withMemberFieldRules([
            // 기본 정보
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($userId)],
            'password' => ['nullable', 'confirmed', ...PasswordPolicy::rules($this->user()?->email)],
            // 비밀번호가 없는 회원(외부 인증 전용)은 처음 정하는 것이라 확인할 현재 비밀번호가 없다(보낸 값은
            // prepareForValidation 이 버린다 — 이 규칙은 값이 남는 일이 없게 두는 방어선).
            'current_password' => $this->userHasPassword()
                ? ['required_with:password', 'current_password']
                : ['prohibited'],
        ], self::LEGACY_RULE_ORDER);

        // 외부(회사 계정)가 관리하는 칸은 현재 값과 같아야 한다 — 요청에 그 칸이 없으면 통과(7.2.0)
        $user = $this->user();

        // 표시 이름 종류 선택(선택 칸) — 권한이 없거나 허용하지 않은 종류면 검증 오류. 닉네임이 필요한 종류로 보이게 되면 닉네임이 필수가 된다.
        $rules = $this->withDisplayNameChoiceRules($rules, $user instanceof User ? $user : null);

        $rules = app(UserLockedFieldsService::class)->applyRules($rules, $user instanceof User ? $user : null, UserLockedFieldsService::SURFACE_PROFILE);

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.update_profile_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // 기본 정보
            'name.required' => __('user.validation.name_required'),
            'name.string' => __('user.validation.name_string'),
            'name.max' => __('user.validation.name_max'),
            'nickname.string' => __('user.validation.nickname_string'),
            'nickname.max' => __('user.validation.nickname_max'),
            'email.required' => __('user.validation.email_required'),
            'email.email' => __('user.validation.email_invalid'),
            'email.unique' => __('user.validation.email_unique'),
            'email.max' => __('user.validation.email_max'),
            'password.confirmed' => __('user.validation.password_confirmed'),
            'password.min' => __('user.validation.password_min'),
            'current_password.required_with' => __('user.validation.current_password_required'),
            'current_password.current_password' => __('user.validation.current_password_invalid'),
            'current_password.prohibited' => __('user.validation.current_password_not_set'),
            'language.in' => __('user.validation.language_invalid'),
            'country.size' => __('user.validation.country_size'),
            'country.alpha' => __('user.validation.country_alpha'),
            'timezone.in' => __('user.validation.timezone_invalid'),

            // 연락처 정보
            'homepage.url' => __('user.validation.homepage_url'),
            'homepage.max' => __('user.validation.homepage_max'),
            'mobile.max' => __('user.validation.mobile_max'),
            'mobile.regex' => __('user.validation.mobile_regex'),
            'phone.max' => __('user.validation.phone_max'),
            'phone.regex' => __('user.validation.phone_regex'),

            // 주소 정보
            'zipcode.max' => __('user.validation.zipcode_max'),
            'address.max' => __('user.validation.address_max'),
            'address_detail.max' => __('user.validation.address_detail_max'),

            // 추가 정보
            'signature.max' => __('user.validation.signature_max'),
            'bio.max' => __('user.validation.bio_max'),
        ];
    }

    /**
     * 내 정보 수정 표면의 회원 항목 문맥을 반환합니다.
     *
     * @return FieldContext 문맥
     */
    protected function memberFieldContext(): FieldContext
    {
        $user = $this->user();
        $user = $user instanceof User ? $user : null;

        return FieldContext::for(FieldContext::SURFACE_SELF_UPDATE, $user, $user);
    }

    /**
     * 옛 화면 호환을 받는다 — 정의 버전(`fields_version`)을 싣지 않은 내 정보 수정은 회원 항목 조각이 없는 옛 화면(7.1.x 템플릿)에서 왔다고 본다.
     *
     * 그 요청은 운영자 추가 항목과 확장 선언 항목의 필수 규칙을 「값을 보냈을 때만」으로 늦춘다. 보내지 않은 항목은 저장된 값이 그대로 남고,
     * 비어 있는 필수 항목은 종전대로 추가 정보 입력 요구가 받는다.
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
     * 검증 속성명 — 운영자 추가 항목과 확장 항목의 이름을 요청 로케일로 싣는다.
     *
     * @return array<string, string> 속성 => 이름
     */
    public function attributes(): array
    {
        return $this->withMemberFieldAttributes(['display_name_kind' => __('validation.attributes.display_name_kind')]);
    }

    /**
     * 요청 회원에게 비밀번호가 있는지 반환합니다 (회원을 알 수 없으면 있다고 본다 — 종전 규칙 유지).
     *
     * @return bool 비밀번호가 있으면 true
     */
    private function userHasPassword(): bool
    {
        $user = $this->user();

        return ! $user instanceof User || $user->hasPassword();
    }

    /**
     * 비밀번호를 함께 바꾸면 재사용 금지 검사를 현재 비밀번호 확인 **뒤에만** 붙입니다 (추측 오라클 차단).
     *
     * @param  Validator  $validator  검증기
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $this->appendPasswordReuseCheck(
            $validator,
            fn () => $this->user() instanceof User ? $this->user() : null,
            ['current_password'],
        );
    }

    /**
     * Prepare the data for validation.
     *
     * @return void 입력을 제자리에서 고친다
     */
    protected function prepareForValidation(): void
    {
        // 비밀번호 없는 회원이 보낸 현재 비밀번호(옛 화면이 칸을 채워 보낸 값)는 대조할 대상이 없어 버린다 — 새 화면이
        // 보내지 않은 것과 같은 상태가 된다. 비밀번호를 함께 바꾸면 본인 확인은 컨트롤러가 거는 본인인증 정책(428)이 맡는다.
        if (! $this->userHasPassword()) {
            $this->getInputSource()->remove('current_password');
        }

        // 빈 password 필드 제거 (null로 변환하여 validation에서 무시되도록)
        if ($this->password === '') {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }
    }
}
