<?php

namespace Tests\Feature\Views;

use App\Support\AssetUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 오류 페이지의 「홈으로」가 마운트 접두사를 갖는지 고정한다.
 *
 * 이 링크는 블레이드가 직접 그리는 날 `<a href>` 라 라우터를 거치지 않는다 — 접두사를
 * 붙일 주체가 이 조립 지점밖에 없다. 하위 폴더 사이트(`https://host/g7/`)에서 접두사가
 * 없으면 404·403·500 화면의 유일한 탈출 버튼이 **문서 루트(=다른 사이트)** 로 나간다.
 *
 * 판정식(`str_starts_with(request()->path(), 'admin')`)은 대상이 아니다 — Laravel 의
 * `path()` 는 마운트 접두사를 이미 벗긴 값이라 그 판정 자체는 옳다. 어긋나는 것은
 * **출력하는 값**뿐이다.
 *
 * @scenario base_path=subpath, page=error_view
 */
class ErrorPageHomeLinkTest extends TestCase
{
    /**
     * 테스트가 강제한 마운트 접두사를 해제해 다음 테스트로 새지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        AssetUrl::forceBasePrefix(null);

        parent::tearDown();
    }

    /**
     * 「홈으로」 링크를 그리는 오류 뷰 다섯 종(401, 403, 404, 500, 503)을 공급한다.
     *
     * @return array<string, array{0: string}> 케이스 이름별 뷰 이름 목록
     */
    public static function errorViews(): array
    {
        return [
            '401' => ['errors.401'],
            '403' => ['errors.403'],
            '404' => ['errors.404'],
            '500' => ['errors.500'],
            '503' => ['errors.503'],
        ];
    }

    /**
     * 마운트 접두사 `/g7` 를 강제했을 때 각 오류 뷰의 「홈으로」가 `href="/g7/"` 로 그려지는지 확인한다.
     *
     * @scenario base_path=subpath, page=error_view
     *
     * @effects error_page_home_link_carries_the_mount_prefix
     *
     * @param  string  $view  렌더링할 오류 뷰 이름
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('errorViews')]
    public function the_home_link_carries_the_mount_prefix(string $view): void
    {
        AssetUrl::forceBasePrefix('/g7');

        $html = view($view)->render();

        $this->assertStringContainsString(
            'href="/g7/"',
            $html,
            $view.' 의 「홈으로」가 마운트 접두사를 잃었다 — 하위 폴더 사이트에서 그 버튼이 사이트 밖으로 나간다'
        );
    }

    /**
     * 접두사가 없는 배치에서는 종전 그대로다 (회귀 0).
     *
     * @scenario base_path=root, page=error_view
     *
     * @effects error_page_home_link_carries_the_mount_prefix
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_home_link_is_unchanged_without_a_mount_prefix(): void
    {
        AssetUrl::forceBasePrefix('');

        $this->assertStringContainsString('href="/"', view('errors.404')->render());
    }
}
