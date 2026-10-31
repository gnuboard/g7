<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 계산 API 배송정책에 남은 후보 밖 요청 필드를 지웁니다.
 *
 * `api_request_fields` 는 외부 요금 API 로 보낼 참고 필드 목록이고, 실제로 전송되는 값은
 * `OrderCalculationService::calculateApiShippingFee()` 가 아는 다섯 가지뿐이다. 그 목록에
 * 없는 값(`order_amount` / `weight` / `zipcode` 등 구버전 샘플 데이터가 남긴 값)은 전송되지
 * 않아 기능상으로는 죽은 값이지만, 저장 검증이 그 값을 거절하기 때문에 **조회한 그대로
 * 되돌려 저장해도 422** 가 된다. 그 결과 기존 설치본의 계산 API 정책은 관리자 화면에서
 * 아무것도 고칠 수 없는 상태로 남는다(S17 B4).
 *
 * 후보 목록을 이 파일에 적어 둔 것은 V-1 안전 규약 때문이다 — 업그레이드 스텝은 구버전
 * 코드베이스에서도 실행될 수 있어 모듈 Enum(`ShippingApiRequestField`)을 참조할 수 없다.
 * 값이 늘어나면 그 Enum 과 이 상수를 함께 갱신한다.
 *
 * 값을 새로 채우지 않는다 — 후보 밖 값을 덜어낼 뿐이라 전송되던 필드는 그대로 유지된다.
 *
 * idempotent: 정리된 행은 다시 태워도 같은 값이라 두 번째 실행은 갱신 0건이다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Enum/Model 을 참조하지 않는다.
 */
class NormalizeShippingApiRequestFields implements DataMigration
{
    /**
     * 대상 테이블 (모듈 접두사 제외).
     */
    private const TABLE = 'ecommerce_shipping_policy_country_settings';

    /**
     * 전송 가능한 참고 필드 후보 — `ShippingApiRequestField` 와 같은 목록이어야 한다.
     */
    private const ALLOWED_FIELDS = [
        'policy_id',
        'country_code',
        'items',
        'group_total',
        'total_quantity',
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 식별자
     */
    public function name(): string
    {
        return 'NormalizeShippingApiRequestFields';
    }

    /**
     * 후보 밖 요청 필드를 제거합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'api_request_fields')) {
            $context->logger->warning('[ecommerce:1.3.0] '.self::TABLE.' 테이블/컬럼 미존재 — 스킵');

            return;
        }

        $updated = 0;

        DB::table(self::TABLE)
            ->whereNotNull('api_request_fields')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$updated) {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->api_request_fields, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    $kept = array_values(array_filter(
                        $decoded,
                        fn ($field) => is_string($field) && in_array($field, self::ALLOWED_FIELDS, true),
                    ));

                    if ($kept === $decoded) {
                        continue;
                    }

                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update(['api_request_fields' => json_encode($kept)]);

                    $updated++;
                }
            });

        $context->logger->info('[ecommerce:1.3.0] 계산 API 요청 필드 정리 완료 — '.$updated.'건');
    }
}
