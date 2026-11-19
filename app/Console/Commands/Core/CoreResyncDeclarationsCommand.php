<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Extension\Helpers\ExtensionOperationLock;
use App\Extension\Support\LifecycleWarnings;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;

/**
 * 판 번호와 무관하게 코어 선언(역할·권한·메뉴·알림 정의·스케줄·회원 항목)을 이 서버의 `config/core.php` 로 다시 맞춥니다
 * (`core:resync-declarations`).
 *
 * 코어 갱신(`core:update`·`core:execute-upgrade-steps`)은 판이 바뀔 때만 돌아, 판 번호를 올리지 않고 `config/core.php` 를 고친
 * 배포는 선언이 DB 에 반영되지 않았다. 검수 서버(stage)에서도 돈다 — 추가·갱신은 곧바로, 새 선언에서 빠진 항목의 삭제는
 * 정리 헬퍼가 장부로 미루고 운영 서버의 `extension:apply-deferred-cleanup`(`stage:apply-to-live` 3단계)이 실행한다.
 *
 * @since 7.2.0
 */
class CoreResyncDeclarationsCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string 명령 시그니처
     */
    protected $signature = 'core:resync-declarations';

    /**
     * @var string 명령 설명 (LocalizesDescription 이 commands.descriptions 로 바꾼다)
     */
    protected $description = 'Resync core declarations from config/core.php';

    /**
     * 코어 선언을 다시 맞춥니다.
     *
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @return int 종료 코드 — 재동기화 단계가 실패를 남겼으면 1
     */
    public function handle(CoreUpdateService $service): int
    {
        // 코어 업데이트와 섞이지 않게 같은 잠금을 잡는다
        $lock = ExtensionOperationLock::acquireCore();
        try {
            $before = count(LifecycleWarnings::current()->all());
            $service->reloadCoreConfigAndResync();
            $warnings = array_slice(LifecycleWarnings::current()->all(), $before);
        } finally {
            $lock->release();
        }

        if ($warnings !== []) {
            foreach ($warnings as $warning) {
                $this->warn('  '.($warning['target'] ?? '').': '.($warning['message'] ?? ''));
            }
            $this->error(__('common.console.core_resync_declarations.failed'));

            return self::FAILURE;
        }

        $this->info(__('common.console.core_resync_declarations.done'));

        return self::SUCCESS;
    }
}
