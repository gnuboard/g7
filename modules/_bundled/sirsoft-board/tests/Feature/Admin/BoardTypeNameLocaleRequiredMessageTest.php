<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 유형 이름 다국어 필수 문구의 칸 이름 테스트
 *
 * 게시판 유형을 추가·수정하며 이름을 비우면 「한국어(ko) 값은 필수입니다.」처럼 칸 이름 없이 나왔다(7.1.0 전수 실측 K4,
 * 결정 2026-10-08 「코어 요청 6종 모두 칸 이름 넣기」). 두 요청이 같은 칸 이름(ko 「게시판 유형 이름」,
 * en 「Board Type Name」)을 싣는지 고정한다.
 *
 * @scenario-manifest tests/scenarios/board-type-name-locale-required.yaml
 */
class BoardTypeNameLocaleRequiredMessageTest extends ModuleTestCase
{
    /**
     * 게시판 생성 권한 관리자
     */
    protected User $adminUser;

    /**
     * 게시판 유형 테이블과 관리자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('board_types')) {
            $this->artisan('migrate', [
                '--path' => $this->getModuleBasePath().'/database/migrations',
                '--realpath' => true,
            ]);
        }

        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.create',
        ]);
    }

    /**
     * 생성·수정 요청에 빈 이름을 보내 받은 name 오류 문구를 모읍니다.
     *
     * @param  string  $locale  관리자 언어
     * @return array<string, string> 요청 이름 → 첫 오류 문구
     */
    private function collectNameErrors(string $locale): array
    {
        $this->adminUser->forceFill(['language' => $locale])->save();
        $boardType = BoardType::create([
            'slug' => 'test_attr_name_'.$locale,
            'name' => ['ko' => '유형', 'en' => 'Type'],
        ]);

        $store = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-board/admin/board-types', [
                'slug' => 'test-attr-'.$locale,
                'name' => ['ko' => '', 'en' => ''],
            ])
            ->assertStatus(422)
            ->json('errors.name.0');

        $update = $this->actingAs($this->adminUser)
            ->putJson("/api/modules/sirsoft-board/admin/board-types/{$boardType->id}", [
                'name' => ['ko' => '', 'en' => ''],
            ])
            ->assertStatus(422)
            ->json('errors.name.0');

        return ['store' => (string) $store, 'update' => (string) $update];
    }

    /**
     * 한국어 관리자에게 칸 이름 「게시판 유형 이름」과 언어 이름이 함께 나온다.
     *
     * @scenario locale=ko
     *
     * @effects board_type_name_locale_required_message_names_field
     *
     * @return void 반환값 없음
     */
    public function test_korean_message_names_board_type_name(): void
    {
        foreach ($this->collectNameErrors('ko') as $request => $message) {
            $this->assertSame('게시판 유형 이름의 한국어(ko) 값은 필수입니다.', $message, "[{$request}]");
        }
    }

    /**
     * 영어 관리자에게 칸 이름 「Board Type Name」과 언어 이름이 함께 나온다.
     *
     * @scenario locale=en
     *
     * @effects board_type_name_locale_required_message_names_field
     *
     * @return void 반환값 없음
     */
    public function test_english_message_names_board_type_name(): void
    {
        foreach ($this->collectNameErrors('en') as $request => $message) {
            $this->assertSame('The 한국어 (ko) value of the Board Type Name field is required.', $message, "[{$request}]");
        }
    }
}
