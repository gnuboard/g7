<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 페이지 본문을 CKEditor5 이미지 참조 스캔 대상으로 등록하는 리스너
 *
 * `sirsoft-ckeditor5.image.filter_reference_sources` 필터 훅에 페이지 본문과 버전
 * 스냅샷 컬럼을 덧붙입니다. 버전 스냅샷을 포함하는 이유는 과거 버전으로 되돌릴 때
 * 그 시점 이미지가 살아 있어야 하기 때문입니다.
 *
 * 테이블명 문자열만 덧붙이며 DB 에 접근하지 않습니다.
 */
class Ckeditor5ReferenceSourcesListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, mixed>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ckeditor5.image.filter_reference_sources' => [
                'method' => 'addPageSources',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 훅 이벤트를 처리합니다.
     *
     * Filter 훅은 getSubscribedHooks 에서 지정한 메서드를 직접 호출하므로
     * 이 메서드는 인터페이스 요구사항 충족을 위해서만 존재합니다.
     *
     * @param  mixed  ...$args  훅에서 전달된 인수들
     */
    public function handle(...$args): void {}

    /**
     * 페이지 참조 소스를 추가합니다.
     *
     * @param  array  $sources  기존 참조 소스 목록
     * @return array 페이지 소스가 추가된 목록
     */
    public function addPageSources(array $sources): array
    {
        $sources[] = ['table' => 'pages', 'columns' => ['content']];
        $sources[] = ['table' => 'page_versions', 'columns' => ['content']];

        return $sources;
    }
}
