<?php

namespace Tests\Unit\Upgrades;

use Tests\TestCase;

/**
 * 코어 7.1.0 데이터 스텝의 실행 순서를 잠근다 (7.1.0 출시 점검 CU-14).
 *
 * `upgrades/data/7.1.0/migrations/` 에는 접두 번호가 겹치는 파일이 두 쌍 있다(`01_`×2, `05_`×2). 실행 순서는
 * `AbstractUpgradeStep` 이 파일 경로를 `sort()` 한 순서라 결정적이다 — 번호가 같으면 파일 이름 나머지가 가른다.
 * 출시 뒤 이 디렉토리는 동결되므로 파일 이름을 바꾸지 않고, 순서와 순서 의존(02 해시 정규화 → 05 재키잉의 수정 판정,
 * 03 거짓 수정 표식 정리 → 07 본인인증 문구 이관, 00 코어 선언 재동기화가 맨 앞)을 시험으로 고정한다.
 */
class Upgrade710DataMigrationOrderTest extends TestCase
{
    /**
     * 파일 정렬 순서가 기대 순서와 같고, 순서 의존 쌍이 앞뒤를 지킨다.
     *
     * @scenario step_dir=core_7_1_0
     *
     * @effects data_migrations_run_in_deterministic_sorted_order, order_dependent_pairs_keep_their_sequence
     *
     * @return void 반환값 없음
     */
    public function test_data_migrations_run_in_locked_order(): void
    {
        $files = glob(base_path('upgrades/data/7.1.0/migrations').'/*.php') ?: [];
        sort($files);
        $names = array_map(fn (string $path) => basename($path, '.php'), $files);

        $this->assertSame([
            '00_ResyncCoreDeclarations',
            '01_BackfillNotificationDefinitionRelations',
            '01_ShiftCoreMenuOrderForAnalytics',
            '02_RehashLayoutOriginalContent',
            '03_PruneFalseUserOverrides',
            '04_BackfillTemplateOverrideOriginals',
            '05_BackfillAdminRoleOnMenus',
            '05_RekeyLegacyLayoutExtensionFragments',
            '06_PurgeOrphanPluginLayouts',
            '07_MigrateIdentityMessagesToNotifications',
            '08_DropLegacyMailTables',
            '09_PruneStaleBundledFiles',
        ], $names, '7.1.0 데이터 스텝 목록·순서가 바뀌었다 — 출시 뒤 동결 디렉토리다');

        $at = array_flip($names);
        $this->assertLessThan($at['05_RekeyLegacyLayoutExtensionFragments'], $at['02_RehashLayoutOriginalContent'], '재키잉의 수정 판정은 02 의 해시 정규화 뒤여야 한다');
        $this->assertLessThan($at['07_MigrateIdentityMessagesToNotifications'], $at['03_PruneFalseUserOverrides'], '본인인증 문구 이관은 03 이 거짓 수정 표식을 지운 뒤여야 한다');
        $this->assertSame(0, $at['00_ResyncCoreDeclarations'], '코어 선언 재동기화는 다른 스텝보다 먼저 돌아야 한다(CU-3)');
    }
}
