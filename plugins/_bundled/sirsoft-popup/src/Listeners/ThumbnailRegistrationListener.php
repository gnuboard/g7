<?php

namespace Plugins\Sirsoft\Popup\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\Popup\Services\PopupImageThumbnailSourceProvider;

/**
 * 팝업 이미지를 코어 이미지 썸네일에 등록하는 리스너
 *
 *  - `core.thumbnail.filter_url_patterns`: 화면이 `?size=` 를 붙여도 되는 서빙 경로에 팝업 이미지 주소를 더한다. 등록하지 않으면
 *    사이트 팝업 레이어의 srcset·관리 목록 규격이 이 주소를 건너뛰어 원본이 나간다(코어는 이 플러그인을 모른다 — 경로는 여기서만 안다).
 *  - `core.thumbnail.filter_source_providers`: 캐시 관리(공개 위치 집계·비우기)와 「기존 이미지 썸네일 만들기」가 팝업 이미지
 *    원본을 알게 한다.
 *
 * 플러그인이 꺼지면 리스너가 등록되지 않으므로 두 목록에서 함께 빠진다.
 */
class ThumbnailRegistrationListener implements HookListenerInterface
{
    /** 팝업 이미지 서빙 경로 (`images/{hash}` 라우트, `*` 는 해시 한 조각) */
    public const URL_PATTERN = '/api/plugins/sirsoft-popup/images/*';

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
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
     * 인터페이스 기본 핸들러 (필터는 지정한 메서드를 직접 부른다)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 썸네일 서빙 경로 목록에 팝업 이미지 주소를 더합니다.
     *
     * @param  mixed  $patterns  기존 경로 패턴 목록
     * @return mixed 팝업 이미지 경로가 더해진 목록 (배열이 아니면 받은 값 그대로)
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
     * 썸네일 원본 제공자 목록에 팝업 이미지 제공자를 더합니다.
     *
     * @param  mixed  $providers  기존 제공자 목록
     * @return mixed 팝업 이미지 제공자가 더해진 목록 (배열이 아니면 받은 값 그대로)
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
     * 팝업 이미지 제공자 — 컨테이너 하나에서 한 번만 만든다.
     *
     * 제공자 목록 필터는 집계·분류·미리 생성마다 불린다. 그때마다 새로 만들면 Repository 와 서빙 서비스까지 매번 다시 조립되므로
     * 처음 부를 때 컨테이너에 공유 바인딩으로 묶어 두고 다시 쓴다. 정적 속성에 두지 않는 까닭은 컨테이너가 바뀌면(테스트의 앱 재생성,
     * 상주 작업자 재시작) 옛 컨테이너의 바인딩을 붙든 제공자가 남기 때문이다.
     *
     * @return PopupImageThumbnailSourceProvider 제공자
     */
    private static function provider(): PopupImageThumbnailSourceProvider
    {
        $app = app();
        $app->singletonIf(PopupImageThumbnailSourceProvider::class);

        return $app->make(PopupImageThumbnailSourceProvider::class);
    }
}
