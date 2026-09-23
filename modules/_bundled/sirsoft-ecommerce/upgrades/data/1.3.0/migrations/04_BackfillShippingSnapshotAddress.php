<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 배송정책 스냅샷의 배송지 메타(`address`)가 빈 과거 주문을 주문 배송지로 백필합니다.
 *
 * 배송지 스냅샷은 1.0.0-beta.5(#421)부터 저장되므로, 그 이전에 생성된 주문은
 * `shipping_policy_applied_snapshot.address` 가 비어 있다. 1.0.5 의 형태 정규화
 * (`06_NormalizeShippingPolicySnapshotShape`)는 스냅샷의 *모양*만 신형으로 옮겼을 뿐
 * 없는 배송지를 채우지는 않았다.
 *
 * 그 상태에서 같은 주문의 도서산간 판정이 경로에 따라 갈린다:
 *
 * - 환불 재계산(`OrderAdjustmentService`)은 `ShippingPolicySnapshot::address()` 원본을
 *   폴백 없이 읽으므로 우편번호가 null 이 되어 도서산간 추가배송비가 0 원이 된다
 * - 클레임 배송비 산정(`ClaimShippingFeeService`)은 `deliveryAddress()` 의 주문 주소
 *   폴백을 타므로 도서산간이 가산된다
 *
 * 두 경로가 갈리면 취소 가능 금액 산정(D19)의 ① 축(환불 대상 금액)과 클레임 청구액이
 * 서로 다른 전제 위에 서게 되어 `min()` 이 성립하지 않는다. 오류도 경고도 남지 않고
 * 금액만 어긋난다.
 *
 * **대응을 읽기 경로 수정이 아니라 백필로 잡은 이유**: 환불 재계산에 폴백을 넣으면 과거
 * 주문의 환불 금액 산식 자체가 바뀐다. 반면 스냅샷을 채우면 두 경로가 **코드 수정 없이**
 * 같은 값으로 수렴한다. 백필 값의 출처(`ecommerce_order_addresses`)는 클레임 경로의 폴백이
 * 이미 읽고 있는 바로 그 값이므로, 새 근사를 도입하는 것이 아니라 **기존 근사를 동결**하는
 * 것이다. 주소 행은 사후 변경될 수 있어 늦출수록 원본에서 멀어진다.
 *
 * 백필되지 않는 구간은 둘이며, 성격이 달라 로그에서도 나눠 센다.
 *
 * - **주소 행 자체가 없는 주문**: 클레임 경로의 폴백도 우편번호를 얻지 못하므로 양 경로 모두
 *   도서산간 0 원으로 남는다(현재도 환불 경로는 그렇게 동작한다). 두 경로가 갈리지 않는다
 * - **주소 행은 있으나 국가 코드가 빈 주문**: 여기에 국가를 써 넣는 것은 확인된 적 없는 값을
 *   데이터로 확정하는 일이라 백필 대상에서 뺀다. 다만 클레임 경로는 `deliveryAddress()` 의
 *   `KR` 기본값과 주소 행의 우편번호로 도서산간을 판정할 수 있어 **두 경로가 갈릴 수 있다.**
 *   운영자가 그 사실을 알 수 있도록 건수를 따로 남긴다
 *
 * idempotent: `address.country_code` 가 이미 있는 행은 건너뛴다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Model/Service/Support 를 참조하지 않는다.
 */
class BackfillShippingSnapshotAddress implements DataMigration
{
    private const ORDERS_TABLE = 'ecommerce_orders';

    private const ADDRESSES_TABLE = 'ecommerce_order_addresses';

    private const COLUMN = 'shipping_policy_applied_snapshot';

    /** @var int 청크 크기 */
    private const CHUNK = 200;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillShippingSnapshotAddress';
    }

    /**
     * 배송지 메타가 빈 스냅샷을 주문 배송지로 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::ORDERS_TABLE)
            || ! Schema::hasColumn(self::ORDERS_TABLE, self::COLUMN)
            || ! Schema::hasTable(self::ADDRESSES_TABLE)) {
            $context->logger->warning('[ecommerce:1.3.0] 배송지 스냅샷 백필 — 대상 스키마 부재로 스킵');

            return;
        }

        $filled = 0;
        $skipped = 0;
        $noAddressRow = 0;
        $noCountryCode = 0;
        $lastId = 0;

        // 갱신값이 필터(whereNotNull) 소속을 유지하므로 키셋 순회로 누락 없이 처리한다.
        // OFFSET 기반 chunk() 는 처리된 행이 결과에서 이탈하면 커서가 밀려 행을 건너뛴다.
        while (true) {
            $orders = DB::table(self::ORDERS_TABLE)
                ->where('id', '>', $lastId)
                ->whereNotNull(self::COLUMN)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', self::COLUMN]);

            if ($orders->isEmpty()) {
                break;
            }

            // 배송지 행은 청크 단위로 한 번에 읽는다 — 주문마다 조회하면 주문 수만큼 쿼리가 늘어
            // 대형 상점의 업그레이드가 그 수에 비례해 길어진다(N+1).
            $addressRows = $this->findShippingAddressRows($orders->pluck('id')->map(fn ($id) => (int) $id)->all());

            foreach ($orders as $order) {
                $lastId = (int) $order->id;

                $decoded = json_decode((string) $order->{self::COLUMN}, true);

                if (! is_array($decoded) || $decoded === []) {
                    $skipped++;

                    continue;
                }

                $address = $this->extractAddress($decoded);

                // 이미 배송지가 있으면 건드리지 않는다 (멱등).
                if (! empty($address['country_code'])) {
                    $skipped++;

                    continue;
                }

                $row = $addressRows[$lastId] ?? null;

                if ($row === null) {
                    $noAddressRow++;

                    continue;
                }

                $resolved = $this->resolveAddressFromRow($row);

                if ($resolved === null) {
                    $noCountryCode++;

                    continue;
                }

                DB::table(self::ORDERS_TABLE)
                    ->where('id', $lastId)
                    ->update([
                        self::COLUMN => json_encode(
                            $this->withAddress($decoded, $resolved),
                            JSON_UNESCAPED_UNICODE
                        ),
                    ]);

                $filled++;
            }
        }

        $context->logger->info(
            '[ecommerce:1.3.0] 배송지 스냅샷 백필 — 채움 '.$filled.'건, 유지 '.$skipped.'건, '
            .'주소 행 부재 '.$noAddressRow.'건(양 경로 모두 도서산간 0원 — 갈리지 않음), '
            .'주소 행에 국가 코드 없음 '.$noCountryCode.'건(클레임 산정만 도서산간을 붙일 수 있어 두 경로가 갈릴 수 있음)'
        );
    }

    /**
     * 스냅샷에서 배송지 메타를 꺼냅니다. (신형·구형 모두 허용)
     *
     * 신형은 `{items: [...], address: {...}}` 이고, 구형은 정수 키 항목에 `'address'`
     * 문자열 키가 섞여 있다. 두 경우 모두 `address` 키를 그대로 읽으면 된다.
     *
     * @param  array  $snapshot  스냅샷 배열
     * @return array 배송지 메타
     */
    private function extractAddress(array $snapshot): array
    {
        $address = $snapshot['address'] ?? [];

        return is_array($address) ? $address : [];
    }

    /**
     * 스냅샷에 배송지 메타를 얹은 배열을 반환합니다.
     *
     * 형태 변환은 하지 않는다 — 구형 주문이 남아 있어도 이 스텝의 책임은 배송지 백필뿐이며,
     * 형태 정규화는 1.0.5 스텝이 이미 담당했다. 여기서 모양까지 바꾸면 그 스텝을 통과한
     * 저장소와 통과하지 않은 저장소에서 결과가 달라진다.
     *
     * @param  array  $snapshot  원본 스냅샷
     * @param  array  $address  배송지 메타
     * @return array 배송지가 채워진 스냅샷
     */
    private function withAddress(array $snapshot, array $address): array
    {
        $snapshot['address'] = $address;

        return $snapshot;
    }

    /**
     * 주문들의 배송지 행을 한 번에 조회합니다.
     *
     * "행이 없다" 와 "행은 있는데 국가가 없다" 는 백필 후의 결과가 다르므로(클래스 docblock
     * 참조) 조회와 해석을 나눠 호출부가 두 경우를 각각 셀 수 있게 한다. 한 주문에 배송지 행이
     * 여럿이면 종전 단건 조회(`orderBy('id')->first()`)와 같게 가장 먼저 만든 행을 쓴다.
     *
     * @param  array<int, int>  $orderIds  주문 ID 목록
     * @return array<int, object> 주문 ID → 배송지 행
     */
    private function findShippingAddressRows(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = [];

        $found = DB::table(self::ADDRESSES_TABLE)
            ->whereIn('order_id', $orderIds)
            ->where('address_type', 'shipping')
            ->orderBy('id')
            ->get(['order_id', 'recipient_country_code', 'zipcode', 'intl_postal_code']);

        foreach ($found as $row) {
            $orderId = (int) $row->order_id;
            $rows[$orderId] ??= $row;
        }

        return $rows;
    }

    /**
     * 배송지 행에서 국가·우편번호를 해석합니다.
     *
     * 클레임 경로의 폴백(`ShippingPolicySnapshot::deliveryAddress` 호출부)과 같은 규칙이다 —
     * 국내 우편번호를 우선하고 없으면 해외 우편번호를 쓴다. 다른 규칙을 쓰면 백필 결과가
     * 지금 클레임 경로가 쓰는 값과 달라져 수렴이라는 목적 자체가 무너진다.
     *
     * @param  object  $row  배송지 행
     * @return array|null 배송지 메타 (국가 코드가 없으면 null)
     */
    private function resolveAddressFromRow(object $row): ?array
    {
        $countryCode = $row->recipient_country_code;

        if ($countryCode === null || $countryCode === '') {
            return null;
        }

        $zipcode = $row->zipcode;

        if ($zipcode === null || $zipcode === '') {
            $zipcode = $row->intl_postal_code;
        }

        return [
            'country_code' => strtoupper((string) $countryCode),
            'zipcode' => ($zipcode !== null && $zipcode !== '') ? (string) $zipcode : null,
        ];
    }
}
