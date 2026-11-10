<?php

namespace Plugins\Sirsoft\Ckeditor5\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Ckeditor5\Services\ImageThumbnailSourceProvider;

/**
 * 본문 이미지를 코어 이미지 썸네일에 등록하는 리스너
 *
 *  - `core.thumbnail.filter_url_patterns`: 화면·SEO 가 `?size=` 를 붙여도 되는 서빙 경로에 본문 이미지
 *    주소를 더한다. 등록하지 않으면 본문 이미지 규격(기본 lg)·srcset 이 이 주소를 건너뛰어 원본이 나간다.
 *    공개 자산 디스크(CDN) 직접 주소로 박힌 본문 이미지는 이 경로가 아니므로 그대로 원본이다.
 *  - `core.thumbnail.filter_source_providers`: 캐시 관리(공개 위치 집계·비우기)와 「기존 이미지 썸네일
 *    만들기」가 본문 이미지 원본을 알게 한다.
 *
 * 플러그인이 꺼지면 리스너가 등록되지 않으므로 두 목록에서 함께 빠진다.
 */
class ThumbnailRegistrationListener implements HookListenerInterface
{
    /** 본문 이미지 서빙 경로 (`images/{hash}` 라우트, `*` 는 해시 한 조각) */
    public const URL_PATTERN = '/api/plugins/sirsoft-ckeditor5/images/*';

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.thumbnail.filter_url_patterns' => [
                'method' => 'addUrlPattern',
                'priority' => 10,
                'type' => 'filter',
            ],
            'core.thumbnail.filter_source_providers' => [
                'method' => 'addSourceProvider',
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
     * 썸네일 서빙 경로 목록에 본문 이미지 주소를 더합니다.
     *
     * @param  mixed  $patterns  기존 경로 패턴 목록
     * @return mixed 본문 이미지 경로가 더해진 목록 (배열이 아니면 받은 값 그대로)
     */
    public function addUrlPattern(mixed $patterns): mixed
    {
        if (! is_array($patterns)) {
            return $patterns;
        }

        $patterns[] = self::URL_PATTERN;

        return $patterns;
    }

    /**
     * 썸네일 원본 제공자 목록에 본문 이미지 제공자를 더합니다.
     *
     * @param  mixed  $providers  기존 제공자 목록
     * @return mixed 본문 이미지 제공자가 더해진 목록 (배열이 아니면 받은 값 그대로)
     */
    public function addSourceProvider(mixed $providers): mixed
    {
        if (! is_array($providers)) {
            return $providers;
        }

        $providers[] = self::provider();

        return $providers;
    }

    /**
     * 본문 이미지 제공자 — 컨테이너 하나에서 한 번만 만든다.
     *
     * 리스너는 필터 호출마다 컨테이너가 새로 만들고, 제공자 목록 필터는 집계·분류·미리 생성마다 불린다.
     * 그때마다 제공자를 새로 만들면 리포지토리와 서빙 서비스까지 매번 다시 조립된다. 그래서 처음 부를 때 컨테이너에
     * 공유 바인딩으로 묶어 두고 다시 쓴다. 정적 속성에 두지 않는 까닭: 컨테이너가 바뀌면(테스트의 앱 재생성,
     * 상주 작업자 재시작) 옛 컨테이너의 바인딩을 붙든 제공자가 남는다 — 공유 바인딩은 컨테이너와 함께 사라진다.
     * 이미 묶여 있으면(테스트 대역 등) 그 바인딩을 그대로 쓴다.
     *
     * @return ImageThumbnailSourceProvider 제공자
     */
    private static function provider(): ImageThumbnailSourceProvider
    {
        $app = app();
        $app->singletonIf(ImageThumbnailSourceProvider::class);

        return $app->make(ImageThumbnailSourceProvider::class);
    }
}
