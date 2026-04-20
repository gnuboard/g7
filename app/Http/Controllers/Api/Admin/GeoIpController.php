<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Services\GeoIpDatabaseService;
use Illuminate\Http\JsonResponse;

/**
 * GeoIP DB 관리 컨트롤러.
 *
 * 수동 DB 업데이트 트리거를 제공합니다. 정기 갱신은
 * routes/console.php의 스케줄(geoip:update)이 담당합니다.
 * 비즈니스 로직은 모두 GeoIpDatabaseService에 위임합니다.
 */
class GeoIpController extends AdminBaseController
{
    public function __construct(
        private GeoIpDatabaseService $geoIpDatabaseService
    ) {
        parent::__construct();
    }

    /**
     * MaxMind GeoLite2-City DB를 즉시 재다운로드합니다.
     *
     * 내부적으로 GeoIpDatabaseService::updateDatabase(true)를 호출합니다.
     * 다운로드는 동기 실행이므로 PHP-FPM/웹서버 타임아웃(90초 이상) 필요.
     *
     * @return JsonResponse 업데이트 결과 JSON 응답
     */
    public function update(): JsonResponse
    {
        $result = $this->geoIpDatabaseService->updateDatabase(true);

        if ($result['success']) {
            return $this->success(
                'settings.geoip.update_success',
                $result['data'] ?? null
            );
        }

        return $this->error(
            $this->mapStatusToMessageKey((string) $result['status']),
            $this->mapStatusToHttpCode((string) $result['status']),
            $result['message'] ?? null
        );
    }

    /**
     * Service 상태 코드를 i18n 메시지 키로 매핑합니다.
     *
     * @param  string  $status  Service 상태 코드
     * @return string i18n 키
     */
    private function mapStatusToMessageKey(string $status): string
    {
        return match ($status) {
            'missing_license_key' => 'settings.geoip.license_key_missing',
            'unauthorized' => 'settings.geoip.license_key_invalid',
            'connection_failed' => 'settings.geoip.connection_failed',
            default => 'settings.geoip.update_failed',
        };
    }

    /**
     * Service 상태 코드를 HTTP 상태 코드로 매핑합니다.
     *
     * @param  string  $status  Service 상태 코드
     * @return int HTTP 상태 코드
     */
    private function mapStatusToHttpCode(string $status): int
    {
        return match ($status) {
            'missing_license_key' => 400,
            'unauthorized' => 401,
            default => 500,
        };
    }
}
