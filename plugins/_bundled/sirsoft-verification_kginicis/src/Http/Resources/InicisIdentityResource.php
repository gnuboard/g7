<?php

namespace Plugins\Sirsoft\VerificationKginicis\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Plugins\Sirsoft\VerificationKginicis\Models\InicisIdentityRecord;

/**
 * 마이페이지 본인인증 카드용 Resource.
 *
 * 평문 PII 는 서버에서 마스킹 후 노출 — 사용자가 자기 본인확인 정보를 확인할 수 있도록
 * PIPC 사용자 본인 PII 열람권 충족 목적. di/ci 등 식별값은 일체 노출하지 않음.
 *
 * @property-read InicisIdentityRecord $resource
 *
 * @since 1.0.0-beta.1
 */
class InicisIdentityResource extends BaseApiResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $verifiedAt = $this->resource->re_verified_at ?? $this->resource->verified_at;

        return [
            'method' => 'KG이니시스 본인확인',
            'verified_at' => $verifiedAt?->format('Y-m-d H:i:s'),
            'name_masked' => $this->maskName((string) $this->resource->name),
            'birthday_masked' => $this->maskBirthday((string) $this->resource->birthday),
            'phone_masked' => $this->maskPhone((string) $this->resource->phone),
            'is_adult' => (bool) $this->resource->is_adult,
            'is_foreigner' => (bool) $this->resource->is_foreigner,
        ];
    }

    /**
     * 실명 마스킹: 첫 글자 + 나머지 *.
     */
    protected function maskName(string $name): string
    {
        if ($name === '') {
            return '';
        }
        $first = mb_substr($name, 0, 1);
        $restLen = max(0, mb_strlen($name) - 1);

        return $first.str_repeat('*', $restLen);
    }

    /**
     * 생년월일 마스킹 (YYYYMMDD → YYYY-**-**).
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
