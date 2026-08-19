<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 활동/공개 프로필 목록의 비밀글 본문 노출 차단 (KVE-2026-1914 형제)
 *
 * 이 두 목록은 본문 일부(`content_plain`, 1000자)를 함께 싣는데 Resource 를 거치지 않고
 * 저장소 배열을 그대로 응답에 싣는다. 공개 프로필 라우트는 `optional.sanctum` 이라
 * **미인증**으로 도달하므로, 타인의 비밀글·블라인드 글 본문이 그대로 나갔다.
 *
 * 결함의 형태는 **사문화된 옵트인 플래그**였다 — 저장소가 `is_public` 을 읽어 필터를 걸도록
 * 돼 있었지만 그 키를 설정하는 코드가 어디에도 없어(전수 grep write 0건) 한 번도 적용되지
 * 않았다. 판정을 열람자 신원(`viewer_id`) 기반 fail-closed 로 뒤집었다.
 *
 * 가리는 것은 **본문뿐**이다. 행과 제목은 게시판 목록에서 이미 같은 수준으로 노출되며
 * (`PostResource` 의 목록 규칙: 제목은 노출, 본문만 차단) 프로필 UI 도 비밀글/블라인드
 * 배지를 그린다 — 행을 지우면 결함 차단에 필요한 범위를 넘어 기능이 깎인다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-activity-secret-masking.yaml 참조.
 * 각 test 메서드의 `@scenario viewer=…, activity_type=…` 마커가 축 조합을, `@effects …` 가
 * 효과를 커버한다(메서드당 단일 조합).
 *
 * 축 요약(마커 아님 — 평문): viewer 는 guest·other_user·owner, activity_type 은
 * authored·commented 다. 클래스 레벨에 `@effects` 를 몰아 적으면 그 메서드가 하나도 없어도
 * 매니페스트 효과가 "언급됨" 으로 집계되어 커버리지를 부풀린다 — 마커는 메서드에만 둔다.
 */
class UserActivitySecretExposureTest extends BoardTestCase
{
    private User $author;

    private User $stranger;

    protected function getTestBoardSlug(): string
    {
        return 'activity-secret';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '활동 노출 테스트 게시판', 'en' => 'Activity Exposure Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->author = User::factory()->create();
        $this->stranger = User::factory()->create();

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            foreach (['posts.read', 'comments.read', 'comments.create'] as $key) {
                $perm = Permission::firstOrCreate(
                    ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                    ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
                );
                $userRole->permissions()->syncWithoutDetaching([$perm->id]);
            }
            $this->author->roles()->attach($userRole->id);
            $this->stranger->roles()->attach($userRole->id);
        }
    }

    private function profileUrl(User $user): string
    {
        return "/api/modules/sirsoft-board/users/{$user->uuid}/posts";
    }

    /**
     * 마이페이지 활동 목록(내가 댓글 단 글) URL.
     *
     * 라우트 이름으로 뽑는다 — 모듈 라우트는 `api/modules/{id}` 프리픽스가 그룹에서 붙으므로
     * 경로를 손으로 적으면 프리픽스를 빠뜨려 404 가 되고, 그 404 는 마스킹 단언에 도달하기
     * 전에 났다는 사실이 드러나지 않으면 "차단됐다" 로 오독되기 쉽다.
     */
    private function commentedActivityUrl(): string
    {
        return route('api.modules.sirsoft-board.me.board-activities.index', [
            'activity_type' => 'commented',
        ], false);
    }

    private function createAuthorPost(array $overrides = []): int
    {
        return $this->createTestPost(array_merge([
            'title' => '작성자 글',
            'content' => '대외비 본문입니다',
            'status' => 'published',
            'is_secret' => false,
            'user_id' => $this->author->id,
            'author_name' => 'author',
        ], $overrides));
    }

    /**
     * 미인증 요청에 타인 비밀글의 **본문**이 나가지 않는다 (행·제목은 유지).
     *
     * 이 라우트는 optional.sanctum 이라 로그인 없이 도달한다 — 결함의 실제 공격 프로필이다.
     *
     * @scenario viewer=guest, activity_type=authored
     *
     * @effects public_profile_masks_secret_post_content_for_others, activity_rows_and_titles_remain_visible
     */
    public function test_guest_sees_secret_post_row_without_content(): void
    {
        $this->createAuthorPost(['title' => '공개글', 'is_secret' => false]);
        $this->createAuthorPost(['title' => '비밀글', 'is_secret' => true, 'content' => '비밀 본문']);

        $response = $this->getJson($this->profileUrl($this->author));

        $response->assertStatus(200);

        $rows = $response->json('data.data') ?? [];
        $titles = array_column($rows, 'title');

        // 행·제목은 게시판 목록에서 이미 같은 수준으로 보인다 — 가리는 것은 본문뿐이다.
        $this->assertContains('공개글', $titles);
        $this->assertContains('비밀글', $titles, '비밀글도 목록에는 나와야 합니다(배지로 구분)');

        $this->assertStringNotContainsString('비밀 본문', $response->getContent(), '비밀글 본문이 응답에 실리면 안 됩니다');
    }

    /**
     * 미인증 요청에 블라인드 글의 **본문**도 나가지 않는다 (행은 유지).
     *
     * 블라인드 본문은 이 모듈의 다른 목록에서도 비워진다
     * (PostResource::getMaskedContentPreviewForList) — 같은 규칙이다.
     *
     * @scenario viewer=guest, activity_type=authored
     *
     * @effects public_profile_masks_blinded_post_content_for_others, activity_rows_and_titles_remain_visible
     */
    public function test_guest_sees_blinded_post_row_without_content(): void
    {
        $this->createAuthorPost(['title' => '공개글']);
        $this->createAuthorPost(['title' => '블라인드글', 'status' => 'blinded', 'content' => '가려진 본문']);

        $response = $this->getJson($this->profileUrl($this->author));

        $titles = array_column($response->json('data.data') ?? [], 'title');
        $this->assertContains('블라인드글', $titles, '블라인드 글도 목록에는 나와야 합니다');
        $this->assertStringNotContainsString('가려진 본문', $response->getContent());
    }

    /**
     * 로그인한 타인에게도 동일하게 본문만 가려진다 (인증 여부와 무관한 판정).
     *
     * @scenario viewer=other_user, activity_type=authored
     *
     * @effects public_profile_masks_secret_post_content_for_others
     */
    public function test_other_authenticated_user_sees_secret_post_row_without_content(): void
    {
        $this->createAuthorPost(['title' => '비밀글', 'is_secret' => true, 'content' => '비밀 본문']);

        $response = $this->actingAs($this->stranger, 'sanctum')
            ->getJson($this->profileUrl($this->author));

        $titles = array_column($response->json('data.data') ?? [], 'title');
        $this->assertContains('비밀글', $titles);
        $this->assertStringNotContainsString('비밀 본문', $response->getContent(), '로그인한 타인에게도 본문은 나가면 안 됩니다');
    }

    /**
     * (과차단 회귀) 본인이 자기 프로필을 보면 자기 비밀글의 본문까지 그대로 보인다.
     *
     * fail-closed 로 뒤집으면서 본인 시야까지 좁히면 기능 축소다.
     *
     * @scenario viewer=owner, activity_type=authored
     *
     * @effects public_profile_shows_own_secret_content_to_owner
     */
    public function test_owner_still_sees_own_secret_post_content(): void
    {
        $this->createAuthorPost(['title' => '비밀글', 'is_secret' => true, 'content' => '비밀 본문']);

        $response = $this->actingAs($this->author, 'sanctum')
            ->getJson($this->profileUrl($this->author));

        $rows = $response->json('data.data') ?? [];
        $titles = array_column($rows, 'title');
        $this->assertContains('비밀글', $titles, '본인에게는 자기 비밀글이 보여야 합니다');
        $this->assertStringContainsString('비밀 본문', $response->getContent(), '본인에게는 본문도 보여야 합니다');
    }

    /**
     * "내가 댓글 단 글" 목록에는 **타인이 쓴** 비밀글이 섞인다 — 그 본문은 나가지 않는다.
     *
     * authored 축과 다른 코드 경로다. authored 는 목록 주인이 곧 글쓴이라 열람자 일치만
     * 보면 되지만(`$isOwnView`), commented 는 글마다 작성자가 달라 **글 단위**로 판정한다
     * (`$post->user_id !== $viewerId`). authored 만 검증하면 이 분기가 무보호로 남는다.
     *
     * @scenario viewer=other_user, activity_type=commented
     *
     * @effects commented_activity_masks_others_secret_content, activity_rows_and_titles_remain_visible
     */
    public function test_commented_activity_masks_others_secret_post_content(): void
    {
        $postId = $this->createAuthorPost([
            'title' => '남의 비밀글',
            'is_secret' => true,
            'content' => '남의 비밀 본문',
        ]);

        // 열람자(stranger)가 그 글에 댓글을 달아 자기 활동 목록에 올린다.
        $this->createTestComment($postId, [
            'user_id' => $this->stranger->id,
            'author_name' => 'stranger',
            'content' => '댓글 답니다',
        ]);

        $response = $this->actingAs($this->stranger, 'sanctum')
            ->getJson($this->commentedActivityUrl());

        $response->assertStatus(200);

        $titles = array_column($response->json('data.data') ?? [], 'title');

        // 행은 내 활동 기록이므로 남는다 — 가리는 것은 남의 본문뿐이다.
        $this->assertContains('남의 비밀글', $titles, '내가 댓글 단 글은 활동 목록에 남아야 합니다');
        $this->assertStringNotContainsString(
            '남의 비밀 본문',
            $response->getContent(),
            '타인이 쓴 비밀글의 본문이 댓글 활동 목록으로 새면 안 됩니다'
        );
    }

    /**
     * (과차단 회귀) 자기 비밀글에 자기가 댓글을 달았으면 본문이 그대로 보인다.
     *
     * 글 단위 판정을 "비밀글이면 무조건 마스킹" 으로 조이면 자기 글까지 가려져 기능이 깎인다.
     *
     * @scenario viewer=owner, activity_type=commented
     *
     * @effects commented_activity_shows_own_secret_content_to_owner
     */
    public function test_commented_activity_shows_own_secret_post_content_to_owner(): void
    {
        $postId = $this->createAuthorPost([
            'title' => '내 비밀글',
            'is_secret' => true,
            'content' => '내 비밀 본문',
        ]);

        $this->createTestComment($postId, [
            'user_id' => $this->author->id,
            'author_name' => 'author',
            'content' => '자문자답',
        ]);

        $response = $this->actingAs($this->author, 'sanctum')
            ->getJson($this->commentedActivityUrl());

        $response->assertStatus(200);

        $titles = array_column($response->json('data.data') ?? [], 'title');
        $this->assertContains('내 비밀글', $titles);
        $this->assertStringContainsString(
            '내 비밀 본문',
            $response->getContent(),
            '본인이 쓴 비밀글이면 댓글 활동 목록에서도 본문이 보여야 합니다'
        );
    }
}
