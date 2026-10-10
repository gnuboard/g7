<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Role;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 비회원 글쓰기 화면의 공지 옵션 노출 회귀 테스트 (#703)
 *
 * 회귀 배경: guest 역할에 공지 등록 권한(posts.write-notice)을 주면 글쓰기 화면이 비회원에게도 공지
 * 체크박스를 보였다. 그러나 서버는 비회원의 공지 지정을 항상 거부해(요청 검증·컨트롤러 판정 모두
 * 로그인 필수) 체크하고 저장하면 오류만 났다. 화면 게이트(can_write_notice)도 같은 기준이어야 한다.
 *
 * 같은 폼 메타 응답의 첨부 최대 크기도 업로드 검증과 같은 기본값 폴백을 따라야 한다(업로더 안내·
 * 클라이언트 차단 기준이 서버와 어긋나지 않도록).
 */
#[Group('board')]
class GuestNoticeAbilityTest extends BoardTestCase
{
    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'guest-notice-ability';
    }

    /**
     * 파일 업로드를 켜고 최대 파일 크기를 비운 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비회원 공지 테스트 게시판', 'en' => 'Guest Notice Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_size' => null,
        ];
    }

    /**
     * guest 역할에 공지 등록 권한이 있어도 비회원 폼 메타는 공지 옵션을 열지 않는다.
     *
     * @scenario actor=guest, guest_notice_permission=granted
     *
     * @effects guest_notice_option_hidden
     *
     * @return void 없음
     */
    public function test_guest_form_meta_does_not_offer_notice(): void
    {
        $this->setGuestPermissions(['posts.read', 'posts.write', 'posts.write-notice']);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/form-meta");

        $response->assertOk();
        $this->assertFalse((bool) $response->json('data.board.user_abilities.can_write_notice'));
    }

    /**
     * 게시판 최대 파일 크기가 비어 있으면 폼 메타도 게시판 기본값을 내려 준다.
     *
     * @scenario actor=guest, guest_notice_permission=none
     *
     * @effects form_meta_max_file_size_falls_back_to_defaults
     *
     * @return void 없음
     */
    public function test_form_meta_max_file_size_falls_back_to_defaults(): void
    {
        $this->updateBoardSettings(['max_file_size' => null]);
        Config::set('g7_settings.modules.sirsoft-board.basic_defaults.max_file_size', 30);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/form-meta");

        $response->assertOk()->assertJsonPath('data.board.max_file_size', 30);
    }

    /**
     * 같은 프로세스에서 guest 역할이 바뀌면 다음 응답의 비회원 능력도 새 역할을 따른다.
     *
     * 게시판 리소스가 guest 역할을 함수 정적 변수에 담으면 프로세스가 끝날 때까지 첫 역할 행을 붙잡아,
     * 장기 실행 프로세스(그리고 같은 PHPUnit 프로세스의 다음 테스트)가 다시 만든 역할의 권한을 보지 못한다.
     * 코어 미들웨어·Gate 와 같은 요청 단위 해석기(GuestRoleResolver)를 써야 한다.
     *
     * @scenario actor=guest, guest_notice_permission=none
     *
     * @effects guest_abilities_follow_guest_role_changes_in_same_process
     *
     * @return void 없음
     */
    public function test_guest_abilities_follow_recreated_guest_role_in_same_process(): void
    {
        $this->setGuestPermissions(['posts.read', 'posts.write']);

        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/form-meta";
        $this->assertTrue((bool) $this->getJson($url)->assertOk()->json('data.board.user_abilities.can_write'));

        // guest 역할 행을 새로 만든다 — 옛 행은 이름을 바꾸고 이 게시판 권한을 뗀다.
        $oldGuest = Role::where('identifier', 'guest')->firstOrFail();
        $boardPermissionIds = $oldGuest->permissions()
            ->where('identifier', 'like', "sirsoft-board.{$this->board->slug}.%")
            ->pluck('permissions.id');
        $oldGuest->permissions()->detach($boardPermissionIds);
        DB::table('roles')->where('id', $oldGuest->id)->update(['identifier' => 'guest_retired_for_test']);

        $newGuest = Role::create([
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
        ]);
        $newGuest->permissions()->sync($boardPermissionIds);
        $this->resetPermissionMiddlewareCache();

        $this->assertNotSame($oldGuest->id, $newGuest->id);
        $this->assertTrue(
            (bool) $this->getJson($url)->assertOk()->json('data.board.user_abilities.can_write'),
            '옛 guest 역할 행을 붙잡고 있으면 새 역할의 글쓰기 권한을 보지 못한다.'
        );
    }
}
