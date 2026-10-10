<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftPayNicepayments\V1_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 주문서 간편결제 버튼 조각의 구 overlay 등록 행을 제거한다.
 *
 * 배경:
 *
 * 1.0.x 는 주문서(`shop/checkout`)의 `nicepay_checkout_payment_section` 안에 간편결제 버튼 묶음을 덧붙이는 조각을
 * 등록했다. 그런데 그 주입 대상은 주문서 어디에도 없어 조각이 한 번도 그려지지 않았고, 간편결제는 이미 이커머스
 * 결제수단 목록에 1급 결제수단으로 등록돼 그 목록이 그린다. 1.1.0 에서 조각 파일을 걷어냈다.
 *
 * 확장 갱신은 등록과 "적용 불가" 정리만 하고, **파일이 더 이상 선언하지 않는 대상의 행은 지우지 않는다.**
 * 그래서 이미 설치를 마친 사이트에는 쓰이지 않는 구 overlay 행이 남는다. 본 마이그레이션이 그 행만 지운다.
 *
 * 범위: **이 플러그인이 주문서에 등록한 overlay 행만** 지운다(`source_identifier` · `target_name`). 이 플러그인은
 * 주문서에 다른 overlay 를 두지 않는다. 다른 확장이 같은 레이아웃을 겨냥해 둔 행은 그 확장의 소관이다.
 *
 * 멱등: 이미 지워졌으면 대상이 0건이라 재실행해도 변화가 없다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - `Illuminate\Support\Facades\{DB,Schema}` 만 사용 (이전 버전에도 존재하던 표면)
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class DropLegacyCheckoutEasyPayOverlay implements DataMigration
{
    /**
     * 확장 등록 테이블.
     */
    private const TABLE = 'template_layout_extensions';

    /**
     * 구 조각이 겨냥하던 레이아웃 이름.
     */
    private const LEGACY_TARGET = 'shop/checkout';

    /**
     * 이 플러그인 식별자 — 자기 행만 지우기 위한 스코프.
     */
    private const SOURCE_IDENTIFIER = 'sirsoft-pay_nicepayments';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'DropLegacyCheckoutEasyPayOverlay';
    }

    /**
     * 구 주문서 간편결제 overlay 등록 행을 제거한다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[sirsoft-pay_nicepayments] 확장 등록 테이블 없음 — 구 주문서 간편결제 조각 정리 skip');

            return;
        }

        $deleted = DB::table(self::TABLE)
            ->where('extension_type', 'overlay')
            ->where('target_name', self::LEGACY_TARGET)
            ->where('source_identifier', self::SOURCE_IDENTIFIER)
            ->delete();

        if ($deleted === 0) {
            $context->logger->info('[sirsoft-pay_nicepayments] 구 주문서 간편결제 overlay 행 없음 — 변경 없음');

            return;
        }

        $context->logger->info('[sirsoft-pay_nicepayments] 그려지지 않던 주문서 간편결제 조각의 구 overlay 행 제거 완료', [
            'deleted' => $deleted,
        ]);
    }
}
