<?php

namespace App\Console\Commands;

use App\Services\ExtensionStaticCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * 부트스트랩 리소스 정적 게시 상태 점검 커맨드 (#122)
 *
 * 게시 실패는 사이트를 멈추지 않는다 — API 폴백으로 넘어가 화면은 정상이다. 그래서 정상
 * 운영 환경에서 실패를 확인할 방법이 사실상 없었다(`/dev` 대시보드는 `app.debug` 가 필요한데
 * 게시는 프로덕션 전용이다). 이 커맨드가 그 통로다.
 *
 * 출력: 현재 버전 / 게시 여부 / manifest 파일 수 / 게시 트리 쓰기 가능성 /
 *       최근 실패 마커 / 잔존 버전 목록.
 *
 * 종료 코드: 이상이 하나라도 있으면 비-0. 비-0 은 "커맨드 실행 실패" 가 아니라
 *          **이상 발견 신호**다 — 운영자가 조치할 대상이 있다는 뜻이다.
 */
class StatusExtensionStaticCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ext-static:status';

    /**
     * The console command description.
     */
    protected $description = '부트스트랩 리소스 정적 게시 상태를 점검합니다 (이상 발견 시 비-0 종료)';

    /**
     * Execute the console command.
     *
     * @param  ExtensionStaticCacheService  $service  정적 게시 서비스
     * @return int 이상 없으면 0, 이상 발견 시 1
     */
    public function handle(ExtensionStaticCacheService $service): int
    {
        $problems = [];

        $enabled = $service->isEnabled();
        $version = ExtensionStaticCacheService::getExtensionCacheVersion();
        $base = $service->baseDir();
        $versionDir = $service->versionDir($version);
        $published = $service->isPublished($version);

        $this->line('');
        $this->line('<options=bold>부트스트랩 리소스 정적 게시 상태</>');
        $this->line('');

        $this->line('  실행 계정      : '.ExtensionStaticCacheService::currentProcessUser());
        $this->line('  kill-switch    : '.($enabled ? '활성 (게시함)' : '<comment>비활성 (게시 안 함)</comment>'));
        $this->line('  현재 캐시 버전 : '.$version);
        $this->line('  게시 루트      : '.$base);

        // kill-switch 가 꺼져 있으면 미게시는 정상이다 — 이상으로 세지 않는다.
        if (! $enabled) {
            $this->line('');
            $this->comment('kill-switch 가 꺼져 있어 게시 상태는 점검하지 않습니다 (core.static_cache.enabled).');

            return self::SUCCESS;
        }

        // 1) 게시 트리 쓰기 가능성 — 제보 본건(P1/P2)의 직접 지표
        $writable = File::isDirectory($base) ? is_writable($base) : $this->parentIsWritable($base);

        if ($writable) {
            $this->line('  트리 쓰기      : 가능');
        } else {
            $this->line('  트리 쓰기      : <error>불가</error>');
            $problems[] = sprintf(
                '게시 트리에 쓸 수 없습니다 (%s, owner=%s, perms=%s). 웹 계정이 재게시할 수 없어 '
                .'모든 요청이 API 폴백으로 동작합니다.',
                $base,
                (string) (@fileowner($base) ?: 'unknown'),
                File::exists($base) ? substr(sprintf('%o', @fileperms($base)), -4) : 'absent'
            );
        }

        // 2) 현재 버전 게시 여부
        if ($published) {
            $manifest = json_decode((string) @file_get_contents($versionDir.'/manifest.json'), true);
            $fileCount = is_array($manifest) ? count($manifest['files'] ?? []) : 0;

            $this->line('  게시 상태      : 완료');
            $this->line('  manifest 파일  : '.$fileCount.'건');
            $this->line('  게시 시각      : '.(is_array($manifest) ? ($manifest['published_at'] ?? '?') : '?'));

            if ($fileCount === 0) {
                $problems[] = 'manifest 에 기록된 파일이 0건입니다 — 게시가 비어 있습니다.';
            }
        } else {
            $this->line('  게시 상태      : <comment>미게시</comment>');
            $problems[] = sprintf(
                '현재 버전(%d)이 게시되지 않았습니다. 다음 웹 렌더의 자가 치유가 시도하며, '
                .'즉시 게시하려면 `php artisan ext-static:publish` 를 실행하세요.',
                $version
            );
        }

        // 3) 최근 실패 마커 — 원인별로 조치가 다르다
        $marker = ExtensionStaticCacheService::failureMarker();

        if ($marker !== null) {
            $this->line('');
            $this->line('  <error>최근 게시 실패</error>');
            $this->line('    사유       : '.($marker['reason'] ?? '?').' — '.$this->reasonHint($marker['reason'] ?? ''));
            $this->line('    버전       : '.($marker['version'] ?? '?'));
            $this->line('    시각       : '.($marker['at'] ?? '?'));
            $this->line('    연속 실패  : '.($marker['count'] ?? '?').'회');
            $this->line('    상세       : '.($marker['message'] ?? ''));

            $problems[] = '게시 실패 마커가 남아 있습니다 (사유: '.($marker['reason'] ?? '?').').';
        }

        // 4) 잔존 버전 목록 — 누적은 삭제 실패(소유권 불일치)의 지표다
        $versions = $this->publishedVersions($base);

        $this->line('');
        $this->line('  잔존 버전      : '.($versions === [] ? '(없음)' : implode(', ', $versions)));

        if (count($versions) > 3) {
            $problems[] = sprintf(
                '게시 버전이 %d개 누적됐습니다 (정상은 현재+직전 2개). 삭제가 실패하고 있을 수 '
                .'있습니다 — 소유권/권한을 확인하세요.',
                count($versions)
            );
        }

        $this->line('');

        if ($problems === []) {
            $this->info('이상 없음.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->warn('• '.$problem);
        }

        $this->line('');

        return self::FAILURE;
    }

    /**
     * 게시 루트가 아직 없을 때, 만들 수 있는지(부모 쓰기 가능) 판정합니다.
     *
     * @param  string  $base  게시 루트 절대 경로
     * @return bool 생성 가능 여부
     */
    private function parentIsWritable(string $base): bool
    {
        $parent = dirname($base);

        return File::isDirectory($parent) && is_writable($parent);
    }

    /**
     * 실패 사유 코드에 대한 조치 힌트를 반환합니다.
     *
     * 사유별로 볼 곳이 다르다 — 뭉뚱그리면 운영자가 어디를 봐야 할지 알 수 없다.
     *
     * @param  string  $reason  사유 코드
     * @return string 조치 힌트
     */
    private function reasonHint(string $reason): string
    {
        return match ($reason) {
            'parent_not_writable' => '게시 트리 권한 문제입니다. CLI 계정과 웹 계정이 그룹을 공유하고 '
                .'`public/build` 가 그룹 쓰기(g+w)인지 확인하세요.',
            'write_failed' => '쓰기 도중 실패했습니다. 디스크 여유 공간과 quota 를 확인하세요.',
            'lock_unavailable' => '캐시 락을 얻지 못했습니다. 캐시 저장소(파일 캐시 디렉토리 권한 등)를 확인하세요.',
            default => '상세 메시지를 확인하세요.',
        };
    }

    /**
     * 게시 루트에 남아 있는 버전 디렉토리 목록을 반환합니다 (오름차순).
     *
     * @param  string  $base  게시 루트 절대 경로
     * @return array<int, int> 버전 목록
     */
    private function publishedVersions(string $base): array
    {
        if (! File::isDirectory($base)) {
            return [];
        }

        $versions = [];

        foreach (File::directories($base) as $dir) {
            $name = basename($dir);

            if (ctype_digit($name)) {
                $versions[] = (int) $name;
            }
        }

        sort($versions);

        return $versions;
    }
}
