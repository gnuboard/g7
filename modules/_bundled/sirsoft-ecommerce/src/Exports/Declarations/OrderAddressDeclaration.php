<?php

namespace Modules\Sirsoft\Ecommerce\Exports\Declarations;

use Modules\Sirsoft\Ecommerce\Exports\ExportExclusion;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;

/**
 * 주문 주소(주문자·수령자) (OrderAddress) 내보내기 선언 — 컬럼마다 내보낼지(값 종류·개인정보 여부·합계 여부) 아니면 제외할지(사유)
 *
 * 모델의 모든 컬럼은 둘 중 한 곳에만 있어야 한다. 이커머스 모듈이 컬럼을 늘리면 스키마 동기화 테스트가
 * 「어느 목록에도 없음」으로 잡으므로, 여기 한 줄을 더하면 내보내기 창·파일·합계·권한이 따라온다.
 */
final class OrderAddressDeclaration implements ModelDeclaration
{
    /**
     * {@inheritDoc}
     */
    public static function model(): string
    {
        return OrderAddress::class;
    }

    /**
     * {@inheritDoc}
     */
    public static function fields(): array
    {
        return [
            'orderer_name' => ['type' => 'string', 'personal' => true],
            'orderer_phone' => ['type' => 'string', 'personal' => true],
            'orderer_email' => ['type' => 'string', 'personal' => true],
            'orderer_locale' => ['type' => 'string', 'personal' => false],
            'recipient_name' => ['type' => 'string', 'personal' => true],
            'recipient_phone' => ['type' => 'string', 'personal' => true],
            'recipient_email' => ['type' => 'string', 'personal' => true],
            'recipient_country_code' => ['type' => 'string', 'personal' => false],
            'recipient_province_code' => ['type' => 'string', 'personal' => false],
            'recipient_city' => ['type' => 'string', 'personal' => true],
            'zipcode' => ['type' => 'string', 'personal' => true],
            'address' => ['type' => 'string', 'personal' => true],
            'address_detail' => ['type' => 'string', 'personal' => true],
            'address_line_1' => ['type' => 'string', 'personal' => true],
            'address_line_2' => ['type' => 'string', 'personal' => true],
            'intl_city' => ['type' => 'string', 'personal' => true],
            'intl_state' => ['type' => 'string', 'personal' => true],
            'intl_postal_code' => ['type' => 'string', 'personal' => true],
            'delivery_memo' => ['type' => 'string', 'personal' => true],
            'delivery_memo_label' => ['type' => 'string', 'personal' => false],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public static function excluded(): array
    {
        return [
            'id' => ExportExclusion::PRIMARY_KEY,
            'order_id' => ExportExclusion::FOREIGN_KEY,
            'address_type' => ExportExclusion::INTERNAL,
            'address_type_code' => ExportExclusion::INTERNAL,
            'created_at' => ExportExclusion::RECORD_META,
            'updated_at' => ExportExclusion::RECORD_META,
        ];
    }
}
