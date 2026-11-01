<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_3\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 주문상품 행의 과세 종류(`order_options.tax_status`)를 백필합니다 (#612).
 *
 * 1.3.3 이 주문 시점 확정 과세 종류를 행에 기록하기 시작했다. 그 전 행은 비어 있으므로 상품 스냅샷
 * (`product_snapshot.tax_status`)의 값으로 채운다. 스냅샷에 값이 없거나 알 수 없는 값이면 과세로 본다
 * — 1.3.2 까지의 계산기가 그 행을 과세로 분류해 금액을 나눴기 때문이다(「없으면 과세」).
 *
 * 1.3.3 시점 판정을 이 파일에 동결한다(버전별 데이터 스냅샷 규약 — 모듈 Enum·서비스를 부르지 않는다).
 *
 * idempotent: 값이 이미 있는 행은 건드리지 않는다. V-1 안전: Facades\DB/Schema 만 사용.
 */
class BackfillOrderOptionTaxStatus implements DataMigration
{
    private const TABLE = 'ecommerce_order_options';

    /** @var int 청크 크기 */
    private const CHUNK = 500;

    /** @var array<int, string> 1.3.3 이 아는 과세 종류 값 */
    private const KNOWN = ['taxable', 'tax_free', 'zero_rated'];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillOrderOptionTaxStatus';
    }

    /**
     * 과세 종류가 비어 있는 주문상품 행을 상품 스냅샷 값으로 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'tax_status')) {
            $context->logger->info('[ecommerce:1.3.3] 주문상품 과세 종류 백필 — 대상 컬럼 부재로 스킵');

            return;
        }

        $hasSnapshot = Schema::hasColumn(self::TABLE, 'product_snapshot');
        $updated = 0;
        $lastId = 0;

        while (true) {
            $rows = DB::table(self::TABLE)
                ->where('id', '>', $lastId)
                ->whereNull('tax_status')
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get($hasSnapshot ? ['id', 'product_snapshot'] : ['id']);

            if ($rows->isEmpty()) {
                break;
            }

            $byStatus = [];
            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $byStatus[$this->resolveStatus($hasSnapshot ? $row->product_snapshot : null)][] = (int) $row->id;
            }

            foreach ($byStatus as $status => $ids) {
                $updated += DB::table(self::TABLE)
                    ->whereIn('id', $ids)
                    ->whereNull('tax_status')
                    ->update(['tax_status' => $status]);
            }

            $context->logger->info("[ecommerce:1.3.3] 주문상품 과세 종류 백필 진행 — 누적 {$updated}건 (마지막 행 ID {$lastId})");
        }

        $context->logger->info("[ecommerce:1.3.3] 주문상품 과세 종류 백필 완료 — 총 {$updated}건");
    }

    /**
     * 상품 스냅샷 JSON 에서 과세 종류를 읽습니다. 없거나 모르는 값이면 과세.
     *
     * @param  mixed  $snapshot  product_snapshot 원문(JSON 문자열) 또는 null
     * @return string 과세 종류 값
     */
    private function resolveStatus(mixed $snapshot): string
    {
        $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
        $value = is_array($decoded) ? ($decoded['tax_status'] ?? null) : null;

        return is_string($value) && in_array($value, self::KNOWN, true) ? $value : 'taxable';
    }
}
