<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsClock;
use App\Services\SettingsService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 클라우드 IP 대역 목록 갱신
 *
 * 데이터센터에서 오는 요청을 거르려면 그 대역이 어디인지 알아야 하는데, 그 목록은
 * 사업자가 바꿉니다. 이 서비스가 공개 목록을 내려받아 파일로 둡니다.
 *
 * 목록을 못 받아 왔다고 해서 기존 파일을 지우지 않습니다 — 새 목록이 없을 때 옛
 * 목록이 사라지면 그 순간부터 모든 데이터센터 요청이 사람으로 집계됩니다.
 */
class CloudIpRangeService
{
    /**
     * 목록 한 곳을 기다릴 시간 (초)
     */
    private const TIMEOUT_SECONDS = 30;

    /**
     * 갱신기를 생성합니다.
     *
     * @param  SettingsService  $settings  설정 서비스
     */
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * 공개 목록을 내려받아 파일로 저장합니다.
     *
     * @return array{count: int, updated_at: string|null, sources: array<string, int>, failed: array<int, string>}
     *                                                                                                             갱신 결과
     */
    public function refresh(): array
    {
        $sources = (array) config('analytics.cloud_ip_ranges.sources', []);
        $ranges = [];
        $perSource = [];
        $failed = [];

        foreach ($sources as $name => $url) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)->get((string) $url);

                if (! $response->successful()) {
                    $failed[] = (string) $name;

                    continue;
                }

                $parsed = $this->extractPrefixes($response->json() ?? []);
                $perSource[(string) $name] = count($parsed);
                $ranges = array_merge($ranges, $parsed);
            } catch (Throwable $e) {
                $failed[] = (string) $name;
                Log::warning('클라우드 IP 대역 목록을 받지 못했습니다', [
                    'source' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $ranges = array_values(array_unique($ranges));

        if ($ranges === []) {
            return [
                'count' => 0,
                'updated_at' => null,
                'sources' => $perSource,
                'failed' => $failed,
            ];
        }

        $updatedAt = AnalyticsClock::now()->toIso8601String();
        $this->write($ranges, $updatedAt);
        $this->settings->saveSettings([
            '_tab' => 'analytics',
            'analytics' => ['cloud_ip_ranges_updated_at' => $updatedAt],
        ]);

        return [
            'count' => count($ranges),
            'updated_at' => $updatedAt,
            'sources' => $perSource,
            'failed' => $failed,
        ];
    }

    /**
     * 저장된 목록의 상태를 돌려줍니다.
     *
     * @return array{present: bool, count: int, updated_at: string|null} 목록 상태
     */
    public function status(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return ['present' => false, 'count' => 0, 'updated_at' => null];
        }

        try {
            $decoded = json_decode(File::get($path), true);
        } catch (Throwable) {
            return ['present' => true, 'count' => 0, 'updated_at' => null];
        }

        return [
            'present' => true,
            'count' => is_array($decoded['ranges'] ?? null) ? count($decoded['ranges']) : 0,
            'updated_at' => $decoded['updated_at'] ?? null,
        ];
    }

    /**
     * 사업자별 응답에서 대역 문자열만 뽑습니다.
     *
     * 두 사업자의 응답 형태가 달라 키 이름이 다릅니다.
     *
     * @param  array<string, mixed>  $payload  응답 본문
     * @return array<int, string> 대역 목록
     */
    private function extractPrefixes(array $payload): array
    {
        $ranges = [];

        foreach ((array) ($payload['prefixes'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (['ip_prefix', 'ipv4Prefix', 'ipv6Prefix'] as $key) {
                if (isset($entry[$key]) && is_string($entry[$key])) {
                    $ranges[] = $entry[$key];
                }
            }
        }

        foreach ((array) ($payload['ipv6_prefixes'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['ipv6_prefix']) && is_string($entry['ipv6_prefix'])) {
                $ranges[] = $entry['ipv6_prefix'];
            }
        }

        return $ranges;
    }

    /**
     * 목록을 파일로 씁니다.
     *
     * @param  array<int, string>  $ranges  대역 목록
     * @param  string  $updatedAt  갱신 시각
     * @return void 없음
     */
    private function write(array $ranges, string $updatedAt): void
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'updated_at' => $updatedAt,
            'ranges' => $ranges,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * 목록 파일 경로를 돌려줍니다.
     *
     * @return string 절대 경로
     */
    private function path(): string
    {
        return storage_path('app/'.config('analytics.cloud_ip_ranges.storage_path', 'analytics/cloud-ip-ranges.json'));
    }
}
