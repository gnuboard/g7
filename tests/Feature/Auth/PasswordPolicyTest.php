<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Rules\PasswordPolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * 비밀번호 정책 배선 테스트 (C4)
 *
 * `security.password_min_length` / `security.require_password_special_char` 는 defaults.json
 * 에만 존재하고 소비자·저장 규칙·UI 가 전부 없는 완전 고아였습니다. 동시에 7개 FormRequest 가
 * 각자 리터럴을 들고 있어 `LoginRequest` 만 `min:6` 으로 어긋나 있었습니다.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 검증 규칙 확장 훅을 비웁니다.
     *
     * `rules()` 는 확장이 규칙을 덧붙이는 필터 훅을 함께 실행한다. 이 테스트가 보려는 것은
     * 코어가 선언한 password 규칙이므로, 확장 리스너 의존이 끼어들지 않도록 비운다
     * (모듈 프로바이더 등록 여부가 실행 순서에 따라 달라져 결과가 흔들리는 것도 함께 막는다).
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'core.auth.register_validation_rules',
            'core.auth.reset_password_validation_rules',
            'core.user.change_password_validation_rules',
            'core.user.create_validation_rules',
            'core.user.update_validation_rules',
            'core.user.update_profile_validation_rules',
        ] as $filter) {
            HookManager::clearFilter($filter);
        }
    }

    /**
     * 보안 환경설정 값을 테스트용으로 덮어씁니다.
     *
     * @param  array  $values  덮어쓸 보안 설정 값
     */
    private function setSecuritySettings(array $values): void
    {
        $existing = (array) config('g7_settings.core.security', []);
        config(['g7_settings.core.security' => array_merge($existing, $values)]);
    }

    /**
     * @test
     *
     * @effects password_shorter_than_setting_is_rejected
     */
    public function 설정한_최소_길이가_회원가입에_적용된다(): void
    {
        $this->setSecuritySettings(['password_min_length' => 12]);

        // 11자 — 수정 전에는 min:8 리터럴이라 통과했다
        $validator = $this->validatePassword('abcdefghijk');

        $this->assertTrue($validator->errors()->has('password'));
    }

    /**
     * @test
     *
     * @effects password_meeting_setting_is_accepted
     */
    public function 설정한_최소_길이를_만족하면_통과한다(): void
    {
        $this->setSecuritySettings(['password_min_length' => 12]);

        $validator = $this->validatePassword('abcdefghijkl');

        $this->assertFalse($validator->errors()->has('password'));
    }

    /**
     * @test
     *
     * @effects special_char_requirement_is_enforced_when_enabled
     */
    public function 특수문자_필수_설정이_적용된다(): void
    {
        $this->setSecuritySettings([
            'password_min_length' => 8,
            'require_password_special_char' => true,
        ]);

        $validator = $this->validatePassword('abcdefgh');

        $this->assertTrue($validator->errors()->has('password'));
    }

    /**
     * @test
     *
     * @effects login_request_has_no_length_rule
     */
    public function 로그인_규칙에는_길이_제한이_없다(): void
    {
        // 로그인은 기존 비밀번호를 대조하는 지점이며, 길이 규칙은 정책 미달 사실을
        // 미인증 공격자에게 누설하는 oracle 이 된다. 수정 전에는 min:6 이 있었다.
        $rules = (new LoginRequest)->rules();

        $this->assertIsString($rules['password']);
        $this->assertStringNotContainsString('min:', $rules['password']);
    }

    /**
     * @test
     *
     * @effects policy_rule_reads_min_length_from_settings, policy_rule_reads_special_requirement_from_settings
     */
    public function 정책_규칙이_설정값을_읽는다(): void
    {
        $this->setSecuritySettings(['password_min_length' => 20]);

        $rule = PasswordPolicy::rule();

        // Password 규칙 내부 min 값 확인 (리플렉션 — 공개 접근자가 없음)
        $reflection = new \ReflectionProperty($rule, 'min');
        $this->assertSame(20, $reflection->getValue($rule));
    }

    /**
     * @test
     *
     * @effects empty_security_settings_fall_back_to_defaults
     */
    public function 설정이_비어도_기본값으로_동작한다(): void
    {
        $this->setSecuritySettings(['password_min_length' => 0]);

        $rule = PasswordPolicy::rule();

        $reflection = new \ReflectionProperty($rule, 'min');
        $this->assertSame(PasswordPolicy::DEFAULT_MIN_LENGTH, $reflection->getValue($rule));
    }

    /**
     * @test
     *
     * @effects all_password_setting_paths_share_one_rule
     */
    public function 비로그인_비밀번호_요청은_모두_정책을_사용한다(): void
    {
        $this->setSecuritySettings(['password_min_length' => 17]);

        // 비밀번호를 **설정**하는 모든 지점. 한 곳이라도 리터럴로 남으면 그 화면이 정책
        // 우회로가 된다 (관리자 사용자 수정 화면이 실제로 min:8 리터럴로 남아 있었다).
        $requestClasses = [
            RegisterRequest::class,
            ResetPasswordRequest::class,
            ChangePasswordRequest::class,
            CreateUserRequest::class,
            UpdateProfileRequest::class,
            UpdateUserRequest::class,
        ];

        foreach ($requestClasses as $requestClass) {
            $rules = (new $requestClass)->rules();
            $passwordRule = collect($rules['password'])->first(fn ($rule) => $rule instanceof Password);

            $this->assertNotNull(
                $passwordRule,
                "{$requestClass} 의 password 규칙이 PasswordPolicy 를 사용하지 않습니다."
            );

            $reflection = new \ReflectionProperty($passwordRule, 'min');
            $this->assertSame(
                17,
                $reflection->getValue($passwordRule),
                "{$requestClass} 가 설정값 대신 리터럴 최소 길이를 사용합니다."
            );
        }
    }

    /**
     * @test
     *
     * @effects saved_security_settings_are_visible_on_reload
     */
    public function 두_보안_설정_키가_저장_후_재조회에_반영된다(): void
    {
        // 저장 화이트리스트에 두 키가 없으면 저장 요청이 조용히 탈락해 UI 가 값을 잃는다.
        app(ConfigRepositoryInterface::class)->saveCategory('security', [
            'password_min_length' => 14,
            'require_password_special_char' => true,
        ]);

        $saved = app(ConfigRepositoryInterface::class)->getCategory('security');

        $this->assertSame(14, (int) $saved['password_min_length']);
        $this->assertTrue((bool) $saved['require_password_special_char']);
    }

    /**
     * 회원가입 요청의 비밀번호 규칙만 실행합니다.
     *
     * HTTP 엔드포인트를 거치면 확장 리스너 의존이 끼어들어 정책 검증과 무관한 실패가 난다.
     *
     * @param  string  $password  검사할 비밀번호
     * @return Validator 실행된 검증기
     */
    private function validatePassword(string $password)
    {
        $rules = (new RegisterRequest)->rules();

        $validator = $this->app['validator']->make(
            ['password' => $password, 'password_confirmation' => $password],
            ['password' => $rules['password']]
        );
        $validator->passes();

        return $validator;
    }
}
