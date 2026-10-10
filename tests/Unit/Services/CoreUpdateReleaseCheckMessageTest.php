<?php

namespace Tests\Unit\Services;

use App\Services\CoreUpdateService;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 코어 업데이트 확인이 돌려주는 실패 사유 문구에 자리표시자가 남지 않는지 검증합니다 (#703).
 *
 * GitHub 가 200 을 주면서 릴리스 정보(tag_name)가 없을 때, 관리자 화면의 업데이트 확인 실패 사유에
 * 「(HTTP :status — :message)」가 그대로 나왔다. 같은 문구를 쓰는 바로 위 분기는 값을 넘기는데 이 분기만
 * 빠져 있었다.
 *
 * 시나리오 매니페스트: tests/scenarios/core-update-release-check-message.yaml
 */
class CoreUpdateReleaseCheckMessageTest extends TestCase
{
    /**
     * 최신 릴리스 조회를 실행합니다.
     *
     * @return array{version: string|null, error: string|null} 조회 결과
     */
    private function fetchLatest(): array
    {
        $method = new ReflectionMethod(CoreUpdateService::class, 'fetchLatestVersionFromGithub');
        $method->setAccessible(true);

        return $method->invoke(app(CoreUpdateService::class), 'https://github.com/example-owner/example-repo');
    }

    /**
     * 200 응답에 릴리스 정보가 없으면 「릴리스 없음」 사유에 상태 코드가 들어가고 자리표시자가 남지 않는다.
     *
     * @scenario response=ok_without_release
     *
     * @effects release_check_reason_has_no_placeholder
     *
     * @return void 반환값 없음
     */
    public function test_ok_response_without_release_reports_reason_without_placeholders(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'no release'], 200)]);

        $result = $this->fetchLatest();

        $this->assertNull($result['version']);
        $this->assertIsString($result['error']);
        $this->assertStringNotContainsString(':status', $result['error']);
        $this->assertStringNotContainsString(':message', $result['error']);
        $this->assertStringContainsString('200', $result['error']);
    }
}
