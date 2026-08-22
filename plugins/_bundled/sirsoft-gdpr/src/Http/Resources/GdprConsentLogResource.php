<?php

namespace Plugins\Sirsoft\Gdpr\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * GDPR 관리자 동의 로그 API 리소스
 *
 * `gdpr_user_consent_histories` 행을 관리자 화면용으로 변환합니다.
 * IP/User-Agent까지 노출하여 DPO 감사 조회를 지원하며,
 * 사용자 정보는 whenLoaded()로 관계 로딩 시에만 포함됩니다.
 */
class GdprConsentLogResource extends BaseApiResource
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
            'user_id' => $this->user_id,
            'session_id' => $this->session_id,
            'consent_key' => $this->consent_key,
            'action' => $this->action,
            'source' => $this->source,
            'policy_version' => $this->policy_version,
            'categories' => $this->categories,
            'categories_snapshot' => $this->buildCategoriesSnapshot(),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'user' => $this->whenLoaded('user', fn () => $this->user !== null ? [
                'id' => $this->user->id,
                'uuid' => $this->user->uuid,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
        ];
    }

    /**
     * 카테고리 스냅샷 표시 순서 — 필수 → 분석 → 마케팅 (위계: 거부 불가 → 선택 항목).
     *
     * MySQL JSON 컬럼이 키를 알파벳 순으로 정규화 저장하므로 (analytics → marketing → necessary)
     * Resource 단계에서 UX 위계 순서로 재정렬한다. 카탈로그 외 키는 본 배열의 마지막에 원래 순서로 노출.
     */
    private const SNAPSHOT_PRIORITY = [
        'cookie_necessary',
        'cookie_analytics',
        'cookie_marketing',
    ];

    /**
     * 카테고리 스냅샷을 iteration 친화 배열로 변환합니다.
     *
     * 저장 형식 {"cookie_necessary": true, "cookie_analytics": false} 는
     * G7 layout iteration 이 객체 순회를 지원하지 않으므로 admin 화면 표시용 배열로 변환합니다.
     * 라벨은 프론트가 `consent.category_{key}` 다국어 키로 해석합니다 (이미 정의된 키 재사용).
     *
     * @return array<int, array{key: string, label_key: string, granted: bool}>|null
     */
    protected function buildCategoriesSnapshot(): ?array
    {
        $categories = $this->categories;

        if (! is_array($categories) || $categories === []) {
            return null;
        }

        $ordered = [];
        foreach (self::SNAPSHOT_PRIORITY as $priorityKey) {
            if (array_key_exists($priorityKey, $categories)) {
                $ordered[$priorityKey] = $categories[$priorityKey];
            }
        }
        foreach ($categories as $key => $granted) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $granted;
            }
        }

        $snapshot = [];
        foreach ($ordered as $key => $granted) {
            $snapshot[] = [
                'key' => (string) $key,
                'label_key' => 'sirsoft-gdpr.consent.category_'.$key,
                'granted' => (bool) $granted,
            ];
        }

        return $snapshot;
    }
}
