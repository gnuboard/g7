<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 게시판 반응 유형(추천/비추천) 기본 데이터를 채웁니다.
 *
 * board_reaction_types 표는 1.2.0 마이그레이션이 새로 만든다. 반응 유형 시더는 module:install 에서만
 * 자동 실행되고 module:update 경로에서는 실행되지 않으며, 같은 일을 하는 1.0.3 백필 스텝
 * (data/1.0.3/migrations/07_SeedReactionTypes.php)은 1.0.3 미만에서 출발할 때만 선택된다. 그래서
 * 1.0.3 이상(예: 7.0.11 의 1.1.2)에서 업데이트한 사이트는 표가 비어 관리 화면의 「사용할 반응 유형」
 * 목록과 반응 관리 화면이 비고, 사용자 화면에 반응 버튼이 나오지 않는다. 이 스텝이 그 공백을 메운다.
 *
 * 1.2.0 이전 개발판에서 일본어 비추천 이름이 「ひどい」로 들어간 행은, 운영자가 일본어 이름을
 * 고치지 않았을 때(user_overrides 에 name·name.ja 없음)만 「よくない」로 바꾼다.
 *
 * idempotent: code 로 존재를 확인해 없는 유형만 삽입한다. 이미 있으면 아이콘·순서·활성 상태를
 * 건드리지 않아 운영자 수정을 보존한다.
 * V-1 안전: Facades\DB/Schema 만 사용하고 ReactionType 모델·시더를 참조하지 않는다.
 */
class SeedReactionTypes implements DataMigration
{
    private const TABLE = 'board_reaction_types';

    /** 1.2.0 이전 개발판의 일본어 비추천 이름 */
    private const LEGACY_DISLIKE_JA = 'ひどい';

    /**
     * 채울 기본 반응 유형 — 1.2.0 시점 값 동결본.
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
     * 누락된 기본 반응 유형을 채우고 옛 일본어 비추천 이름을 바꿉니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'code')) {
            $context->logger->info('[board:1.2.0] 반응 유형 시드 — 대상 스키마 부재로 스킵');

            return;
        }

        $now = now();
        $inserted = 0;

        foreach (self::DEFAULTS as $type) {
            $existing = DB::table(self::TABLE)->where('code', $type['code'])->first();

            if ($existing !== null) {
                if ($this->renameLegacyJapaneseName($existing, $type)) {
                    $context->logger->info("[board:1.2.0] 반응 유형 '{$type['code']}' 일본어 이름을 「{$type['name']['ja']}」로 갱신");
                }

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
        }

        $context->logger->info("[board:1.2.0] 반응 유형 시드 완료 — 신규 {$inserted} 건");
    }

    /**
     * 옛 기본 일본어 이름(「ひどい」)이 그대로 남은 비추천 유형의 일본어 이름을 바꿉니다.
     *
     * 운영자가 일본어 이름을 고쳤으면(user_overrides 에 name 또는 name.ja) 그대로 둔다.
     *
     * @param  object  $row  board_reaction_types 행
     * @param  array<string, mixed>  $type  동결 기본값 한 건
     * @return bool 바꿨으면 true
     */
    private function renameLegacyJapaneseName(object $row, array $type): bool
    {
        if ($type['code'] !== 'dislike') {
            return false;
        }

        $name = json_decode((string) $row->name, true);

        if (! is_array($name) || ($name['ja'] ?? null) !== self::LEGACY_DISLIKE_JA) {
            return false;
        }

        $overrides = json_decode((string) ($row->user_overrides ?? ''), true);

        if (is_array($overrides) && array_intersect(['name', 'name.ja'], $overrides) !== []) {
            return false;
        }

        $name['ja'] = $type['name']['ja'];

        DB::table(self::TABLE)->where('id', $row->id)->update([
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return true;
    }
}
