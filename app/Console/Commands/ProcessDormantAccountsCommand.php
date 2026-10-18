<?php

namespace App\Console\Commands;

use App\Privacy\DormantAccountService;
use App\Privacy\ManualRun\DormantStageRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 휴면 계정 생명주기 처리 커맨드 (2단계형)
 *
 * 한 번 실행에서 네 단계를 **이 순서로** 처리합니다.
 *
 * 1. 휴면 전환 사전 안내
 * 2. 휴면 전환 (안내 후 `dormant_notice_days` 경과분만)
 * 3. 탈퇴 예정 안내 (자동 탈퇴를 켠 경우)
 * 4. 탈퇴 처리 (안내 후 `dormant_notice_days` 경과분만)
 *
 * 순서가 중요합니다 — 안내를 먼저 보내야 다음 주기부터 전환·탈퇴 조건이 성립하므로,
 * **오늘 안내받은 회원이 오늘 전환되지 않습니다.** 해제(③)는 커맨드가 아니라 로그인 경로
 * (`AuthService`)가 담당하므로 여기에 없습니다.
 *
 * 회원 상태를 바꾸고 계정을 파기하므로 기본 꺼짐(운영자 옵트인)입니다.
 *
 * `--scheduled` 는 **판정에 쓰이지 않습니다** — `privacy.dormant_enabled` 재확인이 이 플래그와
 * 무관하게 항상 선행하므로 CLI 수동 실행도 꺼진 기능을 돌리지 못합니다(토글과 무관하게 한 단계를
 * 돌리는 길은 관리자 화면의 직권 실행 하나뿐이다 — `PrivacyManualRunService`). 선례
 * (`attachments:prune-orphans`)는 이 플래그를 읽어 "스케줄 실행일 때만" 조기 종료하지만,
 * 계정을 파기하는 커맨드에 그 느슨함을 두지 않았습니다. 플래그를 남겨 두는 것은
 * `config/schedule_security.php` 허용목록과 `routes/console.php` 등록이 옵션까지 일치해야
 * 하기 때문입니다.
 *
 * 관리자 직권 실행 큐 작업과 같은 락(`privacy-run:dormant`)을 잡는다 — 직권 실행이 처리 중이면
 * 이번 주기를 건너뛴다.
 *
 * @example php artisan privacy:process-dormant --dry-run
 * @example php artisan privacy:process-dormant --scheduled
 */
class ProcessDormantAccountsCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'privacy:process-dormant
                            {--dry-run : 실제 처리 없이 단계별 대상 건수만 확인}
                            {--scheduled : 스케줄러 호출 표시 (기록용) — 꺼짐 판정은 이 플래그와 무관하게 항상 선행한다}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '장기 미접속 회원에게 사전 안내를 보내고 휴면으로 전환하며, 설정에 따라 탈퇴까지 처리합니다.';

    /**
     * @param  DormantAccountService  $service  휴면 생명주기 서비스
     */
    public function __construct(private DormantAccountService $service)
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
            $this->info('휴면 계정 기능이 꺼져 있어 실행하지 않았습니다. (privacy.dormant_enabled = false)');

            return Command::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $prefix = $isDryRun ? '[DRY RUN] ' : '';

        // 관리자 직권 실행 큐 작업과 같은 락 — 네 단계가 같은 회원 행을 옮기므로 단계와 무관하게
        // 하나를 잡는다. 직권 실행이 처리 중이면 이번 주기는 건너뛰고 다음 주기에 맡긴다
        // (겹치면 같은 회원에게 안내가 두 번 나가거나 전환과 탈퇴가 엇갈린다).
        $lock = $isDryRun ? null : Cache::lock(DormantStageRunner::LOCK_KEY, 3600);

        if ($lock !== null && ! $lock->get()) {
            $this->warn('관리자 직권 실행이 휴면 단계를 처리 중이라 이번 주기는 건너뛰었습니다.');

            return Command::SUCCESS;
        }

        try {
            return $this->runStages($isDryRun, $prefix);
        } finally {
            $lock?->release();
        }
    }

    /**
     * 네 단계를 차례로 실행하고 보고합니다.
     *
     * @param  bool  $isDryRun  건수만 세는지 여부
     * @param  string  $prefix  출력 접두사
     * @return int 종료 코드
     */
    private function runStages(bool $isDryRun, string $prefix): int
    {
        // 단계 간 격리 — 한 단계가 통째로 던져도 나머지 단계는 돈다. 감싸지 않으면 1단계의
        // 예외 하나가 전환·탈퇴 예고·탈퇴를 그날 전부 건너뛰게 하고, 단계별 건수조차 출력되지
        // 않는다. 형제 `privacy:process-retention` 이 핸들러별로 같은 방어를 갖고 있다
        // ({@see \App\Privacy\PrivacyRetentionManager::process()}).
        $failed = 0;

        $preNotice = $this->runStage('pre_notice', fn (): int => $this->service->notifyUpcomingDormancy($isDryRun), $failed);
        $converted = $this->runStage('convert', fn (): int => $this->service->convertToDormant($isDryRun), $failed);
        $withdrawNotice = $this->runStage('withdraw_notice', fn (): int => $this->service->notifyUpcomingWithdrawal($isDryRun), $failed);
        $withdrawn = $this->runStage('withdraw', fn (): int => $this->service->withdrawDormant($isDryRun), $failed);

        $this->info("{$prefix}휴면 전환 사전 안내: {$preNotice}건");
        $this->info("{$prefix}휴면 전환: {$converted}건");

        if ($this->service->isWithdrawEnabled()) {
            $this->info("{$prefix}탈퇴 예정 안내: {$withdrawNotice}건");
            $this->info("{$prefix}탈퇴 처리: {$withdrawn}건");
        } else {
            $this->line("{$prefix}휴면 후 자동 탈퇴는 꺼져 있어 실행하지 않았습니다. (privacy.dormant_withdraw_enabled = false)");
        }

        if (! $isDryRun) {
            Log::info('ProcessDormantAccountsCommand: 휴면 생명주기 처리 완료', [
                // 시그니처가 `--scheduled` 를 「기록용」이라 선언하므로 실제로 기록한다
                // (설계 확정 2026-09-23). 이 값이 없으면 되돌릴 수 없는 파기·상태 변경이
                // 자동 실행이었는지 운영자가 손으로 돌린 것인지 사후에 구분할 수 없다 —
                // 코드로 등록한 예약은 `schedule_histories.trigger_type` 에 남지 않아
                // 이 로그가 그 구분의 유일한 자리다.
                'scheduled' => (bool) $this->option('scheduled'),
                'pre_notice' => $preNotice,
                'converted' => $converted,
                'withdraw_notice' => $withdrawNotice,
                'withdrawn' => $withdrawn,
                'failed_stages' => $failed,
                'dormant_days' => $this->service->dormantDays(),
                'notice_days' => $this->service->noticeDays(),
            ]);
        }

        // 실패 사실을 종료 코드로 알린다 — 형제 `privacy:process-retention` 과 같은 계약이다
        // (`$failed > 0 ? FAILURE : SUCCESS`). 성공만 돌려주면 단계가 매일 죽어도 스케줄러가
        // 영구히 정상으로 읽어, 개인정보 파기·안내가 조용히 멈춘 것을 아무도 모른다.
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 한 단계를 격리해 실행합니다.
     *
     * @param  string  $stage  단계 식별자 (로그·출력용)
     * @param  \Closure(): int  $run  단계 실행
     * @param  int  $failed  실패 단계 수 (참조로 증가)
     * @return int 처리 건수 (실패 시 0)
     */
    private function runStage(string $stage, \Closure $run, int &$failed): int
    {
        try {
            return $run();
        } catch (\Throwable $e) {
            $failed++;

            $this->error("단계 실패 — {$stage}: {$e->getMessage()}");

            Log::error('ProcessDormantAccountsCommand: 단계 실패 — 나머지 단계는 계속합니다', [
                'stage' => $stage,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
