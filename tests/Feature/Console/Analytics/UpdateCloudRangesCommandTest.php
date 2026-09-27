<?php

namespace Tests\Feature\Console\Analytics;

use App\Services\Analytics\CloudIpRangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 클라우드 IP 대역 목록 갱신 — 못 받아 왔을 때 무엇을 지키는지.
 *
 * 새 목록이 없을 때 옛 목록이 사라지면 그 순간부터 모든 데이터센터 요청이 사람으로
 * 집계된다. 실패가 기존 파일을 건드리지 않는 것을 잠근다.
 */
class UpdateCloudRangesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 테스트 준비 — 이전 실행이 남긴 목록 파일 제거.
     *
     * @return void 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (File::exists($this->listPath())) {
            File::delete($this->listPath());
        }
    }

    /**
     * 테스트 정리 — 남긴 목록 파일 제거.
     *
     * @return void 없음
     */
    protected function tearDown(): void
    {
        if (File::exists($this->listPath())) {
            File::delete($this->listPath());
        }

        parent::tearDown();
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
     * 두 출처의 대역이 파일 하나로 모이는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=bot_hits, row_age=within
     *
     * @effects cloud_ranges_are_downloaded_and_stored, cloud_range_status_reports_presence_and_time
     */
    public function test_downloaded_ranges_are_stored_in_one_file(): void
    {
        Http::fake([
            'ip-ranges.amazonaws.com/*' => Http::response([
                'prefixes' => [['ip_prefix' => '203.0.113.0/24']],
                'ipv6_prefixes' => [['ipv6_prefix' => '2001:db8::/32']],
            ]),
            'www.gstatic.com/*' => Http::response([
                'prefixes' => [['ipv4Prefix' => '198.51.100.0/24']],
            ]),
        ]);

        $this->artisan('analytics:update-cloud-ranges')->assertSuccessful();

        $this->assertTrue(File::exists($this->listPath()));

        $decoded = json_decode(File::get($this->listPath()), true);
        $this->assertContains('203.0.113.0/24', $decoded['ranges']);
        $this->assertContains('2001:db8::/32', $decoded['ranges']);
        $this->assertContains('198.51.100.0/24', $decoded['ranges']);

        $status = app(CloudIpRangeService::class)->status();
        $this->assertTrue($status['present']);
        $this->assertSame(3, $status['count']);
        $this->assertNotNull($status['updated_at']);
    }

    /**
     * 받아 오지 못했을 때 기존 파일이 남는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=bot_hits, row_age=within
     *
     * @effects a_failed_download_keeps_the_existing_list
     */
    public function test_a_failed_download_keeps_the_previous_list(): void
    {
        File::ensureDirectoryExists(dirname($this->listPath()));
        File::put($this->listPath(), json_encode([
            'updated_at' => '2026-09-01T00:00:00+00:00',
            'ranges' => ['192.0.2.0/24'],
        ]));

        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('analytics:update-cloud-ranges')->assertFailed();

        $decoded = json_decode(File::get($this->listPath()), true);
        $this->assertSame(['192.0.2.0/24'], $decoded['ranges'], '새 목록이 없다고 옛 목록을 버리면 차단이 통째로 풀린다');
    }

    /**
     * 목록 파일이 없을 때 상태가 그렇게 보고되는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario target=bot_hits, row_age=within
     *
     * @effects cloud_range_status_reports_presence_and_time
     */
    public function test_status_reports_a_missing_list(): void
    {
        $status = app(CloudIpRangeService::class)->status();

        $this->assertFalse($status['present']);
        $this->assertSame(0, $status['count']);
    }
}
