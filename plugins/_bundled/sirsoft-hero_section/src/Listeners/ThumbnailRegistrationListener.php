<?php

namespace Plugins\Sirsoft\HeroSection\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\HeroSection\Services\BannerImageThumbnailSourceProvider;

/**
 * 배너 이미지를 코어 이미지 썸네일에 등록하는 리스너
 *
 *  - `core.thumbnail.filter_url_patterns`: 화면이 `?size=` 를 붙여도 되는 서빙 경로에 배너 이미지 주소를 더한다. 등록하지 않으면
 *    사이트 슬라이더의 srcset·관리 목록 규격이 이 주소를 건너뛰어 원본이 나간다(코어는 이 플러그인을 모른다 — 경로는 여기서만 안다).
 *  - `core.thumbnail.filter_source_providers`: 캐시 관리(공개 위치 집계·비우기)와 「기존 이미지 썸네일 만들기」가 배너 이미지
 *    원본을 알게 한다.
 *  - `core.thumbnail.filter_presets`: 관리 목록 규격 `sirsoft-hero_section.list`(가로 240, 가로만 맞춤)를 확장 규격으로 등록한다.
 *    배너는 가로로 긴 이미지라 정사각 자르기(sm)는 가운데만 남기고, md(640)는 96px 칸에 과하다. 환경설정 업로드 탭 「썸네일 규격」 표에
 *    이름이 잠긴 행으로 나타나 운영자는 값만 고치고 「기본값으로」로 이 값에 돌아온다. 표는 식별자 대신 `label` 다국어 키의 이름
 *    (「배너 목록」)을 보이고 식별자는 그 아래 작게 둔다.
 *
 *  플러그인이 꺼지면 리스너가 등록되지 않으므로 세 목록에서 함께 빠진다(운영자가 고친 규격 값은 설정에 남는다).
 */
class ThumbnailRegistrationListener implements HookListenerInterface
{
    /** 배너 이미지·영상 서빙 경로 (`images/{hash}` 라우트, `*` 는 해시 한 조각) — 영상은 서버가 원본으로 낸다 */
    public const URL_PATTERN = '/api/plugins/sirsoft-hero_section/images/*';

    /** 관리 목록 규격 이름 (`{확장 식별자}.{키}` — 코어가 이 형식만 확장 규격으로 받는다) */
    public const LIST_PRESET = 'sirsoft-hero_section.list';

    /** 관리 목록 규격의 표시 이름 다국어 키 (ko·en 은 이 플러그인 lang, ja 는 언어팩) */
    public const LIST_PRESET_LABEL = 'sirsoft-hero_section::messages.thumbnail.list_preset_label';

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
            'core.thumbnail.filter_presets' => [
                'method' => 'addPreset',
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
     * 썸네일 서빙 경로 목록에 배너 이미지 주소를 더합니다.
     *
     * @param  mixed  $patterns  기존 경로 패턴 목록
     * @return mixed 배너 이미지 경로가 더해진 목록 (배열이 아니면 받은 값 그대로)
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
     * 썸네일 원본 제공자 목록에 배너 이미지 제공자를 더합니다.
     *
     * @param  mixed  $providers  기존 제공자 목록
     * @return mixed 배너 이미지 제공자가 더해진 목록 (배열이 아니면 받은 값 그대로)
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
     * 썸네일 규격 목록에 관리 목록 규격을 더합니다 (가로 240, 가로만 맞춤, 숫자 요청·본문 srcset 대상 아님).
     *
     * 목록 칸(96×32)·순서 변경 창(64×24)은 2배 화면에서도 192px 이하라 240 이면 넉넉하다. 생략한 키(기준점·형식·확대·등록 시 생성·사용)는
     * 코어 기본값을 쓴다. `label` 은 화면 표시 이름이라 결과 그림·지문과 무관하다.
     *
     * @param  mixed  $presets  기존 규격 행 목록
     * @return mixed 관리 목록 규격이 더해진 목록 (배열이 아니면 받은 값 그대로)
     */
    public function addPreset(mixed $presets): mixed
    {
        if (! is_array($presets)) {
            return $presets;
        }

        $presets[] = [
            'name' => self::LIST_PRESET,
            'label' => self::LIST_PRESET_LABEL,
            'width' => 240,
            'height' => null,
            'fit' => 'width',
            'numeric' => false,
        ];

        return $presets;
    }

    /**
     * 배너 이미지 제공자 — 컨테이너 하나에서 한 번만 만든다.
     *
     * 제공자 목록 필터는 집계·분류·미리 생성마다 불린다. 그때마다 새로 만들면 Repository 와 서빙 서비스까지 매번 다시 조립되므로
     * 처음 부를 때 컨테이너에 공유 바인딩으로 묶어 두고 다시 쓴다. 정적 속성에 두지 않는 까닭은 컨테이너가 바뀌면(테스트의 앱 재생성,
     * 상주 작업자 재시작) 옛 컨테이너의 바인딩을 붙든 제공자가 남기 때문이다.
     *
     * @return BannerImageThumbnailSourceProvider 제공자
     */
    private static function provider(): BannerImageThumbnailSourceProvider
    {
        $app = app();
        $app->singletonIf(BannerImageThumbnailSourceProvider::class);

        return $app->make(BannerImageThumbnailSourceProvider::class);
    }
}
