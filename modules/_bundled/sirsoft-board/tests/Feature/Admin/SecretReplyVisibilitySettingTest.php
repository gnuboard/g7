<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Board\Enums\SecretReplyVisibility;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 설정 「비밀 답변 열람 범위」(secret_reply_visibility) 저장·기본값·일괄 적용 (#789 D19)
 *
 * @scenario link=board-secret-thread-visibility
 */
class SecretReplyVisibilitySettingTest extends ModuleTestCase
{
    /**
     * 게시판·환경설정 관리자
     */
    private User $adminUser;

    /**
     * 설정 저장 디렉터리
     */
    private string $settingsStoragePath;

    /**
     * 관리자와 설정 저장소를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.read',
            'sirsoft-board.boards.update',
            'sirsoft-board.settings.read',
            'sirsoft-board.settings.update',
        ]);

        $this->settingsStoragePath = ExtensionStoragePath::module('sirsoft-board', 'settings');
        if (File::isDirectory($this->settingsStoragePath)) {
            File::deleteDirectory($this->settingsStoragePath);
        }
    }

    /**
     * 설정 저장소와 시험 게시판을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::isDirectory($this->settingsStoragePath)) {
            File::deleteDirectory($this->settingsStoragePath);
        }
        Board::where('slug', 'like', 'srv-test-%')->delete();

        parent::tearDown();
    }

    /**
     * 시험 게시판을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return Board 게시판
     */
    private function makeBoard(array $attributes = []): Board
    {
        return Board::factory()->create([
            'slug' => 'srv-test-'.substr(md5(uniqid('', true)), 0, 8),
            ...$attributes,
        ]);
    }

    /**
     * 새 게시판은 기본값 경로 규칙으로 시작하고, 모델은 Enum 으로 읽습니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects secret_reply_visibility_defaults_to_thread_path
     *
     * @return void 반환값 없음
     */
    public function test_new_board_defaults_to_thread_path(): void
    {
        $board = Board::create([
            'name' => ['ko' => '기본값', 'en' => 'Default'],
            'slug' => 'srv-test-default',
            'type' => 'basic',
        ])->fresh();

        $this->assertSame(SecretReplyVisibility::ThreadPath, $board->secret_reply_visibility);
    }

    /**
     * 게시판 수정 API 로 값을 저장하고 응답·DB 에 그대로 남습니다. 잘못된 값은 422 입니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects secret_reply_visibility_saved_via_board_update
     *
     * @return void 반환값 없음
     */
    public function test_board_update_saves_value(): void
    {
        $board = $this->makeBoard();

        $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$board->id}", ['secret_reply_visibility' => 'descendants_only'])
            ->assertOk()
            ->assertJsonPath('data.secret_reply_visibility', 'descendants_only');

        $this->assertSame(SecretReplyVisibility::DescendantsOnly, $board->fresh()->secret_reply_visibility);

        $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/boards/{$board->id}", ['secret_reply_visibility' => 'everyone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['secret_reply_visibility']);
    }

    /**
     * 환경설정의 새 게시판 기본값으로 저장하고, 일괄 적용으로 기존 게시판에 반영합니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects secret_reply_visibility_bulk_applied
     *
     * @return void 반환값 없음
     */
    public function test_settings_default_and_bulk_apply(): void
    {
        $board = $this->makeBoard(['secret_reply_visibility' => 'thread_path']);

        $this->actingAs($this->adminUser)
            ->putJson('/api/modules/sirsoft-board/admin/settings', [
                'basic_defaults' => ['secret_reply_visibility' => 'per_post'],
            ])
            ->assertOk();
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults.secret_reply_visibility', 'per_post');

        $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/settings/bulk-apply', [
                'fields' => ['secret_reply_visibility'],
                'apply_all' => false,
                'board_ids' => [$board->id],
            ])
            ->assertOk();

        $this->assertSame(SecretReplyVisibility::PerPost, $board->fresh()->secret_reply_visibility);
    }
}
