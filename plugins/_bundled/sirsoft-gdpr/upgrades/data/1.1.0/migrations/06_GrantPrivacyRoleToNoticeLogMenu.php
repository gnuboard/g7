<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftGdpr\V1_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.1.0 에서 새로 생긴 「개인정보 통지 이력」 메뉴에 개인정보 담당 역할을 붙입니다.
 *
 * 배경:
 *
 * 이 플러그인의 관리자 메뉴는 관리자 역할 말고도 개인정보 담당 역할(`sirsoft-gdpr.privacy`)에
 * 읽기 권한을 붙인다. 그 부여는 플러그인 활성화(activate) 때만 일어나는데, 업데이트 경로는
 * 메뉴 행만 만들고(선언형 동기화 — 관리자 역할만 부여) activate 를 부르지 않는다. 그래서
 * 1.0.x 에서 올린 사이트는 새 「개인정보 통지 이력」 메뉴가 개인정보 담당자에게 보이지 않는다.
 * 같은 사이트에서 플러그인을 껐다 켜야 비로소 붙는다.
 *
 * 이 스텝이 그 메뉴(와 하위 메뉴)에 개인정보 담당 역할의 읽기 권한을 붙인다. 활성화 경로와 같은 규칙:
 *  - 운영자가 그 메뉴에서 개인정보 담당 역할을 뗐다면(메뉴의 user_overrides 에 역할 식별자) 붙이지 않는다
 *  - 이미 붙어 있으면 그대로 둔다
 *
 * 메뉴 행은 업그레이드 스텝 전에 선언형 동기화가 만들어 둔다. 메뉴나 역할이 없으면 skip 한다.
 *
 * 멱등: 이미 붙은 행은 다시 넣지 않는다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - `Illuminate\Support\Facades\{DB,Schema}` 만 사용 (이전 버전에도 존재하던 표면)
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class GrantPrivacyRoleToNoticeLogMenu implements DataMigration
{
    /**
     * 개인정보 담당 역할 식별자.
     */
    private const PRIVACY_ROLE = 'sirsoft-gdpr.privacy';

    /**
     * 1.1.0 에서 새로 생긴 메뉴 슬러그 (1.1.0 시점 값 동결).
     */
    private const NOTICE_LOG_SLUG = 'sirsoft-gdpr-notice-log';

    /**
     * 부여할 권한 종류 (활성화 경로와 같은 읽기 권한).
     */
    private const PERMISSION_TYPE = 'read';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'GrantPrivacyRoleToNoticeLogMenu';
    }

    /**
     * 통지 이력 메뉴 트리에 개인정보 담당 역할의 읽기 권한을 붙인다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_menus')) {
            $context->logger->info('[sirsoft-gdpr] 메뉴·역할 테이블 없음 — 통지 이력 메뉴 역할 부여 skip');

            return;
        }

        $roleId = DB::table('roles')->where('identifier', self::PRIVACY_ROLE)->value('id');
        if ($roleId === null) {
            $context->logger->info('[sirsoft-gdpr] 개인정보 담당 역할 없음 — 통지 이력 메뉴 역할 부여 skip');

            return;
        }

        $root = DB::table('menus')->where('slug', self::NOTICE_LOG_SLUG)->first(['id', 'user_overrides']);
        if ($root === null) {
            $context->logger->info('[sirsoft-gdpr] 통지 이력 메뉴 없음 — 역할 부여 skip');

            return;
        }

        $granted = 0;
        $skippedByOperator = 0;
        $queue = [$root];

        while ($queue !== []) {
            $menu = array_shift($queue);

            if ($this->operatorRemovedRole($menu->user_overrides)) {
                $skippedByOperator++;
            } else {
                $exists = DB::table('role_menus')
                    ->where('role_id', $roleId)
                    ->where('menu_id', $menu->id)
                    ->where('permission_type', self::PERMISSION_TYPE)
                    ->exists();

                if (! $exists) {
                    DB::table('role_menus')->insert([
                        'role_id' => $roleId,
                        'menu_id' => $menu->id,
                        'permission_type' => self::PERMISSION_TYPE,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $granted++;
                }
            }

            foreach (DB::table('menus')->where('parent_id', $menu->id)->get(['id', 'user_overrides']) as $child) {
                $queue[] = $child;
            }
        }

        $context->logger->info('[sirsoft-gdpr] 통지 이력 메뉴에 개인정보 담당 역할 부여', [
            'granted' => $granted,
            'skipped_by_operator' => $skippedByOperator,
        ]);
    }

    /**
     * 운영자가 이 메뉴에서 개인정보 담당 역할을 뗐는지 판정한다(user_overrides 에 역할 식별자).
     *
     * @param  string|null  $userOverrides  메뉴의 user_overrides 원문(JSON)
     * @return bool 뗐으면 true
     */
    private function operatorRemovedRole(?string $userOverrides): bool
    {
        if ($userOverrides === null || $userOverrides === '') {
            return false;
        }

        $decoded = json_decode($userOverrides, true);

        return is_array($decoded) && in_array(self::PRIVACY_ROLE, $decoded, true);
    }
}
