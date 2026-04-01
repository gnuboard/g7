<?php

namespace Tests\Feature\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class OptionalSanctumMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // 테스트용 사용자 생성
        $this->user = User::factory()->create();

        // 테스트 라우트 등록
        Route::middleware(['api', 'optional.sanctum'])->get('/api/test-optional-sanctum', function () {
            $user = request()->user();

            return response()->json([
                'authenticated' => $user !== null,
                'user_id' => $user?->id,
            ]);
        });
    }

    /**
     * 토큰 없이 요청하면 guest로 통과해야 합니다.
     */
    public function test_passes_without_token_as_guest(): void
    {
        $response = $this->getJson('/api/test-optional-sanctum');

        $response->assertStatus(200)
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    /**
     * 유효한 토큰으로 요청하면 인증된 사용자로 처리해야 합니다.
     */
    public function test_authenticates_with_valid_token(): void
    {
        // 토큰 생성
        $token = $this->user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/test-optional-sanctum');

        $response->assertStatus(200)
            ->assertJson([
                'authenticated' => true,
                'user_id' => $this->user->id,
            ]);
    }

    /**
     * 무효한 토큰으로 요청하면 401 응답을 받아야 합니다.
     */
    public function test_returns_401_for_invalid_token(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer invalid-token-123',
        ])->getJson('/api/test-optional-sanctum');

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * 이미 인증된 사용자는 그냥 통과해야 합니다.
     */
    public function test_passes_if_already_authenticated(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/test-optional-sanctum');

        $response->assertStatus(200)
            ->assertJson([
                'authenticated' => true,
                'user_id' => $this->user->id,
            ]);
    }

    /**
     * 빈 문자열 토큰은 guest로 처리해야 합니다.
     */
    public function test_empty_string_token_passes_as_guest(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ',
        ])->getJson('/api/test-optional-sanctum');

        // 빈 문자열 토큰은 bearerToken()이 null 반환하므로 guest로 통과
        $response->assertStatus(200)
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    /**
     * Bearer 없이 Authorization 헤더만 있으면 guest로 처리해야 합니다.
     */
    public function test_non_bearer_authorization_passes_as_guest(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Basic some-token',
        ])->getJson('/api/test-optional-sanctum');

        // Bearer 형식이 아니므로 bearerToken()이 null 반환
        $response->assertStatus(200)
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    /**
     * DB에서 삭제된 토큰으로 요청하면 401 응답을 받아야 합니다.
     */
    public function test_returns_401_for_deleted_token(): void
    {
        // 토큰 생성
        $tokenModel = $this->user->createToken('test-token');
        $token = $tokenModel->plainTextToken;

        // 토큰 직접 삭제
        $tokenModel->accessToken->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/test-optional-sanctum');

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'message']);
    }

    /**
     * expires_at이 만료된 토큰으로 요청하면 guest로 통과해야 합니다.
     * (로그인 페이지 등 공개 페이지 접근 허용을 위함)
     */
    public function test_passes_as_guest_for_expired_token(): void
    {
        // 토큰 생성
        $tokenModel = $this->user->createToken('test-token');
        $token = $tokenModel->plainTextToken;

        // 토큰 만료 시간을 과거로 설정
        $tokenModel->accessToken->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/test-optional-sanctum');

        // 만료된 토큰은 guest로 통과 (로그인 페이지 접근 가능)
        $response->assertStatus(200)
            ->assertJson([
                'authenticated' => false,
                'user_id' => null,
            ]);
    }

    /**
     * 삭제된 사용자의 토큰으로 요청하면 401 응답을 받아야 합니다.
     */
    public function test_returns_401_for_deleted_user_token(): void
    {
        // 토큰 생성
        $token = $this->user->createToken('test-token')->plainTextToken;

        // 사용자 삭제
        $this->user->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/test-optional-sanctum');

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'message']);
    }
}
