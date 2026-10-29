<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 관리자 API 권한 경계 테스트 (매니페스트 admin-permissions.yaml)
 *
 * 관리자 API 를 필요한 권한(read·update·delete)으로 묶고, 행위자 5종이 각 묶음의 모든 엔드포인트를 부를 때
 * 401·403·성공을 확인한다. 소유 범위(owner_key)가 없는 권한이라 범위 판정 케이스는 없다.
 */
class PopupPermissionTest extends PluginTestCase
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
     * 묶음의 엔드포인트를 모두 불러 기대 응답을 확인합니다.
     *
     * @param  string  $level  권한 묶음 (read, update, delete)
     * @param  string  $actorType  행위자
     * @param  string  $expected  기대 (401, 403, ok)
     * @return void 반환값 없음
     */
    private function assertGroup(string $level, string $actorType, string $expected): void
    {
        $user = $this->actor($actorType);

        foreach ($this->requests($level, $user) as $label => $request) {
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
     * 행위자를 만듭니다.
     *
     * @param  string  $actor  행위자 종류
     * @return User|null 회원 (비로그인이면 null)
     */
    private function actor(string $actor): ?User
    {
        return match ($actor) {
            'guest' => null,
            'no_permission' => User::factory()->create(),
            'read_only' => $this->adminWith(['sirsoft-popup.popups.read']),
            'editor' => $this->adminWith(['sirsoft-popup.popups.read', 'sirsoft-popup.popups.update']),
            'deleter' => $this->adminWith(['sirsoft-popup.popups.read', 'sirsoft-popup.popups.delete']),
        };
    }

    /**
     * 묶음별 요청 목록을 만듭니다 — 호출할 때마다 새 데이터를 만들어 앞 요청의 결과(삭제 등)에 영향받지 않게 합니다.
     *
     * @param  string  $level  권한 묶음
     * @param  User|null  $user  행위자 — 임시 이미지 삭제는 자기가 올린 이미지만 되므로 그 행위자가 올린 것으로 만든다
     * @return array<string, callable> 이름 => [메서드, 주소, 본문] 을 돌려주는 함수
     */
    private function requests(string $level, ?User $user): array
    {
        $popup = fn () => Popup::factory()->withImages(mobile: true)->create();

        return match ($level) {
            'read' => [
                'index' => fn () => ['GET', $this->api('/admin/popups'), []],
                'summary' => fn () => ['GET', $this->api('/admin/popups/summary'), []],
                'order' => fn () => ['GET', $this->api('/admin/popups/order'), []],
                'show' => fn () => ['GET', $this->api('/admin/popups/'.$popup()->id), []],
                'display-settings' => fn () => ['GET', $this->api('/admin/display-settings'), []],
            ],
            'update' => [
                'reorder' => function () use ($popup) {
                    $popup();

                    return ['PUT', $this->api('/admin/popups/order'), ['ids' => Popup::query()->orderBy('id')->pluck('id')->all()]];
                },
                'bulk-status' => fn () => ['PATCH', $this->api('/admin/popups/bulk-status'), ['ids' => [$popup()->id], 'is_active' => false]],
                'bulk-copy' => fn () => ['POST', $this->api('/admin/popups/bulk-copy'), ['ids' => [Popup::factory()->html()->create()->id]]],
                'store' => function () {
                    $tempKey = (string) Str::uuid();
                    PopupImage::factory()->pc()->temp($tempKey)->create();

                    return ['POST', $this->api('/admin/popups'), $this->popupPayload() + ['temp_key' => $tempKey]];
                },
                'update' => fn () => ['PUT', $this->api('/admin/popups/'.$popup()->id), $this->popupPayload()],
                'image-store' => fn () => ['POSTFILE', $this->api('/admin/popup-images'), ['file' => UploadedFile::fake()->image('a.jpg', 100, 50), 'device' => 'pc', 'temp_key' => (string) Str::uuid()]],
                'image-destroy' => fn () => ['DELETE', $this->api('/admin/popup-images/'.PopupImage::factory()->mobile()->temp((string) Str::uuid())->create(['created_by' => $user?->id])->id), []],
            ],
            'delete' => [
                'bulk' => fn () => ['DELETE', $this->api('/admin/popups/bulk'), ['ids' => [$popup()->id]]],
                'destroy' => fn () => ['DELETE', $this->api('/admin/popups/'.$popup()->id), []],
            ],
        };
    }
}
