<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * /storage/{path} 자동 서빙 라우트 미노출 회귀 테스트 (공개#52).
 *
 * local/modules/plugins 디스크의 serve => false 로 Laravel 이 자동 생성하던
 * GET/PUT /storage/{path}(storage.local/modules/plugins(.upload)) 라우트가
 * 더 이상 등록되지 않는지 검증한다. 첨부(attachments)·확장 에셋은 이 라우트를
 * 사용하지 않으므로 무손상.
 */
class StorageServeRouteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * storage serve 라우트(GET/PUT)가 라우트 목록에 등록되지 않았는지 테스트합니다.
     *
     * @scenario route_state=after_fix_serve_false, http_method=GET, http_method=PUT
     *
     * @effects storage_local_serve_route_unregistered, storage_modules_serve_route_unregistered, storage_plugins_serve_route_unregistered
     *
     * @return void 반환값 없음
     */
    public function test_storage_serve_routes_are_not_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->all();

        // 프레임워크 자동 라우트 이름은 `storage.{disk}` **접두**다. 포함 검사로 두면 G7 자신의
        // 공개 스토리지 서빙 라우트(`api.public.system.public-storage.extensionless`)까지 걸린다 —
        // 그 라우트는 읽기 전용 대체 통로이지 이 검사가 막는 자동 PUT 입구가 아니다.
        foreach ($names as $name) {
            $this->assertStringStartsNotWith(
                'storage.',
                (string) $name,
                "serve 자동 라우트가 여전히 등록됨: {$name}"
            );
        }
    }

    /**
     * PUT /storage/{path}(업로드 입구)가 더 이상 처리되지 않는지 테스트합니다.
     *
     * serve 라우트가 살아 있으면 PUT 이 ReceiveFile 로 라우팅되지만, 제거 후에는
     * PUT 을 처리하는 라우트가 없어 405(Method Not Allowed) 또는 404 가 된다.
     * (GET-only SPA catch-all 만 남으므로 PUT 은 메서드 미허용.)
     *
     * @scenario route_state=after_fix_serve_false, http_method=PUT
     *
     * @effects storage_put_upload_entry_not_handled
     *
     * @return void 반환값 없음
     */
    public function test_storage_put_upload_route_is_not_handled(): void
    {
        $response = $this->put('/storage/foo.txt?upload=1', [], [
            'Content-Type' => 'text/plain',
        ]);

        $this->assertContains(
            $response->getStatusCode(),
            [404, 405],
            'PUT /storage 업로드 입구가 여전히 처리됨 (serve 라우트 잔존)'
        );
    }

    /**
     * GET storage serve 라우트 이름이 라우트 컬렉션에 존재하지 않는지 테스트합니다.
     *
     * (GET /storage/foo 는 SPA catch-all 이 흡수하므로 HTTP status 가 아닌
     * 라우트 이름 부재로 serve 라우트 제거를 검증한다.)
     *
     * @scenario route_state=after_fix_serve_false, http_method=GET, disk=local, disk=modules, disk=plugins
     *
     * @effects storage_local_serve_route_unregistered, storage_modules_serve_route_unregistered, storage_plugins_serve_route_unregistered
     *
     * @return void 반환값 없음
     */
    public function test_storage_serve_route_names_absent(): void
    {
        foreach (['storage.local', 'storage.modules', 'storage.plugins', 'storage.public'] as $name) {
            $this->assertNull(
                Route::getRoutes()->getByName($name),
                "serve 라우트 {$name} 이 여전히 등록됨"
            );
        }
    }

    /**
     * 로컬 디스크 전부가 `serve => false` 를 **명시**한다 (15차 G3 라운드).
     *
     * `public` 디스크만 선언이 없었다. 프레임워크 기본값이 오늘은 미서빙이라 무증상이지만,
     * 형제 여섯 디스크와 달리 이 디스크만 기본값에 기대면 프레임워크가 기본값을 바꾸는 순간
     * 인증 없는 `PUT /storage/{path}` 입구가 이 디스크에서만 열린다 — 공개#52 가 닫은 바로
     * 그 입구다. 선언은 디스크 정의에서 도출하므로 디스크가 늘면 이 검사가 함께 넓어진다.
     *
     * @scenario route_state=after_fix_serve_false, disk=local, disk=modules, disk=plugins
     *
     * @effects every_local_disk_declares_serve_false_explicitly
     *
     * @return void 반환값 없음
     */
    public function test_every_local_disk_declares_serve_false_explicitly(): void
    {
        $undeclared = [];

        foreach ((array) config('filesystems.disks') as $name => $disk) {
            if (($disk['driver'] ?? null) !== 'local') {
                continue;
            }

            if (! array_key_exists('serve', $disk) || $disk['serve'] !== false) {
                $undeclared[] = $name;
            }
        }

        $this->assertSame([], $undeclared, '로컬 디스크가 serve => false 를 명시하지 않는다: '.implode(', ', $undeclared));
    }
}
