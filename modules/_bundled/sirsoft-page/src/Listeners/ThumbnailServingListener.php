<?php

namespace Modules\Sirsoft\Page\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Page\Support\AttachmentThumbnailSourceProvider;

/**
 * 페이지 첨부 미리보기를 코어 이미지 썸네일에 등록하는 리스너
 *
 * - `core.thumbnail.filter_url_patterns`: 화면·SEO 가 `?size=` 를 붙여도 되는 서빙 경로에 미리보기 주소를 더한다.
 *   패턴 밖 주소는 화면이 원본 그대로 쓰므로, 빠지면 첨부 목록·업로더가 조용히 원본을 받는다.
 * - `core.thumbnail.filter_source_providers`: 썸네일 캐시 관리(분류별 집계·비우기)와 「기존 이미지 썸네일
 *   만들기」, 업로드 직후 미리 생성이 페이지 첨부를 권한이 걸린 원본으로 알아보게 한다.
 *
 * 모듈이 꺼지면 리스너가 등록되지 않아 두 목록에서 함께 빠진다.
 */
class ThumbnailServingListener implements HookListenerInterface
{
    /** 미리보기 서빙 경로 (`*` 는 해시 한 조각) */
    public const PREVIEW_PATTERN = '/api/modules/sirsoft-page/pages/attachment/*/preview';

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, mixed>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.thumbnail.filter_url_patterns' => [
                'method' => 'addUrlPatterns',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.thumbnail.filter_source_providers' => [
                'method' => 'addSourceProviders',
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
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 썸네일 서빙 경로에 페이지 첨부 미리보기를 더합니다.
     *
     * @param  mixed  $patterns  기존 패턴 목록
     * @return mixed 미리보기 경로가 더해진 목록 (배열이 아니면 그대로)
     */
    public function addUrlPatterns(mixed $patterns): mixed
    {
        if (! is_array($patterns)) {
            return $patterns;
        }

        $patterns[] = self::PREVIEW_PATTERN;

        return $patterns;
    }

    /**
     * 썸네일 원본 제공자에 페이지 첨부를 더합니다.
     *
     * @param  mixed  $providers  기존 제공자 목록
     * @return mixed 페이지 첨부 제공자가 더해진 목록 (배열이 아니면 그대로)
     */
    public function addSourceProviders(mixed $providers): mixed
    {
        if (! is_array($providers)) {
            return $providers;
        }

        $providers[] = self::provider();

        return $providers;
    }

    /**
     * 첨부 제공자 — 컨테이너 하나에서 한 번만 만든다.
     *
     * 리스너는 필터 호출마다 컨테이너가 새로 만들고, 제공자 목록 필터는 집계·분류·미리 생성마다 불린다.
     * 그때마다 제공자를 새로 만들면 리포지토리와 첨부 서비스까지 매번 다시 조립된다. 그래서 처음 부를 때 컨테이너에
     * 공유 바인딩으로 묶어 두고 다시 쓴다. 정적 속성에 두지 않는 까닭: 컨테이너가 바뀌면(테스트의 앱 재생성,
     * 상주 작업자 재시작) 옛 컨테이너의 바인딩을 붙든 제공자가 남는다 — 공유 바인딩은 컨테이너와 함께 사라진다.
     * 이미 묶여 있으면(테스트 대역 등) 그 바인딩을 그대로 쓴다.
     *
     * @return AttachmentThumbnailSourceProvider 제공자
     */
    private static function provider(): AttachmentThumbnailSourceProvider
    {
        $app = app();
        $app->singletonIf(AttachmentThumbnailSourceProvider::class);

        return $app->make(AttachmentThumbnailSourceProvider::class);
    }
}
