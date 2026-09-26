<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 게시글 작성 IP 의 자리표시값 '0.0.0.0' 을 NULL 로 되돌립니다.
 *
 * 1.2.0 이전 board_posts.ip_address 는 NOT NULL 이라, IP 를 알 수 없는 글(이커머스 상품문의
 * 연동이 만든 글)에 '0.0.0.0' 을 채웠다. 실제 접속 IP 가 될 수 없는 값이라 뜻은 "모름" 이고,
 * 남겨 두면 개인정보 보유기간 처리가 그 행을 IP 가 남은 행으로 센다.
 *
 * 같은 버전의 마이그레이션이 컬럼을 nullable 로 바꾼 뒤에 실행된다(확장 갱신은 마이그레이션 →
 * 업그레이드 스텝 순서). 컬럼이 아직 NOT NULL 이면 건너뛴다.
 *
 * 멱등: 자리표시값이 남은 행만 바꾸므로 재실행해도 결과가 같다.
 */
class NormalizePlaceholderPostIp implements DataMigration
{
    private const POSTS_TABLE = 'board_posts';

    private const PLACEHOLDER = '0.0.0.0';

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'NormalizePlaceholderPostIp';
    }

    /**
     * 자리표시값을 NULL 로 바꿉니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::POSTS_TABLE) || ! Schema::hasColumn(self::POSTS_TABLE, 'ip_address')) {
            $context->logger->warning('[board:1.2.0] board_posts.ip_address 미존재 — 스킵');

            return;
        }

        $column = collect(Schema::getColumns(self::POSTS_TABLE))->firstWhere('name', 'ip_address');

        if (! ($column['nullable'] ?? false)) {
            $context->logger->warning('[board:1.2.0] board_posts.ip_address 가 아직 NOT NULL — 스킵');

            return;
        }

        $normalized = DB::table(self::POSTS_TABLE)
            ->where('ip_address', self::PLACEHOLDER)
            ->update(['ip_address' => null]);

        $context->logger->info("[board:1.2.0] 게시글 작성 IP 자리표시값 정리: {$normalized}건");
    }
}
