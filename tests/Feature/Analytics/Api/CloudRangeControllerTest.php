<?php

namespace Tests\Feature\Analytics\Api;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * 클라우드 대역 갱신 API — 설정 화면의 「지금 갱신」 버튼.
 *
 * 주 1회 예약이 돌지만, 차단을 막 켠 운영자는 목록이 채워지기를 일주일 기다릴 수 없다.
 * 받아 오지 못했을 때 옛 목록을 지키는 것이 이 경로의 핵심이다.
 */
class CloudRangeControllerTest extends AnalyticsApiTestCase
{
    /**
     * 테스트 준비 — 이전 실행이 남긴 목록 파일 제거.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->removeList();
    }

    /**
     * 테스트 정리 — 남긴 목록 파일 제거.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        $this->removeList();

        parent::tearDown();
    }

    /**
     * 목록 파일을 지웁니다.
     *
     * @return void 없음
     */
    private function removeList(): void
    {
        if (File::exists($this->listPath())) {
            File::delete($this->listPath());
        }
    }

    /**
     * 목록 파일 경로를 돌려줍니다.
     *
     * @return string 절대 경로
     */
    private function listPath(): string
    {
        return storage_path('app/'.config('analytics.cloud_ip_ranges.storage_path', 'analytics/cloud-ip-ranges.json'));
    }

    /**
     * 갱신이 대역을 받아 저장하고 건수를 돌려주는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=cloud_ranges, role=update, confirm=given
     *
     * @effects cloud_ranges_are_downloaded_and_stored
     */
    public function test_refresh_stores_the_ranges_and_reports_the_count(): void
    {
        Http::fake([
            'ip-ranges.amazonaws.com/*' => Http::response(['prefixes' => [['ip_prefix' => '203.0.113.0/24']]]),
            'www.gstatic.com/*' => Http::response(['prefixes' => [['ipv4Prefix' => '198.51.100.0/24']]]),
        ]);

        $response = $this->actingAs($this->updateAdmin())
            ->postJson('/api/admin/analytics/cloud-ranges/refresh');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.count'));
        $this->assertNotNull($response->json('data.updated_at'));
        $this->assertTrue(File::exists($this->listPath()));
    }

    /**
     * 받아 오지 못하면 502 이고 옛 목록이 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=cloud_ranges, role=update, confirm=given
     *
     * @effects a_failed_download_keeps_the_existing_list
     */
    public function test_a_failed_refresh_keeps_the_previous_list(): void
    {
        File::ensureDirectoryExists(dirname($this->listPath()));
        File::put($this->listPath(), json_encode(['updated_at' => '2026-09-01T00:00:00+00:00', 'ranges' => ['192.0.2.0/24']]));

        Http::fake(['*' => Http::response([], 500)]);

        $this->actingAs($this->updateAdmin())
            ->postJson('/api/admin/analytics/cloud-ranges/refresh')
            ->assertStatus(502);

        $decoded = json_decode(File::get($this->listPath()), true);
        $this->assertSame(
            ['192.0.2.0/24'],
            $decoded['ranges'],
            '새 목록이 없다고 옛 목록을 버리면 그 순간부터 모든 데이터센터 요청이 사람으로 집계된다',
        );
    }

    /**
     * 조회 권한만으로는 갱신하지 못하는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=cloud_ranges, role=read
     *
     * @effects read_permission_cannot_reach_update_endpoints
     */
    public function test_read_permission_cannot_refresh(): void
    {
        Http::fake(['*' => Http::response(['prefixes' => [['ip_prefix' => '203.0.113.0/24']]])]);

        $this->actingAs($this->readAdmin())
            ->postJson('/api/admin/analytics/cloud-ranges/refresh')
            ->assertStatus(403);

        $this->assertFalse(File::exists($this->listPath()));
    }
}
