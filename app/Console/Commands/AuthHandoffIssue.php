<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Services\Auth\LoginHandoffService;
use App\Support\SiteUrl;
use Illuminate\Console\Command;

/**
 * 브라우저에 묶지 않은 로그인 인계 코드를 발급하는 실측 도구.
 *
 * 로그인 인계 코드는 보통 발급한 브라우저에 묶여(쿠키) 다른 브라우저에서 교환되지 않는다. 화면 실측과 시험에서는 CLI 로 만든 코드를
 * 브라우저에 넣어야 하므로 묶음 없는 코드를 이 명령으로만 만든다. 운영 환경(`APP_ENV=production`)에서는 거절한다.
 *
 * 예: `php artisan auth:handoff-issue admin@example.test --guard=admin`
 *
 * @since 7.2.0
 */
class AuthHandoffIssue extends Command
{
    protected $signature = 'auth:handoff-issue
        {email : 인계할 회원 이메일}
        {--guard=user : 로그인 경로 (user | admin)}
        {--login-method=cli:handoff : 로그인 수단 이름 (로그인 완료 훅에 실린다)}';

    protected $description = '브라우저에 묶지 않은 로그인 인계 코드를 발급합니다 (실측·시험 전용, 운영 환경 거절)';

    /**
     * 명령을 실행합니다.
     *
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  LoginHandoffService  $handoff  로그인 인계 서비스
     * @return int 종료 코드
     */
    public function handle(UserRepositoryInterface $users, LoginHandoffService $handoff): int
    {
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

        $code = $handoff->issueUnbound($user, ['login_method' => (string) $this->option('login-method')], $guard);
        $path = ($guard === 'admin' ? '/admin/login' : '/login').'?login_handoff='.$code;

        $this->line(__('auth.handoff_issue_command.issued', ['seconds' => LoginHandoffService::TTL_SECONDS]));
        $this->line(SiteUrl::absolute($path));

        return self::SUCCESS;
    }
}
