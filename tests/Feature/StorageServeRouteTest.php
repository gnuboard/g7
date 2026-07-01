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
     */
    public function test_storage_serve_routes_are_not_registered(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->all();

        foreach ($names as $name) {
            $this->assertStringNotContainsString(
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
     */
    public function test_storage_serve_route_names_absent(): void
    {
        foreach (['storage.local', 'storage.modules', 'storage.plugins'] as $name) {
            $this->assertNull(
                Route::getRoutes()->getByName($name),
                "serve 라우트 {$name} 이 여전히 등록됨"
            );
        }
    }
}
