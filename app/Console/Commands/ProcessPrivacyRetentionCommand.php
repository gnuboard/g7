<?php

namespace App\Console\Commands;

use App\Privacy\PrivacyRetentionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 보유기간 경과 개인정보 파기·익명화 커맨드
 *
 * 등록된 보유기간 핸들러(코어 + 확장)를 모아 실행합니다. 어떤 도메인이 무엇을 지우는지는
 * 각 핸들러가 알고, 이 커맨드는 활성 여부와 건수만 취합해 보고합니다.
 *
 * 개인정보를 실제로 파기하므로 **전 핸들러 기본 꺼짐**(운영자 옵트인)입니다. 각 핸들러가
 * 자기 설정을 false 폴백으로 재확인하므로 `--scheduled` 무인 실행이 켜지지 않은 도메인을
 * 건드리지 않습니다.
 *
 * `--scheduled` 는 **판정에 쓰이지 않습니다** — 토글 재확인이 이 플래그와 무관하게 항상
 * 일어나므로 CLI 수동 실행도 꺼진 도메인을 건드리지 못합니다(토글과 무관하게 처리하는 길은
 * 관리자 화면의 직권 실행 하나뿐이다 — `PrivacyManualRunService`, 핸들러 `process(force: true)`).
 * 선례(`attachments:prune-orphans`)는 이 플래그를 읽어 "스케줄 실행일 때만" 조기 종료하는데,
 * 되돌릴 수 없는 파기에는 그 느슨함을
 * 두지 않았습니다. 플래그를 남겨 두는 것은 `config/schedule_security.php` 허용목록과
 * `routes/console.php` 등록이 옵션까지 일치해야 하기 때문입니다.
 *
 * 핸들러마다 관리자 직권 실행 큐 작업과 같은 락을 잡는다 — 직권 실행이 처리 중인 도메인은
 * 「건너뜀」으로 보고하고 다음 주기에 맡긴다.
 *
 * 출력은 "꺼짐" 과 "대상 0건" 을 구분합니다 — 둘을 같은 0 으로 뭉개면 운영자가 켠 줄 아는
 * 기능이 실제로는 놀고 있어도 알아챌 수 없습니다.
 *
 * @example php artisan privacy:process-retention --dry-run
 * @example php artisan privacy:process-retention --handler=user-withdrawn
 */
class ProcessPrivacyRetentionCommand extends Command
{
    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'privacy:process-retention
                            {--dry-run : 실제 처리 없이 대상 건수만 확인}
                            {--handler= : 특정 핸들러 하나만 실행 (식별자)}
                            {--scheduled : 스케줄러 호출 표시 (기록용) — 토글 재확인은 이 플래그와 무관하게 항상 수행한다}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '보유기간이 지난 개인정보를 각 도메인 핸들러에 위임해 파기·익명화합니다.';

    /**
     * @param  PrivacyRetentionManager  $manager  보유기간 처리 진입점
     */
    public function __construct(private PrivacyRetentionManager $manager)
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
        $isDryRun = (bool) $this->option('dry-run');
        $only = $this->option('handler');

        // `--handler=`(빈 값)을 「전 핸들러」로 해석하지 않는다 — 한 도메인만 돌리려던 운영자의
        // 오타·빈 변수 치환이 코어와 모든 확장의 되돌릴 수 없는 파기로 번진다. 옵션을 아예
        // 주지 않은 것만 전 핸들러 실행이다.
        if ($only !== null && trim((string) $only) === '') {
            $this->error('--handler 에 핸들러 식별자를 지정하세요. 전 핸들러를 실행하려면 옵션을 빼세요.');
            $this->line('등록된 핸들러: '.(implode(', ', array_keys($this->manager->handlers())) ?: '없음'));

            return Command::FAILURE;
        }

        $only = $only === null ? null : trim((string) $only);

        if ($only !== null && $this->manager->handler($only) === null) {
            $this->error("등록되지 않은 보유기간 핸들러입니다: {$only}");
            $this->line('등록된 핸들러: '.(implode(', ', array_keys($this->manager->handlers())) ?: '없음'));

            return Command::FAILURE;
        }

        $results = $this->manager->process($isDryRun, $only);

        if ($results === []) {
            $this->info('등록된 보유기간 핸들러가 없습니다.');

            return Command::SUCCESS;
        }

        $prefix = $isDryRun ? '[DRY RUN] ' : '';
        $total = 0;
        $failed = 0;

        foreach ($results as $id => $result) {
            if ($result['status'] === 'disabled') {
                $this->line("{$prefix}{$id} ({$result['label']}): 꺼져 있어 실행하지 않았습니다.");

                continue;
            }

            if ($result['status'] === 'locked') {
                // 관리자가 화면에서 직권 실행한 큐 작업이 이 도메인을 처리하는 중이다 — 겹쳐 돌리지
                // 않고 다음 주기에 맡긴다. 실패가 아니므로 종료 코드에 반영하지 않는다.
                $this->warn("{$prefix}{$id} ({$result['label']}): 관리자 직권 실행이 처리 중이라 건너뛰었습니다.");

                continue;
            }

            if ($result['status'] === 'failed') {
                $failed++;
                $this->error("{$prefix}{$id} ({$result['label']}): 실패 — {$result['error']}");

                continue;
            }

            $total += $result['count'];
            $this->info("{$prefix}{$id} ({$result['label']}): {$result['count']}건 ({$result['mode']})");
        }

        $this->line($prefix.'합계: '.$total.'건');

        if (! $isDryRun) {
            Log::info('ProcessPrivacyRetentionCommand: 보유기간 처리 완료', [
                // 형제 `privacy:process-dormant` 와 같은 계약 — 시그니처가 「기록용」이라
                // 선언한 플래그를 실제로 기록한다(설계 확정 2026-09-23). 이 커맨드는 되돌릴
                // 수 없는 2차 파기를 수행하므로 자동/수동 구분이 감사 가치를 갖는다.
                'scheduled' => (bool) $this->option('scheduled'),
                'total' => $total,
                'failed' => $failed,
                'results' => $results,
            ]);
        }

        // 한 도메인이 실패해도 나머지는 처리됐다 — 스케줄러가 실패로 재시도하면 성공한
        // 도메인을 다시 훑는다. 실패 사실은 종료 코드로 알리고 재시도는 다음 주기에 맡긴다.
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
