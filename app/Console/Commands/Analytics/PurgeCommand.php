<?php

namespace App\Console\Commands\Analytics;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Console\Commands\Traits\LocalizesDescription;
use App\Services\Analytics\PurgeService;
use Illuminate\Console\Command;

/**
 * 방문자 통계 전체 삭제 커맨드
 *
 * 쌓인 방문 기록과 집계를 모두 지웁니다. 되돌릴 수 없으므로 --confirm 을 반드시
 * 붙여야 하고, 지우기 전에 터미널에서 한 번 더 묻습니다. 그 확인에 답할 수 없는 실행(cron, CI,
 * 원격 명령, --no-interaction)은 지우지 않고 실패로 끝납니다 — --confirm 은 비대화형 동의가 아닙니다.
 *
 * @example php artisan analytics:purge --confirm
 */
class PurgeCommand extends Command
{
    use HasUnifiedConfirm;
    use LocalizesDescription;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:purge {--confirm : 되돌릴 수 없음을 확인}';

    /**
     * 커맨드 실행
     *
     * @param  PurgeService  $service  삭제 서비스
     * @return int 종료 코드
     */
    public function handle(PurgeService $service): int
    {
        if (! $this->option('confirm')) {
            $this->error(__('analytics.console.purge.confirm_option_required'));

            return self::FAILURE;
        }

        // 확인에 답할 수 없는 실행(cron, CI, 원격 명령, --no-interaction)은 종전에 기본값 「아니오」로 취소 문구만 내고
        // 종료 코드 0 으로 끝나 자동화가 성공으로 읽었다. 그런 실행은 사유와 터미널 확인 안내를 남기고 실패로 끝낸다.
        // 대화형에서 운영자가 직접 「아니오」를 답한 취소는 의도이므로 종전대로 0 이다.
        // --confirm 은 필수 동의 표식일 뿐 비대화형 동의로 보지 않는다(되돌릴 수 없는 삭제라 대화형 확인을 유지).
        if (! $this->canAnswerConfirmation()) {
            return $this->refuseUnanswerableConfirmation(
                __('analytics.console.purge.confirm_unavailable'),
                __('analytics.console.purge.confirm_unavailable_hint'),
            );
        }

        if (! $this->unifiedConfirm(__('analytics.console.purge.confirm'))) {
            $this->info(__('analytics.console.purge.cancelled'));

            return self::SUCCESS;
        }

        $counts = $service->purgeAll();

        $this->table(
            [__('analytics.console.purge.headers.target'), __('analytics.console.purge.headers.deleted')],
            array_map(fn (string $key, int $count): array => [$key, $count], array_keys($counts), $counts),
        );

        $this->info(__('analytics.console.purge.done', ['count' => array_sum($counts)]));

        return self::SUCCESS;
    }
}
