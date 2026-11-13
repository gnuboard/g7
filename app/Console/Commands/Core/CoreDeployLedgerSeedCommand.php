<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Services\CoreUpdateService;
use Illuminate\Console\Command;

/**
 * `deploy/**` 서버 설정 조각의 해시 원장을 현재 디스크 내용으로 씨앗합니다.
 *
 * 코어 업데이트는 그 원장(`storage/app/core-update/deploy-fragments.json`)을 근거로
 * 「운영자가 고친 조각인가」를 판정한다. 원장이 없으면 어느 조각도 판정할 수 없어 첫
 * 업데이트에서 전량이 보존(`.dist` + 경고)으로 떨어진다 — 갓 설치한 사이트도 마찬가지다.
 * 설치 직후에는 디스크가 곧 릴리즈 판본이므로 그 시점에 한 번 씨앗을 뿌린다. 인스톨러가
 * 마무리 단계에서 이 커맨드를 부르고, 이미 원장이 있으면 아무것도 하지 않는다(멱등).
 *
 * ── 선행 조건 (자동 판정 불가) ────────────────────────────────────────────────
 * 씨앗기의 유일한 방어선은 **사이드카(`.dist`) 존재 여부**다. 사이드카가 없으면 「그 조각은
 * 코어가 쓴 그대로」로 보고 현재 디스크 해시를 코어 판본으로 기록한다. 그 전제가 성립하는
 * 시점은 **설치 직후뿐**이다.
 *
 * 롤백(`restoreFromBackup`)은 사이드카와 원장을 **함께** 지운다 — 복원된 내용이 코어가 쓴
 * 것인지 운영자 수정본인지 알 수 없어 다음 업데이트를 「판정 불가(보존 + 안내)」로 떨어뜨리는
 * 의도적 fail-safe 다. 그런데 그 직후 이 커맨드를 손으로 실행하면 그 의도가 무효가 된다:
 * 사이드카가 없으니 운영자 수정본이 「코어가 쓴 그대로」로 원장에 박히고, 다음 업데이트가
 * 그 조각을 경고 없이 덮어쓴다(`fastcgi_pass`·마운트 접두사 같은 환경별 수정이 사라진다).
 *
 * 그래서 씨앗을 **실제로 수행할 때는** 그 전제를 함께 알린다. 판정을 코드가 대신할 수 없는
 * 자리이므로(디스크 내용만으로는 릴리즈 판본과 대조할 근거가 없다) 사람이 알고 결정해야 한다.
 */
class CoreDeployLedgerSeedCommand extends Command
{
    use LocalizesDescription;

    protected $signature = 'core:deploy-ledger-seed';

    /**
     * 원장을 씨앗하고 결과를 출력합니다.
     *
     * 반환값의 `[]` 만으로는 「완전한 원장이라 더할 것이 없음(정상)」과 「원장 쓰기 실패」를
     * 구분할 수 없다 — 씨앗기는 실패 시 경고 통로(`getLastRootFileWarnings()`)에 사유를 싣는다.
     * 그래서 경고가 있으면 그 사유를 방출하고 비-0 으로 끝낸다 — 종전에는 쓰기 실패도
     * 「기록했습니다/건너뜀」으로 위장돼 운영자가 원장 부재를 알 수 없었다. 실패해도 인스톨러는
     * 이 커맨드의 비-0 을 「건너뜀」 로그로만 기록하고 설치를 멈추지 않는다(원장이 없으면 다음
     * 업데이트가 보존 쪽으로 떨어질 뿐이다).
     *
     * @param  CoreUpdateService  $service  코어 업데이트 서비스
     * @return int 종료 코드 (씨앗 성공/건너뜀=0, 원장 쓰기 실패=1)
     */
    public function handle(CoreUpdateService $service): int
    {
        $seeded = $service->seedDeployFragmentLedger(base_path());
        $warnings = $service->getLastRootFileWarnings();

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        if ($warnings !== []) {
            return self::FAILURE;
        }

        if ($seeded === []) {
            $this->info(__('settings.core_update.deploy_fragment_ledger_seed_skipped'));
        } else {
            $this->info(trans_count('settings.core_update.deploy_fragment_ledger_seeded', ['count' => count($seeded)]));

            // 씨앗을 **실제로 수행했을 때만** 그 전제를 알린다. 클래스 docblock 의
            // 「선행 조건」참조 — 설치 직후가 아닌 시점(특히 롤백 직후)에 실행하면
            // 운영자 수정본이 코어 판본으로 기록되어 다음 업데이트가 덮어쓴다.
            $this->warn(__('settings.core_update.deploy_fragment_ledger_seed_premise'));
        }

        return self::SUCCESS;
    }
}
