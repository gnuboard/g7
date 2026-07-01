<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Concerns;

/**
 * 주소록 폼 필드 ↔ UserAddress 컬럼 매핑 트레이트 (MP08 후속 — D8/D10)
 *
 * 체크아웃/주소록 폼은 해외 주소를 intl_city/intl_state/intl_postal_code 키로 제출하지만,
 * UserAddress 모델 컬럼은 city/state/postal_code 다(주문 OrderAddress 의 intl_* 와 의도적 분리).
 * 키를 그대로 mass-assign 하면 비-fillable 키가 조용히 누락돼 해외 주소가 유실된다.
 *
 * 본 트레이트는 validated() 결과를 국가별로 정규화한다:
 *   - KR(국내): zipcode/address/address_detail 만 유지, 해외 필드 제거
 *   - 그 외(해외): intl_* → city/state/postal_code 변환, 국내 필드 제거
 */
trait MapsAddressBookFields
{
    /**
     * 검증된 데이터를 UserAddress 컬럼 구조로 국가별 정규화하여 반환합니다.
     *
     * @param  string|null  $key  특정 키만 추출(기본 null=전체)
     * @param  mixed  $default  기본값
     * @return mixed 정규화된 검증 데이터
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated();

        // country_code 미제출(부분 패치)이면 매핑/스트립을 적용하지 않는다(의도치 않은 필드 제거 방지).
        if (! array_key_exists('country_code', $data)) {
            if ($key !== null) {
                return $data[$key] ?? $default;
            }

            return $data;
        }

        $country = strtoupper((string) ($data['country_code'] ?? 'KR'));
        if ($country === '') {
            $country = 'KR';
        }

        if ($country === 'KR') {
            // 국내: 해외 전용 필드 제거
            unset(
                $data['address_line_1'],
                $data['address_line_2'],
                $data['intl_city'],
                $data['intl_state'],
                $data['intl_postal_code'],
            );
        } else {
            // 해외: intl_* → city/state/postal_code 변환, 국내 전용 필드 제거
            $data['city'] = $data['intl_city'] ?? null;
            $data['state'] = $data['intl_state'] ?? null;
            $data['postal_code'] = $data['intl_postal_code'] ?? null;

            unset(
                $data['intl_city'],
                $data['intl_state'],
                $data['intl_postal_code'],
                $data['zipcode'],
                $data['address'],
                $data['address_detail'],
                $data['address_type_code'],
            );
        }

        if ($key !== null) {
            return $data[$key] ?? $default;
        }

        return $data;
    }
}
