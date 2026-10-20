<?php

namespace App\Console\Commands;

use App\Extension\Helpers\FilePermissionHelper;
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
 * 판정은 `ExtensionStaticCacheService::statusReport()` 가 소유한다 — 관리자 화면의
 * 「초기 화면 정적 파일」 카드와 같은 값을 소비하며, 이 커맨드는 문구·조치 힌트만 담당한다.
 *
 * 출력: 실행 계정 / 현재 버전 / 게시 여부 / manifest 파일 수 / 게시 트리 쓰기 가능성 /
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

        $report = $service->statusReport();
        $base = $service->baseDir();
        $publishHint = self::publishCommandHint();

        $this->line('');
        $this->line('<options=bold>'.__('extensions.commands.ext_static_status.title').'</>');
        $this->line('');

        $this->line('  '.__('extensions.commands.ext_static_status.labels.process_user').' '.$report['process_user']);

        // root 로 실행 중이면 여기서 게시하지 말라고 먼저 알린다 — 캐시 락 샤드·병합 번들이 root
        // 소유로 남아 이후 웹 요청의 캐시 쓰기가 Permission denied 로 죽는다(전면 500 실사례).
        // 중단하지는 않는다 — 상태 점검은 읽기 전용이다. (#651 C1)
        if (self::isRootProcess()) {
            $this->line('  <comment>'.__('extensions.commands.ext_static_status.root_warning', ['command' => $publishHint]).'</comment>');
        }

        $this->line('  kill-switch    : '.($report['enabled']
            ? __('extensions.commands.ext_static_status.values.enabled_on')
            : '<comment>'.__('extensions.commands.ext_static_status.values.enabled_off').'</comment>'));
        $this->line('  '.__('extensions.commands.ext_static_status.labels.version').' '.$report['version']);
        $this->line('  '.__('extensions.commands.ext_static_status.labels.base').' '.$base);

        // kill-switch 가 꺼져 있으면 미게시는 정상이다 — 이상으로 세지 않는다.
        if (! $report['enabled']) {
            $this->line('');
            $this->comment(__('extensions.commands.ext_static_status.kill_switch_off'));

            return self::SUCCESS;
        }

        // 1) 게시 트리 쓰기 가능성 — 제보 본건(P1/P2)의 직접 지표
        if ($report['tree_writable']) {
            $this->line('  '.__('extensions.commands.ext_static_status.labels.tree_write').' '.__('extensions.commands.ext_static_status.values.writable_yes'));
        } else {
            $this->line('  '.__('extensions.commands.ext_static_status.labels.tree_write').' <error>'.__('extensions.commands.ext_static_status.values.writable_no').'</error>');
            $problems[] = __('extensions.commands.ext_static_status.problems.tree_not_writable', [
                'path' => $base,
                'owner' => (string) (@fileowner($base) ?: 'unknown'),
                'perms' => File::exists($base) ? substr(sprintf('%o', @fileperms($base)), -4) : 'absent',
            ]);
        }

        // 2) 현재 버전 게시 여부
        if ($report['published']) {
            $this->line('  '.__('extensions.commands.ext_static_status.labels.publish_state').' '.__('extensions.commands.ext_static_status.values.published'));
            $this->line('  '.__('extensions.commands.ext_static_status.labels.manifest_files').' '.__('extensions.commands.ext_static_status.values.files_count', ['count' => $report['files']]));
            $this->line('  '.__('extensions.commands.ext_static_status.labels.published_at').' '.($report['published_at'] ?? '?'));

            if ($report['files'] === 0) {
                $problems[] = __('extensions.commands.ext_static_status.problems.manifest_empty');
            }
        } else {
            $this->line('  '.__('extensions.commands.ext_static_status.labels.publish_state').' <comment>'.__('extensions.commands.ext_static_status.values.not_published').'</comment>');
            $problems[] = __('extensions.commands.ext_static_status.problems.not_published', [
                'version' => (int) $report['version'],
                'command' => $publishHint,
            ]);
        }

        // 3) 최근 실패 마커 — 원인별로 조치가 다르다
        $marker = $report['failure'];

        if ($marker !== null) {
            $this->line('');
            $this->line('  <error>'.__('extensions.commands.ext_static_status.failure_title').'</error>');
            $this->line('    '.__('extensions.commands.ext_static_status.labels.failure_reason').' '.($marker['reason'] ?? '?').' — '.$this->reasonHint($marker['reason'] ?? ''));
            $this->line('    '.__('extensions.commands.ext_static_status.labels.failure_version').' '.($marker['version'] ?? '?'));
            $this->line('    '.__('extensions.commands.ext_static_status.labels.failure_at').' '.($marker['at'] ?? '?'));
            $this->line('    '.__('extensions.commands.ext_static_status.labels.failure_count').' '.__('extensions.commands.ext_static_status.values.failure_count', ['count' => $marker['count'] ?? '?']));
            $this->line('    '.__('extensions.commands.ext_static_status.labels.failure_message').' '.($marker['message'] ?? ''));

            $problems[] = __('extensions.commands.ext_static_status.problems.failure_marker', ['reason' => $marker['reason'] ?? '?']);
        }

        // 4) 잔존 버전 목록 — 누적은 삭제 실패(소유권 불일치)의 지표다
        $versions = $report['retained_versions'];

        $this->line('');
        $this->line('  '.__('extensions.commands.ext_static_status.labels.retained_versions').' '.($versions === [] ? __('extensions.commands.ext_static_status.values.none') : implode(', ', $versions)));

        if (count($versions) > 3) {
            $problems[] = __('extensions.commands.ext_static_status.problems.versions_accumulated', ['count' => count($versions)]);
        }

        $this->line('');

        if ($problems === []) {
            $this->info(__('extensions.commands.ext_static_status.ok'));

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->warn('• '.$problem);
        }

        $this->line('');

        return self::FAILURE;
    }

    /**
     * 수동 게시 명령을 웹 계정 실행 형태로 안내합니다 — `ext-static:publish` 와 공유.
     *
     * root 가 아니면 명령만 그대로다(일반 SSH 사용자 = 파일 소유자 / 공유 호스팅 대칭 구성 / posix
     * 미지원). root 이면 웹서버 계정을 식별해 `sudo -u {계정}` 을 붙이고, 식별하지 못하면
     * placeholder 와 확인 방법을 함께 적는다 — 판정은 `FilePermissionHelper::describeWebServerAccount()`
     * 가 소유한다(`core:update` 재실행 안내와 같은 4분기).
     *
     * @return string 안내 명령 문자열
     */
    public static function publishCommandHint(): string
    {
        $command = 'php artisan ext-static:publish';
        $account = FilePermissionHelper::describeWebServerAccount();

        return match ($account['mode']) {
            'root_web_known' => 'sudo -u '.$account['name'].' '.$command,
            'root_web_unknown' => __('extensions.commands.ext_static_status.publish_hint_unknown_web', ['command' => $command]),
            default => $command,
        };
    }

    /**
     * 현재 프로세스가 root(euid 0)로 실행 중인지 판정합니다 — 안내 분기 전용.
     *
     * @return bool root 실행 여부
     */
    public static function isRootProcess(): bool
    {
        return FilePermissionHelper::describeWebServerAccount()['mode'] !== 'non_root';
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
            'parent_not_writable' => __('extensions.commands.ext_static_status.hints.parent_not_writable', ['command' => self::publishCommandHint()]),
            'write_failed' => __('extensions.commands.ext_static_status.hints.write_failed'),
            'lock_unavailable' => __('extensions.commands.ext_static_status.hints.lock_unavailable'),
            default => __('extensions.commands.ext_static_status.hints.default'),
        };
    }
}
