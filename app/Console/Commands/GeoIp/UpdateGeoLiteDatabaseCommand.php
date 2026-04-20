<?php

namespace App\Console\Commands\GeoIp;

use App\Services\GeoIpDatabaseService;
use Illuminate\Console\Command;

/**
 * MaxMind GeoLite2 DB 다운로드/갱신 커맨드.
 *
 * 모든 비즈니스 로직은 GeoIpDatabaseService에 위임합니다.
 * 이 커맨드는 CLI 인터페이스(옵션 파싱 + 결과 출력)만 담당합니다.
 */
class UpdateGeoLiteDatabaseCommand extends Command
{
    protected $signature = 'geoip:update
        {--force : 파일이 이미 존재해도 재다운로드}
        {--dry-run : 실제 다운로드 없이 키/URL만 검증}';

    protected $description = 'MaxMind GeoLite2-City DB를 다운로드하여 storage/app/geoip/에 배치합니다.';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  GeoIpDatabaseService  $service  GeoIP DB 관리 서비스
     * @return int 종료 코드
     */
    public function handle(GeoIpDatabaseService $service): int
    {
        if ($this->option('dry-run')) {
            return $this->handleDryRun($service);
        }

        $result = $service->updateDatabase((bool) $this->option('force'));

        return $this->reportResult($result);
    }

    /**
     * dry-run 모드: 라이선스 키 마스킹 후 URL만 출력합니다.
     *
     * @param  GeoIpDatabaseService  $service  GeoIP DB 관리 서비스
     * @return int 종료 코드
     */
    private function handleDryRun(GeoIpDatabaseService $service): int
    {
        if (! $service->isLicenseKeyConfigured()) {
            $this->error('MaxMind 라이선스 키가 설정되지 않았습니다.');
            $this->line('관리자 > 환경설정 > 고급 탭에서 라이선스 키를 입력하세요.');

            return Command::FAILURE;
        }

        $maskedUrl = $service->buildDownloadUrlForDisplay(true);
        $this->info('[dry-run] 다운로드 URL 검증 완료');
        $this->line("  URL: {$maskedUrl}");

        return Command::SUCCESS;
    }

    /**
     * Service 결과를 CLI 출력으로 변환하고 exit code를 반환합니다.
     *
     * @param  array<string, mixed>  $result  Service::updateDatabase() 반환값
     * @return int 종료 코드
     */
    private function reportResult(array $result): int
    {
        $status = (string) ($result['status'] ?? '');
        $message = (string) ($result['message'] ?? '');

        if ($status === 'missing_license_key') {
            $this->error($message);
            $this->line('관리자 > 환경설정 > 고급 탭에서 라이선스 키를 입력하세요.');
            $this->line('무료 발급: https://www.maxmind.com/en/geolite2/signup');

            return Command::FAILURE;
        }

        if ($status === 'skipped') {
            $this->info($message);

            return Command::SUCCESS;
        }

        if ($status === 'updated') {
            $data = $result['data'] ?? [];
            $this->newLine();
            $this->info('✅ '.$message);
            $this->line(sprintf('  파일: %s', $data['database_path'] ?? '-'));
            $this->line(sprintf('  크기: %.2f MB', (int) ($data['file_size_bytes'] ?? 0) / 1048576));
            $this->line(sprintf('  소요 시간: %s초', $data['elapsed_seconds'] ?? '-'));

            return Command::SUCCESS;
        }

        // 그 외 모든 실패 상태
        $this->error($message !== '' ? $message : 'GeoIP DB 업데이트에 실패했습니다.');

        return Command::FAILURE;
    }
}
