<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 관리자 반응 유형 목록 API Feature 테스트.
 *
 * GET admin/reaction-types 목록 조회, 다국어 라벨 폴백(확정 15), 시더 등록 확인,
 * 권한 경계를 검증한다 (이슈 #525 §10 테스트 범위).
 */
class ReactionTypeApiTest extends ModuleTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/admin/reaction-types';

    protected function setUp(): void
    {
        parent::setUp();

        // FK(restrictOnDelete) 순서: 이력 먼저 정리 후 유형 삭제 (타 테스트 잔여 데이터 대비)
        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);
    }

    /**
     * 권한 있는 관리자는 활성 유형 목록을 display_order 순으로 조회한다.
     */
    public function test_admin_can_list_active_reaction_types(): void
    {
        $admin = $this->createAdminUser(['sirsoft-board.settings.read']);

        $response = $this->actingAs($admin)
            ->withHeader('Accept-Language', 'ko')
            ->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('data.reaction_types.0.code', 'like')
            ->assertJsonPath('data.reaction_types.0.name', '추천')
            ->assertJsonPath('data.reaction_types.0.icon', 'fas fa-thumbs-up')
            ->assertJsonPath('data.reaction_types.0.icon_name', 'fa-thumbs-up')
            ->assertJsonPath('data.reaction_types.1.code', 'dislike')
            ->assertJsonPath('data.reaction_types.1.name', '비추천')
            ->assertJsonPath('data.reaction_types.1.icon_name', 'fa-thumbs-down');
    }

    /**
     * en 로케일에서는 영어 라벨을 반환한다.
     *
     * @scenario case=reaction_type_label_localized
     * @effects reaction_type_name_localized
     */
    public function test_reaction_type_name_localized_to_en(): void
    {
        $admin = $this->createAdminUser(['sirsoft-board.settings.read']);

        $this->actingAs($admin)
            ->withHeader('Accept-Language', 'en')
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.reaction_types.0.name', 'Recommend');
    }

    /**
     * 미입력 언어는 사이트 기본 언어(fallback)로 대체 노출된다 (확정 15).
     *
     * @scenario case=reaction_type_label_falls_back
     * @effects missing_locale_falls_back_to_default
     */
    public function test_missing_locale_falls_back(): void
    {
        // ja 미입력 유형을 하나 추가해 폴백 경로를 직접 검증
        ReactionType::create([
            'code' => 'love',
            'name' => ['ko' => '사랑', 'en' => 'Love'],
            'icon' => 'fas fa-heart',
            'display_order' => 3,
            'is_active' => true,
        ]);

        $admin = $this->createAdminUser(['sirsoft-board.settings.read']);

        $response = $this->actingAs($admin)
            ->withHeader('Accept-Language', 'ja')
            ->getJson(self::ENDPOINT);
        $response->assertOk();

        $love = collect($response->json('data.reaction_types'))->firstWhere('code', 'love');
        $this->assertNotNull($love);
        // ja 미입력 → fallback_locale(en) 또는 첫 값으로 폴백 (빈 문자열 아님)
        $this->assertNotSame('', $love['name']);
    }

    /**
     * 권한 없는 사용자는 403 으로 차단된다.
     */
    public function test_user_without_permission_forbidden(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->getJson(self::ENDPOINT)
            ->assertForbidden();
    }
}
