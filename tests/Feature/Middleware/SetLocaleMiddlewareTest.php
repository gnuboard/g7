<?php

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SetLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트 라우트 등록 - 현재 locale을 반환
        Route::middleware(['api', 'auth:sanctum'])->get('/api/test-locale', function () {
            return response()->json([
                'locale' => App::getLocale(),
                'message' => __('common.success'),
            ]);
        });

        // 인증 불필요 테스트 라우트
        Route::middleware(['api'])->get('/api/test-locale-public', function () {
            return response()->json([
                'locale' => App::getLocale(),
            ]);
        });
    }

    /**
     * 사용자 언어 설정이 'en'인 경우 API 응답이 영어로 나와야 합니다.
     */
    public function test_api_response_uses_user_language_setting(): void
    {
        $user = User::factory()->create(['language' => 'en']);

        $response = $this->actingAs($user)
            ->getJson('/api/test-locale');

        $response->assertStatus(200)
            ->assertJson(['locale' => 'en']);
    }

    /**
     * 사용자 언어 설정이 'ko'인 경우 API 응답이 한국어로 나와야 합니다.
     */
    public function test_api_response_uses_korean_for_ko_user(): void
    {
        $user = User::factory()->create(['language' => 'ko']);

        $response = $this->actingAs($user)
            ->getJson('/api/test-locale');

        $response->assertStatus(200)
            ->assertJson(['locale' => 'ko']);
    }

    /**
     * Bearer 토큰 인증 시에도 사용자 언어 설정이 적용되어야 합니다.
     */
    public function test_bearer_token_auth_applies_user_language(): void
    {
        $user = User::factory()->create(['language' => 'en']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/test-locale');

        $response->assertStatus(200)
            ->assertJson(['locale' => 'en']);
    }

    /**
     * 인증되지 않은 요청에서 Accept-Language 헤더가 우선됩니다.
     */
    public function test_accept_language_header_used_for_unauthenticated(): void
    {
        $response = $this->withHeaders([
            'Accept-Language' => 'en-US,en;q=0.9',
        ])->getJson('/api/test-locale-public');

        $response->assertStatus(200)
            ->assertJson(['locale' => 'en']);
    }

    /**
     * Accept-Language가 없고 인증도 없으면 기본 언어가 사용됩니다.
     */
    public function test_default_locale_used_when_no_preference(): void
    {
        $response = $this->getJson('/api/test-locale-public');

        $response->assertStatus(200);

        // 기본 로케일은 config('app.locale')에 따름
        $defaultLocale = config('app.locale');
        $this->assertEquals($defaultLocale, $response->json('locale'));
    }

    /**
     * 사용자 언어 설정이 Accept-Language보다 우선합니다.
     */
    public function test_user_language_takes_priority_over_accept_language(): void
    {
        $user = User::factory()->create(['language' => 'ko']);

        $response = $this->actingAs($user)
            ->withHeaders([
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->getJson('/api/test-locale');

        $response->assertStatus(200)
            ->assertJson(['locale' => 'ko']);
    }

    /**
     * 지원되지 않는 언어는 무시되고 기본값이 사용됩니다.
     */
    public function test_unsupported_language_falls_back_to_default(): void
    {
        $user = User::factory()->create(['language' => 'fr']); // 지원되지 않는 언어

        $response = $this->actingAs($user)
            ->getJson('/api/test-locale');

        $response->assertStatus(200)
            ->assertJson(['locale' => 'ko']); // 기본값 사용
    }

    /**
     * 실제 API 엔드포인트에서 다국어 메시지가 올바르게 반환됩니다.
     */
    public function test_actual_api_returns_localized_message(): void
    {
        $user = User::factory()->create(['language' => 'en']);

        $response = $this->actingAs($user)
            ->getJson('/api/auth/user');

        $response->assertStatus(200);

        // 응답 메시지가 영어인지 확인 (한국어 유니코드 문자가 없어야 함)
        $message = $response->json('message');
        // 한글 유니코드 범위: \xAC00-\xD7AF (가-힣)
        $this->assertDoesNotMatchRegularExpression('/[\xAC00-\xD7AF]/u', $message);
    }
}
