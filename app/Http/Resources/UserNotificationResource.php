<?php

namespace App\Http\Resources;

use App\Contracts\Repositories\NotificationDefinitionRepositoryInterface;
use Illuminate\Http\Request;

class UserNotificationResource extends BaseApiResource
{
    /**
     * type 식별자 → 다국어 라벨 맵 (Collection에서 사전 주입).
     *
     * Collection에서 일괄 응답 시 N+1을 회피하기 위해 한 번만 로드한 맵을 주입받습니다.
     * 단일 Resource로 사용될 경우 null이며, toArray() 내부에서 즉시 조회합니다.
     *
     * @var array<string, string>|null
     */
    protected ?array $typeLabelMap = null;

    /**
     * 사전 주입된 type 라벨 맵을 설정합니다.
     *
     * @param array<string, string> $map
     * @return $this
     */
    public function withTypeLabelMap(array $map): static
    {
        $this->typeLabelMap = $map;

        return $this;
    }

    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        $data = $this->getValue('data') ?? [];
        $user = $request->user();
        $timezone = $user?->getTimezone() ?? config('app.default_user_timezone', 'Asia/Seoul');

        $type = $data['type'] ?? $this->getValue('type');
        $typeLabel = $this->resolveTypeLabel($type, $user?->locale);

        // 알림 클릭 시 이동 URL — click_url(템플릿 패턴 기반) 우선, 없으면 action_url(메일 CTA) fallback
        $innerData = $data['data'] ?? [];
        $url = $data['click_url'] ?? $innerData['action_url'] ?? null;

        return [
            'id' => $this->getValue('id'),
            'type' => $type,
            'type_label' => $typeLabel,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
            'url' => $url,
            'data' => $data,
            'read_at' => $this->resource->read_at?->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'created_at' => $this->resource->created_at?->setTimezone($timezone)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * type 식별자에 해당하는 다국어 라벨을 해석합니다.
     *
     * 우선순위:
     * 1. Collection에서 사전 주입한 typeLabelMap
     * 2. Repository에서 즉시 조회 (단일 Resource 사용 시)
     * 3. 정의 미존재 시 type 식별자 그대로 반환 (UI에 raw 키 노출 방지 fallback)
     *
     * @param string|null $type
     * @param string|null $locale
     * @return string
     */
    protected function resolveTypeLabel(?string $type, ?string $locale): string
    {
        if (! $type) {
            return '';
        }

        if ($this->typeLabelMap !== null) {
            return $this->typeLabelMap[$type] ?? '';
        }

        $repository = app(NotificationDefinitionRepositoryInterface::class);
        $definition = $repository->getActiveByType($type);

        return $definition?->getLocalizedName($locale) ?: '';
    }
}
