<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\NicknameRequirement;
use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Seo\Contracts\SeoCacheManagerInterface;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\DisplayName\BoardDisplayNames;
use Modules\Sirsoft\Board\Http\Resources\PostCollection;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\CountsQueries;

/**
 * 게시판의 작성자 표시 이름
 *
 * 글, 댓글, 최근글, 인기글, 스크랩과 반응 관리, 신고가 회원 이름을 `users.name` 에서 직접 읽지 않고
 * 표시 이름 계산기를 거치는지 잠근다. 수정 전에도 있던 결함 넷도 함께 잠근다.
 *
 * | 결함 | 수정 전 |
 * | --- | --- |
 * | 작성자순 정렬 | 회원 글은 `author_name` 이 비어 있어 이름 순서와 무관하게 앞에 몰렸다 |
 * | 최근글의 회원 작성자 | 회원 글의 작성자가 빈 값이었다 |
 * | 반응 관리의 작성자 검색 | 회원이 쓴 글은 작성자 이름으로 찾지 못했다 |
 * | 닉네임 검색 | 작성자 검색이 이름만 찾았다 |
 */
#[Group('board')]
class AuthorDisplayNameTest extends BoardTestCase
{
    use CountsQueries;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'i490-author-display';
    }

    /**
     * 표시 이름 설정을 기본값으로 되돌리고 비회원 열람 권한을 줍니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->memberSettings([
            'display_name_areas' => null,
            'display_name_selectable_kinds' => ['name'],
            'display_name_member_default_kind' => 'name',
        ]);
        $this->updateBoardSettings([
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'display_name_setting' => null,
            'order_by' => 'created_at',
            'order_direction' => 'DESC',
        ]);
        $this->setGuestPermissions(['posts.read', 'comments.read', 'posts.write', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
    }

    /**
     * 회원 탭의 표시 이름 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $member  설정 키 => 값
     * @return void 반환값 없음
     */
    private function memberSettings(array $member): void
    {
        foreach ($member as $key => $value) {
            config(["g7_settings.core.member.{$key}" => $value]);
        }
        app(NicknameRequirement::class)->flush();
        app(BoardDisplayNames::class)->flush();
    }

    /**
     * 이름과 닉네임을 정한 회원을 만듭니다.
     *
     * @param  string  $name  이름
     * @param  string|null  $nickname  닉네임
     * @return User 회원
     */
    private function member(string $name, ?string $nickname = null): User
    {
        $user = $this->createUser();
        $user->forceFill(['name' => $name, 'nickname' => $nickname])->save();

        return $user->refresh();
    }

    /**
     * 게시글 관리 권한만 가진(회원 조회 권한이 없는) 관리자를 만듭니다.
     *
     * @return User 회원 조회 권한이 없는 관리자
     */
    private function adminWithoutMemberRead(): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-board.i490-post-manager'],
            ['name' => ['ko' => '게시글 관리 전용', 'en' => 'Post manager only']]
        );
        foreach (["sirsoft-board.{$this->board->slug}.admin.posts.read", "sirsoft-board.{$this->board->slug}.admin.manage"] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);
        $this->resetPermissionMiddlewareCache();

        return $user;
    }

    /**
     * 게시글 관리 권한과 회원 조회 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function adminWithMemberRead(): User
    {
        $admin = $this->createAdminUser([
            "sirsoft-board.{$this->board->slug}.admin.posts.read",
            "sirsoft-board.{$this->board->slug}.admin.manage",
            'core.users.read',
        ]);
        $this->resetPermissionMiddlewareCache();

        return $admin;
    }

    /**
     * 사용자 화면의 글 목록 주소를 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리 인자
     * @param  string|null  $slug  게시판 slug (생략하면 테스트 게시판)
     * @return string 주소
     */
    private function listUrl(array $query = [], ?string $slug = null): string
    {
        $slug ??= $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/posts".($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * 관리자 화면의 글 목록 주소를 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리 인자
     * @return string 주소
     */
    private function adminListUrl(array $query = []): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts".($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * 목록 응답에서 글 ID => 작성자 이름을 뽑습니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  목록 행
     * @return array<int, string|null> 글 ID => 작성자 이름
     */
    private function authorNames(array $rows): array
    {
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = $row['author']['name'] ?? null;
        }

        return $names;
    }

    // ==========================================
    // 설정을 바꾸지 않은 사이트 (수정 전과 같은 응답)
    // ==========================================

    /**
     * 표시 이름 설정을 바꾸지 않은 사이트에서는 글 목록, 글 상세, 댓글의 작성자가 수정 전과 같이 회원 이름이다.
     *
     * @scenario case=default_settings_show_member_name
     *
     * @effects default_settings_author_is_member_name, default_settings_guest_author_is_entered_name, user_response_has_no_real_name
     *
     * @return void 반환값 없음
     */
    public function test_default_settings_keep_member_name_in_list_detail_and_comments(): void
    {
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null, 'title' => '회원 글']);
        $guestPostId = $this->createTestPost(['user_id' => null, 'author_name' => '지나가던손님', 'title' => '비회원 글']);
        $this->createTestComment($postId, ['user_id' => $member->id, 'author_name' => null]);
        $this->createTestComment($postId, ['user_id' => null, 'author_name' => '댓글손님']);

        $list = $this->getJson($this->listUrl())->assertOk();
        $names = $this->authorNames($list->json('data.data'));
        $this->assertSame('홍길동', $names[$postId]);
        $this->assertSame('지나가던손님', $names[$guestPostId]);
        foreach ($list->json('data.data') as $row) {
            $this->assertArrayNotHasKey('real_name', $row['author'], '사용자 화면 응답에 실명 키가 실렸다');
        }

        $detail = $this->getJson($this->listUrl()."/{$postId}")->assertOk();
        $this->assertSame('홍길동', $detail->json('data.author.name'));
        $this->assertArrayNotHasKey('real_name', $detail->json('data.author'));

        $commentAuthors = array_column(array_column($detail->json('data.comments') ?? [], 'author'), 'name');
        sort($commentAuthors);
        $this->assertSame(['댓글손님', '홍길동'], $commentAuthors);
    }

    // ==========================================
    // 영역 설정과 게시판별 설정
    // ==========================================

    /**
     * 사이트 기본 영역을 닉네임으로 바꾸면 글 목록, 글 상세, 댓글의 회원 작성자가 닉네임으로 나가고 실명은 실리지 않는다.
     *
     * 과거에 쓴 글도 지금 설정으로 보인다(작성 당시 이름을 저장하지 않는다).
     *
     * @scenario case=site_area_nickname_applies_everywhere
     *
     * @effects post_list_author_follows_area, post_detail_author_follows_area, comment_author_follows_area, guest_author_unaffected_by_area
     *
     * @return void 반환값 없음
     */
    public function test_site_area_nickname_shows_nickname_in_list_detail_and_comments(): void
    {
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null, 'created_at' => now()->subYear()]);
        $guestPostId = $this->createTestPost(['user_id' => null, 'author_name' => '지나가던손님']);
        $this->createTestComment($postId, ['user_id' => $member->id, 'author_name' => null]);

        $this->memberSettings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'nickname']]]);

        $list = $this->getJson($this->listUrl())->assertOk();
        $names = $this->authorNames($list->json('data.data'));
        $this->assertSame('길동이', $names[$postId]);
        $this->assertSame('지나가던손님', $names[$guestPostId]);
        $this->assertStringNotContainsString('홍길동', $list->getContent(), '닉네임 사이트의 목록 응답에 실명이 실렸다');

        $detail = $this->getJson($this->listUrl()."/{$postId}")->assertOk();
        $this->assertSame('길동이', $detail->json('data.author.name'));
        $this->assertSame('길동이', $detail->json('data.comments.0.author.name'));
        $this->assertStringNotContainsString('홍길동', $detail->getContent(), '닉네임 사이트의 상세 응답에 실명이 실렸다');
    }

    /**
     * 게시판마다 다른 설정이 적용된다. 닉네임으로 고정한 게시판은 닉네임, 정하지 않은 게시판은 사이트 기본(이름)이다.
     *
     * @scenario case=board_setting_overrides_area
     *
     * @effects board_instance_setting_applies, board_without_setting_follows_area
     *
     * @return void 반환값 없음
     */
    public function test_board_setting_overrides_the_area_setting_per_board(): void
    {
        $member = $this->member('홍길동', '길동이');
        $other = Board::updateOrCreate(
            ['slug' => 'i490-author-display-b'],
            ['name' => ['ko' => '이름 게시판', 'en' => 'Name board'], 'is_active' => true, 'secret_mode' => 'disabled', 'display_name_setting' => null]
        );
        DB::table('board_posts')->where('board_id', $other->id)->delete();
        $this->grantUserRoleBoardPermissions($other, ['posts.read']);
        $guestRole = Role::where('identifier', 'guest')->first();
        $read = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$other->slug}.posts.read"],
            ['name' => ['ko' => 'posts.read', 'en' => 'posts.read'], 'type' => 'user']
        );
        $guestRole->permissions()->syncWithoutDetaching([$read->id]);
        $this->resetPermissionMiddlewareCache();

        $this->updateBoardSettings(['display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $postA = $this->createTestPost(['user_id' => $member->id, 'author_name' => null]);
        $postB = $this->createTestPost(['board_id' => $other->id, 'user_id' => $member->id, 'author_name' => null]);

        $namesA = $this->authorNames($this->getJson($this->listUrl())->assertOk()->json('data.data'));
        $namesB = $this->authorNames($this->getJson($this->listUrl([], $other->slug))->assertOk()->json('data.data'));

        $this->assertSame('길동이', $namesA[$postA]);
        $this->assertSame('홍길동', $namesB[$postB]);
    }

    /**
     * 「회원이 고른 값」 영역에서는 회원이 고른 종류로 보이고, 고르지 않은 회원은 회원 기본 종류로 보인다.
     *
     * @scenario case=member_mode_uses_member_choice
     *
     * @effects member_chosen_kind_applies, member_without_choice_uses_default_kind
     *
     * @return void 반환값 없음
     */
    public function test_member_mode_shows_each_member_by_their_chosen_kind(): void
    {
        $chooser = $this->member('홍길동', '길동이');
        $chooser->forceFill(['display_name_kind' => 'nickname'])->save();
        $plain = $this->member('김철수', '철수짱');
        $chosenPost = $this->createTestPost(['user_id' => $chooser->id, 'author_name' => null]);
        $plainPost = $this->createTestPost(['user_id' => $plain->id, 'author_name' => null]);

        $this->memberSettings([
            'display_name_selectable_kinds' => ['name', 'nickname'],
            'display_name_member_default_kind' => 'name',
            'display_name_areas' => ['core:site' => ['mode' => 'member']],
        ]);

        $names = $this->authorNames($this->getJson($this->listUrl())->assertOk()->json('data.data'));
        $this->assertSame('길동이', $names[$chosenPost]);
        $this->assertSame('김철수', $names[$plainPost]);
    }

    // ==========================================
    // 작성자순 정렬 (수정 전 결함)
    // ==========================================

    /**
     * 작성자순 정렬은 화면에 보이는 이름 순서다. 회원 글과 비회원 글이 한 순서로 섞인다.
     *
     * 수정 전에는 회원 글의 `author_name` 이 비어 있어 회원 글이 이름과 무관하게 한쪽에 몰렸다.
     *
     * @scenario case=author_sort_by_displayed_name
     *
     * @effects author_sort_orders_member_and_guest_posts_by_displayed_name
     *
     * @return void 반환값 없음
     */
    public function test_author_sort_orders_member_and_guest_posts_by_displayed_name(): void
    {
        $da = $this->member('다람쥐');
        $ga = $this->member('가오리');
        $postDa = $this->createTestPost(['user_id' => $da->id, 'author_name' => null]);
        $postNa = $this->createTestPost(['user_id' => null, 'author_name' => '나비']);
        $postGa = $this->createTestPost(['user_id' => $ga->id, 'author_name' => null]);
        $postRa = $this->createTestPost(['user_id' => null, 'author_name' => '라마']);

        $asc = array_map('intval', array_column(
            $this->getJson($this->listUrl(['sort_by' => 'author', 'sort_order' => 'asc']))->assertOk()->json('data.data'),
            'id'
        ));
        $this->assertSame([$postGa, $postNa, $postDa, $postRa], $asc);

        $desc = array_map('intval', array_column(
            $this->getJson($this->listUrl(['sort_by' => 'author', 'sort_order' => 'desc']))->assertOk()->json('data.data'),
            'id'
        ));
        $this->assertSame([$postRa, $postDa, $postNa, $postGa], $desc);
    }

    /**
     * 닉네임으로 보이는 게시판의 작성자순 정렬은 닉네임 순서다.
     *
     * @scenario case=author_sort_follows_board_kind
     *
     * @effects author_sort_follows_board_kind
     *
     * @return void 반환값 없음
     */
    public function test_author_sort_follows_the_kind_shown_on_the_board(): void
    {
        // 이름 순서와 닉네임 순서가 반대인 두 회원
        $first = $this->member('가오리', '하마');
        $second = $this->member('하늘소', '가젤');
        $postFirst = $this->createTestPost(['user_id' => $first->id, 'author_name' => null]);
        $postSecond = $this->createTestPost(['user_id' => $second->id, 'author_name' => null]);

        $this->updateBoardSettings(['display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $ids = array_map('intval', array_column(
            $this->getJson($this->listUrl(['sort_by' => 'author', 'sort_order' => 'asc']))->assertOk()->json('data.data'),
            'id'
        ));
        $this->assertSame([$postSecond, $postFirst], $ids);
    }

    /**
     * 작성자순 정렬에서 이전 글과 다음 글도 같은 순서를 따른다.
     *
     * @scenario case=author_sort_adjacent_posts
     *
     * @effects adjacent_posts_follow_author_sort
     *
     * @return void 반환값 없음
     */
    public function test_adjacent_posts_follow_the_author_sort(): void
    {
        $ga = $this->member('가오리');
        $da = $this->member('다람쥐');
        $postGa = $this->createTestPost(['user_id' => $ga->id, 'author_name' => null]);
        $postNa = $this->createTestPost(['user_id' => null, 'author_name' => '나비']);
        $postDa = $this->createTestPost(['user_id' => $da->id, 'author_name' => null]);

        $adjacent = app(PostRepositoryInterface::class)->getAdjacentPosts(
            $this->board->slug,
            $postNa,
            ['order_by' => 'author', 'order_direction' => 'asc'],
            false,
            $this->board->id,
        );

        $this->assertSame($postGa, (int) $adjacent['prev']?->id);
        $this->assertSame($postDa, (int) $adjacent['next']?->id);
    }

    // ==========================================
    // 검색 (수정 전 결함: 닉네임 누락)
    // ==========================================

    /**
     * 사용자 화면의 작성자 검색은 그 게시판에서 보이는 값만 찾는다. 닉네임 게시판에서 닉네임으로 찾고 실명으로는 찾지 못한다.
     *
     * @scenario case=user_search_matches_displayed_kind_only
     *
     * @effects user_author_search_finds_nickname, user_author_search_does_not_find_hidden_real_name, user_author_search_finds_guest_name
     *
     * @return void 반환값 없음
     */
    public function test_user_author_search_matches_only_the_kind_shown_on_the_board(): void
    {
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null]);
        $guestPostId = $this->createTestPost(['user_id' => null, 'author_name' => '길동손님']);

        $search = fn (string $keyword) => array_map('intval', array_column(
            $this->getJson($this->listUrl(['search' => $keyword, 'search_field' => 'author']))->assertOk()->json('data.data'),
            'id'
        ));

        // 기본 설정(이름): 이름으로 찾는다
        $this->assertSame([$postId], $search('홍길동'));

        $this->updateBoardSettings(['display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $this->assertEqualsCanonicalizing([$postId], $search('길동이'));
        $this->assertSame([], $search('홍길동'), '닉네임 게시판에서 실명으로 글을 찾을 수 있다');
        $this->assertEqualsCanonicalizing([$guestPostId], $search('길동손님'));
    }

    /**
     * 관리자 화면의 작성자 검색은 이름과 닉네임을 모두 찾는다.
     *
     * 수정 전에는 닉네임으로는 찾지 못했다.
     *
     * @scenario case=admin_search_matches_name_and_nickname
     *
     * @effects admin_author_search_finds_name, admin_author_search_finds_nickname
     *
     * @return void 반환값 없음
     */
    public function test_admin_author_search_matches_name_and_nickname(): void
    {
        $admin = $this->adminWithMemberRead();
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null]);
        $this->createTestPost(['user_id' => null, 'author_name' => '무관한손님']);

        $search = fn (string $keyword) => array_map('intval', array_column(
            $this->actingAs($admin)->getJson($this->adminListUrl([
                'filters' => [['field' => 'author', 'value' => $keyword, 'operator' => 'like']],
            ]))->assertOk()->json('data.data'),
            'id'
        ));

        $this->assertSame([$postId], $search('홍길동'));
        $this->assertSame([$postId], $search('길동이'));
    }

    /**
     * 반응 관리의 작성자 검색이 회원이 쓴 글과 댓글도 찾는다 (이름과 닉네임).
     *
     * 수정 전에는 비회원 글의 작성자 이름만 찾았다.
     *
     * @scenario case=reaction_admin_author_search
     *
     * @effects reaction_admin_author_search_finds_member_author
     *
     * @return void 반환값 없음
     */
    public function test_reaction_admin_author_search_finds_member_authors(): void
    {
        $admin = $this->createAdminUser(['sirsoft-board.reactions.view', 'core.users.read']);
        $this->resetPermissionMiddlewareCache();
        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);
        $likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->updateBoardSettings(['use_reaction' => true, 'use_comment_reaction' => true, 'active_reaction_types' => ['like']]);

        $author = $this->member('홍길동', '길동이');
        $reactor = $this->member('반응한사람');
        $postId = $this->createTestPost(['user_id' => $author->id, 'author_name' => null]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id, 'author_name' => null]);
        $otherPostId = $this->createTestPost(['user_id' => null, 'author_name' => '무관한손님']);

        $ids = [];
        foreach ([['post', $postId], ['comment', $commentId], ['post', $otherPostId]] as [$type, $targetId]) {
            $ids[] = DB::table('board_reactions')->insertGetId([
                'user_id' => $reactor->id,
                'target_type' => $type,
                'target_id' => $targetId,
                'reaction_type_id' => $likeId,
                'board_id' => $this->board->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $search = fn (string $keyword) => array_map('intval', array_column(
            $this->actingAs($admin)->getJson(
                '/api/modules/sirsoft-board/admin/reactions?filters[0][field]=author_name&filters[0][value]='.urlencode($keyword)
            )->assertOk()->json('data.data'),
            'id'
        ));

        $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], $search('홍길동'));
        $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], $search('길동이'));
    }

    // ==========================================
    // 최근글, 인기글 (수정 전 결함: 최근글의 회원 작성자 빈 값)
    // ==========================================

    /**
     * 최근글 위젯에 회원이 쓴 글의 작성자가 실린다. 캐시된 뒤에 설정이 바뀌어도 바뀐 이름으로 나간다.
     *
     * 수정 전에는 회원 글의 작성자가 빈 값이었다.
     *
     * @scenario case=recent_posts_member_author
     *
     * @effects recent_posts_member_author_filled, recent_posts_author_follows_setting_change_without_cache_clear
     *
     * @return void 반환값 없음
     */
    public function test_recent_posts_carry_member_author_and_follow_setting_changes(): void
    {
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null, 'title' => '회원 최근글']);
        $guestPostId = $this->createTestPost(['user_id' => null, 'author_name' => '지나가던손님', 'title' => '비회원 최근글']);

        $authors = function (): array {
            $rows = $this->getJson('/api/modules/sirsoft-board/boards/posts/recent?limit=20')->assertOk()->json('data');

            return array_column($rows, 'author_name', 'id');
        };

        $first = $authors();
        $this->assertSame('홍길동', $first[$postId] ?? null);
        $this->assertSame('지나가던손님', $first[$guestPostId] ?? null);

        // 캐시를 비우지 않고 설정만 바꾼다
        $this->memberSettings(['display_name_areas' => ['core:site' => ['mode' => 'fixed', 'kind' => 'nickname']]]);

        $second = $authors();
        $this->assertSame('길동이', $second[$postId] ?? null);
        $this->assertSame('지나가던손님', $second[$guestPostId] ?? null);
    }

    /**
     * 인기글의 작성자도 캐시에서 꺼낸 뒤 계산한다. 회원이 이름을 바꾸면 캐시를 비우지 않아도 바뀐 이름으로 나간다.
     *
     * @scenario case=popular_posts_author_recomputed
     *
     * @effects popular_posts_author_follows_member_change_without_cache_clear, popular_posts_cache_has_no_member_name
     *
     * @return void 반환값 없음
     */
    public function test_popular_posts_recompute_the_author_after_reading_the_cache(): void
    {
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null, 'view_count' => 500]);

        $author = function () use ($postId): ?string {
            foreach (app(BoardService::class)->getCachedPopularPosts('week', 20) as $row) {
                if ((int) $row['id'] === $postId) {
                    return $row['author']['name'] ?? null;
                }
            }

            return null;
        };

        $this->assertSame('홍길동', $author());

        $member->forceFill(['name' => '홍개명'])->save();

        $this->assertSame('홍개명', $author());
    }

    /**
     * 게시판 목록에 딸린 게시판별 최근글에도 회원 작성자가 그 게시판의 설정대로 실린다.
     *
     * @scenario case=board_recent_posts_member_author
     *
     * @effects board_recent_posts_member_author_filled
     *
     * @return void 반환값 없음
     */
    public function test_board_recent_posts_carry_member_author_with_board_setting(): void
    {
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null]);
        $service = app(BoardService::class);

        $byId = array_column($service->getBoardRecentPostsById($this->board->id, 5, $this->board), 'author_name', 'id');
        $this->assertSame('홍길동', $byId[$postId] ?? null);

        $this->updateBoardSettings(['display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $bySlug = array_column($service->getBoardRecentPosts($this->board->slug, 5), 'author_name', 'id');
        $this->assertSame('길동이', $bySlug[$postId] ?? null);
    }

    // ==========================================
    // 관리자 화면의 실명 보조
    // ==========================================

    /**
     * 관리자 글 목록은 관리자 영역의 표시 이름을 싣고, 회원 조회 권한이 있는 관리자에게만 실명을 `real_name` 으로 싣는다.
     *
     * 실명이 표시 이름과 같으면 싣지 않는다. 게시판별 설정은 관리자 화면에 적용되지 않는다.
     *
     * @scenario case=admin_real_name_gate
     *
     * @effects admin_list_uses_admin_area, admin_real_name_only_with_member_read_permission, admin_real_name_omitted_when_same_as_display_name, admin_list_ignores_board_setting
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_carries_real_name_only_for_admins_who_can_read_members(): void
    {
        $member = $this->member('홍길동', '길동이');
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null]);

        $row = function (User $admin) use ($postId): array {
            foreach ($this->actingAs($admin)->getJson($this->adminListUrl())->assertOk()->json('data.data') as $candidate) {
                if ((int) $candidate['id'] === $postId) {
                    return $candidate['author'];
                }
            }

            return [];
        };

        $withRead = $this->adminWithMemberRead();
        $withoutRead = $this->adminWithoutMemberRead();

        // 기본 설정: 관리자 영역이 이름이라 표시 이름과 실명이 같다. 실명 키를 싣지 않는다.
        $this->updateBoardSettings(['display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname']]);
        $author = $row($withRead);
        $this->assertSame('홍길동', $author['name'], '게시판별 설정이 관리자 화면에 적용됐다');
        $this->assertArrayNotHasKey('real_name', $author);

        // 관리자 영역을 닉네임으로: 표시 이름은 닉네임, 실명은 권한이 있을 때만
        $this->memberSettings(['display_name_areas' => [
            'core:site' => ['mode' => 'fixed', 'kind' => 'name'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
        ]]);

        $author = $row($withRead);
        $this->assertSame('길동이', $author['name']);
        $this->assertSame('홍길동', $author['real_name'] ?? null);

        $author = $row($withoutRead);
        $this->assertSame('길동이', $author['name']);
        $this->assertArrayNotHasKey('real_name', $author, '회원 조회 권한이 없는 관리자에게 실명이 실렸다');
    }

    // ==========================================
    // 쿼리 수
    // ==========================================

    /**
     * 표시 이름을 계산해도 글 목록의 쿼리 수가 글 수에 따라 늘지 않는다 (추가 항목 종류 포함).
     *
     * @scenario case=list_query_count_stable
     *
     * @effects post_list_display_name_query_count_stable
     *
     * @return void 반환값 없음
     */
    public function test_post_list_query_count_does_not_grow_with_rows(): void
    {
        $this->memberSettings([
            'display_name_selectable_kinds' => ['name', 'nickname'],
            'display_name_areas' => ['core:site' => ['mode' => 'member']],
        ]);

        $seed = function (int $count): void {
            for ($i = 0; $i < $count; $i++) {
                $member = $this->member('회원'.uniqid(), '닉'.uniqid());
                Post::create([
                    'board_id' => $this->board->id,
                    'user_id' => $member->id,
                    'title' => '쿼리 수 '.uniqid(),
                    'content' => '본문',
                    'status' => 'published',
                    'ip_address' => '127.0.0.1',
                ]);
            }
        };
        $seed(3);

        $this->assertQueryCountStableAsDataGrows(
            measure: function () {
                $paginator = app(PostService::class)->getPosts($this->board->slug, [], 50, false, 'user', $this->board);
                $collection = new PostCollection($paginator);

                return $collection->toArray(request());
            },
            grow: fn () => $seed(6),
            context: '표시 이름이 실린 게시글 목록',
        );
    }

    // ==========================================
    // 글쓰기 화면의 안내 줄 (U19)
    // ==========================================

    /**
     * 글쓰기 메타 응답이 로그인한 회원이 이 게시판에서 보이는 이름과 종류를 싣는다.
     *
     * @scenario case=form_meta_author_display
     *
     * @effects form_meta_carries_author_display_name_and_kind, form_meta_guest_has_no_author_display
     *
     * @return void 반환값 없음
     */
    public function test_form_meta_carries_how_the_author_will_be_shown(): void
    {
        $member = $this->member('홍길동', '길동이');
        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/form-meta";

        $default = $this->actingAs($member)->getJson($url)->assertOk();
        $this->assertSame('홍길동', $default->json('data.author_display_name'));
        $this->assertSame('name', $default->json('data.author_display_kind'));

        $this->updateBoardSettings(['display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $nickname = $this->actingAs($member)->getJson($url)->assertOk();
        $this->assertSame('길동이', $nickname->json('data.author_display_name'));
        $this->assertSame('nickname', $nickname->json('data.author_display_kind'));
        $this->assertNotEmpty($nickname->json('data.author_display_kind_label'));
    }

    // ==========================================
    // 영역 선언과 닉네임 항목 잠금
    // ==========================================

    /**
     * 게시판 모듈이 표시 이름 영역 둘을 등록하고, 닉네임으로 고정한 게시판이 있으면 닉네임 항목이 잠긴다.
     *
     * @scenario case=area_declaration_and_nickname_lock
     *
     * @effects board_areas_registered, board_nickname_setting_locks_nickname_field
     *
     * @return void 반환값 없음
     */
    public function test_board_areas_are_registered_and_a_nickname_board_locks_the_nickname_field(): void
    {
        $areas = app(DisplayNameAreaRegistry::class);
        $this->assertSame('core:site', $areas->get(BoardDisplayNames::AREA_POSTS)?->parent);
        $this->assertSame('core:admin', $areas->get(BoardDisplayNames::AREA_ADMIN)?->parent);

        $requirement = app(NicknameRequirement::class);
        $this->assertSame(NicknameRequirement::LEVEL_NONE, $requirement->level());

        $this->updateBoardSettings(['display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname']]);

        $this->assertSame(NicknameRequirement::LEVEL_ALL, $requirement->level());
        $usages = app(BoardDisplayNames::class)->usages();
        $this->assertSame('nickname', $usages[0]['kind'] ?? null);
        $this->assertNotEmpty($usages[0]['label'] ?? null);
    }

    // ==========================================
    // 기록물 (새 기록은 관리자 영역 표시 이름, 과거 기록은 그대로)
    // ==========================================

    /**
     * 표시 이름이 바뀌면 그 회원이 나오는 화면의 검색 엔진용 캐시를 비우도록 훅을 구독한다.
     *
     * @scenario case=display_name_changed_hook
     *
     * @effects display_name_changed_hook_subscribed
     *
     * @return void 반환값 없음
     */
    public function test_seo_cache_listener_subscribes_to_display_name_changes(): void
    {
        $cache = \Mockery::spy(SeoCacheManagerInterface::class);
        $this->app->instance(SeoCacheManagerInterface::class, $cache);

        HookManager::doAction('core.user.display_name_changed', $this->member('홍길동', '길동이'), ['nickname']);

        // 글 목록과 글 상세(쿼리 변종 포함), 게시판 목록, 홈, 검색 화면
        $cache->shouldHaveReceived('invalidateByUrl')->with('/board/*')->once();
        $cache->shouldHaveReceived('invalidateByUrl')->with('/boards')->once();
        $cache->shouldHaveReceived('invalidateByLayout')->with('home')->once();
        $cache->shouldHaveReceived('invalidateByLayout')->with('search/index')->once();
    }

    /**
     * 신고 스냅샷과 조치 이력은 기록물이다. 새 기록은 그 시점의 관리자 화면 표시 이름으로 남고, 설정을 바꿔도 과거 기록은 그대로다.
     *
     * @scenario case=records_keep_snapshot
     *
     * @effects new_report_snapshot_uses_admin_area_name, past_report_snapshot_unchanged_after_setting_change
     *
     * @return void 반환값 없음
     */
    public function test_report_snapshot_keeps_the_name_at_report_time(): void
    {
        $this->updateBoardSettings(['use_report' => true]);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $author = $this->member('홍길동', '길동이');
        $reporter = $this->member('신고한사람');
        $postId = $this->createTestPost(['user_id' => $author->id, 'author_name' => null, 'title' => '신고 대상']);

        $this->actingAs($reporter)->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", [
            'reason_type' => 'spam',
            'reason_detail' => '광고 글입니다.',
        ])->assertSuccessful();

        $snapshot = fn () => json_decode((string) DB::table('boards_report_logs')->orderByDesc('id')->value('snapshot'), true);
        $this->assertSame('홍길동', $snapshot()['author_name'] ?? null);

        // 설정을 바꿔도 이미 남긴 스냅샷은 그대로다
        $this->memberSettings(['display_name_areas' => [
            'core:site' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'core:admin' => ['mode' => 'fixed', 'kind' => 'nickname'],
        ]]);
        $this->assertSame('홍길동', $snapshot()['author_name'] ?? null);
    }
}
