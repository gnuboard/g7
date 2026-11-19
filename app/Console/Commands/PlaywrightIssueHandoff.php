<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Services\Auth\LoginHandoffService;
use Illuminate\Console\Command;

/**
 * Playwright E2E 용 브라우저 묶음 로그인 인계 코드 발급 커맨드.
 *
 * `LoginHandoffService::issueTicket()` 로 인계 코드와 묶음 쿠키를 함께 만들고 JSON 한 줄로 출력한다 — 스펙이 한 브라우저 맥락에만 쿠키를
 * 심어 「발급한 브라우저에서만 교환된다」(K2)를 두 맥락으로 잰다. 브라우저에 묶지 않는 `auth:handoff-issue` 와 달리 묶음 검사를 그대로 탄다.
 *
 * 보안 가드는 `playwright:issue-token` 과 같다: ① CLI 한정 ② `G7_PLAYWRIGHT_BYPASS=1` 명시 옵트인 ③ 운영 환경 거절.
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:issue-handoff playwright_x@example.com
 *
 * @since 7.2.0
 */
class PlaywrightIssueHandoff extends Command
{
    use LocalizesDescription;

    protected $signature = 'playwright:issue-handoff
        {email : 인계할 회원 이메일 (playwright:issue-token 이 만든 시험 계정)}
        {--guard=user : 로그인 경로 (user | admin)}';

    /**
     * 가드를 통과하면 묶음 인계 코드를 발급해 `{code, cookie: {name, value, path}}` JSON 을 출력합니다.
     *
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  LoginHandoffService  $handoff  로그인 인계 서비스
     * @return int 종료 코드
     */
    public function handle(UserRepositoryInterface $users, LoginHandoffService $handoff): int
    {
        if (php_sapi_name() !== 'cli') {
            $this->error(__('common.console.playwright_guard.cli_only', ['sapi' => php_sapi_name()]));

            return self::FAILURE;
        }

        // 호출자가 그 자리에서 넘기는 프로세스 환경변수다 — config 로 캡처할 대상이 아니다(playwright:issue-token 과 같다).
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error(__('common.console.playwright_guard.bypass_required'));

            return self::FAILURE;
        }

        if (app()->environment('production')) {
            $this->error(__('auth.handoff_issue_command.production_refused'));

            return self::FAILURE;
        }

        $guard = (string) $this->option('guard');
        if (! in_array($guard, ['user', 'admin'], true)) {
            $this->error(__('auth.handoff_issue_command.invalid_guard', ['guard' => $guard]));

            return self::FAILURE;
        }

        $user = $users->findByEmail((string) $this->argument('email'));
        if ($user === null) {
            $this->error(__('auth.handoff_issue_command.user_not_found'));

            return self::FAILURE;
        }

        $ticket = $handoff->issueTicket($user, ['login_method' => 'playwright:handoff'], $guard);

        $this->line((string) json_encode([
            'code' => $ticket->code,
            'cookie' => [
                'name' => $ticket->cookie->getName(),
                'value' => $ticket->cookie->getValue(),
                'path' => $ticket->cookie->getPath(),
            ],
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
