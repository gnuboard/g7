<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 설치본의 코어 관리자 메뉴 순서를 방문자 통계 자리만큼 밀어 냅니다.
 *
 * 7.1.0 에서 「방문자 통계」가 대시보드 바로 다음(order 2)에 들어갑니다. 코어 메뉴
 * 동기화는 config 정의의 order 를 기존 행에 반영하는데, 메뉴를 **하나씩** 갱신하므로
 * 방문자 통계가 2 로 들어오는 시점에 기존 「환경설정」도 아직 2 라서 두 메뉴가 같은 순위로
 * 잠시 겹칩니다(정렬은 order 단독이라 그 상태의 표시 순서는 정해지지 않습니다).
 *
 * 이 스텝은 코어 업데이트의 메뉴 동기화 **뒤에** 돈다(재개 경로 `core:execute-upgrade-steps` 도
 * 동기화 → 스텝 순서). 동기화가 이미 config 순서를 반영한 설치본에서는 바꿀 것이 없고(no-op),
 * 동기화가 순서를 반영하지 못한 행이 남았을 때 최종 순서를 기대값으로 맞추는 보정이다. 운영자가
 * 관리자 UI 에서 순서를 직접 바꾼 메뉴(user_overrides 에 order 가 등록된 행)는 건드리지
 * 않습니다 — 그 순서는 운영자의 결정이고, 코어가 되돌릴 대상이 아닙니다.
 *
 * 함께 없애는 것: 종전 정의에 있던 소수 order(「회원 알림함」 3.5)는 int 컬럼에서 3 으로
 * 잘려 「알림 발송 이력」(3)과 같은 순위였습니다. 이번 재배치로 두 메뉴가 4·5 로 갈립니다.
 *
 * idempotent: 이미 밀린 설치본에서는 대상 slug 의 현재 order 가 기대값과 같아 변경이
 * 일어나지 않습니다.
 *
 * V-1 안전: 모델·Enum 을 참조하지 않고 DB 파사드와 리터럴만 씁니다.
 */
class ShiftCoreMenuOrderForAnalytics implements DataMigration
{
    /**
     * 7.1.0 에서 코어 최상위 메뉴가 가져야 할 순서
     *
     * config/core.php 의 menus 정의와 같은 값입니다. 여기에 없는 메뉴(확장이 만든
     * 메뉴, 운영자가 추가한 메뉴)는 대상이 아닙니다.
     *
     * @var array<string, int>
     */
    private const TARGET_ORDER = [
        'admin-dashboard' => 1,
        'admin-analytics' => 2,
        'admin-settings' => 3,
        'admin-notification-logs' => 4,
        'admin-member-notifications' => 5,
        'admin-identity-logs' => 6,
        'admin-activity-logs' => 7,
        'admin-menus' => 8,
        'admin-users' => 9,
        'admin-roles' => 10,
        'admin-modules' => 11,
        'admin-plugins' => 12,
        'admin-templates' => 13,
        'admin-schedules' => 14,
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ShiftCoreMenuOrderForAnalytics';
    }

    /**
     * 코어 최상위 메뉴의 order 를 7.1.0 기준으로 맞춥니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('menus')) {
            $context->logger->info('[core:7.1.0] menus 테이블 부재 — 코어 메뉴 순서 재배치 스킵');

            return;
        }

        $rows = DB::table('menus')
            ->where('extension_type', 'core')
            ->whereNull('parent_id')
            ->whereIn('slug', array_keys(self::TARGET_ORDER))
            ->get(['id', 'slug', 'order', 'user_overrides']);

        if ($rows->isEmpty()) {
            $context->logger->info('[core:7.1.0] 재배치 대상 코어 메뉴 없음 — 스킵');

            return;
        }

        $updated = 0;
        $preserved = 0;

        foreach ($rows as $row) {
            $target = self::TARGET_ORDER[$row->slug];

            if ((int) $row->order === $target) {
                continue;
            }

            if ($this->orderIsUserOverridden($row->user_overrides)) {
                $preserved++;
                $context->logger->info(
                    '[core:7.1.0] 운영자가 순서를 지정한 메뉴 — 보존: '.$row->slug
                );

                continue;
            }

            DB::table('menus')->where('id', $row->id)->update(['order' => $target]);
            $updated++;
        }

        $context->logger->info(
            '[core:7.1.0] 코어 메뉴 순서 재배치 완료 — 변경 '.$updated.'건, 운영자 지정 보존 '.$preserved.'건'
        );
    }

    /**
     * 운영자가 이 메뉴의 순서를 직접 바꿨는지 판정합니다.
     *
     * user_overrides 는 컬럼명 목록(`["order"]`) 또는 dot-path 키를 담는 JSON 입니다.
     * 형식이 깨져 있으면 보존 쪽으로 판단하지 않습니다 — 깨진 값 때문에 전 설치본의
     * 재배치가 조용히 멈추는 것이 더 나쁩니다.
     *
     * @param  string|null  $userOverrides  메뉴 행의 user_overrides JSON
     * @return bool 운영자 지정 여부
     */
    private function orderIsUserOverridden(?string $userOverrides): bool
    {
        if ($userOverrides === null || $userOverrides === '') {
            return false;
        }

        $decoded = json_decode($userOverrides, true);

        if (! is_array($decoded)) {
            return false;
        }

        foreach ($decoded as $key => $value) {
            if ($value === 'order' || $key === 'order') {
                return true;
            }
        }

        return false;
    }
}
