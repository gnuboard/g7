<?php

namespace Tests\Feature\Identity;

use App\Contracts\Extension\CacheInterface;
use App\Models\IdentityPolicy;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Route scope 자동 매핑 회귀.
 *
 * 핵심 검증: 라우트 코드를 수정하지 않고 정책 DB 만 토글했을 때 EnforceIdentityPolicy 자동 매핑
 * 미들웨어 (bootstrap/app.php 의 API 그룹 글로벌 등록) 가 즉시 enforce 하는지.
 *
 * @since 7.0.0-beta.4
 */
class RouteScopeAutoMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityPolicySeeder::class);
        $this->app->make(CacheInterface::class)->flush();
    }

    /**
     * 정책 disabled 인 상태에서는 라우트가 통과해야 함 (자동 매핑이 무매칭 상태로 통과).
     */
    public function test_disabled_policy_does_not_enforce_via_auto_mapping(): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => false]);

        $response = $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'auto1@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        // 정책이 없으니 일반 가입 흐름 — 201 통과
        $response->assertStatus(201);
    }

    /**
     * 정책 enabled 토글 → 라우트 코드 수정 없이 자동 매핑이 즉시 428 enforce.
     *
     * routes/api.php 의 register 라우트는 명시 ->middleware('identity.policy:KEY') 가 없음.
     * bootstrap/app.php 의 API 그룹 글로벌 EnforceIdentityPolicy 가 라우트 이름
     * (api.auth.register) 을 키로 정책 인덱스 조회 → 매칭 정책 enforce → 428.
     */
    public function test_enabled_policy_enforces_via_auto_mapping_without_route_change(): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => true]);

        $response = $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'auto2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);

        $response->assertStatus(428);
        $response->assertJsonPath('error_code', 'identity_verification_required');
        $response->assertJsonPath('verification.policy_key', 'core.auth.signup_before_submit');
    }

    /**
     * 캐시 무효화 — 정책 update 직후 다음 요청에서 즉시 새 정책 반영 (CacheInterface tag flush).
     *
     * 1) enabled=false 로 시작 → 통과 확인
     * 2) admin 이 enabled=true 로 update → IdentityPolicy 모델 saved 이벤트가 캐시 invalidate
     * 3) 다음 요청에서 즉시 enforce
     */
    public function test_policy_toggle_invalidates_cache_immediately(): void
    {
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->update(['enabled' => false]);

        // 첫 요청 — 정책 없음, 통과
        $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'toggle1@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ])->assertStatus(201);

        // 정책 enable 토글 (모델 saved 이벤트로 캐시 자동 invalidate)
        IdentityPolicy::where('key', 'core.auth.signup_before_submit')->first()
            ->update(['enabled' => true]);

        // 두 번째 요청 — 즉시 enforce
        $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'toggle2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ])->assertStatus(428);
    }

    /**
     * 비밀번호 변경 라우트 (PUT /api/me/password) 도 자동 매핑으로 보호.
     *
     * 시드 정책 core.profile.password_change 의 target 이 'api.me.password' (라우트 이름과 일치).
     * 라우트 정의는 미들웨어 명시 없음 — 자동 매핑이 처리.
     */
    public function test_password_change_route_is_protected_via_auto_mapping(): void
    {
        IdentityPolicy::where('key', 'core.profile.password_change')->update(['enabled' => true]);

        $user = \App\Models\User::factory()->create();
        $token = $user->createToken('test', ['*'], now()->addHour())->plainTextToken;

        // contact_change hook 정책이 enabled=true 인 상태로 user update 호출 시 같이 enforce 되어
        // 노이즈가 됨 — 본 테스트는 password_change 자체만 검증하므로 hook 정책 일시 비활성.
        IdentityPolicy::where('key', 'core.profile.contact_change')->update(['enabled' => false]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/me/password', [
                'current_password' => 'irrelevant',
                'password' => 'newpassword123',
                'password_confirmation' => 'newpassword123',
            ]);

        // 미들웨어가 라우트 진입 전 차단 — 428
        $response->assertStatus(428);
        $response->assertJsonPath('verification.policy_key', 'core.profile.password_change');
    }

    /**
     * 회귀 — hook scope 정책의 conditions (changed_fields 등) 가 평가되어야 한다.
     *
     * 사례: core.profile.contact_change 정책 (scope=hook, target=core.user.before_update,
     * conditions: changed_fields=['email','phone','mobile']) 이 비밀번호 변경 같은
     * email/phone/mobile 미변경 user update 에서도 발화하던 회귀.
     *
     * 원인: EnforceIdentityPolicyListener 와 EnforceIdentityPolicy 미들웨어가
     * resolveByScopeTarget() 으로 정책을 가져와 즉시 enforce 하면서 policyMatchesContext()
     * 검사를 누락. enforce() 자체가 conditions 평가를 자체 보장해야 모든 진입점이 안전.
     */
    public function test_policy_with_unmatched_conditions_does_not_enforce(): void
    {
        IdentityPolicy::where('key', 'core.profile.contact_change')->update(['enabled' => true]);

        // changed_fields 가 ['password'] 만 — contact_change conditions 와 교집합 0
        $policy = IdentityPolicy::where('key', 'core.profile.contact_change')->first();
        $service = $this->app->make(\App\Services\IdentityPolicyService::class);

        $user = \App\Models\User::factory()->create();

        // conditions 미매칭 → no-op (예외 throw X)
        $service->enforce($policy, $user, [
            'changed_fields' => ['password'],
            'origin_type' => 'hook',
            'origin_identifier' => 'core.user.before_update',
        ]);

        $this->assertTrue(true, 'conditions 미매칭 정책은 enforce 안 됨');
    }

    /**
     * 회귀 — conditions 매칭 시에는 정상 enforce.
     */
    public function test_policy_with_matched_conditions_does_enforce(): void
    {
        IdentityPolicy::where('key', 'core.profile.contact_change')->update(['enabled' => true]);
        $policy = IdentityPolicy::where('key', 'core.profile.contact_change')->first();
        $service = $this->app->make(\App\Services\IdentityPolicyService::class);

        $user = \App\Models\User::factory()->create();

        $this->expectException(\App\Exceptions\IdentityVerificationRequiredException::class);
        $service->enforce($policy, $user, [
            'changed_fields' => ['email'],  // contact_change conditions 매칭
            'origin_type' => 'hook',
            'origin_identifier' => 'core.user.before_update',
        ]);
    }

    /**
     * 회귀 — IdentityVerificationRequiredException 은 \Error 자식이어야 한다.
     *
     * 코어/모듈/플러그인 컨트롤러 23+ 곳의 catch (\Exception $e) 패턴이 IDV 예외를 잡아 422 로
     * 강등시키지 않으려면, IDV 예외가 PHP 의 \Error 계층에 있어야 한다 (\Exception 상속 시 자동
     * catch). PHP 언어 차원에서 \Error 와 \Exception 은 별도 계층이며 \Throwable 만 공통 부모.
     *
     * 이 단언이 깨지면 모든 라우트 catch-all 안전망이 동시에 무력화되므로 절대 회귀 금지.
     */
    public function test_identity_exception_is_not_caught_by_generic_exception_catch(): void
    {
        $exception = new \App\Exceptions\IdentityVerificationRequiredException(
            policyKey: 'test',
            purpose: 'test',
        );

        $this->assertInstanceOf(\Throwable::class, $exception, '\\Throwable 는 모든 throw 가능 객체의 공통 부모');
        $this->assertInstanceOf(\Error::class, $exception, '\\Error 자식이어야 controller catch (\\Exception) 우회');
        $this->assertNotInstanceOf(\Exception::class, $exception, '\\Exception 자식이면 모든 catch-all 에 포획되어 422 강등');

        // 실제 catch 시뮬레이션 — catch (\Exception) 가 못 잡고 \Throwable 만 잡음
        $caughtByException = false;
        $caughtByThrowable = false;
        try {
            throw $exception;
        } catch (\Exception $e) {
            $caughtByException = true;
        } catch (\Throwable $e) {
            $caughtByThrowable = true;
        }

        $this->assertFalse($caughtByException, 'catch (\\Exception) 에 잡히면 안 됨 — 모든 controller catch-all 패턴 무력화');
        $this->assertTrue($caughtByThrowable, 'catch (\\Throwable) 에는 잡혀야 함 — Laravel 글로벌 핸들러 시그니처');
    }
}
