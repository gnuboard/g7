<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_4\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 상품 후기 표가 회원·주문 없는 후기를 담을 수 있는지 확인하고, 빠졌으면 채웁니다.
 *
 * 1.3.4 는 다른 쇼핑몰에서 이관한 후기처럼 회원·주문 연결이 없는 후기를 후기 표에 직접 넣는다. 스키마 변경은
 * 마이그레이션(`update_imported_review_fields_in_ecommerce_product_reviews_table`)이 하지만, 업데이트 도중
 * 마이그레이션이 부분 적용된 채 끝난 설치본이 그대로 1.3.4 로 기록되면 이관이 NOT NULL 제약에 막힌다.
 * 업그레이드 시점에 같은 결과(`order_option_id`·`user_id` NULL 허용, `author_name` 존재)를 한 번 더 보장한다.
 *
 * 1.3.4 시점 판정을 이 파일에 동결한다(버전별 데이터 스냅샷 규약 — 모듈 모델·서비스를 부르지 않는다).
 *
 * idempotent: 이미 반영된 항목은 건드리지 않는다. V-1 안전: Facades\Schema 만 사용.
 */
class EnsureImportedReviewColumns implements DataMigration
{
    private const TABLE = 'ecommerce_product_reviews';

    /** @var array<string, string> NULL 허용이어야 하는 컬럼 => comment */
    private const NULLABLE_COLUMNS = [
        'order_option_id' => '주문 옵션 ID (회원·주문 없이 등록한 후기는 NULL)',
        'user_id' => '작성자 ID (회원·주문 없이 등록한 후기는 NULL)',
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'EnsureImportedReviewColumns';
    }

    /**
     * 후기 표의 이관 후기 컬럼을 확인하고 빠진 것만 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[ecommerce:1.3.4] 상품 후기 표 부재 — 이관 후기 컬럼 확인 스킵');

            return;
        }

        $columns = [];
        foreach (Schema::getColumns(self::TABLE) as $definition) {
            $columns[$definition['name']] = $definition;
        }

        foreach (self::NULLABLE_COLUMNS as $column => $comment) {
            if (! isset($columns[$column])) {
                $context->logger->warning("[ecommerce:1.3.4] 상품 후기 표에 {$column} 컬럼이 없음 — NULL 허용 보정 스킵");

                continue;
            }

            if ($columns[$column]['nullable'] ?? false) {
                $context->logger->info("[ecommerce:1.3.4] {$column} 는 이미 NULL 허용 — 스킵");

                continue;
            }

            Schema::table(self::TABLE, function ($table) use ($column, $comment) {
                $table->unsignedBigInteger($column)->nullable()->comment($comment)->change();
            });

            $context->logger->info("[ecommerce:1.3.4] {$column} 를 NULL 허용으로 보정");
        }

        if (isset($columns['author_name'])) {
            $context->logger->info('[ecommerce:1.3.4] author_name 컬럼이 이미 있음 — 스킵');

            return;
        }

        Schema::table(self::TABLE, function ($table) {
            $table->string('author_name', 50)
                ->nullable()
                ->after('user_id')
                ->comment('작성자 표시 이름 (회원·주문 없이 등록한 후기 — 다른 쇼핑몰 이관 등)');
        });

        $context->logger->info('[ecommerce:1.3.4] author_name 컬럼 추가');
    }
}
