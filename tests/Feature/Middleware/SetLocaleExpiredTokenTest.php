<?php

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * SetLocale 미들웨어가 만료된 Sanctum 토큰을 인정하지 않는지 검증합니다.
 *
 * 결함(㉗): resolveUser() 가 토큰의 만료(expires_at) 여부를 검사하지 않아
 * 만료된 토큰으로도 그 회원의 로케일이 해석되었다. OptionalSanctumMiddleware 와
 * 동일한 만료 검사를 이식하여 만료 토큰은 guest(null) 로 취급한다.
 */
class SetLocaleExpiredTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 인증 불필요 공개 라우트 — auth:sanctum 이 만료 토큰을 먼저 차단하지 않도록
        // SetLocale(api 그룹) 단독 동작을 관찰한다.
        Route::middleware(['api'])->get('/api/test-expired-locale', function () {
            return response()->json([
                'locale' => App::getLocale(),
            ]);
        });
    }

    /**
     * 만료된 토큰으로 요청하면 그 회원의 언어 설정을 따르지 않고
     * guest 로케일(Accept-Language)로 폴백해야 합니다.
     *
     * @scenario case=expired_token_locale
     *
     * @effects expired_token_treated_as_guest_for_locale
     */
    public function test_expired_token_does_not_resolve_member_locale(): void
    {
        $user = User::factory()->create(['language' => 'ko']);
        $newToken = $user->createToken('test-token');
        $plainTextToken = $newToken->plainTextToken;

        // 토큰 만료 시각을 과거로 직접 세팅 (Sanctum 기본 expiration 은 null 이라 미설정됨)
        PersonalAccessToken::findToken($plainTextToken)->update([
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainTextToken,
            'Accept-Language' => 'en-US,en;q=0.9',
        ])->getJson('/api/test-expired-locale');

        $response->assertStatus(200);

        // 회원 언어(ko)를 따르지 않고 Accept-Language(en) 로 폴백해야 한다.
        $this->assertNotSame('ko', $response->json('locale'));
        $this->assertSame('en', $response->json('locale'));
    }

    /**
     * 유효한 토큰은 그 회원의 언어 설정을 따라야 합니다 (기존 동작 유지).
     *
     * @scenario case=valid_token_locale
     *
     * @effects valid_token_applies_member_locale
     */
    public function test_valid_token_resolves_member_locale(): void
    {
        $user = User::factory()->create(['language' => 'ko']);
        $plainTextToken = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$plainTextToken,
            'Accept-Language' => 'en-US,en;q=0.9',
        ])->getJson('/api/test-expired-locale');

        $response->assertStatus(200);

        // 회원 언어(ko)가 Accept-Language(en)보다 우선한다.
        $this->assertSame('ko', $response->json('locale'));
    }
}
