<?php

namespace Plugins\Sirsoft\SocialLogin\Console\Commands;

use Illuminate\Console\Command;
use Plugins\Sirsoft\SocialLogin\Services\PruneService;

/**
 * 소셜 로그인 보존 기간 정리 (매일 예약 실행).
 *
 * 원본 프로필 사본(보존 기한 지남 → 비움), 시도 기록·웹훅 수신 이력(보존 기간 밖 → 삭제), 만료된 모의 제공자 표지를 정리한다.
 * `--dry-run` 은 지우지 않고 대상 수만 보인다.
 *
 * @since 1.0.0
 */
class PruneCommand extends Command
{
    /** @var string 명령 서명 */
    protected $signature = 'sirsoft-social_login:prune {--dry-run : 지우지 않고 대상 수만 봅니다}';

    /** @var string 명령 설명 */
    protected $description = 'Prune social login raw profiles, attempt logs, webhook events and expired mock flags past their retention.';

    /**
     * 명령을 실행합니다.
     *
     * @param  PruneService  $prune  정리 서비스
     * @return int 종료 코드
     */
    public function handle(PruneService $prune): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $prune->run($dryRun);

        $this->info(__('sirsoft-social_login::messages.prune.'.($dryRun ? 'dry_run' : 'done')));
        $this->table(
            [__('sirsoft-social_login::messages.prune.target'), __('sirsoft-social_login::messages.prune.count')],
            collect($result)->map(fn ($count, $target) => [__('sirsoft-social_login::messages.prune.targets.'.$target), (int) $count])->values()->all(),
        );

        return self::SUCCESS;
    }
}
