<?php

namespace Tests\Feature\Api\Public;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 공개 프로필 API 테스트
 *
 * 사용자 상태별 프로필 데이터 반환을 검증합니다:
 * - active: 전체 정보 (name, avatar, bio, created_at)
 * - inactive: 기본 정보만 (bio 제외)
 * - blocked: 최소 정보만 (avatar, bio, created_at 제외)
 * - withdrawn/미존재: 404 에러
 */
class PublicProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * active 사용자는 전체 프로필 정보를 반환합니다.
     */
    public function test_active_user_returns_full_profile(): void
    {
        // Arrange
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
            'bio' => 'Test bio content',
        ]);

        // Act
        $response = $this->getJson("/api/users/{$user->uuid}/profile");

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'uuid',
                    'name',
                    'status',
                    'status_label',
                    'avatar',
                    'bio',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.bio', 'Test bio content')
            ->assertJsonPath('data.uuid', $user->uuid);
    }

    /**
     * inactive 사용자는 기본 정보만 반환합니다 (bio 제외).
     */
    public function test_inactive_user_returns_basic_info(): void
    {
        // Arrange
        $user = User::factory()->create([
            'status' => UserStatus::Inactive->value,
            'bio' => 'This should not appear',
        ]);

        // Act
        $response = $this->getJson("/api/users/{$user->uuid}/profile");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.status_label', __('user.status.inactive'))
            ->assertJsonPath('data.bio', null)
            ->assertJsonPath('data.name', $user->name);

        // avatar와 created_at은 있어야 함
        $this->assertArrayHasKey('avatar', $response->json('data'));
        $this->assertArrayHasKey('created_at', $response->json('data'));
    }

    /**
     * blocked 사용자는 최소 정보만 반환합니다 (avatar, bio, created_at 제외).
     */
    public function test_blocked_user_returns_minimal_info(): void
    {
        // Arrange
        $user = User::factory()->create([
            'status' => UserStatus::Blocked->value,
            'bio' => 'This should not appear',
        ]);

        // Act
        $response = $this->getJson("/api/users/{$user->uuid}/profile");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.status_label', __('user.status.blocked'))
            ->assertJsonPath('data.avatar', null)
            ->assertJsonPath('data.bio', null)
            ->assertJsonPath('data.created_at', null)
            ->assertJsonPath('data.name', $user->name);
    }

    /**
     * withdrawn 사용자는 익명화된 정보를 반환합니다.
     */
    public function test_withdrawn_user_returns_anonymized_info(): void
    {
        // Arrange
        $user = User::factory()->create([
            'status' => UserStatus::Withdrawn->value,
        ]);

        // Act
        $response = $this->getJson("/api/users/{$user->uuid}/profile");

        // Assert
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $user->uuid)
            ->assertJsonPath('data.status', UserStatus::Withdrawn->value)
            ->assertJsonPath('data.status_label', __('user.status.withdrawn'))
            ->assertJsonPath('data.name', __('user.withdrawn_user'))
            ->assertJsonPath('data.avatar', null)
            ->assertJsonPath('data.bio', null)
            ->assertJsonPath('data.created_at', null)
            ->assertJsonPath('data.is_withdrawn', true);
    }

    /**
     * 존재하지 않는 사용자 ID는 404를 반환합니다.
     */
    public function test_non_existent_user_returns_404(): void
    {
        // Act
        $response = $this->getJson('/api/users/00000000-0000-0000-0000-000000000000/profile');

        // Assert - Route Model Binding이 UUID로 사용자를 찾지 못하면 404
        $response->assertNotFound();
    }

    /**
     * 프로필 응답에 status와 status_label이 포함됩니다.
     */
    public function test_profile_contains_status_and_label(): void
    {
        // Arrange
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
        ]);

        // Act
        $response = $this->getJson("/api/users/{$user->uuid}/profile");

        // Assert
        $response->assertOk();

        $data = $response->json('data');
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('status_label', $data);
        $this->assertEquals('active', $data['status']);
        $this->assertEquals(__('user.status.active'), $data['status_label']);
    }

    /**
     * 프로필 응답에 게시글 통계가 포함되지 않습니다.
     * (게시글 통계는 게시판 모듈 API에서 별도 제공)
     */
    public function test_profile_does_not_contain_post_stats(): void
    {
        // Arrange
        $user = User::factory()->create([
            'status' => UserStatus::Active->value,
        ]);

        // Act
        $response = $this->getJson("/api/users/{$user->uuid}/profile");

        // Assert
        $response->assertOk();

        $data = $response->json('data');
        $this->assertArrayNotHasKey('posts_count', $data);
        $this->assertArrayNotHasKey('comments_count', $data);
        $this->assertArrayNotHasKey('recent_posts', $data);
    }
}
