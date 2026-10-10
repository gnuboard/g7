<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_0_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 게시판 반응 유형(추천/비추천) 기본 데이터를 백필합니다.
 *
 * 기본 반응 유형은 BoardReactionTypeSeeder 가 만드는데, 시더는 module:install 에서만 자동 실행되고
 * module:update 경로에서는 실행되지 않는다(관리자 반응 유형 편집 화면에서 유형을 추가할 수는 있지만
 * 기본 유형인 추천·비추천을 대신 만들어 주지는 않는다).
 * 따라서 반응 기능 이전에 이미 설치된 사이트가 업데이트만 받으면 board_reaction_types
 * 테이블이 비어 관리 화면의 "사용할 반응 유형" 목록이 아무것도 표시되지 않는다. 이 스텝이
 * 업그레이드 시 기본 유형(추천·비추천)을 채워 그 공백을 메운다.
 *
 * 이 스텝은 1.0.3 미만에서 출발할 때만 선택된다. 1.0.3 이상(예: 1.1.2)에서 출발하는 사이트는
 * 같은 일을 하는 1.2.0 스텝(data/1.2.0/migrations/06_SeedReactionTypes.php)이 맡는다.
 *
 * idempotent: code 로 존재를 확인해 없는 유형만 삽입한다. 이미 있으면(신규 설치 시더가 넣었거나
 * 운영자가 편집했으면) 건드리지 않아 사용자 수정(user_overrides)과 활성 상태를 보존한다.
 * V-1 안전: Facades\DB/Schema 만 사용하고 ReactionType 모델·시더를 참조하지 않는다.
 */
class SeedReactionTypes implements DataMigration
{
    private const TABLE = 'board_reaction_types';

    /**
     * 백필할 기본 반응 유형 — 1.0.3 시점 값 동결본.
     *
     * 현행 SSoT 는 Support\ReactionTypeDefaults 이며, 이 상수는 이미 이 스텝을 실행한 설치본의
     * 재현성을 위해 그 시점 값 그대로 동결한다(V-1 안전 규약상 모델·시더 참조도 불가).
     * 두 값의 드리프트는 tests/Unit/Support/ReactionTypeDefaultsTest 가 감시한다.
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEFAULTS = [
        [
            'code' => 'like',
            'name' => ['ko' => '추천', 'en' => 'Recommend', 'ja' => 'おすすめ'],
            'icon' => 'fas fa-thumbs-up',
            'display_order' => 1,
        ],
        [
            'code' => 'dislike',
            'name' => ['ko' => '비추천', 'en' => 'Not Recommend', 'ja' => 'よくない'],
            'icon' => 'fas fa-thumbs-down',
            'display_order' => 2,
        ],
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SeedReactionTypes';
    }

    /**
     * 누락된 기본 반응 유형을 백필합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'code')) {
            $context->logger->info('[board:1.0.3] 반응 유형 백필 — 대상 스키마 부재로 스킵');

            return;
        }

        $now = now();
        $inserted = 0;

        foreach (self::DEFAULTS as $type) {
            $exists = DB::table(self::TABLE)->where('code', $type['code'])->exists();

            if ($exists) {
                $context->logger->info("[board:1.0.3] 반응 유형 '{$type['code']}' 이미 존재 — 보존(스킵)");

                continue;
            }

            DB::table(self::TABLE)->insert([
                'code' => $type['code'],
                'name' => json_encode($type['name'], JSON_UNESCAPED_UNICODE),
                'icon' => $type['icon'],
                'display_order' => $type['display_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $inserted++;
            $context->logger->info("[board:1.0.3] 반응 유형 '{$type['code']}' 백필 생성");
        }

        $context->logger->info("[board:1.0.3] 반응 유형 백필 완료 — 신규 {$inserted} 건");
    }
}
