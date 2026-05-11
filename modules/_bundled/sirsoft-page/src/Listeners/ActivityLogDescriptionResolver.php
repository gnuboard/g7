<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;

/**
 * 페이지 활동 로그 description_params 해석 리스너
 *
 * 활동 로그에 저장된 엔티티 ID를 표시 시점에 사람이 읽을 수 있는 이름으로 변환합니다.
 * properties에 저장된 스냅샷 이름을 우선 사용하고, 없으면 DB를 조회합니다.
 *
 * 훅: core.activity_log.filter_description_params (Filter)
 */
class ActivityLogDescriptionResolver implements HookListenerInterface
{
    /**
     * @param  PageRepositoryInterface  $pageRepository  페이지 Repository (ID → 이름 변환)
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
    ) {}

    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.activity_log.filter_description_params' => [
                'method' => 'resolveDescriptionParams',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 훅 이벤트 처리 (기본 핸들러)
     *
     * @param mixed ...$args 훅에서 전달된 인수들
     * @return mixed
     */
    public function handle(...$args): void
    {
        // 기본 핸들러는 사용하지 않음
    }

    /**
     * description_params에 엔티티 이름을 해석하여 추가합니다.
     *
     * @param array $params 현재 description_params
     * @param string $descriptionKey 번역 키
     * @param array $properties 활동 로그 properties
     * @return array 해석된 description_params
     */
    public function resolveDescriptionParams(array $params, string $descriptionKey, array $properties): array
    {
        $prefix = 'sirsoft-page::activity_log.description.';
        if (! str_starts_with($descriptionKey, $prefix)) {
            return $params;
        }

        $keySuffix = str_replace($prefix, '', $descriptionKey);

        // 페이지 관련 키: title 해석
        if (str_starts_with($keySuffix, 'page_')) {
            return $this->resolvePageTitle($params, $properties);
        }

        return $params;
    }

    /**
     * 페이지 제목을 해석합니다.
     *
     * properties.title 스냅샷을 우선 사용하고, 없으면 loggable에서 조회합니다.
     *
     * @param array $params description_params
     * @param array $properties 활동 로그 properties
     * @return array 해석된 params
     */
    private function resolvePageTitle(array $params, array $properties): array
    {
        // 이미 해석된 이름이 있으면 스킵
        if (! empty($params['title'])) {
            return $params;
        }

        // 1순위: properties.title 스냅샷 (로그 기록 시점의 이름)
        if (! empty($properties['title'])) {
            $params['title'] = $properties['title'];

            return $params;
        }

        // 2순위: properties.page_id로 DB 조회
        $pageId = $properties['page_id'] ?? null;
        if ($pageId) {
            $page = $this->pageRepository->findById((int) $pageId);
            if ($page) {
                $params['title'] = $page->title ?? "ID: {$pageId}";
            } else {
                $params['title'] = "ID: {$pageId}";
            }
        }

        return $params;
    }
}
