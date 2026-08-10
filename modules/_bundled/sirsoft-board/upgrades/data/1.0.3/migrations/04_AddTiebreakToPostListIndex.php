<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_0_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 게시글 목록 인덱스 말미에 기본키를 덧붙여 정렬을 완전히 덮습니다.
 *
 * 기존 `idx_board_posts_list_count` 는 created_at 에서 끝나는데 목록 정렬은
 * `created_at DESC, id DESC` 라, 동률 구간의 id 순서를 인덱스로 만들 수 없어 filesort 가
 * 붙습니다. 지연 조인의 inner 는 키 컬럼만 읽으므로 filesort 만 걷어내면 인덱스 순서 그대로
 * 끝납니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드
 * 시점에 같은 교체를 수행합니다.
 *
 * 게시글이 많은 사이트에서는 인덱스 교체가 수 분 이상 걸릴 수 있고 그동안 게시글 쓰기가
 * 대기합니다. 새 인덱스를 먼저 만들고 기존 인덱스를 나중에 지우므로, 중간에 중단되어도
 * 조회가 인덱스 없이 남는 구간은 없습니다.
 *
 * 소요 시간 주의 — `board_posts` 는 `PARTITION BY LIST (board_id)` 파티션 테이블입니다
 * (`2026_04_01_000004_create_board_posts_table.php`). 로컬 인덱스라 인덱스 생성·삭제가
 * 게시판 수만큼의 **전 파티션에 각각 적용**되므로, 같은 행 수의 비파티션 테이블보다 소요
 * 시간이 큽니다. 게시판이 많은 사이트일수록 차이가 커지니 트래픽이 적은 시간대에 실행하세요.
 *
 * idempotent: 이미 교체된 경우 아무것도 하지 않습니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddTiebreakToPostListIndex implements DataMigration
{
    private const TABLE = 'board_posts';

    private const OLD_INDEX = 'idx_board_posts_list_count';

    private const NEW_INDEX = 'idx_board_posts_list_count_id';

    /** @var array<int, string> */
    private const NEW_COLUMNS = ['board_id', 'is_notice', 'parent_id', 'deleted_at', 'created_at', 'id'];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddTiebreakToPostListIndex';
    }

    /**
     * 목록 인덱스를 기본키 포함 형태로 교체합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[board:1.0.3] 게시글 테이블 부재 — 목록 인덱스 교체 스킵');

            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        if (! in_array(self::NEW_INDEX, $existing, true)) {
            $context->logger->info('[board:1.0.3] 목록 인덱스 교체 시작 (게시글이 많으면 수 분 이상 걸릴 수 있고 그동안 게시글 쓰기가 대기합니다)');

            Schema::table(self::TABLE, function ($table) {
                $table->index(self::NEW_COLUMNS, self::NEW_INDEX);
            });

            $context->logger->info('[board:1.0.3] 새 목록 인덱스 생성 완료: '.self::NEW_INDEX);
        } else {
            $context->logger->info('[board:1.0.3] 새 목록 인덱스가 이미 존재 — 생성 스킵');
        }

        // 새 인덱스가 기존 인덱스의 상위집합이므로 남겨 두면 쓰기 비용만 늘어난다.
        // 반드시 새 인덱스 생성 이후에 지운다 — 순서를 바꾸면 중간 중단 시 목록 조회가
        // 인덱스 없이 남는 구간이 생긴다.
        if (in_array(self::OLD_INDEX, $existing, true)) {
            Schema::table(self::TABLE, function ($table) {
                $table->dropIndex(self::OLD_INDEX);
            });

            $context->logger->info('[board:1.0.3] 상위집합에 포함된 기존 인덱스 제거: '.self::OLD_INDEX);
        }
    }
}
