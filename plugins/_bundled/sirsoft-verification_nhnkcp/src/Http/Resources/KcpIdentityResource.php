<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Plugins\Sirsoft\VerificationNhnkcp\Models\KcpIdentityRecord;

/**
 * 마이페이지 본인확인 카드용 Resource.
 *
 * 평문 PII 는 서버에서 마스킹 후 노출한다 — 사용자가 자기 본인확인 정보를 확인할 수 있도록
 * PIPC 본인 열람권을 충족하는 목적이며, CI/DI 등 식별값은 일체 노출하지 않는다.
 *
 * @property-read KcpIdentityRecord $resource
 *
 * @since 1.0.0
 */
class KcpIdentityResource extends BaseApiResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $verifiedAt = $this->resource->re_verified_at ?? $this->resource->verified_at;
        $birthday = (string) $this->resource->birthday;

        return [
            'method' => __('sirsoft-verification_nhnkcp::messages.card.method_value'),
            'verified_at' => $verifiedAt?->format('Y-m-d H:i:s'),
            'name_masked' => $this->maskName((string) $this->resource->name),
            'birthday_masked' => $this->maskBirthday($birthday),
            'phone_masked' => $this->maskPhone((string) $this->resource->phone),
            'is_adult' => (bool) $this->resource->is_adult,
            // 생년월일이 없으면 성인 여부는 "아님"이 아니라 "확인 불가"다. 두 상태를 화면에서
            // 구분하지 못하면 미성년으로 잘못 안내된다 (테스트 응답에 생년월일이 빠지는 경우).
            'is_adult_known' => $birthday !== '',
            'is_foreigner' => (bool) $this->resource->is_foreigner,
        ];
    }

    /**
     * 실명 마스킹: 성과 끝 글자만 남기고 가운데를 가린다 (홍길동 → 홍*동).
     *
     * 본인이 자기 정보를 확인하는 화면이므로 "내 이름이 맞다" 를 알아볼 수 있어야 하고,
     * 어깨너머로 전체 이름이 드러나서도 안 된다. 두 글자 이름은 가릴 가운데가 없으므로
     * 뒷 글자만 가린다 (김철 → 김*).
     *
     * @param  string  $name  실명 평문
     * @return string 마스킹된 실명
     */
    protected function maskName(string $name): string
    {
        $length = mb_strlen($name);

        if ($length === 0) {
            return '';
        }

        if ($length === 1) {
            return $name;
        }

        if ($length === 2) {
            return mb_substr($name, 0, 1).'*';
        }

        return mb_substr($name, 0, 1).str_repeat('*', $length - 2).mb_substr($name, -1);
    }

    /**
     * 생년월일 마스킹 (YYYYMMDD → YYYY-**-**).
     *
     * @param  string  $birthday  생년월일 평문
     * @return string 마스킹된 생년월일
     */
    protected function maskBirthday(string $birthday): string
    {
        if (strlen($birthday) < 4) {
            return '';
        }

        return substr($birthday, 0, 4).'-**-**';
    }

    /**
     * 휴대폰 마스킹 (01012345678 → 010-****-5678).
     *
     * @param  string  $phone  휴대폰 평문
     * @return string 마스킹된 휴대폰
     */
    protected function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 7) {
            return '';
        }

        $prefix = substr($digits, 0, 3);
        $suffix = substr($digits, -4);

        return "{$prefix}-****-{$suffix}";
    }
}
