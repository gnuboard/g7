<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Thumbnails\CategoryImageSourceProvider;
use Modules\Sirsoft\Ecommerce\Thumbnails\ClaimImageSourceProvider;
use Modules\Sirsoft\Ecommerce\Thumbnails\ProductImageSourceProvider;
use Modules\Sirsoft\Ecommerce\Thumbnails\ReviewImageSourceProvider;

/**
 * 코어 이미지 썸네일에 이 모듈의 서빙 경로와 원본 제공자를 알립니다.
 *
 * - `core.thumbnail.filter_url_patterns`: 화면·SEO 가 `?size=` 를 붙여도 되는 주소. 화면이 받는 이미지 주소
 *   (`ProductImage::apiDownloadUrl()` 등 — 하위 폴더 설치 접두는 코어가 벗긴다)와 같은 모양이어야 한다.
 *   공개 자산 디스크(CDN) 행의 직접 URL 은 이 경로가 아니라서 원본 그대로 나간다.
 * - `core.thumbnail.filter_source_providers`: 등록 시 미리 생성·캐시 관리(분류별 집계·비우기)·기존 이미지 썸네일
 *   만들기가 쓰는 원본 제공자. 상품·카테고리는 공개, 리뷰·클레임은 게이트다.
 *
 * 모듈이 비활성이면 리스너가 등록되지 않아 패턴·제공자도 빠진다 — 그때 이 경로는 404 이므로 맞는 동작이다.
 */
class ThumbnailSourcesListener implements HookListenerInterface
{
    /**
     * 이 모듈의 이미지 서빙 경로 (`*` 는 해시 한 조각)
     *
     * @var list<string>
     */
    public const URL_PATTERNS = [
        '/api/modules/sirsoft-ecommerce/product-image/*',
        '/api/modules/sirsoft-ecommerce/category-image/*',
        '/api/modules/sirsoft-ecommerce/review-image/*',
        '/api/modules/sirsoft-ecommerce/claim-image/*',
    ];

    /**
     * 원본 제공자 클래스
     *
     * @var list<class-string>
     */
    private const PROVIDERS = [
        ProductImageSourceProvider::class,
        CategoryImageSourceProvider::class,
        ReviewImageSourceProvider::class,
        ClaimImageSourceProvider::class,
    ];

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, priority: int, type: string}> 훅 이름 => 구독 설정
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.thumbnail.filter_url_patterns' => [
                'method' => 'addUrlPatterns',
                'priority' => 20,
                'type' => 'filter',
            ],
            'core.thumbnail.filter_source_providers' => [
                'method' => 'addSourceProviders',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 핸들러 (미사용 — 필터 메서드가 처리)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void
    {
        // Filter Hook 은 getSubscribedHooks 에서 지정한 메서드를 직접 호출한다.
    }

    /**
     * 서빙 경로 패턴을 덧붙입니다.
     *
     * @param  mixed  $patterns  지금까지 모인 패턴
     * @return mixed 이 모듈 패턴을 더한 목록 (배열이 아니면 그대로)
     */
    public function addUrlPatterns(mixed $patterns): mixed
    {
        if (! is_array($patterns)) {
            return $patterns;
        }

        return [...$patterns, ...self::URL_PATTERNS];
    }

    /**
     * 원본 제공자를 덧붙입니다.
     *
     * 이 필터는 저장소 쓰기마다(경로 분류) 불린다. 제공자는 의존 없이 만들고, 리포지토리·서비스는 제공자가 행을
     * 세거나 읽을 때(count·chunk) 처음 해석한다 — 컨테이너로 만들면 분류 한 번에 서비스 네 개와 그 의존이 매번
     * 만들어진다.
     *
     * @param  mixed  $providers  지금까지 모인 제공자
     * @return mixed 이 모듈 제공자를 더한 목록 (배열이 아니면 그대로)
     */
    public function addSourceProviders(mixed $providers): mixed
    {
        if (! is_array($providers)) {
            return $providers;
        }

        foreach (self::PROVIDERS as $class) {
            $providers[] = new $class;
        }

        return $providers;
    }
}
