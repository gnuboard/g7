<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 관리자 API 권한 경계 테스트 (매니페스트 admin-permissions.yaml)
 *
 * 관리자 API 14개를 필요한 권한(read·update·delete)으로 묶고, 행위자 5종이 각 묶음의 모든 엔드포인트를 부를 때
 * 401·403·성공을 확인한다. 소유 범위(owner_key)가 없는 권한이라 범위 판정 케이스는 없다(설계 결정 — 조회·등록·수정·삭제 3권한, 소유 범위 없음).
 */
class BannerPermissionTest extends PluginTestCase
{
    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크에 쓴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
    }

    /**
     * 조회 묶음 엔드포인트 전부 — 비로그인 → 401.
     *
     * @scenario permission_level=read, actor=guest
     *
     * @effects unauthenticated_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_read_endpoints_for_guest(): void
    {
        $this->assertGroup('read', 'guest', '401');
    }

    /**
     * 조회 묶음 엔드포인트 전부 — 권한 없는 회원 → 403.
     *
     * @scenario permission_level=read, actor=no_permission
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_read_endpoints_for_no_permission(): void
    {
        $this->assertGroup('read', 'no_permission', '403');
    }

    /**
     * 조회 묶음 엔드포인트 전부 — 조회만 가진 관리자 → 성공.
     *
     * @scenario permission_level=read, actor=read_only
     *
     * @effects granted_permission_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_read_endpoints_for_read_only(): void
    {
        $this->assertGroup('read', 'read_only', 'ok');
    }

    /**
     * 「플러그인 보기」(core.plugins.read)만 있는 관리자 — 플러그인 설정 화면이 받는 위치 설정(업로드 한도)은 읽고,
     * 배너 업무 조회는 계속 403.
     *
     * 설정 화면은 코어 계약대로 `core.plugins.read` 로 읽기 전용 진입하는데 이 조회가 `banners.read` 만 받아,
     * 들어갈 때마다 「해당 권한이 없습니다」 알림이 떴다.
     *
     * @scenario permission_level=read, actor=plugin_viewer
     *
     * @effects plugin_viewer_reads_settings_screen_upload_limit
     *
     * @return void 반환값 없음
     */
    public function test_plugin_viewer_reads_position_settings_only(): void
    {
        $viewer = $this->adminWith(['core.plugins.read']);

        $this->actingAs($viewer)->getJson($this->api('/admin/position-settings'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['upload' => ['max_mb', 'server_limit_mb', 'effective_mb']]]);
        $this->actingAs($viewer)->getJson($this->api('/admin/banners?position=home_hero'))->assertForbidden();
    }

    /**
     * 조회 묶음 엔드포인트 전부 — 조회+등록·수정 관리자 → 성공.
     *
     * @scenario permission_level=read, actor=editor
     *
     * @effects granted_permission_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_read_endpoints_for_editor(): void
    {
        $this->assertGroup('read', 'editor', 'ok');
    }

    /**
     * 조회 묶음 엔드포인트 전부 — 조회+삭제 관리자 → 성공.
     *
     * @scenario permission_level=read, actor=deleter
     *
     * @effects granted_permission_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_read_endpoints_for_deleter(): void
    {
        $this->assertGroup('read', 'deleter', 'ok');
    }

    /**
     * 등록·수정 묶음 엔드포인트 전부 — 비로그인 → 401.
     *
     * @scenario permission_level=update, actor=guest
     *
     * @effects unauthenticated_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_update_endpoints_for_guest(): void
    {
        $this->assertGroup('update', 'guest', '401');
    }

    /**
     * 등록·수정 묶음 엔드포인트 전부 — 권한 없는 회원 → 403.
     *
     * @scenario permission_level=update, actor=no_permission
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_update_endpoints_for_no_permission(): void
    {
        $this->assertGroup('update', 'no_permission', '403');
    }

    /**
     * 등록·수정 묶음 엔드포인트 전부 — 조회만 가진 관리자 → 403.
     *
     * @scenario permission_level=update, actor=read_only
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_update_endpoints_for_read_only(): void
    {
        $this->assertGroup('update', 'read_only', '403');
    }

    /**
     * 등록·수정 묶음 엔드포인트 전부 — 조회+등록·수정 관리자 → 성공.
     *
     * @scenario permission_level=update, actor=editor
     *
     * @effects granted_permission_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_update_endpoints_for_editor(): void
    {
        $this->assertGroup('update', 'editor', 'ok');
    }

    /**
     * 등록·수정 묶음 엔드포인트 전부 — 조회+삭제 관리자 → 403.
     *
     * @scenario permission_level=update, actor=deleter
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_update_endpoints_for_deleter(): void
    {
        $this->assertGroup('update', 'deleter', '403');
    }

    /**
     * 삭제 묶음 엔드포인트 전부 — 비로그인 → 401.
     *
     * @scenario permission_level=delete, actor=guest
     *
     * @effects unauthenticated_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_delete_endpoints_for_guest(): void
    {
        $this->assertGroup('delete', 'guest', '401');
    }

    /**
     * 삭제 묶음 엔드포인트 전부 — 권한 없는 회원 → 403.
     *
     * @scenario permission_level=delete, actor=no_permission
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_delete_endpoints_for_no_permission(): void
    {
        $this->assertGroup('delete', 'no_permission', '403');
    }

    /**
     * 삭제 묶음 엔드포인트 전부 — 조회만 가진 관리자 → 403.
     *
     * @scenario permission_level=delete, actor=read_only
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_delete_endpoints_for_read_only(): void
    {
        $this->assertGroup('delete', 'read_only', '403');
    }

    /**
     * 삭제 묶음 엔드포인트 전부 — 조회+등록·수정 관리자 → 403.
     *
     * @scenario permission_level=delete, actor=editor
     *
     * @effects missing_permission_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_delete_endpoints_for_editor(): void
    {
        $this->assertGroup('delete', 'editor', '403');
    }

    /**
     * 삭제 묶음 엔드포인트 전부 — 조회+삭제 관리자 → 성공.
     *
     * @scenario permission_level=delete, actor=deleter
     *
     * @effects granted_permission_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_delete_endpoints_for_deleter(): void
    {
        $this->assertGroup('delete', 'deleter', 'ok');
    }

    /**
     * 행위자를 만듭니다(비로그인은 null).
     *
     * @param  string  $actor  행위자 종류
     * @return User|null 회원
     */
    private function actor(string $actor): ?User
    {
        return match ($actor) {
            'guest' => null,
            'no_permission' => User::factory()->create(),
            'read_only' => $this->adminWith(['sirsoft-hero_section.banners.read']),
            'editor' => $this->adminWith(['sirsoft-hero_section.banners.read', 'sirsoft-hero_section.banners.update']),
            'deleter' => $this->adminWith(['sirsoft-hero_section.banners.read', 'sirsoft-hero_section.banners.delete']),
        };
    }

    /**
     * 묶음의 엔드포인트를 모두 불러 기대 결과를 확인합니다.
     *
     * @param  string  $level  권한 묶음 (read, update, delete)
     * @param  string  $actorType  행위자 종류
     * @param  string  $expected  기대 결과 (401, 403, ok)
     * @return void 반환값 없음
     */
    private function assertGroup(string $level, string $actorType, string $expected): void
    {
        $user = $this->actor($actorType);

        foreach ($this->requests($level) as $label => $request) {
            [$method, $uri, $data] = $request();
            $call = $user ? $this->actingAs($user) : $this;
            $response = $method === 'POSTFILE'
                ? $call->post($uri, $data, ['Accept' => 'application/json'])
                : $call->json($method, $uri, $data);

            $status = $response->getStatusCode();
            if ($expected === 'ok') {
                $this->assertTrue($status >= 200 && $status < 300, "{$label} 기대 2xx, 실제 {$status}: ".$response->getContent());
            } else {
                $this->assertSame((int) $expected, $status, "{$label} 기대 {$expected}, 실제 {$status}");
            }
        }
    }

    /**
     * 묶음별 요청 목록을 만듭니다 — 호출할 때마다 새 데이터를 만들어 앞 요청의 결과(삭제 등)에 영향받지 않게 합니다.
     *
     * @param  string  $level  권한 묶음
     * @return array<string, callable> 이름 => [메서드, 주소, 본문] 을 돌려주는 함수
     */
    private function requests(string $level): array
    {
        $banner = fn () => Banner::factory()->withImages(mobile: true)->create();

        return match ($level) {
            'read' => [
                'index' => fn () => ['GET', $this->api('/admin/banners?position=home_hero'), []],
                'preview' => fn () => ['GET', $this->api('/admin/banners/preview?position=home_hero'), []],
                'order' => fn () => ['GET', $this->api('/admin/banners/order?position=home_hero'), []],
                'show' => fn () => ['GET', $this->api('/admin/banners/'.$banner()->id), []],
                'position-settings' => fn () => ['GET', $this->api('/admin/position-settings'), []],
            ],
            'update' => [
                'reorder' => function () use ($banner) {
                    $banner();

                    return ['PUT', $this->api('/admin/banners/order'), ['position' => 'home_hero', 'ids' => Banner::query()->where('position', 'home_hero')->pluck('id')->all()]];
                },
                'bulk-status' => fn () => ['PATCH', $this->api('/admin/banners/bulk-status'), ['ids' => [$banner()->id], 'is_active' => false]],
                'store' => function () {
                    $tempKey = (string) Str::uuid();
                    BannerImage::factory()->pc()->temp($tempKey)->create();

                    return ['POST', $this->api('/admin/banners'), $this->bannerPayload() + ['temp_key' => $tempKey]];
                },
                'update' => fn () => ['PUT', $this->api('/admin/banners/'.$banner()->id), $this->bannerPayload()],
                'image-store' => fn () => ['POSTFILE', $this->api('/admin/banner-images'), ['file' => UploadedFile::fake()->image('a.jpg', 100, 50), 'device' => 'pc', 'temp_key' => (string) Str::uuid()]],
                'image-destroy' => fn () => ['DELETE', $this->api('/admin/banner-images/'.BannerImage::factory()->mobile()->temp((string) Str::uuid())->create()->id), []],
                'position-setting' => fn () => ['PUT', $this->api('/admin/position-settings/home_hero'), ['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'fade']],
            ],
            'delete' => [
                'bulk' => fn () => ['DELETE', $this->api('/admin/banners/bulk'), ['ids' => [$banner()->id]]],
                'destroy' => fn () => ['DELETE', $this->api('/admin/banners/'.$banner()->id), []],
            ],
        };
    }
}
