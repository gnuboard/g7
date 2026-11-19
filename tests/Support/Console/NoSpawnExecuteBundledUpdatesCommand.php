<?php

namespace Tests\Support\Console;

use App\Console\Commands\Core\ExecuteBundledUpdatesCommand;

/**
 * 새 PHP 프로세스를 띄울 수 없는 서버를 흉내 내는 번들 일괄 업데이트 자식 명령.
 *
 * 항목이 여럿이어도 항목마다 새 프로세스를 띄우지 않고 같은 프로세스에서 처리하므로, 확장 매니저를 페이크로 바꾼
 * 시험이 그 호출을 그대로 관찰할 수 있다. `$this->app[Kernel::class]->registerCommand(app(self::class))` 로 같은
 * 이름의 명령을 바꿔 끼운다.
 */
class NoSpawnExecuteBundledUpdatesCommand extends ExecuteBundledUpdatesCommand
{
    /**
     * 새 프로세스를 띄울 수 없다고 답합니다.
     *
     * @return bool 늘 false
     */
    protected function canSpawnProcess(): bool
    {
        return false;
    }
}
