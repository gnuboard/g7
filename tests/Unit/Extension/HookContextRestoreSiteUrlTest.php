<?php

namespace Tests\Unit\Extension;

use App\Extension\HookContextCapture;
use App\Extension\HookManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 큐 리스너 컨텍스트 복원 뒤의 URL 생성이 사이트 주소를 쓰는지 검증합니다.
 *
 * 복원은 원 요청의 경로만 담은 요청을 다시 바인딩한다. Host 가 없는 요청으로 재바인딩하면 그 안의
 * `url()`·`route()` 가 `http://localhost/…` 를 만들어 큐에서 만든 알림 링크가 깨진다.
 *
 * DB 를 쓰지 않는다(user_id 없이 복원).
 */
class HookContextRestoreSiteUrlTest extends TestCase
{
    /**
     * 훅 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::resetAll();
    }

    /**
     * 훅 상태를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::resetAll();
        parent::tearDown();
    }

    /**
     * 사이트 주소 변종.
     *
     * @return array<string, array{0: string, 1: string}> 사이트 주소와 기대 origin
     */
    public static function siteUrls(): array
    {
        return [
            'plain' => ['https://shop.example.test', 'https://shop.example.test'],
            'explicit port' => ['https://shop.example.test:8443', 'https://shop.example.test:8443'],
            'sub path' => ['https://shop.example.test/g7', 'https://shop.example.test'],
        ];
    }

    /**
     * 복원 뒤 url() 은 사이트 origin 을 쓰고, 요청 경로 판정은 그대로다.
     *
     * @scenario host_header=canonical, sink=hook_context
     *
     * @effects hook_context_restore_binds_site_origin
     *
     * @param  string  $siteUrl  사이트 주소
     * @param  string  $origin  기대 origin
     * @return void 반환값 없음
     */
    #[DataProvider('siteUrls')]
    public function test_restore_binds_site_origin(string $siteUrl, string $origin): void
    {
        config(['app.url' => $siteUrl]);

        HookContextCapture::restore(['path' => 'api/admin/settings', 'ip_address' => '198.51.100.7']);

        $this->assertStringStartsWith($origin.'/', url('/x'));
        $this->assertSame('api/admin/settings', request()->path());
        $this->assertTrue(request()->is('api/admin/*'));
        $this->assertSame('198.51.100.7', request()->ip());
    }

    /**
     * 사이트 주소가 없으면 종전처럼 경로만으로 재구성한다.
     *
     * @scenario host_header=canonical, sink=hook_context
     *
     * @effects hook_context_restore_binds_site_origin
     *
     * @return void 반환값 없음
     */
    public function test_restore_without_site_url_keeps_path_only(): void
    {
        config(['app.url' => '']);

        HookContextCapture::restore(['path' => 'api/x']);

        $this->assertSame('api/x', request()->path());
    }
}
