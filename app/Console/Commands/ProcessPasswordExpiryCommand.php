<?php

namespace App\Console\Commands;

use App\Privacy\ManualRun\PasswordExpiryStageRunner;
use App\Services\PasswordExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 비밀번호 만료 처리 커맨드
 *
 * 한 번 실행에서 두 단계를 **이 순서로** 처리합니다.
 *
 * 1. 사전 안내 — 만료 N일 전 안내(메일 + 사이트 알림), 주기마다 1통
 * 2. 만료일 처리 — 만료 알림 1통 + ('변경해야 로그인' 모드면) 로그인 토큰 전부 폐기
 *
 * 두 단계 모두 '1회 실행당 안내 상한'(D25)을 각각 적용하고 만료가 가까운 순서로 처리한다. 남은
 * 인원은 다음 실행이 처리한다. 기본 꺼짐(운영자 옵트인) — `security.password_expiry_enabled` 를
 * `--scheduled` 와 무관하게 항상 먼저 확인한다. 관리자 화면 직권 실행도 꺼져 있으면 접수되지 않으므로
 * 토글과 무관하게 돌리는 길은 없다 — 꺼져 있으면 로그인 관문이 변경을 요구하지 않는다(#736).
 *
 * 관리자 직권 실행 큐 작업과 같은 락(`password-expiry-run`)을 잡는다 — 직권 실행이 처리 중이면
 * 이번 주기를 건너뛴다.
 *
 * @example php artisan auth:process-password-expiry --dry-run
 * @example php artisan auth:process-password-expiry --scheduled
 *
 * @since 7.1.0
 */
class ProcessPasswordExpiryCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'auth:process-password-expiry
                            {--dry-run : 실제 처리 없이 단계별 대상 건수만 확인}
                            {--scheduled : 스케줄러 호출 표시 (기록용) — 꺼짐 판정은 이 플래그와 무관하게 항상 선행한다}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '비밀번호 만료 예정 회원에게 사전 안내를 보내고, 만료된 회원에게 만료 알림을 보내며 설정에 따라 로그인을 해제합니다.';

    /**
     * @param  PasswordExpiryService  $service  비밀번호 만료 정책 서비스
     */
    public function __construct(private PasswordExpiryService $service)
    {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if (! $this->service->isEnabled()) {
            $this->info('비밀번호 만료 기능이 꺼져 있어 실행하지 않았습니다. (security.password_expiry_enabled = false)');

            return Command::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        $lock = $isDryRun ? null : Cache::lock(PasswordExpiryStageRunner::LOCK_KEY, 3600);

        if ($lock !== null && ! $lock->get()) {
            $this->warn('관리자 직권 실행이 비밀번호 만료 처리를 하고 있어 이번 주기는 건너뛰었습니다.');

            return Command::SUCCESS;
        }

        try {
            return $this->runStages($isDryRun, $prefix);
        } finally {
            $lock?->release();
        }
    }

    /**
     * 두 단계를 차례로 실행하고 보고합니다.
     *
     * @param  bool  $isDryRun  건수만 세는지 여부
     * @param  string  $prefix  출력 접두사
     * @return int 종료 코드
     */
    private function runStages(bool $isDryRun, string $prefix): int
    {
        // 단계 간 격리 — 사전 안내가 통째로 던져도 만료일 처리는 돈다.
        $failed = 0;

        $notice = $this->runStage('notice', fn (): array => $this->service->notifyUpcoming($isDryRun), $failed);
        $expire = $this->runStage('expire', fn (): array => $this->service->processExpired($isDryRun), $failed);

        $this->info("{$prefix}만료 사전 안내: {$notice['count']}건 (남은 대상 {$notice['remaining']}건)");
        $this->info("{$prefix}만료일 처리: {$expire['count']}건 (남은 대상 {$expire['remaining']}건)");

        if (! $isDryRun) {
            Log::info('ProcessPasswordExpiryCommand: 비밀번호 만료 처리 완료', [
                'scheduled' => (bool) $this->option('scheduled'),
                'notice' => $notice['count'],
                'notice_remaining' => $notice['remaining'],
                'expire' => $expire['count'],
                'expire_remaining' => $expire['remaining'],
                'failed_stages' => $failed,
                'expiry_days' => $this->service->days(),
                'notice_days' => $this->service->noticeDays(),
                'action' => $this->service->action()->value,
            ]);
        }

        // 실패 사실을 종료 코드로 알린다 — 성공만 돌려주면 단계가 매일 죽어도 스케줄러가 정상으로 읽는다.
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 한 단계를 격리해 실행합니다.
     *
     * @param  string  $stage  단계 식별자 (로그·출력용)
     * @param  \Closure(): array{count: int, remaining: int}  $run  단계 실행
     * @param  int  $failed  실패 단계 수 (참조로 증가)
     * @return array{count: int, remaining: int} 처리 건수·남은 건수 (실패 시 0)
     */
    private function runStage(string $stage, \Closure $run, int &$failed): array
    {
        try {
            return $run();
        } catch (\Throwable $e) {
            $failed++;

            $this->error("단계 실패 — {$stage}: {$e->getMessage()}");

            Log::error('ProcessPasswordExpiryCommand: 단계 실패 — 나머지 단계는 계속합니다', [
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);

            return ['count' => 0, 'remaining' => 0];
        }
    }
}
