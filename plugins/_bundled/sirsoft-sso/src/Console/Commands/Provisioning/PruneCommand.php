<?php

namespace Plugins\Sirsoft\Sso\Console\Commands\Provisioning;

use Illuminate\Console\Command;
use Plugins\Sirsoft\Sso\Repositories\Contracts\LoginAttemptRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimEventRepositoryInterface;
use Plugins\Sirsoft\Sso\Repositories\Contracts\SsoSessionRepositoryInterface;

/**
 * 보존 정리 — 보존일이 지난 로그인 시도 이력, SCIM 수신 이력, 끝난 SSO 세션을 지웁니다 (스케줄 매일).
 *
 * 보존일은 사이트 정책(`attempts_retention_days` 90, `scim_retention_days` 90, `session_retention_days` 30). 실패해도 예외를 밖으로
 * 내지 않는다.
 *
 * @since 1.0.0
 */
class PruneCommand extends Command
{
    /** @var array<string, int> 정책 키 => 기본 보존일 */
    public const RETENTION_DEFAULTS = [
        'attempts_retention_days' => 90,
        'scim_retention_days' => 90,
        'session_retention_days' => 30,
    ];

    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:prune';

    /** @var string 명령 설명 */
    protected $description = 'SSO 보존 정리 — 보존일이 지난 로그인 시도, SCIM 수신 이력, 끝난 세션을 지웁니다';

    /**
     * 명령을 실행합니다.
     *
     * @param  LoginAttemptRepositoryInterface  $attempts  로그인 시도 이력
     * @param  ScimEventRepositoryInterface  $scimEvents  SCIM 수신 이력
     * @param  SsoSessionRepositoryInterface  $sessions  SSO 세션
     * @return int 종료 코드
     */
    public function handle(LoginAttemptRepositoryInterface $attempts, ScimEventRepositoryInterface $scimEvents, SsoSessionRepositoryInterface $sessions): int
    {
        try {
            $summary = [
                'attempts' => $attempts->pruneBefore(now()->subDays($this->days('attempts_retention_days'))),
                'scim' => $scimEvents->pruneBefore(now()->subDays($this->days('scim_retention_days'))),
                'sessions' => $sessions->pruneEndedBefore(now()->subDays($this->days('session_retention_days'))),
            ];
        } catch (\Throwable $e) {
            report($e);
            $this->error(__('sirsoft-sso::provisioning.command.failed', ['command' => $this->getName()]));

            return self::FAILURE;
        }

        $this->info(__('sirsoft-sso::provisioning.command.prune', $summary));

        return self::SUCCESS;
    }

    /**
     * 보존일을 정책에서 읽습니다 (정책 서비스가 없으면 기본값, 1일 미만은 1일).
     *
     * @param  string  $key  정책 키
     * @return int 보존일
     */
    private function days(string $key): int
    {
        $default = self::RETENTION_DEFAULTS[$key];
        $service = 'Plugins\\Sirsoft\\Sso\\Services\\Policy\\PolicyService';
        $value = class_exists($service) && method_exists($service, 'get') ? app($service)->get($key, $default) : $default;

        return is_numeric($value) ? max(1, min(3650, (int) $value)) : $default;
    }
}
