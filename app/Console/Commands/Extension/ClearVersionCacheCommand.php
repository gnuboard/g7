<?php

namespace App\Console\Commands\Extension;

use App\Extension\CoreVersionChecker;
use Illuminate\Console\Command;

/**
 * 확장 버전 검증 캐시 삭제 커맨드
 *
 * 확장(모듈, 플러그인, 템플릿)의 코어 버전 호환성 검증 캐시를 삭제합니다.
 * 코어 버전 업데이트 후 또는 수동으로 버전 검증을 다시 수행해야 할 때 사용합니다.
 */
class ClearVersionCacheCommand extends Command
{
    /**
     * 커맨드 시그니처
     *
     * @var string
     */
    protected $signature = 'extension:clear-version-cache';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '확장 버전 검증 캐시를 삭제합니다';

    /**
     * 커맨드 실행
     *
     * @return int 실행 결과 코드
     */
    public function handle(): int
    {
        CoreVersionChecker::clearCache();

        $this->info(__('extensions.commands.clear_cache_success'));

        return Command::SUCCESS;
    }
}
