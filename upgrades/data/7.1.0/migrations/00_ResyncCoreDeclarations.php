<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 코어 역할·권한·메뉴·알림 정의 선언을 새 코드에서 한 번 더 동기화합니다 (7.1.0 출시 점검 CU-3).
 *
 * 코어 업데이트는 마이그레이션 뒤(Step 9) 부모 프로세스가 코어 선언을 재동기화하고, 업그레이드 스텝을 도는 자식
 * `core:execute-upgrade-steps` 는 `G7_UPDATE_IN_PROGRESS=1` 이면 그 재동기화를 건너뛴다(부모가 이미 했다는 전제).
 * 그런데 7.0.x 에서 올리면 부모는 7.0.x 코드다 — 옛 `App\Models\Role`(HasUserOverrides 에 planUpgrade 없음)을 이미
 * 메모리에 올린 채 새 파일의 역할 동기화 헬퍼를 불러 `Call to undefined method Role::planUpgrade()` 로 권한 재동기화가
 * 통째로 실패하고 경고만 남는다. 그 결과 7.1.0 의 새 권한(`core.profile.read`·`core.analytics.read`·
 * `core.member-notifications.read` 등)이 어느 역할에도 연결되지 않아, 일반 회원의 내 정보·활동 기록 API 와 관리자
 * 방문자 통계·회원 알림함이 403 이었다(W3 리허설 실측).
 *
 * 이 스텝은 자식(디스크의 새 코드)에서 돌므로 코어 서비스의 재동기화(`reloadCoreConfigAndResync` — 디스크의
 * `config/core.php` 를 다시 읽어 역할·권한·메뉴·알림 정의·본인인증 정책을 비파괴 동기화)를 그대로 부른다. 운영자가
 * 고친 역할·메뉴 칸(user_overrides)은 보존된다. 부모 재동기화가 정상이었던 사이트에서는 바꿀 것이 없다(멱등).
 * 스텝 번호를 맨 앞(`00_`)에 둔 이유: 뒤 스텝(05 메뉴 관리자 역할 백필 등)이 동기화된 선언 위에서 돌게 한다.
 *
 * V-1 안전 예외: 데이터 스텝은 서비스 호출을 피하는 것이 원칙이지만, 이 스텝의 목적이 「부모 메모리의 옛 코드로는
 * 할 수 없었던 동기화를 새 코드로 다시 하는 것」이라 코어 서비스를 직접 부른다. 메서드가 없는 판(이론상 in-process
 * 대체 실행)에서는 실행하지 않고 운영자 복구 명령을 경고로 남긴다. 실패도 예외로 올리지 않는다 — 업데이트 전체를
 * 되돌릴 사유가 아니고, 같은 복구 명령으로 다시 할 수 있다.
 */
class ResyncCoreDeclarations implements DataMigration
{
    /** 운영자 복구 명령 (공개 업그레이드 안내 문서와 같은 문장) */
    private const RECOVERY_COMMAND = 'php artisan core:execute-upgrade-steps --from=7.1.0 --to=7.1.0 --force --skip-migrations --skip-bundled-updates';

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ResyncCoreDeclarations';
    }

    /**
     * 코어 선언 재동기화를 새 코드로 1회 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! File::exists(config_path('core.php'))) {
            $context->logger->warning('[7.1.0] 코어 선언 재동기화 건너뜀 — config/core.php 없음');

            return;
        }

        // audit:allow upgrade-step-vone-safety reason: 부모(7.0.x) 메모리의 옛 코드로 실패한 코어 선언 재동기화를 자식(새 코드)에서 다시 하는 것이 이 스텝의 목적 — method_exists 가드 (CU-3)
        // audit:allow upgrade-step-data-snapshot reason: 위와 같음 — 서비스 재동기화가 이 스텝의 목적이다
        $service = app(\App\Services\CoreUpdateService::class);

        if (! method_exists($service, 'reloadCoreConfigAndResync')) {
            $context->logger->warning('[7.1.0] 코어 선언 재동기화 건너뜀 — 이 프로세스의 코어 서비스에 재동기화 메서드가 없음. 업데이트 뒤 실행: '.self::RECOVERY_COMMAND);

            return;
        }

        try {
            $service->reloadCoreConfigAndResync();
            $context->logger->info('[7.1.0] 코어 역할·권한·메뉴·알림 정의 재동기화 완료 (운영자 수정 보존, 멱등)');
        } catch (\Throwable $e) {
            $context->logger->warning('[7.1.0] 코어 선언 재동기화 실패 — 업데이트 뒤 실행: '.self::RECOVERY_COMMAND, [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
