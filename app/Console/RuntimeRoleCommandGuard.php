<?php

namespace App\Console;

use App\Support\RuntimeRole;
use Illuminate\Console\Events\CommandStarting;

/**
 * 실행 역할·서버별 스케줄 스위치에 따라 운영 전용 Artisan 명령의 기동을 막습니다 (`CommandStarting` 리스너).
 *
 * - 스케줄(`schedule:run`·`schedule:work`·`g7:schedule-run`): 스케줄이 꺼진 서버(stage 역할 또는 `G7_SCHEDULER=off`)면
 *   안내를 내고 종료 0 — cron 은 그대로 두어도 된다.
 * - 워커·신호(`queue:work`·`queue:listen`·`reverb:start`·`queue:restart`): stage 역할이면 안내 후 종료 1. `queue:restart` 는
 *   공유 캐시 신호로 다른 서버의 워커까지 재시작시키므로 막는다.
 * - 확장 수명주기(모듈·플러그인·템플릿·언어팩의 activate·deactivate·uninstall): stage 역할이면 안내 후 종료 1 — 공유 DB 의
 *   확장 상태를 바꿔 운영 서버에 바로 보인다(같은 종류 운영 활성 템플릿 끄기, `--delete-data` 로 운영 테이블 삭제 등).
 *   install·update·refresh-layout·`extension:reset-files-from-bundled`·`core:execute-upgrade-steps` 는 새 확장을 검수 서버에서
 *   시험하는 흐름이라 막지 않는다.
 *
 * 막을 때는 안내를 낸 뒤 프로세스를 `exit()` 로 끝낸다. 그래서 앱 안(컨트롤러·서비스·다른 명령·잡)에서 이 명령들을
 * `Artisan::call` 로 부르지 않는다 — 막히면 부른 쪽 프로세스(웹 요청·큐 워커·부모 명령)가 그 자리에서 함께 끝난다.
 * 이 명령들은 서버 cron·프로세스 관리자·운영자 터미널에서 따로 띄운다.
 *
 * @since 7.2.0
 */
class RuntimeRoleCommandGuard
{
    /**
     * 스케줄 실행 명령.
     */
    public const SCHEDULE_COMMANDS = ['schedule:run', 'schedule:work', 'g7:schedule-run'];

    /**
     * stage 역할에서 막는 워커·신호 명령.
     */
    public const STAGE_BLOCKED_COMMANDS = ['queue:work', 'queue:listen', 'reverb:start', 'queue:restart'];

    /**
     * stage 역할에서 막는 확장 수명주기 명령 — 공유 DB 의 확장 상태를 바꾼다.
     */
    public const STAGE_BLOCKED_LIFECYCLE_COMMANDS = [
        'module:activate', 'module:deactivate', 'module:uninstall',
        'plugin:activate', 'plugin:deactivate', 'plugin:uninstall',
        'template:activate', 'template:deactivate', 'template:uninstall',
        'language-pack:activate', 'language-pack:deactivate', 'language-pack:uninstall',
    ];

    /**
     * 시험용 — 종료 대신 판정을 기록한다.
     */
    public static ?\Closure $exitUsing = null;

    /**
     * 명령을 막을지 판정합니다.
     *
     * @param  string  $command  명령 이름
     * @return array{exit: int, message: string}|null 막으면 종료 코드와 안내, 아니면 null
     */
    public static function verdict(string $command): ?array
    {
        if (in_array($command, self::SCHEDULE_COMMANDS, true) && ! RuntimeRole::schedulerEnabled()) {
            return [
                'exit' => 0,
                'message' => RuntimeRole::isStage()
                    ? (string) __('common.console.runtime_role.schedule_stage')
                    : (string) __('common.console.runtime_role.schedule_disabled'),
            ];
        }

        if (in_array($command, self::STAGE_BLOCKED_COMMANDS, true) && RuntimeRole::isStage()) {
            return ['exit' => 1, 'message' => (string) __('common.console.runtime_role.worker_stage', ['command' => $command])];
        }

        if (in_array($command, self::STAGE_BLOCKED_LIFECYCLE_COMMANDS, true) && RuntimeRole::isStage()) {
            return ['exit' => 1, 'message' => (string) __('common.console.runtime_role.lifecycle_stage', ['command' => $command])];
        }

        return null;
    }

    /**
     * 명령 시작 시 판정하고, 막으면 안내를 출력하고 종료합니다.
     *
     * @param  CommandStarting  $event  명령 시작 이벤트
     * @return void 반환값 없음
     */
    public function handle(CommandStarting $event): void
    {
        $verdict = self::verdict((string) $event->command);
        if ($verdict === null) {
            return;
        }

        $event->output->writeln($verdict['exit'] === 0 ? "<comment>{$verdict['message']}</comment>" : "<error>{$verdict['message']}</error>");

        if (self::$exitUsing !== null) {
            (self::$exitUsing)($verdict['exit']);

            return;
        }

        exit($verdict['exit']);
    }
}
