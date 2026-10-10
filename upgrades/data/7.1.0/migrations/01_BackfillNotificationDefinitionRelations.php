<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 알림 정의의 `relations` 를 기존 설치본에 채웁니다.
 *
 * 7.1.0 은 알림 템플릿의 「관련 사용자」 수신자(`related_user.relation`)를 정의가 선언한 키로만
 * 받는다. 그런데 `relations` 컬럼은 마이그레이션이 추가만 하고 기존 정의는 NULL 로 둔다 — 확장이
 * 다시 동기화될 때까지(제3자 확장은 그 확장이 업데이트될 때까지) 기존 related_user 규칙이 든 템플릿을
 * 저장하면 검증이 선언 밖 키로 보고 거절했다.
 *
 * 채우는 규칙은 선언 경로(`NotificationSyncHelper::relationsFromTemplates`)와 같다 — 그 정의의 템플릿이
 * 실제로 쓰는 relation 키(등장 순서, 중복 제거). related_user 규칙이 없으면 빈 배열로 둔다("선언 전" 에서
 * "없음" 으로). 이미 값이 있는 정의는 건드리지 않는다.
 *
 * 멱등: NULL 인 행만 고치므로 여러 번 실행해도 결과가 같다.
 *
 * V-1 안전: 본 디렉토리는 릴리즈 후 동결된다. 모델 대신 쿼리 빌더로 저장값을 직접 다룬다.
 */
class BackfillNotificationDefinitionRelations implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillNotificationDefinitionRelations';
    }

    /**
     * relations 가 NULL 인 정의를 템플릿이 쓰는 relation 키로 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('notification_definitions') || ! Schema::hasColumn('notification_definitions', 'relations')) {
            $context->logger->info('[core:7.1.0] notification_definitions.relations 부재 — 백필 스킵');

            return;
        }

        $filled = 0;

        DB::table('notification_definitions')->whereNull('relations')->orderBy('id')->chunkById(200, function ($definitions) use (&$filled) {
            foreach ($definitions as $definition) {
                $relations = [];

                $rows = DB::table('notification_templates')->where('definition_id', $definition->id)->pluck('recipients');

                foreach ($rows as $raw) {
                    $rules = is_string($raw) ? json_decode($raw, true) : $raw;

                    foreach (is_array($rules) ? $rules : [] as $rule) {
                        if (! is_array($rule) || ($rule['type'] ?? null) !== 'related_user') {
                            continue;
                        }

                        $relation = $rule['relation'] ?? null;

                        if (is_string($relation) && $relation !== '' && ! in_array($relation, $relations, true)) {
                            $relations[] = $relation;
                        }
                    }
                }

                DB::table('notification_definitions')->where('id', $definition->id)->whereNull('relations')
                    ->update(['relations' => json_encode($relations)]);
                $filled++;
            }
        });

        $context->logger->info("[core:7.1.0] notification_definitions.relations 백필 {$filled}건");
    }
}
