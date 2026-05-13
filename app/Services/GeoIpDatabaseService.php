<?php

namespace App\Services;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MaxMind GeoLite2 데이터베이스 관리 서비스.
 *
 * 다운로드/압축 해제/원자적 교체/메타데이터 기록을 담당합니다.
 * GeoIpService(IP → 타임존 조회)와 책임이 분리되어 있습니다.
 */
class GeoIpDatabaseService
{
    public function __construct(
        private ConfigRepositoryInterface $configRepository
    ) {}

    /**
     * MaxMind GeoLite2 DB를 다운로드하여 원자적으로 교체합니다.
     *
     * @param  bool  $force  파일이 이미 존재해도 재다운로드
     * @return array{success: bool, status: string, message?: string, data?: array<string, mixed>}
     *         status: 'updated' | 'skipped' | 'missing_license_key' | 'download_failed' |
     *                 'unauthorized' | 'extract_failed' | 'file_not_found' | 'replace_failed' |
     *                 'connection_failed' | 'empty_response'
     */
    public function updateDatabase(bool $force = false): array
    {
        $licenseKey = (string) config('geoip.license_key', '');

        if ($licenseKey === '') {
            return [
                'success' => false,
                'status' => 'missing_license_key',
                'message' => 'MaxMind 라이선스 키가 설정되지 않았습니다.',
            ];
        }

        $finalPath = (string) config('geoip.database_path');

        // 최근 업데이트 건너뛰기 (force 없이, 24시간 이내)
        if (! $force && File::exists($finalPath)) {
            $ageSeconds = time() - File::lastModified($finalPath);
            if ($ageSeconds < 86400) {
                return [
                    'success' => true,
                    'status' => 'skipped',
                    'message' => sprintf('최근 %d시간 전에 업데이트되었습니다.', (int) floor($ageSeconds / 3600)),
                    'data' => $this->getDatabaseStatus(),
                ];
            }
        }

        $editionId = (string) config('geoip.download.edition_id', 'GeoLite2-City');

        // Before 훅: 확장이 업데이트 시작 시점에 부가 작업을 수행할 수 있음
        HookManager::doAction('core.geoip.database.before_update', [
            'edition_id' => $editionId,
            'force' => $force,
        ]);

        // Filter 훅: 확장이 다운로드 옵션을 변형할 수 있음 (edition_id, base_url 등 오버라이드 가능)
        $downloadContext = HookManager::applyFilters('core.geoip.database.filter_download_context', [
            'edition_id' => $editionId,
            'base_url' => (string) config('geoip.download.base_url', 'https://download.maxmind.com/app/geoip_download'),
            'timeout' => (int) config('geoip.download.timeout', 120),
            'retry_attempts' => (int) config('geoip.download.retry_attempts', 3),
            'retry_delay_ms' => (int) config('geoip.download.retry_delay_ms', 10000),
            'user_agent' => (string) config('geoip.download.user_agent', 'g7-core/1.0'),
        ]);

        $url = $this->buildDownloadUrl($licenseKey, (string) $downloadContext['edition_id'], (string) $downloadContext['base_url']);

        $geoipDir = dirname($finalPath);
        $tmpDir = $geoipDir.DIRECTORY_SEPARATOR.'.tmp';
        $archivePath = $tmpDir.DIRECTORY_SEPARATOR.$editionId.'.tar.gz';
        $extractPath = $tmpDir.DIRECTORY_SEPARATOR.'extract';

        try {
            $this->prepareDirectories($geoipDir, $tmpDir, $extractPath);

            $startedAt = microtime(true);

            // 1. 다운로드
            $downloadResult = $this->downloadArchive($url, $archivePath, $downloadContext);
            if (! $downloadResult['success']) {
                HookManager::doAction('core.geoip.database.after_update_failed', $downloadResult);

                return $downloadResult;
            }

            // 2. 압축 해제
            $extractResult = $this->extractArchive($archivePath, $extractPath);
            if (! $extractResult['success']) {
                HookManager::doAction('core.geoip.database.after_update_failed', $extractResult);

                return $extractResult;
            }

            // 3. mmdb 파일 탐색
            $extractedMmdb = $this->findMmdbFile($extractPath, $editionId);
            if ($extractedMmdb === null) {
                $result = [
                    'success' => false,
                    'status' => 'file_not_found',
                    'message' => '압축 파일 내부에서 mmdb 파일을 찾을 수 없습니다.',
                ];
                HookManager::doAction('core.geoip.database.after_update_failed', $result);

                return $result;
            }

            // 4. 원자적 교체
            if (! @rename($extractedMmdb, $finalPath)) {
                $result = [
                    'success' => false,
                    'status' => 'replace_failed',
                    'message' => 'mmdb 파일을 최종 경로로 이동할 수 없습니다.',
                ];
                HookManager::doAction('core.geoip.database.after_update_failed', $result);

                return $result;
            }

            // 5. 메타데이터 갱신
            $this->updateLastUpdatedAt();

            $elapsed = round(microtime(true) - $startedAt, 2);
            $finalSize = File::size($finalPath);

            Log::info('GeoIP DB updated successfully', [
                'path' => $finalPath,
                'size_bytes' => $finalSize,
                'elapsed_seconds' => $elapsed,
            ]);

            $result = [
                'success' => true,
                'status' => 'updated',
                'message' => 'GeoIP DB 업데이트가 완료되었습니다.',
                'data' => [
                    'database_path' => $finalPath,
                    'file_size_bytes' => $finalSize,
                    'elapsed_seconds' => $elapsed,
                    'last_updated_at' => date('c', File::lastModified($finalPath)),
                ],
            ];

            // After 훅: 확장이 업데이트 완료 시점에 부가 작업 수행 (캐시 워밍, 알림 등)
            HookManager::doAction('core.geoip.database.after_update', $result);

            return $result;
        } finally {
            // 임시 파일 정리 (성공/실패 무관)
            if (File::isDirectory($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }
        }
    }

    /**
     * 현재 DB 파일의 상태 정보를 반환합니다.
     *
     * @return array{exists: bool, database_path: string, file_size_bytes: int, last_updated_at: ?string}
     */
    public function getDatabaseStatus(): array
    {
        $finalPath = (string) config('geoip.database_path');
        $exists = File::exists($finalPath);

        return [
            'exists' => $exists,
            'database_path' => $finalPath,
            'file_size_bytes' => $exists ? File::size($finalPath) : 0,
            'last_updated_at' => $exists ? date('c', File::lastModified($finalPath)) : null,
        ];
    }

    /**
     * 라이선스 키 설정 여부를 확인합니다.
     *
     * @return bool 설정 여부
     */
    public function isLicenseKeyConfigured(): bool
    {
        return (string) config('geoip.license_key', '') !== '';
    }

    /**
     * 다운로드 URL(dry-run용 마스킹 포함)을 반환합니다.
     *
     * @param  bool  $masked  true면 라이선스 키를 마스킹
     * @return string 다운로드 URL
     */
    public function buildDownloadUrlForDisplay(bool $masked = true): string
    {
        $licenseKey = (string) config('geoip.license_key', '');
        $editionId = (string) config('geoip.download.edition_id', 'GeoLite2-City');
        $url = $this->buildDownloadUrl($licenseKey, $editionId);

        if ($masked && $licenseKey !== '') {
            return str_replace($licenseKey, $this->maskKey($licenseKey), $url);
        }

        return $url;
    }

    /**
     * 라이선스 키의 앞 4자만 남기고 나머지를 마스킹합니다.
     *
     * @param  string  $key  원본 키
     * @return string 마스킹된 키
     */
    public function maskKey(string $key): string
    {
        $length = strlen($key);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($key, 0, 4).str_repeat('*', $length - 4);
    }

    /**
     * 다운로드 URL을 조합합니다.
     *
     * @param  string  $licenseKey  라이선스 키
     * @param  string  $editionId  에디션 ID (예: GeoLite2-City)
     * @param  string|null  $baseUrl  베이스 URL (null이면 config 기본값)
     * @return string 전체 URL
     */
    private function buildDownloadUrl(string $licenseKey, string $editionId, ?string $baseUrl = null): string
    {
        $baseUrl ??= (string) config('geoip.download.base_url', 'https://download.maxmind.com/app/geoip_download');

        return sprintf('%s?edition_id=%s&license_key=%s&suffix=tar.gz', $baseUrl, $editionId, $licenseKey);
    }

    /**
     * 작업 디렉토리를 준비합니다.
     *
     * @param  string  $geoipDir  GeoIP 최종 디렉토리
     * @param  string  $tmpDir  임시 디렉토리
     * @param  string  $extractPath  추출 경로
     */
    private function prepareDirectories(string $geoipDir, string $tmpDir, string $extractPath): void
    {
        if (! File::isDirectory($geoipDir)) {
            File::makeDirectory($geoipDir, 0755, true);
        }
        if (File::isDirectory($tmpDir)) {
            File::deleteDirectory($tmpDir);
        }
        File::makeDirectory($tmpDir, 0755, true);
        File::makeDirectory($extractPath, 0755, true);
    }

    /**
     * 아카이브를 다운로드합니다.
     *
     * @param  string  $url  다운로드 URL
     * @param  string  $archivePath  저장 경로
     * @param  array<string, mixed>  $context  다운로드 옵션 (filter 훅으로 변형된 컨텍스트)
     * @return array{success: bool, status: string, message?: string} 결과 배열
     */
    private function downloadArchive(string $url, string $archivePath, array $context): array
    {
        $timeout = (int) ($context['timeout'] ?? 120);
        $retryAttempts = (int) ($context['retry_attempts'] ?? 3);
        $retryDelayMs = (int) ($context['retry_delay_ms'] ?? 10000);
        $userAgent = (string) ($context['user_agent'] ?? 'g7-core/1.0');

        try {
            // retry의 기본 $throw=true는 4xx/5xx 시 RequestException을 던집니다.
            // 우리는 응답을 받아 직접 상태 코드를 검사해야 하므로 throw=false를 지정합니다.
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout($timeout)
                ->retry($retryAttempts, $retryDelayMs, null, false)
                ->sink($archivePath)
                ->get($url);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'success' => false,
                'status' => 'connection_failed',
                'message' => '다운로드 연결 실패: '.$e->getMessage(),
            ];
        }

        if ($response->status() === 401) {
            return [
                'success' => false,
                'status' => 'unauthorized',
                'message' => '라이선스 키가 유효하지 않습니다. (HTTP 401)',
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'status' => 'download_failed',
                'message' => sprintf('다운로드 실패: HTTP %d', $response->status()),
            ];
        }

        if (! File::exists($archivePath) || File::size($archivePath) === 0) {
            return [
                'success' => false,
                'status' => 'empty_response',
                'message' => '다운로드된 파일이 비어 있습니다.',
            ];
        }

        return ['success' => true, 'status' => 'ok'];
    }

    /**
     * tar.gz 아카이브를 추출합니다.
     *
     * @param  string  $archivePath  아카이브 경로
     * @param  string  $extractPath  추출 대상 경로
     * @return array{success: bool, status: string, message?: string} 결과 배열
     */
    private function extractArchive(string $archivePath, string $extractPath): array
    {
        try {
            $phar = new \PharData($archivePath);
            $phar->extractTo($extractPath, null, true);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 'extract_failed',
                'message' => '압축 해제 실패: '.$e->getMessage(),
            ];
        }

        return ['success' => true, 'status' => 'ok'];
    }

    /**
     * 추출된 디렉토리에서 mmdb 파일을 찾습니다.
     *
     * MaxMind 아카이브는 `GeoLite2-City_YYYYMMDD/GeoLite2-City.mmdb` 형식입니다.
     *
     * @param  string  $extractPath  추출 경로
     * @param  string  $editionId  에디션 ID
     * @return string|null mmdb 파일 경로 또는 null
     */
    private function findMmdbFile(string $extractPath, string $editionId): ?string
    {
        $pattern = $extractPath.DIRECTORY_SEPARATOR.$editionId.'_*'.DIRECTORY_SEPARATOR.$editionId.'.mmdb';
        $found = glob($pattern);

        return ! empty($found) ? $found[0] : null;
    }

    /**
     * settings의 cache 카테고리에 geoip_last_updated_at을 기록합니다.
     */
    private function updateLastUpdatedAt(): void
    {
        try {
            $current = $this->configRepository->getCategory('geoip');
            $current['last_updated_at'] = now()->toIso8601String();
            $this->configRepository->saveCategory('geoip', $current);

            // Laravel config 캐시 재로드 강제
            Artisan::call('config:clear');
        } catch (\Throwable $e) {
            Log::warning('GeoIP last_updated_at 갱신 실패', ['error' => $e->getMessage()]);
        }
    }
}
