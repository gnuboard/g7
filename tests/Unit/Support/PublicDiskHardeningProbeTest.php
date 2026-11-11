<?php

namespace Tests\Unit\Support;

use App\Support\PublicDiskHardeningProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 공개 디스크 직접 주소의 보호 헤더 자기 점검.
 *
 * 규칙 파일을 만들어도 웹서버가 읽지 않으면(nginx, 재정의 금지 설정) 보호가 걸리지 않는다. 시험 파일을
 * 공개 디스크에 두고 직접 주소의 응답 헤더를 확인해, 걸리지 않았을 때만 관리자 화면이 경고한다.
 *
 * @scenario-manifest tests/scenarios/public-disk-exportable-extensions.yaml
 */
class PublicDiskHardeningProbeTest extends TestCase
{
    private string $directory;

    /**
     * 임시 공개 디스크 디렉터리와 사이트 주소를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/public-disk-probe-'.uniqid());
        mkdir($this->directory, 0777, true);

        config(['app.url' => 'https://probe.example.test', 'cache.default' => 'array']);
        PublicDiskHardeningProbe::forget();
    }

    /**
     * 임시 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * 남아 있는 시험 파일 수를 셉니다.
     *
     * @return int 파일 수
     */
    private function probeFilesLeft(): int
    {
        return count(glob($this->directory.'/g7-header-probe-*') ?: []);
    }

    /**
     * nosniff 와 내려받기 지시가 모두 있으면 정상이고, 요청은 `/storage` 의 시험 파일로 간다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=self_check__not_exportable
     *
     * @effects self_check_reports_ok_only_with_nosniff_and_attachment
     */
    public function test_ok_when_both_headers_are_present(): void
    {
        $requested = null;
        Http::fake(function (Request $request) use (&$requested) {
            $requested = $request->url();

            return Http::response('probe', 200, [
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => 'attachment',
            ]);
        });

        $result = PublicDiskHardeningProbe::run($this->directory);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['nosniff']);
        $this->assertTrue($result['disposition']);
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['checked_at']);
        $this->assertMatchesRegularExpression(
            '#^https://probe\.example\.test/storage/g7-header-probe-[a-z0-9]+\.txt$#',
            (string) $requested
        );
        $this->assertSame(0, $this->probeFilesLeft());
        $this->assertSame($result, PublicDiskHardeningProbe::cached());
    }

    /**
     * 헤더가 없으면(웹서버가 규칙을 읽지 않음) 비정상이다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=self_check__not_exportable
     *
     * @effects self_check_reports_ok_only_with_nosniff_and_attachment
     */
    public function test_not_ok_when_headers_are_missing(): void
    {
        Http::fake(fn () => Http::response('probe', 200, ['Content-Type' => 'text/plain']));

        $result = PublicDiskHardeningProbe::run($this->directory);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['nosniff']);
        $this->assertFalse($result['disposition']);
        $this->assertSame(0, $this->probeFilesLeft());
    }

    /**
     * 헤더 모듈이 없어 비이미지가 차단(403)된 응답도 비정상으로 알린다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=self_check__not_exportable
     *
     * @effects self_check_reports_ok_only_with_nosniff_and_attachment
     */
    public function test_not_ok_when_the_probe_file_is_denied(): void
    {
        Http::fake(fn () => Http::response('', 403));

        $result = PublicDiskHardeningProbe::run($this->directory);

        $this->assertFalse($result['ok']);
        $this->assertSame(403, $result['status']);
    }

    /**
     * 서버가 자기 주소에 닿지 못하면 판정하지 않는다(ok=null) — 경고를 띄우지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=self_check__not_exportable
     *
     * @effects self_check_unknown_when_site_unreachable
     */
    public function test_unknown_when_the_site_cannot_be_reached(): void
    {
        Http::fake(fn () => throw new ConnectionException('unreachable'));

        $result = PublicDiskHardeningProbe::run($this->directory);

        $this->assertNull($result['ok']);
        $this->assertSame(0, $this->probeFilesLeft());
    }

    /**
     * 리다이렉트를 따라가지 않는다 — 다른 주소의 헤더를 이 사이트의 것으로 읽지 않는다.
     *
     * 리다이렉트 응답은 보호 규칙이 적용됐는지 알려 주지 않으므로 「판정 불가」 다(경고를 내지 않는다).
     * 사이트 주소를 http 로 적어 둔 채 서버가 https 로 넘기는 사이트가 그렇다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=self_check__not_exportable
     *
     * @effects self_check_reports_ok_only_with_nosniff_and_attachment
     */
    public function test_redirect_is_not_followed(): void
    {
        $options = null;
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response('', 301, ['Location' => 'https://elsewhere.test/']);
        });

        $result = PublicDiskHardeningProbe::run($this->directory);

        $this->assertFalse($options['allow_redirects'] ?? null);
        $this->assertNull($result['ok']);
        $this->assertSame(301, $result['status']);
    }

    /**
     * 점검 파일을 찾지 못한 응답(404 — `/storage` 링크 없음 등)도 「판정 불가」 다.
     *
     * 파일이 실제로 내려온 응답(200)에 헤더가 없을 때와, 보호 규칙이 접근을 막은 응답(403)만 미적용으로 본다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=self_check__not_exportable
     *
     * @effects self_check_reports_ok_only_with_nosniff_and_attachment
     */
    public function test_unknown_when_the_probe_file_is_not_served(): void
    {
        // 가짜 응답은 먼저 등록한 것이 이긴다 — 한 번만 등록하고 상태만 바꿔 가며 잰다.
        $status = 0;
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });

        foreach ([302, 307, 404, 500, 503] as $status) {
            $result = PublicDiskHardeningProbe::run($this->directory);

            $this->assertNull($result['ok'], "상태 {$status}");
            $this->assertSame($status, $result['status']);
            $this->assertSame(0, $this->probeFilesLeft());
        }
    }

    /**
     * 판정 결과를 지우면 저장된 값이 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=self_check__exportable
     *
     * @effects self_check_result_cleared_when_public_disk_not_used
     */
    public function test_forget_clears_the_cached_result(): void
    {
        Http::fake(fn () => Http::response('probe', 200));
        PublicDiskHardeningProbe::run($this->directory);

        PublicDiskHardeningProbe::forget();

        $this->assertNull(PublicDiskHardeningProbe::cached());
    }
}
