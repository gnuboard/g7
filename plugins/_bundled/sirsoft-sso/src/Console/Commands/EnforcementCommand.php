<?php

namespace Plugins\Sirsoft\Sso\Console\Commands;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use Illuminate\Console\Command;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminIdpRepositoryInterface;
use Plugins\Sirsoft\Sso\Services\Policy\PolicyService;

/**
 * SSO 강제 상태 확인, 강제 해제, 되돌리기 (D12 — IdP 장애 때 서버에서 여는 복구 경로).
 *
 * `--disable` 은 사이트 전체, 역할, 연결 회원 전원 강제와 모든 IdP 의 「이 도메인 비밀번호 로그인 막기」를 끄고, 이전 값을 정책의
 * `enforcement_backup` 에 보관한다. `--restore` 는 보관한 값을 되살린다. 강제가 풀리면 코어 비밀번호 로그인과 비밀번호 찾기 메일이
 * 다시 동작한다.
 *
 * @since 1.0.0
 */
class EnforcementCommand extends Command
{
    use ResolvesActivityLogType;

    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:enforcement
        {--status : 지금 켜진 강제를 봅니다}
        {--disable : 모든 강제를 끄고 이전 값을 보관합니다}
        {--restore : --disable 로 보관한 강제를 되살립니다}';

    /** @var string 명령 설명 */
    protected $description = 'SSO 강제(비밀번호 로그인 막기) 확인, 해제, 되돌리기 — IdP 장애 복구용';

    /**
     * 명령을 실행합니다.
     *
     * @param  PolicyService  $policy  정책
     * @param  AdminIdpRepositoryInterface  $idps  IdP 저장소
     * @return int 종료 코드
     */
    public function handle(PolicyService $policy, AdminIdpRepositoryInterface $idps): int
    {
        if ($this->option('disable')) {
            return $this->disable($policy, $idps);
        }

        if ($this->option('restore')) {
            return $this->restore($policy, $idps);
        }

        $this->printStatus($policy, $idps);

        return self::SUCCESS;
    }

    /**
     * 현재 강제 상태를 출력합니다.
     *
     * @param  PolicyService  $policy  정책
     * @param  AdminIdpRepositoryInterface  $idps  IdP 저장소
     * @return void 반환값 없음
     */
    private function printStatus(PolicyService $policy, AdminIdpRepositoryInterface $idps): void
    {
        $current = $policy->current();
        $yes = __('sirsoft-sso::policy.cli.on');
        $no = __('sirsoft-sso::policy.cli.off');

        $this->table([__('sirsoft-sso::policy.cli.item'), __('sirsoft-sso::policy.cli.value')], [
            [__('sirsoft-sso::policy.cli.enforce_site'), $current['enforce_site'] ? $yes : $no],
            [__('sirsoft-sso::policy.cli.enforce_roles'), $current['enforce_roles'] === [] ? $no : implode(', ', $current['enforce_roles'])],
            [__('sirsoft-sso::policy.cli.enforce_linked'), $current['enforce_linked'] ? $yes : $no],
            [__('sirsoft-sso::policy.cli.enforced_idps'), $idps->enforcing()->pluck('key')->implode(', ') ?: $no],
            [__('sirsoft-sso::policy.cli.emergency_users'), implode(', ', $current['emergency_user_ids'])],
            [__('sirsoft-sso::policy.cli.backup'), $current['enforcement_backup'] !== null ? $yes : $no],
        ]);
    }

    /**
     * 모든 강제를 끄고 이전 값을 보관합니다.
     *
     * @param  PolicyService  $policy  정책
     * @param  AdminIdpRepositoryInterface  $idps  IdP 저장소
     * @return int 종료 코드
     */
    private function disable(PolicyService $policy, AdminIdpRepositoryInterface $idps): int
    {
        $current = $policy->current();
        $enforcing = $idps->enforcing();

        $backup = [
            'enforce_site' => $current['enforce_site'],
            'enforce_roles' => $current['enforce_roles'],
            'enforce_linked' => $current['enforce_linked'],
            'idp_ids' => $enforcing->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'saved_at' => now()->toIso8601String(),
        ];

        foreach ($enforcing as $idp) {
            $idps->update($idp, ['enforce_owned_domains' => false]);
        }

        $policy->storeRaw(array_merge($current, [
            'enforce_site' => false,
            'enforce_roles' => [],
            'enforce_linked' => false,
            'enforcement_backup' => $backup,
        ]));

        $this->logActivity('sso.enforcement_disabled_cli', [
            'description_key' => 'sirsoft-sso::activity_log.description.sso.enforcement_disabled_cli',
            'description_params' => [],
            'properties' => ['backup' => $backup, 'via' => 'cli'],
        ]);

        $this->info(__('sirsoft-sso::policy.cli.disabled'));
        $this->printStatus($policy, $idps);

        return self::SUCCESS;
    }

    /**
     * 보관한 강제를 되살립니다.
     *
     * @param  PolicyService  $policy  정책
     * @param  AdminIdpRepositoryInterface  $idps  IdP 저장소
     * @return int 종료 코드
     */
    private function restore(PolicyService $policy, AdminIdpRepositoryInterface $idps): int
    {
        $current = $policy->current();
        $backup = $current['enforcement_backup'];

        if (! is_array($backup)) {
            $this->error(__('sirsoft-sso::policy.cli.no_backup'));

            return self::FAILURE;
        }

        foreach ((array) ($backup['idp_ids'] ?? []) as $id) {
            $idp = $idps->findById((int) $id);
            if ($idp !== null) {
                $idps->update($idp, ['enforce_owned_domains' => true]);
            }
        }

        $policy->storeRaw(array_merge($current, [
            'enforce_site' => (bool) ($backup['enforce_site'] ?? false),
            'enforce_roles' => (array) ($backup['enforce_roles'] ?? []),
            'enforce_linked' => (bool) ($backup['enforce_linked'] ?? false),
            'enforcement_backup' => null,
        ]));

        $this->logActivity('sso.enforcement_restored_cli', [
            'description_key' => 'sirsoft-sso::activity_log.description.sso.enforcement_restored_cli',
            'description_params' => [],
            'properties' => ['backup' => $backup, 'via' => 'cli'],
        ]);

        $this->info(__('sirsoft-sso::policy.cli.restored'));
        $this->printStatus($policy, $idps);

        return self::SUCCESS;
    }
}
