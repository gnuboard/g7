<?php

namespace Plugins\Sirsoft\Gdpr\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * GDPR 정책 버전 detail API 리소스 (admin snapshot 모달용)
 *
 * `gdpr_policy_versions` 단일 행을 admin 동의 이력 / 정책 버전 이력 화면의
 * snapshot 모달용으로 변환합니다. 목록용 `GdprPolicyVersionResource` 와 달리
 * `snapshot` 본문 (cookie_categories / privacy_policy_slug / blocked_domains)
 * 을 포함하여 DPO 가 그 시점 정책 본문을 즉시 확인 가능 (Art.7(1) 입증 책임).
 *
 * 발행 운영자 정보 (id/uuid/name/email) 는 createdBy 관계가 로드된 경우만 포함됩니다.
 */
class GdprPolicyVersionDetailResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'change_type' => $this->change_type->value,
            'memo' => $this->memo,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            // 발행 시점 settings snapshot 전체 (immutable). admin 권한 가드를 통과한 호출자만 접근.
            'snapshot' => $this->snapshot,
            // 차단 도메인 카탈로그를 프론트 iteration 친화 배열로 변환:
            //   { analytics: [...], marketing: [...] } → [{ category, domains }, ...]
            // G7 표현식 엔진이 Object.entries 미지원이라 객체 직접 iteration 불가 → 백엔드에서 변환.
            'blocked_domains_list' => $this->mapBlockedDomainsToArray(),
            // 발행 운영자 정보 (관계 로드 시에만). raw FK (created_by) 는 노출하지 않고
            // 관계 데이터 (uuid/name/email) 만 노출.
            'publisher' => $this->whenLoaded('createdBy', fn () => $this->createdBy !== null ? [
                'uuid' => $this->createdBy->uuid,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email,
            ] : null),
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * snapshot.blocked_domains 의 카테고리→도메인 객체를 프론트 iteration 친화 배열로 변환.
     *
     * 입력: ['analytics' => ['google-analytics.com', ...], 'marketing' => [...]]
     * 출력: [['category' => 'analytics', 'domains' => [...]], ['category' => 'marketing', 'domains' => [...]]]
     *
     * 카테고리 순서는 입력 키 순서를 보존 (PHP 연관 배열 특성).
     * snapshot.blocked_domains 가 없거나 객체가 아니면 빈 배열 반환.
     *
     * @return array<int, array{category: string, domains: array<int, string>}>
     */
    private function mapBlockedDomainsToArray(): array
    {
        $blockedDomains = $this->snapshot['blocked_domains'] ?? null;

        if (! is_array($blockedDomains)) {
            return [];
        }

        $result = [];
        foreach ($blockedDomains as $category => $domains) {
            if (! is_array($domains)) {
                continue;
            }
            $result[] = [
                'category' => (string) $category,
                'domains' => array_values(array_map('strval', $domains)),
            ];
        }

        return $result;
    }
}
