<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_0_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 게시글 목록의 조회수 정렬 색인을 추가합니다.
 *
 * 조회수 정렬은 화면에서 실제로 도달 가능한 경로인데(게시판 설정의 기본 정렬과 목록
 * URL 둘 다 있습니다), 목록 술어를 등치 사슬로 덮으면서 `view_count` 로 끝나는 색인이
 * 없어 뒤쪽 페이지가 테이블 전체를 훑고 정렬했습니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드
 * 시점에 같은 색인을 추가합니다.
 *
 * 게시글이 많이 쌓인 사이트에서는 수 분 걸릴 수 있고 그동안 글쓰기가 대기합니다.
 *
 * 기존 `idx_board_posts_board_view_count` 는 건드리지 않습니다 — 인기글 조회가 쓰는
 * 색인이며 이 목록 색인이 대신하지 못합니다.
 *
 * idempotent: 이미 존재하는 색인은 건너뜁니다. V-1 안전: Facades\Schema 만 사용합니다.
 */
class AddPostListViewCountIndex implements DataMigration
{
    private const TABLE = 'board_posts';

    private const INDEX_NAME = 'idx_board_posts_list_views';

    /** @var array<int, string> */
    private const INDEX_COLUMNS = ['board_id', 'is_notice', 'parent_id', 'deleted_at', 'view_count', 'id'];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddPostListViewCountIndex';
    }

    /**
     * 게시글 목록 조회수 정렬 색인을 추가합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[board:1.0.3] 게시글 테이블 부재 — 조회수 정렬 색인 추가 스킵');

            return;
        }

        $existing = array_column(Schema::getIndexes(self::TABLE), 'name');

        if (in_array(self::INDEX_NAME, $existing, true)) {
            $context->logger->info('[board:1.0.3] 이미 존재하는 색인 — 스킵: '.self::INDEX_NAME);

            return;
        }

        $context->logger->info('[board:1.0.3] 게시글 목록 조회수 정렬 색인 추가 시작 (게시글이 많으면 수 분 걸릴 수 있고 그동안 글쓰기가 대기합니다)');

        Schema::table(self::TABLE, function ($table) {
            $table->index(self::INDEX_COLUMNS, self::INDEX_NAME);
        });

        $context->logger->info('[board:1.0.3] 게시글 목록 조회수 정렬 색인 추가 완료: '.self::INDEX_NAME);
    }
}
