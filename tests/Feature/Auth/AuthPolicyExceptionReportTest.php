<?php

namespace Tests\Feature\Auth;

use App\Exceptions\Auth\ExternalStepRequiredException;
use App\Exceptions\Auth\PasswordAuthDisallowedException;
use App\Exceptions\Auth\TwoFactorMeansRequiredException;
use App\Extension\Auth\PasswordAuthDecision;
use App\Extension\HookManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 정상 정책 거절 예외가 오류 로그에 보고되지 않는지 검증합니다 (#798 — F1 M5 T7 실측).
 *
 * 비밀번호 인증 정책 거절(403 `auth.password_auth_disabled`)·외부 인증 추가 단계(409)·2단계 수단 없음(422)은
 * 사용자에게 안내로 답하는 정상 결과인데, 세 예외가 `RuntimeException` 이라 전역 렌더러가 응답을 만들어도
 * 보고기가 laravel 로그에 ERROR + 스택으로 남겼다. 같은 성격의 기존 인증 예외(`AccountLockedException` 등)는
 * `HttpException` 이라 보고되지 않는다 — 세 예외도 보고하지 않는다(`ShouldntReport`).
 *
 * @scenario-manifest tests/scenarios/auth-policy-exception-report.yaml
 */
class AuthPolicyExceptionReportTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 회원 기준으로 비밀번호 설정을 막는 판정 필터를 둡니다.
     *
     * @return void 반환값 없음
     */
    private function denySetPassword(): void
    {
        HookManager::addFilter('core.auth.password_auth_decision', function ($decision, array $subject) {
            return in_array($subject['purpose'], ['set_password', 'admin_set_password'], true)
                ? PasswordAuthDecision::deny(PasswordAuthDecision::BASIS_USER, ['ko' => '회사 계정', 'en' => 'Company account'], '/plugins/test/login/corp')
                : $decision;
        });
    }

    /**
     * 시험이 둔 판정 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.auth.password_auth_decision');

        parent::tearDown();
    }

    /**
     * 마이페이지 비밀번호 변경이 정책으로 거절되면 403 안내만 하고 보고하지 않는다.
     *
     * @scenario path=me_password
     *
     * @effects policy_denial_not_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 마이페이지_비밀번호_변경_거절은_보고하지_않는다(): void
    {
        Exceptions::fake();
        $this->denySetPassword();
        $user = $this->makeMember();

        $this->withToken($this->issueToken($user))->putJson('/api/me/password', [
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertForbidden()->assertJsonPath('errors.code', 'auth.password_auth_disabled');

        Exceptions::assertNotReported(PasswordAuthDisallowedException::class);
    }

    /**
     * 정보 수정(`PUT /api/me`)으로 비밀번호를 함께 바꾸다 거절돼도 보고하지 않는다.
     *
     * @scenario path=me_profile
     *
     * @effects policy_denial_not_reported
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정보_수정의_비밀번호_거절도_보고하지_않는다(): void
    {
        Exceptions::fake();
        $this->denySetPassword();
        $user = $this->makeMember();

        $this->withToken($this->issueToken($user))->putJson('/api/me', [
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => 'Passw0rd!old',
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
        ])->assertForbidden()->assertJsonPath('errors.code', 'auth.password_auth_disabled');

        Exceptions::assertNotReported(PasswordAuthDisallowedException::class);
    }

    /**
     * 같은 묶음의 세 예외는 보고 대상이 아니다(로그인·관리자 지정·2단계 경로 공통).
     *
     * @scenario path=handler
     *
     * @effects policy_exceptions_not_reportable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정책_예외_세_개는_보고_대상이_아니다(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(new PasswordAuthDisallowedException(PasswordAuthDecision::deny())));
        $this->assertFalse($handler->shouldReport(new ExternalStepRequiredException('/plugins/test/step')));
        $this->assertFalse($handler->shouldReport(new TwoFactorMeansRequiredException));
        // 대조군: 일반 런타임 예외는 그대로 보고된다
        $this->assertTrue($handler->shouldReport(new \RuntimeException('unexpected')));
    }
}
