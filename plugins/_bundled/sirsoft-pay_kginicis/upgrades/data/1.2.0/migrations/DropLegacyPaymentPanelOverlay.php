<?php

declare(strict_types=1);

namespace App\Upgrades\Data\Ext\Plugins\SirsoftPayKginicis\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 결제 정보 카드 주입의 구 overlay 등록 행을 제거한다.
 *
 * 배경:
 *
 * 이 플러그인은 가상계좌 입금 안내·영수증·부가세 내역을 주문 상세의 결제 정보 카드에
 * 끼워 넣는다. 그 주입이 레이아웃 이름을 겨냥하는 overlay(`target_layout: mypage/orders/show`)
 * 였는데, 그 카드는 `templates/_bundled/sirsoft-basic/layouts/partials/mypage/orders/_payment.g7.html` 이고 다른 화면(클레임 신청서 상세)도
 * 같은 partial 을 재사용한다. overlay 는 `extends` 상속 체인만 따라가고 partial 재사용은
 * 따라가지 않으므로, 그 화면에서는 카드는 뜨는데 이 블록만 빠진 채 열렸다 — 오류도 경고도
 * 남지 않는다. 그래서 주입 대상을 partial 안의 공용 슬롯
 * (`mypage_order_payment_provider_slot`)으로 옮겼다.
 *
 * 그런데 확장 갱신은 등록과 "적용 불가" 정리만 하고, **파일이 더 이상 선언하지 않는 대상의
 * 행은 지우지 않는다.** 그래서 이미 설치를 마친 사이트는 구 overlay 행이 그대로 살아남아
 * 주문 상세에 같은 블록이 **두 번** 뜬다. 본 마이그레이션이 그 행만 지운다.
 *
 * 범위: **이 플러그인이 등록한 행만** 지운다(`source_identifier`). 다른 확장이 같은 레이아웃을
 * 겨냥해 둔 행은 그 확장의 소관이다.
 *
 * 멱등: 이미 지워졌으면 대상이 0건이라 재실행해도 변화가 없다.
 *
 * V-1 안전 격리 (docs/extension/upgrade-step-guide.md §13):
 *   - `Illuminate\Support\Facades\{DB,Schema}` 만 사용 (이전 버전에도 존재하던 표면)
 *   - Service / Manager / Repository 컨테이너 해석 없음
 */
final class DropLegacyPaymentPanelOverlay implements DataMigration
{
    /**
     * 확장 등록 테이블.
     */
    private const TABLE = 'template_layout_extensions';

    /**
     * 구 overlay 가 겨냥하던 레이아웃 이름.
     */
    private const LEGACY_TARGET = 'mypage/orders/show';

    /**
     * 이 플러그인 식별자 — 자기 행만 지우기 위한 스코프.
     */
    private const SOURCE_IDENTIFIER = 'sirsoft-pay_kginicis';

    /**
     * 마이그레이션 식별자 (로그용).
     *
     * @return string 사람이 읽을 수 있는 짧은 식별자
     */
    public function name(): string
    {
        return 'DropLegacyPaymentPanelOverlay';
    }

    /**
     * 구 overlay 등록 행을 제거한다. idempotent.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거 등)
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[sirsoft-pay_kginicis] 확장 등록 테이블 없음 — 구 overlay 정리 skip');

            return;
        }

        $deleted = DB::table(self::TABLE)
            ->where('extension_type', 'overlay')
            ->where('target_name', self::LEGACY_TARGET)
            ->where('source_identifier', self::SOURCE_IDENTIFIER)
            ->delete();

        if ($deleted === 0) {
            $context->logger->info('[sirsoft-pay_kginicis] 구 overlay 등록 행 없음 — 변경 없음');

            return;
        }

        $context->logger->info('[sirsoft-pay_kginicis] 결제 정보 카드의 구 overlay 등록 행 제거 완료 (슬롯 주입으로 대체)', [
            'deleted' => $deleted,
        ]);
    }
}
