<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

use App\Models\User;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 반응 유형 인라인 편집기 동기화 Feature 테스트.
 *
 * 유형 관리는 전용 CRUD 엔드포인트가 아니라 게시판 환경설정 저장(PUT settings)이
 * basic_defaults.reaction_types 행 배열을 받아 DB 와 맞추는 방식이다 (결정 R2).
 * 이 테스트는 그 동기화 계약 — 생성·수정·순서·삭제·불변 code·사용 중 차단·JSON 미오염 — 을 고정한다.
 */
class ReactionTypeSettingsSyncTest extends BoardTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/admin/settings';

    private User $admin;

    private string $settingsStoragePath;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ]);
        // 반응 유형 이름은 사이트 기본 언어 값이 필수다(게시판 유형과 같은 계약) — 픽스처는 한국어 이름을 싣는다
        $this->admin->forceFill(['language' => 'ko'])->save();

        // FK(restrictOnDelete) 순서: 이력 먼저 정리 후 유형 삭제
        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->settingsStoragePath = ExtensionStoragePath::module('sirsoft-board', 'settings');

        if (File::isDirectory($this->settingsStoragePath)) {
            File::deleteDirectory($this->settingsStoragePath);
        }
    }

    /**
     * 각 테스트 후 사용한 자원을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->settingsStoragePath)) {
            File::deleteDirectory($this->settingsStoragePath);
        }

        parent::tearDown();
    }

    /**
     * 설정 조회 응답이 전 유형(활성+비활성) 행을 싣는다.
     *
     * @scenario case=settings_read_lists_all_types_including_inactive
     *
     * @effects editor_iterates_form_reaction_types
     *
     * @return void 반환값 없음
     */
    public function test_settings_read_includes_reaction_types_rows(): void
    {
        ReactionType::create([
            'code' => 'inactive_old',
            'name' => ['ko' => '옛유형'],
            'display_order' => 9,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->admin)->getJson(self::ENDPOINT);

        $response->assertOk();

        $rows = $response->json('data.basic_defaults.reaction_types');

        $this->assertIsArray($rows);
        $this->assertCount(3, $rows, '비활성 유형까지 포함한 전 유형이 실려야 합니다.');

        $codes = array_column($rows, 'code');
        $this->assertContains('inactive_old', $codes);

        $like = collect($rows)->firstWhere('code', 'like');
        $this->assertIsArray($like['name'], '편집기는 로케일별 입력을 다루므로 name 은 맵이어야 합니다.');
        $this->assertSame('추천', $like['name']['ko']);
        $this->assertNull($like['icon_url']);
        $this->assertSame('fa-thumbs-up', $like['icon_name']);
        $this->assertSame(0, $like['reaction_count']);
    }

    /**
     * 저장이 유형을 생성·수정·재정렬하며, 행 배열은 설정 JSON 에 새지 않는다.
     *
     * @scenario case=settings_create_update_reorder_in_one_save
     *
     * @effects save_payload_carries_reaction_types, sync_before_save_leaves_json_untouched_on_422
     *
     * @return void 반환값 없음
     */
    public function test_store_creates_updates_reorders_types_and_does_not_persist_rows_into_json(): void
    {
        $like = ReactionType::where('code', 'like')->firstOrFail();
        $dislike = ReactionType::where('code', 'dislike')->firstOrFail();

        $response = $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'use_reaction' => true,
                'active_reaction_types' => ['like', 'dislike'],
                'reaction_types' => [
                    // 순서를 뒤집고(dislike 가 먼저), like 라벨을 수정하고, 새 유형을 추가한다.
                    ['id' => $dislike->id, 'code' => 'dislike', 'name' => ['ko' => '비추천', 'en' => 'Not Recommend'], 'is_active' => true],
                    ['id' => $like->id, 'code' => 'like', 'name' => ['ko' => '좋아요', 'en' => 'Like'], 'is_active' => true],
                    ['id' => null, 'code' => 'wow', 'name' => ['ko' => '놀라워요', 'en' => 'Wow'], 'is_active' => true],
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertSame(0, $dislike->fresh()->display_order, '배열 순서가 display_order 로 재부여되어야 합니다.');
        $this->assertSame(1, $like->fresh()->display_order);
        $this->assertSame('좋아요', $like->fresh()->name['ko']);

        $wow = ReactionType::where('code', 'wow')->first();
        $this->assertNotNull($wow, '새 행은 유형으로 생성되어야 합니다.');
        $this->assertSame(2, $wow->display_order);
        $this->assertNull($wow->icon, '관리자는 FA 클래스를 지정할 수 없습니다 (이미지 업로드만).');

        // 설정 JSON 에 행 배열이 새지 않았는지 — 새면 basic_defaults.json 과 config 미러가 오염된다.
        $file = $this->settingsStoragePath.DIRECTORY_SEPARATOR.'basic_defaults.json';

        if (File::exists($file)) {
            $saved = json_decode(File::get($file), true);
            $this->assertArrayNotHasKey('reaction_types', $saved ?? [], 'reaction_types 행은 설정 JSON 에 저장하지 않습니다.');
        }
    }

    /**
     * 기존 유형의 code 는 페이로드로 바꿀 수 없다 (추가 후 불변 — 결정 R12).
     *
     * @scenario case=settings_code_immutable_on_update
     *
     * @effects edit_uses_map_by_index
     *
     * @return void 반환값 없음
     */
    public function test_code_is_immutable_on_update(): void
    {
        $like = ReactionType::where('code', 'like')->firstOrFail();

        $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => $like->id, 'code' => 'renamed', 'name' => ['ko' => '추천'], 'is_active' => true],
                ],
            ],
        ])->assertOk();

        $this->assertSame('like', $like->fresh()->code, 'code 는 불변이어야 합니다.');
    }

    /**
     * 이미 있는 code 로 새 행을 만들면 그 행에 422 오류가 붙는다.
     *
     * @scenario case=settings_duplicate_code_rejected
     *
     * @effects add_rejects_duplicate_code, server_422_maps_to_row_error
     *
     * @return void 반환값 없음
     */
    public function test_new_row_with_taken_code_returns_422(): void
    {
        $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => null, 'code' => 'like', 'name' => ['ko' => '중복'], 'is_active' => true],
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['basic_defaults.reaction_types.0.code']);
    }

    /**
     * code 형식이 규격을 벗어나면 422.
     *
     * @scenario case=settings_invalid_code_rejected
     *
     * @effects add_requires_code_and_default_name
     *
     * @return void 반환값 없음
     */
    public function test_invalid_code_regex_returns_422(): void
    {
        $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => null, 'code' => 'Bad-Code!', 'name' => ['ko' => '나쁨'], 'is_active' => true],
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['basic_defaults.reaction_types.0.code']);
    }

    /**
     * 사이트 기본 언어의 이름이 비면 422 — 오류는 이름 칸 전체 키(name)로 돌아온다.
     *
     * 반응 유형 이름은 게시판 유형 이름과 같게 코어 LocaleRequiredTranslatable 로 검증하고, 필수 언어는 사이트
     * 기본 언어다(2026-10-09 PO 확정 — 다른 언어 화면의 대체 표시가 늘 채워지도록).
     *
     * @scenario case=settings_missing_default_name_rejected
     *
     * @effects add_requires_code_and_default_name
     *
     * @return void 반환값 없음
     */
    public function test_missing_site_locale_name_returns_422(): void
    {
        $response = $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => null, 'code' => 'noname', 'name' => ['en' => 'No Name'], 'is_active' => true],
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['basic_defaults.reaction_types.0.name']);

        $this->assertArrayNotHasKey('basic_defaults.reaction_types.0.name.ko', $response->json('errors'));
        $this->assertStringContainsString(
            __('sirsoft-board::validation.attributes.settings')['basic_defaults.reaction_types.*.name'],
            $response->json('errors')['basic_defaults.reaction_types.0.name'][0]
        );
    }

    /**
     * 영어 화면 관리자도 사이트 기본 언어(한국어) 이름이 비면 영어 이름이 있어도 422 다.
     *
     * @scenario case=settings_missing_default_name_rejected
     *
     * @effects add_requires_code_and_default_name
     *
     * @return void 반환값 없음
     */
    public function test_english_admin_still_needs_site_locale_name(): void
    {
        $this->admin->forceFill(['language' => 'en'])->save();

        $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => null, 'code' => 'en_only', 'name' => ['ko' => '', 'en' => 'English Only'], 'is_active' => true],
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['basic_defaults.reaction_types.0.name']);

        $this->assertNull(ReactionType::where('code', 'en_only')->first());
    }

    /**
     * 영어 화면 관리자는 사이트 기본 언어(한국어) 이름만으로 반응 유형을 추가한다 — 화면 언어 칸은 선택이다.
     *
     * @scenario case=settings_missing_default_name_rejected
     *
     * @effects add_requires_code_and_default_name
     *
     * @return void 반환값 없음
     */
    public function test_english_admin_adds_type_with_site_locale_name_only(): void
    {
        $this->admin->forceFill(['language' => 'en'])->save();

        $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => null, 'code' => 'ko_only', 'name' => ['ko' => '한국어만'], 'is_active' => true],
                ],
            ],
        ])->assertOk();

        $this->assertSame('한국어만', ReactionType::where('code', 'ko_only')->firstOrFail()->name['ko'] ?? null);
    }

    /**
     * 반응이 0건인 유형은 삭제되고, 게시판의 활성 code 목록에서도 빠진다.
     *
     * @scenario case=settings_delete_unreferenced_type
     *
     * @effects delete_requires_confirm_and_zero_reactions, delete_removes_code_from_active_types
     *
     * @return void 반환값 없음
     */
    public function test_delete_unreferenced_type_hard_deletes_and_strips_board_active_codes(): void
    {
        $wow = ReactionType::create([
            'code' => 'wow',
            'name' => ['ko' => '놀라워요'],
            'display_order' => 3,
            'is_active' => true,
        ]);

        $board = Board::find($this->board->id);
        $board->active_reaction_types = ['like', 'dislike', 'wow'];
        $board->save();

        $like = ReactionType::where('code', 'like')->firstOrFail();
        $dislike = ReactionType::where('code', 'dislike')->firstOrFail();

        $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'active_reaction_types' => ['like', 'dislike', 'wow'],
                'reaction_types' => [
                    ['id' => $like->id, 'code' => 'like', 'name' => ['ko' => '추천'], 'is_active' => true],
                    ['id' => $dislike->id, 'code' => 'dislike', 'name' => ['ko' => '비추천'], 'is_active' => true],
                ],
            ],
        ])->assertOk();

        $this->assertNull(ReactionType::find($wow->id), '참조가 없는 유형은 삭제되어야 합니다.');
        $this->assertNotContains('wow', $board->fresh()->active_reaction_types, '삭제된 code 는 게시판 활성 목록에서 빠져야 합니다.');
    }

    /**
     * 반응이 남아 있는 유형은 삭제되지 않고 422 + 이름·건수를 돌려준다. 설정도 저장되지 않는다.
     *
     * @scenario case=settings_delete_referenced_type_blocked
     *
     * @effects delete_blocked_shows_count_tooltip, sync_before_save_leaves_json_untouched_on_422
     *
     * @return void 반환값 없음
     */
    public function test_delete_referenced_type_returns_422_with_name_and_count_and_saves_nothing(): void
    {
        $like = ReactionType::where('code', 'like')->firstOrFail();
        $dislike = ReactionType::where('code', 'dislike')->firstOrFail();

        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);

        DB::table('board_reactions')->insert([
            'user_id' => $reactor->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reaction_type_id' => $dislike->id,
            'board_id' => $this->board->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'per_page' => 33,
                'reaction_types' => [
                    ['id' => $like->id, 'code' => 'like', 'name' => ['ko' => '추천'], 'is_active' => true],
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'reaction_type_in_use');

        // 메시지에 유형 이름과 건수가 들어가야 운영자가 무엇을 못 지우는지 안다.
        $this->assertStringContainsString('1', (string) $response->json('message'));

        $this->assertNotNull(ReactionType::find($dislike->id), '참조가 있는 유형은 남아야 합니다.');

        // 422 면 설정 파일은 손대지 않는다 (부분 저장 금지).
        $file = $this->settingsStoragePath.DIRECTORY_SEPARATOR.'basic_defaults.json';
        $this->assertFileDoesNotExist($file, '동기화 실패 시 설정 JSON 은 저장되지 않아야 합니다.');
    }

    /**
     * 관리자가 끈 유형은 시더를 다시 돌려도 켜지지 않는다 (user_overrides 보존).
     *
     * @scenario case=settings_deactivate_survives_seeder
     *
     * @effects deactivated_type_survives_seeder_resync
     *
     * @return void 반환값 없음
     */
    public function test_deactivated_type_survives_seeder_resync(): void
    {
        $like = ReactionType::where('code', 'like')->firstOrFail();
        $dislike = ReactionType::where('code', 'dislike')->firstOrFail();

        $this->actingAs($this->admin)->putJson(self::ENDPOINT, [
            '_tab' => 'basic_defaults',
            'basic_defaults' => [
                'reaction_types' => [
                    ['id' => $like->id, 'code' => 'like', 'name' => ['ko' => '추천'], 'is_active' => false],
                    ['id' => $dislike->id, 'code' => 'dislike', 'name' => ['ko' => '비추천'], 'is_active' => true],
                ],
            ],
        ])->assertOk();

        $this->assertFalse($like->fresh()->is_active);

        $this->seed(BoardReactionTypeSeeder::class);

        $this->assertFalse(
            $like->fresh()->is_active,
            'is_active 가 trackableFields 에 없으면 시더 재실행이 관리자의 비활성화를 되돌립니다.',
        );
    }

    /**
     * 카테고리별 조회(basic_defaults)에도 유형 행이 실린다.
     *
     * @scenario case=settings_read_lists_all_types_including_inactive
     *
     * @effects editor_iterates_form_reaction_types
     *
     * @return void 반환값 없음
     */
    public function test_settings_show_basic_defaults_appends_rows(): void
    {
        $response = $this->actingAs($this->admin)->getJson(self::ENDPOINT.'/basic_defaults');

        $response->assertOk();

        $rows = $response->json('data.settings.reaction_types');

        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);
    }
}
