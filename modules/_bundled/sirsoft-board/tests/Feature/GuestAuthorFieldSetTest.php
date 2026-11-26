<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldSetRegistry;
use App\Models\FieldDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Fields\GuestAuthorFields;
use Modules\Sirsoft\Board\Listeners\ExemptRequiredFieldsRoutesListener;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시판 비회원 작성자 항목 집합
 *
 * 집합 `sirsoft-board.guest_author` 의 선언, 게시판별(글과 댓글별) 사용과 필수, 요청 규칙, 값 저장과 파기,
 * 다른 확장이 기여한 항목, 게시판 설정 폼의 표, 그리고 추가 정보를 입력하지 않은 회원의 쓰기 차단과 허용 라우트.
 */
#[Group('board')]
#[Group('fields')]
class GuestAuthorFieldSetTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'i490-guest-fields';
    }

    /**
     * 모듈의 항목 선언을 동기화하고 비회원 글, 댓글 쓰기 권한을 줍니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        ModuleManager::invalidateModuleStatusCache();
        app(FieldSetRegistry::class)->flush();
        app(FieldDefinitionSyncHelper::class)->sync('module', 'sirsoft-board', (new Module)->getFields());
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        DB::table('field_values')->whereIn('owner_type', [GuestAuthorFields::OWNER_POST, GuestAuthorFields::OWNER_COMMENT])->delete();
        $this->updateBoardSettings([
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'guest_author_fields' => null,
            'blocked_keywords' => [],
            'min_title_length' => 2,
            'min_content_length' => 2,
            'min_comment_length' => 2,
        ]);
        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
    }

    /**
     * 항목 정의 메모를 비웁니다 (롤백된 행이 다음 테스트에 남지 않게). 테스트가 건 필터는 기반 클래스가 되돌린다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
        parent::tearDown();
    }

    /**
     * 게시판의 비회원 작성자 항목 설정을 바꿉니다.
     *
     * @param  array<string, mixed>  $settings  `{항목 키: {post: {enabled, required}, comment: {...}}}`
     * @return void 반환값 없음
     */
    private function boardFields(array $settings): void
    {
        $this->updateBoardSettings(['guest_author_fields' => $settings]);
    }

    /**
     * 비회원 글쓰기 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $extra  더할 요청 값
     * @return TestResponse 응답
     */
    private function guestPost(array $extra = [])
    {
        return $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", array_merge([
            'title' => '비회원 글 '.uniqid(),
            'content' => '비회원이 쓴 글입니다.',
            'author_name' => '지나가던손님',
            'password' => 'guest-1234',
        ], $extra));
    }

    /**
     * 비회원 댓글 쓰기 요청을 보냅니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  array<string, mixed>  $extra  더할 요청 값
     * @return TestResponse 응답
     */
    private function guestComment(int $postId, array $extra = [])
    {
        return $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", array_merge([
            'content' => '비회원 댓글입니다.',
            'author_name' => '댓글손님',
            'password' => 'guest-1234',
        ], $extra));
    }

    /**
     * 소유자에 저장된 비회원 항목 값을 읽습니다.
     *
     * @param  string  $ownerType  소유자 종류
     * @param  int  $ownerId  소유자 ID
     * @return array<string, string|null> 항목 키 => 글자 값
     */
    private function storedValues(string $ownerType, int $ownerId): array
    {
        return DB::table('field_values')
            ->join('field_definitions', 'field_definitions.id', '=', 'field_values.field_definition_id')
            ->where('field_values.owner_type', $ownerType)
            ->where('field_values.owner_id', $ownerId)
            ->pluck('field_values.value_text', 'field_definitions.key')
            ->all();
    }

    // ==========================================
    // 선언
    // ==========================================

    /**
     * 모듈이 집합을 등록하고 이메일 항목을 꺼진 채로 선언한다. 이름과 비밀번호는 집합에 들어 있지 않다.
     *
     * @scenario case=set_and_field_declared
     *
     * @effects guest_author_set_registered, guest_email_field_declared_disabled, fixed_fields_outside_engine
     *
     * @return void 반환값 없음
     */
    public function test_module_declares_the_set_and_the_email_field(): void
    {
        $set = app(FieldSetRegistry::class)->get(GuestAuthorFields::SET);

        $this->assertNotNull($set);
        $this->assertSame('module', $set->ownerType);
        $this->assertSame('sirsoft-board', $set->ownerIdentifier);
        $this->assertSame(GuestAuthorFields::BUNDLE, $set->bundleKey);
        $this->assertSame([GuestAuthorFields::OWNER_POST, GuestAuthorFields::OWNER_COMMENT], $set->ownerTypes);
        $this->assertSame('sirsoft-board.boards.update', $set->managePermission);
        $this->assertTrue($set->acceptsContributions);
        $this->assertFalse($set->allowsType('file'));
        $this->assertFalse($set->allowsType('image'));
        $this->assertTrue($set->allowsType('email'));

        $definitions = app(FieldRegistry::class)->forSet(GuestAuthorFields::SET);
        $this->assertSame(['email'], $definitions->pluck('key')->all());
        $email = $definitions->first();
        $this->assertFalse((bool) $email->enabled);
        $this->assertSame('value', $email->storage);
        $this->assertSame('guest_fields.email', app(FieldRegistry::class)->attributeFor($email));
    }

    // ==========================================
    // 설정을 바꾸지 않은 게시판 (수정 전과 같은 동작)
    // ==========================================

    /**
     * 설정을 바꾸지 않은 게시판에서는 비회원 글과 댓글이 이름과 비밀번호만으로 써지고, 보낸 이메일은 저장되지 않는다.
     *
     * @scenario case=default_board_unchanged
     *
     * @effects default_board_guest_post_needs_only_name_and_password, default_board_ignores_disabled_field_input, fixed_rules_still_required
     *
     * @return void 반환값 없음
     */
    public function test_default_board_accepts_guest_writing_with_name_and_password_only(): void
    {
        $response = $this->guestPost(['guest_fields' => ['email' => 'guest@example.com']])->assertStatus(201);
        $postId = (int) $response->json('data.id');
        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_POST, $postId));

        $comment = $this->guestComment($postId)->assertStatus(201);
        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_COMMENT, (int) $comment->json('data.id')));

        // 고정 규칙(이름, 비밀번호)은 그대로 필수다
        $this->guestPost(['author_name' => '', 'password' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['author_name', 'password']);
    }

    // ==========================================
    // 게시판별, 표면별 설정
    // ==========================================

    /**
     * 게시판이 글에서 이메일을 필수로 켜면 빠뜨린 글쓰기는 422 이고, 넣으면 값 테이블에 글 소유로 저장된다.
     *
     * @scenario case=board_requires_email_on_post
     *
     * @effects required_guest_field_missing_is_422, invalid_guest_field_is_422, guest_field_value_stored_for_post
     *
     * @return void 반환값 없음
     */
    public function test_board_setting_makes_email_required_on_guest_posts(): void
    {
        $this->boardFields(['email' => [
            'post' => ['enabled' => true, 'required' => true],
            'comment' => ['enabled' => false, 'required' => false],
        ]]);

        $this->guestPost()->assertStatus(422)->assertJsonValidationErrors(['guest_fields.email']);
        $this->guestPost(['guest_fields' => ['email' => '이메일아님']])->assertStatus(422)->assertJsonValidationErrors(['guest_fields.email']);

        $response = $this->guestPost(['guest_fields' => ['email' => 'guest@example.com']])->assertStatus(201);
        $postId = (int) $response->json('data.id');

        $this->assertSame(['email' => 'guest@example.com'], $this->storedValues(GuestAuthorFields::OWNER_POST, $postId));
        // 글의 컬럼에는 들어가지 않는다
        $this->assertNull(Post::find($postId)->getAttribute('guest_fields'));
    }

    /**
     * 글과 댓글의 설정은 따로다. 글에서만 켠 게시판의 댓글은 이메일 없이 써지고, 댓글에서 켜면 댓글 소유로 저장된다.
     *
     * @scenario case=surface_settings_are_independent
     *
     * @effects comment_surface_follows_its_own_setting, guest_field_value_stored_for_comment, optional_guest_field_can_be_omitted
     *
     * @return void 반환값 없음
     */
    public function test_post_and_comment_surfaces_follow_their_own_settings(): void
    {
        $this->boardFields(['email' => [
            'post' => ['enabled' => true, 'required' => true],
            'comment' => ['enabled' => false, 'required' => false],
        ]]);
        $postId = $this->createTestPost();

        $ignored = $this->guestComment($postId, ['guest_fields' => ['email' => 'c@example.com']])->assertStatus(201);
        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_COMMENT, (int) $ignored->json('data.id')));

        // 댓글에서 선택으로 켠다: 빼도 되고, 넣으면 저장된다
        $this->boardFields(['email' => [
            'post' => ['enabled' => false, 'required' => false],
            'comment' => ['enabled' => true, 'required' => false],
        ]]);
        $this->guestComment($postId)->assertStatus(201);
        $stored = $this->guestComment($postId, ['guest_fields' => ['email' => 'c@example.com']])->assertStatus(201);
        $this->assertSame(['email' => 'c@example.com'], $this->storedValues(GuestAuthorFields::OWNER_COMMENT, (int) $stored->json('data.id')));

        // 댓글에서 필수로 켠다
        $this->boardFields(['email' => ['comment' => ['enabled' => true, 'required' => true]]]);
        $this->guestComment($postId)->assertStatus(422)->assertJsonValidationErrors(['guest_fields.email']);

        // 글 쪽은 이 게시판에서 꺼져 있다
        $this->guestPost()->assertStatus(201);
    }

    /**
     * 회원이 쓰는 글과 댓글에는 비회원 항목 규칙이 걸리지 않고 값도 저장되지 않는다.
     *
     * @scenario case=member_writing_unaffected
     *
     * @effects member_post_has_no_guest_field_rule, member_post_stores_no_guest_field_value
     *
     * @return void 반환값 없음
     */
    public function test_member_writing_is_not_affected_by_guest_fields(): void
    {
        $this->boardFields(['email' => [
            'post' => ['enabled' => true, 'required' => true],
            'comment' => ['enabled' => true, 'required' => true],
        ]]);
        $member = $this->createUser();

        $response = $this->actingAs($member)->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => '회원 글',
            'content' => '회원이 쓴 글입니다.',
            'guest_fields' => ['email' => 'member@example.com'],
        ])->assertStatus(201);
        $postId = (int) $response->json('data.id');
        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_POST, $postId));

        $comment = $this->actingAs($member)->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
            'content' => '회원 댓글입니다.',
        ])->assertStatus(201);
        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_COMMENT, (int) $comment->json('data.id')));
    }

    // ==========================================
    // 화면이 그리는 값
    // ==========================================

    /**
     * 글쓰기 메타와 글 상세가 비회원 칸을 그릴 값을 싣는다. 이름과 비밀번호는 늘 필수이고, 게시판이 켠 항목만 실린다.
     *
     * @scenario case=form_payload_for_guest_screens
     *
     * @effects form_meta_carries_guest_fields, post_detail_carries_guest_fields_for_guests, guest_fields_hidden_from_members_on_detail
     *
     * @return void 반환값 없음
     */
    public function test_screens_receive_the_guest_field_contract(): void
    {
        $metaUrl = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/form-meta";

        $default = $this->getJson($metaUrl)->assertOk()->json('data.board.guest_fields');
        $this->assertTrue($default['fixed']['author_name']['required']);
        $this->assertTrue($default['fixed']['password']['required']);
        $this->assertSame([], $default['post']);
        $this->assertSame([], $default['comment']);

        $this->boardFields(['email' => [
            'post' => ['enabled' => true, 'required' => true],
            'comment' => ['enabled' => true, 'required' => false],
        ]]);

        $payload = $this->getJson($metaUrl)->assertOk()->json('data.board.guest_fields');
        $this->assertCount(1, $payload['post']);
        $this->assertSame('email', $payload['post'][0]['key']);
        $this->assertSame('guest_fields.email', $payload['post'][0]['name']);
        $this->assertSame('email', $payload['post'][0]['type']);
        $this->assertTrue($payload['post'][0]['required']);
        $this->assertNotEmpty($payload['post'][0]['label']);
        $this->assertFalse($payload['comment'][0]['required']);

        $postId = $this->createTestPost();
        $detailUrl = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}";
        $guestDetail = $this->getJson($detailUrl)->assertOk()->json('data.board.guest_fields');
        $this->assertSame('email', $guestDetail['comment'][0]['key']);
        $this->assertTrue($guestDetail['fixed']['password']['required']);

        $memberDetail = $this->actingAs($this->createUser())->getJson($detailUrl)->assertOk();
        $this->assertNull($memberDetail->json('data.board.guest_fields'));
    }

    // ==========================================
    // 훅
    // ==========================================

    /**
     * 요청 규칙을 만들 때 항목 엔진의 규칙 필터가 표면과 함께 발화하고, 게시판의 기존 검증 필터가 그 뒤에 그대로 탄다.
     *
     * @scenario case=hooks_fire_in_order
     *
     * @effects field_rules_filter_fires_with_guest_surface, existing_post_validation_filter_receives_guest_field_rules, existing_comment_validation_filter_receives_guest_field_rules
     *
     * @return void 반환값 없음
     */
    public function test_engine_filters_fire_and_existing_validation_filters_still_run(): void
    {
        $this->boardFields(['email' => [
            'post' => ['enabled' => true, 'required' => true],
            'comment' => ['enabled' => true, 'required' => true],
        ]]);

        $surfaces = [];
        HookManager::addFilter('core.field.filter_rules', function ($rules, $set = null, $surface = null) use (&$surfaces) {
            if ($set === GuestAuthorFields::SET) {
                $surfaces[] = $surface;
            }

            return $rules;
        });
        $seen = [];
        HookManager::addFilter('sirsoft-board.user_post.store_validation_rules', function ($rules) use (&$seen) {
            $seen['post'] = array_keys($rules);

            return $rules;
        });
        HookManager::addFilter('sirsoft-board.comment.store_validation_rules', function ($rules) use (&$seen) {
            $seen['comment'] = array_keys($rules);

            return $rules;
        });

        $postId = $this->createTestPost();
        $this->guestPost()->assertStatus(422);
        $this->guestComment($postId)->assertStatus(422);

        $this->assertContains(GuestAuthorFields::SURFACE_POST, $surfaces);
        $this->assertContains(GuestAuthorFields::SURFACE_COMMENT, $surfaces);
        $this->assertContains('guest_fields.email', $seen['post'] ?? []);
        $this->assertContains('author_name', $seen['post'] ?? []);
        $this->assertContains('guest_fields.email', $seen['comment'] ?? []);
    }

    // ==========================================
    // 다른 확장이 기여한 항목
    // ==========================================

    /**
     * 다른 확장이 이 집합에 기여한 항목은 게시판 설정 표에 출처와 함께 나타나고, 기여한 쪽이 잠근 속성은 게시판에서 바꿀 수 없다.
     *
     * @scenario case=contributed_field
     *
     * @effects contributed_field_listed_with_source, contributed_lock_cannot_be_overridden_by_board, contributed_field_validated_and_stored
     *
     * @return void 반환값 없음
     */
    public function test_contributed_field_appears_with_source_and_keeps_its_lock(): void
    {
        $contributed = FieldDefinition::create([
            'field_set' => GuestAuthorFields::SET,
            'key' => 'demo_visit_path',
            'type' => 'text',
            'source_type' => 'plugin',
            'source_identifier' => 'demo-visit',
            'storage' => 'value',
            'name' => ['ko' => '방문 경로', 'en' => 'Visit path'],
            'enabled' => true,
            'required' => true,
            'locks' => ['required' => 'fields.locks.declared'],
            'sort_order' => 200,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        // 기여한 플러그인이 켜져 있는 상황을 만든다: 등록소는 꺼진 확장의 행을 빼므로, 그 뒤에 도는 필터로 다시 싣는다.
        HookManager::addFilter('core.field.filter_definitions', function ($rows, $set = null) use ($contributed) {
            if ($set === GuestAuthorFields::SET && $rows instanceof Collection) {
                $rows->push(clone $contributed);
            }

            return $rows;
        }, 5);

        $rows = collect(app(GuestAuthorFields::class)->settingsRows($this->board))->keyBy('key');

        $this->assertTrue($rows['author_name']['fixed']);
        $this->assertTrue($rows['password']['post']['required_locked']);
        $this->assertNull($rows['email']['source_label'], '이 모듈이 선언한 항목에는 출처를 붙이지 않는다');
        $this->assertSame('plugin', $rows['demo_visit_path']['source_type']);
        $this->assertNotEmpty($rows['demo_visit_path']['source_label']);
        $this->assertTrue($rows['demo_visit_path']['post']['required_locked']);
        $this->assertFalse($rows['demo_visit_path']['post']['enabled_locked']);

        // 게시판이 잠긴 「필수」를 끄려 해도 선언 값이 유지된다
        $normalized = app(GuestAuthorFields::class)->normalizeSettings([
            'demo_visit_path' => ['post' => ['enabled' => true, 'required' => false], 'comment' => ['enabled' => false, 'required' => false]],
            'unknown_key' => ['post' => ['enabled' => true, 'required' => true]],
        ]);
        $this->assertTrue($normalized['demo_visit_path']['post']['required']);
        $this->assertFalse($normalized['demo_visit_path']['comment']['enabled']);
        $this->assertArrayNotHasKey('unknown_key', $normalized);

        $this->boardFields($normalized);
        $this->guestPost()->assertStatus(422)->assertJsonValidationErrors(['guest_fields.demo_visit_path']);
        $response = $this->guestPost(['guest_fields' => ['demo_visit_path' => '검색']])->assertStatus(201);
        $this->assertSame(['demo_visit_path' => '검색'], $this->storedValues(GuestAuthorFields::OWNER_POST, (int) $response->json('data.id')));
    }

    // ==========================================
    // 파기
    // ==========================================

    /**
     * 글과 댓글의 비회원 항목 값은 한 건 파기와 게시판 삭제 때 함께 지워진다.
     *
     * @scenario case=values_purged
     *
     * @effects guest_field_values_purged_for_owner, guest_field_values_purged_on_board_delete
     *
     * @return void 반환값 없음
     */
    public function test_guest_field_values_are_purged_with_their_owner(): void
    {
        $this->boardFields(['email' => [
            'post' => ['enabled' => true, 'required' => false],
            'comment' => ['enabled' => true, 'required' => false],
        ]]);

        $first = (int) $this->guestPost(['guest_fields' => ['email' => 'a@example.com']])->assertStatus(201)->json('data.id');
        $second = (int) $this->guestPost(['guest_fields' => ['email' => 'b@example.com']])->assertStatus(201)->json('data.id');
        $commentId = (int) $this->guestComment($second, ['guest_fields' => ['email' => 'c@example.com']])->assertStatus(201)->json('data.id');

        $fields = app(GuestAuthorFields::class);
        $this->assertSame(1, $fields->purge(Post::find($first)));
        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_POST, $first));
        $this->assertNotSame([], $this->storedValues(GuestAuthorFields::OWNER_POST, $second));

        // 관리자 화면용 값 읽기: 값이 있는 소유자만 싣는다
        $values = $fields->valuesFor(new Collection([Post::find($first), Post::find($second)]));
        $this->assertArrayNotHasKey($first, $values);
        $this->assertSame('b@example.com', $values[$second][0]['display_value']);
        $this->assertNotEmpty($fields->valuesFor(new Collection([Comment::find($commentId)])));

        // 게시판을 지우면 그 게시판의 글과 댓글에 딸린 값도 사라진다
        $admin = $this->createAdminUser(['sirsoft-board.boards.delete']);
        $this->actingAs($admin);
        app(BoardService::class)->deleteBoard($this->board->id, true);

        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_POST, $second));
        $this->assertSame([], $this->storedValues(GuestAuthorFields::OWNER_COMMENT, $commentId));
    }

    // ==========================================
    // 게시판 설정 폼
    // ==========================================

    /**
     * 게시판 설정 폼이 작성자 표시 이름 칸과 비회원 작성자 항목 표의 값을 받고, 저장하면 정리된 모양으로 남는다.
     *
     * @scenario case=board_form_round_trip
     *
     * @effects board_form_carries_display_name_setting_and_options, board_form_carries_guest_field_rows, board_update_saves_normalized_settings, board_update_rejects_unavailable_kind
     *
     * @return void 반환값 없음
     */
    public function test_board_form_carries_and_saves_the_two_settings(): void
    {
        $admin = $this->createAdminUser([
            'sirsoft-board.boards.read',
            'sirsoft-board.boards.update',
            'sirsoft-board.boards.create',
        ]);
        $this->resetPermissionMiddlewareCache();

        $form = $this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?board_slug='.$this->board->slug)
            ->assertOk()
            ->json('data');

        $this->assertSame(['mode' => 'inherit', 'kind' => null], $form['display_name_setting']);
        $this->assertContains('nickname', array_column($form['display_name_options']['kinds'], 'value'));
        $this->assertSame('name', $form['display_name_options']['inherited']['kind']);
        $this->assertSame(['author_name', 'password', 'email'], array_column($form['guest_author_field_rows'], 'key'));
        $this->assertFalse($form['guest_author_fields']['email']['post']['enabled']);

        // 새 게시판 폼도 같은 모양을 받는다
        $create = $this->actingAs($admin)->getJson('/api/modules/sirsoft-board/admin/boards/form-data')->assertOk()->json('data');
        $this->assertSame(['mode' => 'inherit', 'kind' => null], $create['display_name_setting']);
        $this->assertArrayHasKey('email', $create['guest_author_fields']);

        $payload = [
            'name' => ['ko' => '비회원 항목 게시판', 'en' => 'Guest field board'],
            'display_name_setting' => ['mode' => 'fixed', 'kind' => 'nickname'],
            'guest_author_fields' => [
                // 사용을 끄면 필수는 저장되지 않는다
                'email' => ['post' => ['enabled' => true, 'required' => true], 'comment' => ['enabled' => false, 'required' => true]],
            ],
        ];
        $update = $this->actingAs($admin)->putJson('/api/modules/sirsoft-board/admin/boards/'.$this->board->slug, $payload);
        $this->assertNotSame(422, $update->status(), json_encode($update->json('errors'), JSON_UNESCAPED_UNICODE));
        $update->assertSuccessful();

        $this->board->refresh();
        // JSON 컬럼은 키 순서를 보장하지 않는다 — 값만 비교한다
        $this->assertEquals(['mode' => 'fixed', 'kind' => 'nickname'], $this->board->display_name_setting);
        $this->assertEquals([
            'email' => [
                'post' => ['enabled' => true, 'required' => true],
                'comment' => ['enabled' => false, 'required' => false],
            ],
        ], $this->board->guest_author_fields);

        // 「따름」으로 되돌리면 컬럼이 비워진다
        $this->actingAs($admin)->putJson('/api/modules/sirsoft-board/admin/boards/'.$this->board->slug, [
            ...$payload,
            'display_name_setting' => ['mode' => 'inherit', 'kind' => null],
        ])->assertSuccessful();
        $this->assertNull($this->board->refresh()->display_name_setting);

        // 지금 없는 종류로 고정할 수 없다
        $this->actingAs($admin)->putJson('/api/modules/sirsoft-board/admin/boards/'.$this->board->slug, [
            ...$payload,
            'display_name_setting' => ['mode' => 'fixed', 'kind' => 'field:no_such_field'],
        ])->assertStatus(422)->assertJsonValidationErrors(['display_name_setting.kind']);
    }

    // ==========================================
    // 모듈 기본값, 운영자 추가 항목, 관리자 상세
    // ==========================================

    /**
     * 「게시판 기본 설정」 응답이 비회원 작성자 항목 표의 행과 값을 싣고, 저장하면 값만 정리해 남기며, 설정을 보내지 않고 만든
     * 새 게시판은 그 기본값을 그대로 물려받는다.
     *
     * @scenario case=module_defaults
     *
     * @effects module_defaults_carry_guest_field_table, module_defaults_save_normalized_values_without_rows, new_board_inherits_module_defaults
     *
     * @return void 반환값 없음
     */
    public function test_module_defaults_carry_the_table_and_new_boards_inherit_them(): void
    {
        $admin = $this->createAdminUser(['sirsoft-board.settings.read', 'sirsoft-board.settings.update']);
        $this->resetPermissionMiddlewareCache();

        $settings = $this->actingAs($admin)->getJson('/api/modules/sirsoft-board/admin/settings')->assertOk()->json('data.basic_defaults');
        $this->assertSame(['author_name', 'password', 'email'], array_column($settings['guest_author_field_rows'], 'key'));
        $this->assertFalse($settings['guest_author_fields']['email']['post']['enabled']);

        $this->actingAs($admin)->putJson('/api/modules/sirsoft-board/admin/settings', [
            'basic_defaults' => [
                'guest_author_fields' => [
                    // 사용을 끈 표면의 필수는 저장되지 않는다
                    'email' => ['post' => ['enabled' => true, 'required' => true], 'comment' => ['enabled' => false, 'required' => true]],
                    'no_such_field' => ['post' => ['enabled' => true, 'required' => true]],
                ],
                'guest_author_field_rows' => $settings['guest_author_field_rows'],
            ],
        ])->assertOk();

        $stored = g7_module_settings('sirsoft-board', 'basic_defaults', []);
        $this->assertArrayNotHasKey('guest_author_field_rows', $stored, '표의 행은 저장하지 않는다');
        $this->assertEquals([
            'email' => [
                'post' => ['enabled' => true, 'required' => true],
                'comment' => ['enabled' => false, 'required' => false],
            ],
        ], $stored['guest_author_fields']);

        // 설정을 보내지 않은 생성 요청은 기본값을 컬럼에 그대로 담는다(나중에 기본값을 바꿔도 이 게시판은 따라가지 않는다)
        $board = app(BoardService::class)->createBoard([
            'slug' => 'i490-guest-default-'.uniqid(),
            'name' => ['ko' => '기본값 물려받기', 'en' => 'Inherit defaults'],
            'type' => 'basic',
        ]);
        $this->assertEquals($stored['guest_author_fields'], $board->refresh()->guest_author_fields);
    }

    /**
     * 게시판 설정 폼의 표 행은 운영자가 만든 항목에 ID 와 `is_custom` 을 실어, 화면이 그 행에만 지우기 단추를 그린다.
     *
     * @scenario case=custom_field_rows
     *
     * @effects board_form_rows_mark_custom_fields_with_id
     *
     * @return void 반환값 없음
     */
    public function test_board_form_rows_mark_operator_fields(): void
    {
        $admin = $this->createAdminUser([
            'sirsoft-board.boards.read',
            'sirsoft-board.boards.update',
        ]);
        $this->resetPermissionMiddlewareCache();

        $created = $this->actingAs($admin)->postJson('/api/admin/field-sets/'.GuestAuthorFields::SET.'/fields', [
            'key' => 'i490_guest_note',
            'type' => 'text',
            'name' => ['ko' => '방문 경로', 'en' => 'How you found us'],
            'enabled' => false,
            'required' => false,
        ]);
        $this->assertSame(201, $created->status(), json_encode($created->json(), JSON_UNESCAPED_UNICODE));
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $rows = collect($this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-board/admin/boards/form-data?board_slug='.$this->board->slug)
            ->assertOk()
            ->json('data.guest_author_field_rows'))->keyBy('key');

        $this->assertTrue($rows['i490_guest_note']['is_custom']);
        $this->assertSame($created->json('data.field.id'), $rows['i490_guest_note']['id']);
        $this->assertFalse($rows['i490_guest_note']['post']['enabled'], '다른 게시판에 갑자기 나타나지 않게 꺼진 채로 만든다');
        $this->assertFalse($rows['email']['is_custom'], '모듈이 선언한 항목은 지울 수 없다');
        $this->assertArrayNotHasKey('id', $rows['author_name'], '고정 항목은 항목 엔진 밖이다');
    }

    /**
     * 관리자 글 상세는 비회원 글과 댓글이 낸 항목 값을 게시판 관리 권한(admin.manage)이 있는 관리자에게만 싣는다.
     * 사용자 화면의 글 상세에는 싣지 않는다.
     *
     * @scenario case=admin_detail_values
     *
     * @effects admin_post_detail_shows_guest_values_to_managers, admin_post_detail_hides_guest_values_without_manage, user_post_detail_never_carries_guest_values
     *
     * @return void 반환값 없음
     */
    public function test_admin_post_detail_shows_guest_values_only_to_managers(): void
    {
        $this->boardFields([
            'email' => ['post' => ['enabled' => true, 'required' => true], 'comment' => ['enabled' => true, 'required' => true]],
        ]);
        $postId = (int) $this->guestPost(['guest_fields' => ['email' => 'guest@example.test']])->assertCreated()->json('data.id');
        $this->guestComment($postId, ['guest_fields' => ['email' => 'commenter@example.test']])->assertCreated();

        $slug = $this->board->slug;
        $manager = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.posts.read",
            "sirsoft-board.{$slug}.admin.comments.read",
            "sirsoft-board.{$slug}.admin.manage",
        ]);
        // 읽기만 하는 관리자는 별도 역할로 만든다 — createAdminUser 는 권한을 공용 admin 역할에 붙여 위 관리자의 권한을 함께 받는다
        $reader = User::factory()->create();
        $readerRole = Role::create([
            'identifier' => 'i490-guest-values-reader-'.$slug,
            'name' => ['ko' => '읽기 전용', 'en' => 'Read only'],
            'type' => 'admin',
        ]);
        foreach (["sirsoft-board.{$slug}.admin.posts.read", "sirsoft-board.{$slug}.admin.comments.read"] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin'],
            );
            $readerRole->permissions()->attach($permission->id);
        }
        $reader->roles()->attach($readerRole->id);
        $this->resetPermissionMiddlewareCache();

        $detail = $this->actingAs($manager)->getJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts/{$postId}")->assertOk()->json('data');
        $this->assertSame('guest@example.test', collect($detail['guest_field_values'])->firstWhere('key', 'email')['value']);
        $comment = collect($detail['comments'] ?? [])->first();
        $this->assertNotNull($comment, '비회원 댓글이 상세에 실려야 한다');
        $this->assertSame('commenter@example.test', collect($comment['guest_field_values'])->firstWhere('key', 'email')['value']);

        $this->resetPermissionMiddlewareCache();
        $hidden = $this->actingAs($reader)->getJson("/api/modules/sirsoft-board/admin/board/{$slug}/posts/{$postId}")->assertOk()->json('data');
        $this->assertNull($hidden['guest_field_values']);
        $this->assertNull(collect($hidden['comments'] ?? [])->first()['guest_field_values'] ?? null);

        $this->app['auth']->forgetGuards();
        $public = $this->getJson("/api/modules/sirsoft-board/boards/{$slug}/posts/{$postId}")->assertOk()->json('data');
        $this->assertNull($public['guest_field_values'] ?? null);
    }

    // ==========================================
    // 추가 정보를 입력하지 않은 회원
    // ==========================================

    /**
     * 필수 회원 항목이 비어 있는 회원의 글쓰기는 403 `required_fields_pending` 이고, 비밀번호 확인 라우트는 그 관문에 막히지 않는다.
     *
     * @scenario case=pending_member_write_gate
     *
     * @effects pending_member_post_write_rejected, pending_member_comment_write_rejected, password_verify_routes_exempt_for_pending_member
     *
     * @return void 반환값 없음
     */
    public function test_pending_member_cannot_write_but_can_verify_passwords(): void
    {
        FieldDefinition::create([
            'field_set' => 'core.user', 'key' => 'i490_demanded', 'type' => 'text', 'source_type' => 'custom',
            'source_identifier' => 'custom', 'storage' => 'value', 'name' => ['ko' => '필수 추가 항목'],
            'enabled' => true, 'required' => true, 'require_existing_members' => true, 'edit_scope' => 'self', 'sort_order' => 900,
        ]);
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        $member = $this->createUser();
        $guestPostId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('guest-1234')]);
        $guestCommentId = $this->createTestComment($guestPostId, ['user_id' => null, 'password' => Hash::make('guest-1234')]);
        $base = "/api/modules/sirsoft-board/boards/{$this->board->slug}";

        $this->actingAs($member)->postJson($base.'/posts', ['title' => '막힐 글', 'content' => '추가 정보가 없는 회원의 글'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'required_fields_pending');
        $this->actingAs($member)->postJson($base."/posts/{$guestPostId}/comments", ['content' => '막힐 댓글입니다.'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'required_fields_pending');

        $verifyRoutes = [
            $base."/posts/{$guestPostId}/verify-password" => ['password' => 'guest-1234'],
            $base."/posts/{$guestPostId}/verify-password-for-modify" => ['password' => 'guest-1234'],
            $base."/posts/{$guestPostId}/verify-thread-password" => ['password' => 'guest-1234'],
            $base."/comments/{$guestCommentId}/verify-password" => ['password' => 'guest-1234'],
        ];
        foreach ($verifyRoutes as $uri => $body) {
            $response = $this->actingAs($member)->postJson($uri, $body);
            $this->assertNotSame('required_fields_pending', $response->json('error_code'), $uri.' 가 추가 정보 요구로 막혔다');
        }

        // 선언한 허용 라우트 이름이 실제 라우트에 모두 있다
        $names = collect(app('router')->getRoutes()->getRoutesByName())->keys()->all();
        foreach (ExemptRequiredFieldsRoutesListener::ROUTES as $name) {
            $this->assertContains($name, $names, $name.' 라우트가 없다');
        }
    }
}
