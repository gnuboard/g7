<?php

namespace Tests\Feature\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\IdentityPolicyProviderUnavailableException;
use App\Exceptions\IdentityVerificationRequiredException;
use App\Http\Middleware\EnforceIdentityPolicy;
use App\Models\IdentityPolicy;
use App\Models\IdentityVerificationLog;
use App\Services\IdentityPolicyService;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 본인인증 결과(verification_token)가 작업 1건에 1번만 인정되는지 검증한다 (KVE 동형 B2).
 *
 * 「작업 1건 = HTTP 요청 1건」 — 같은 요청 안에서는 다시 인정하고, 422·428 로 끝난 요청은 소비를 되돌린다.
 */
class IdentityTokenConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = 'core.auth.password_reset';

    private const EMAIL = 'consume@example.test';

    /**
     * 정책을 켜고 확인 완료된 결과를 하나 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityPolicySeeder::class);
        IdentityPolicy::where('key', self::POLICY)->update(['enabled' => true, 'grace_minutes' => 0, 'provider_id' => null]);
    }

    /**
     * 확인 완료·미소비 결과를 만듭니다.
     *
     * @return IdentityVerificationLog 결과 로그
     */
    private function verifiedLog(): IdentityVerificationLog
    {
        return IdentityVerificationLog::create([
            'id' => (string) Str::uuid(),
            'provider_id' => 'g7:core.mail',
            'purpose' => 'password_reset',
            'channel' => 'email',
            'target_hash' => hash('sha256', self::EMAIL),
            'status' => IdentityVerificationStatus::Verified->value,
            'verification_token' => 'tok-'.Str::random(24),
            'verified_at' => now()->subSeconds(2),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * 토큰을 실은 요청을 만듭니다.
     *
     * @param  string  $token  확인 토큰
     * @return Request 요청
     */
    private function requestWith(string $token): Request
    {
        return Request::create('/api/test', 'POST', ['email' => self::EMAIL, 'verification_token' => $token]);
    }

    /**
     * 미들웨어를 지나 컨트롤러가 주어진 상태 코드로 끝나는 요청을 흉내 냅니다.
     *
     * @param  Request  $request  요청
     * @param  int  $status  컨트롤러 응답 상태
     * @return TestResponse 단언용으로 감싼 응답
     */
    private function pass(Request $request, int $status = 200): TestResponse
    {
        return TestResponse::fromBaseResponse(
            app(EnforceIdentityPolicy::class)->handle($request, fn () => new Response('done', $status), self::POLICY)
        );
    }

    /**
     * 작업이 성공하면 결과가 소비되어 다음 요청은 다시 본인인증을 요구한다 (수정 전: 계속 통과).
     *
     * @effects token_consumed_once, next_request_requires_verification
     *
     * @return void 반환값 없음
     */
    public function test_next_request_requires_verification_after_success(): void
    {
        $log = $this->verifiedLog();

        $this->pass($this->requestWith($log->verification_token))->assertStatus(200);
        $this->assertNotNull($log->fresh()->consumed_at);

        $this->expectException(IdentityVerificationRequiredException::class);
        $this->pass($this->requestWith($log->verification_token));
    }

    /**
     * 같은 요청 안에서 다시 검사하면(예: 라우트 정책 + 훅 정책) 같은 결과를 다시 인정한다.
     *
     * @effects same_request_reaccepts_token
     *
     * @return void 반환값 없음
     */
    public function test_same_request_reaccepts_token(): void
    {
        $log = $this->verifiedLog();
        $request = $this->requestWith($log->verification_token);
        $policy = IdentityPolicy::where('key', self::POLICY)->firstOrFail();
        $service = app(IdentityPolicyService::class);

        $service->enforcePolicyForRequest($policy, $request);
        $service->enforcePolicyForRequest($policy, $request);

        $this->assertNotNull($log->fresh()->consumed_at);
    }

    /**
     * 작업이 입력 오류(422)로 끝나면 결과를 돌려줘 고쳐서 다시 보낼 수 있다.
     *
     * @effects failed_request_releases_token
     *
     * @return void 반환값 없음
     */
    public function test_validation_failure_releases_token(): void
    {
        $log = $this->verifiedLog();

        $this->pass($this->requestWith($log->verification_token), 422)->assertStatus(422);
        $this->assertNull($log->fresh()->consumed_at);

        $this->pass($this->requestWith($log->verification_token))->assertStatus(200);
        $this->assertNotNull($log->fresh()->consumed_at);
    }

    /**
     * 다른 정책이 428 을 내면 이미 인정한 결과도 돌려준다.
     *
     * @effects failed_request_releases_token
     *
     * @return void 반환값 없음
     */
    public function test_policy_exception_releases_token(): void
    {
        $log = $this->verifiedLog();
        $request = $this->requestWith($log->verification_token);

        try {
            app(EnforceIdentityPolicy::class)->handle($request, function () {
                throw new IdentityVerificationRequiredException('other.policy', 'sensitive_action');
            }, self::POLICY);
            $this->fail('428 이 전파되어야 합니다.');
        } catch (IdentityVerificationRequiredException) {
            // 기대한 예외
        }

        $this->assertNull($log->fresh()->consumed_at);
    }

    /**
     * 뒤 정책이 「지정 수단 사용 불가(503)」로 끝나도 앞 정책이 인정한 결과를 돌려준다 — 수단이 복구된 뒤
     * 본인인증을 처음부터 다시 하지 않아도 되게 한다(V5).
     *
     * @effects failed_request_releases_token
     *
     * @return void 반환값 없음
     */
    public function test_provider_unavailable_exception_releases_token(): void
    {
        $log = $this->verifiedLog();
        $request = $this->requestWith($log->verification_token);

        try {
            app(EnforceIdentityPolicy::class)->handle($request, function () {
                throw new IdentityPolicyProviderUnavailableException('other.policy', 'sirsoft-verification_nhnkcp');
            }, self::POLICY);
            $this->fail('503 예외가 전파되어야 합니다.');
        } catch (IdentityPolicyProviderUnavailableException) {
            // 기대한 예외
        }

        $this->assertNull($log->fresh()->consumed_at);
    }

    /**
     * 라우트 정책이 인정한 뒤 컨트롤러 안의 훅 정책이 「지정 수단 사용 불가(503)」로 끝나도 결과를 돌려준다(V5 의 훅 경로).
     *
     * 실제 라우터를 지난다 — 라우터 파이프라인은 `$next` 안에서 던진 예외를 응답으로 바꿔 돌려주므로, 미들웨어는
     * 예외가 아니라 503 응답을 받는다(직접 `handle()` 을 부르는 위 시험으로는 이 경로가 재지지 않는다).
     *
     * @effects failed_request_releases_token
     *
     * @return void 반환값 없음
     */
    public function test_hook_policy_provider_unavailable_inside_route_releases_token(): void
    {
        $log = $this->verifiedLog();
        Route::middleware('identity.policy:'.self::POLICY)
            ->post('/__kve-v5-hook-503', function () {
                throw new IdentityPolicyProviderUnavailableException('other.hook_policy', 'sirsoft-verification_nhnkcp');
            });

        $this->postJson('/__kve-v5-hook-503', ['email' => self::EMAIL, 'verification_token' => $log->verification_token])
            ->assertStatus(503);

        $this->assertNull($log->fresh()->consumed_at, '훅 정책 503 으로 끝난 작업은 앞 정책이 인정한 결과를 돌려줘야 한다');
    }

    /**
     * 훅 정책이 트랜잭션 안에서 소비한 뒤 작업이 롤백되면 소비도 함께 되돌아간다.
     *
     * @effects rollback_releases_token
     *
     * @return void 반환값 없음
     */
    public function test_transaction_rollback_releases_hook_consumption(): void
    {
        $log = $this->verifiedLog();
        $policy = IdentityPolicy::where('key', self::POLICY)->firstOrFail();
        $this->app->instance('request', $this->requestWith($log->verification_token));

        try {
            DB::transaction(function () use ($policy, $log): void {
                app(IdentityPolicyService::class)->enforce($policy, null, [
                    'target_email' => self::EMAIL,
                    'verification_token' => $log->verification_token,
                ]);
                $this->assertNotNull($log->fresh()->consumed_at);

                throw new \RuntimeException('작업 실패');
            });
        } catch (\RuntimeException) {
            // 롤백
        }

        $this->assertNull($log->fresh()->consumed_at);
    }
}
