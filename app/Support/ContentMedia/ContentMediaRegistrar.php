<?php

namespace App\Support\ContentMedia;

use App\Contracts\ContentMedia\ContentMediaStore;
use App\Support\Content\MediaSourceRoutes;

/**
 * 본문 미디어 서빙 라우트 등록 도우미 (#887)
 *
 * 본문의 `<video>`·`<audio>`·`<source>` 가 가리켜도 되는 주소(미디어 출처 허용 패턴)는 확장이 실제로 등록한 서빙 라우트에서
 * 만든다(`App\Support\Content\MediaSourceRoutes`) — 경로 모양만 흉내 낸 가짜 주소를 막기 위해서다. 본문 미디어를 서빙하는
 * 확장(모듈·플러그인)은 서비스 프로바이더 `boot()` 에서 한 번 부른다.
 *
 * ```php
 * public function boot(): void
 * {
 *     ContentMediaRegistrar::register($this->app->make(NoteContentMediaStore::class));
 *     // 또는 라우트 이름만: ContentMediaRegistrar::register('api.plugins.vendor-notes.content-media.show');
 * }
 * ```
 *
 * 확장이 꺼져 라우트가 없으면 패턴 생성 쪽이 그 이름을 건너뛴다(등록만으로 허용되지 않는다).
 *
 * @since 7.1.5
 */
final class ContentMediaRegistrar
{
    /**
     * 어댑터(또는 라우트 이름)의 서빙 라우트를 미디어 출처 허용 패턴 원천으로 등록합니다.
     *
     * @param  ContentMediaStore|string  $storeOrRouteName  어댑터 또는 서빙 라우트 이름
     * @return void 반환값 없음
     */
    public static function register(ContentMediaStore|string $storeOrRouteName): void
    {
        $routeName = $storeOrRouteName instanceof ContentMediaStore ? $storeOrRouteName->serveRouteName() : $storeOrRouteName;
        if ($routeName === '') {
            return;
        }

        app(MediaSourceRoutes::class)->register($routeName);
    }
}
