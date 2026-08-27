<?php

namespace Tests\Unit\Resources;

use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\Resources\TemplateResource;
use App\Support\TemplateExternals;
use Tests\TestCase;

/**
 * TemplateResource externals 정규화 테스트
 *
 * `asset`(템플릿이 자체 제공하는 `dist/` 이하 경로) 항목은 URL 을 만들 때
 * 식별자와 캐시 버전을 요구한다. 리소스가 그 둘을 넘기지 않으면 항목이
 * "식별자 미상" 으로 **조용히 버려져** 응답에서 사라진다 — 예외도 오류도
 * 남지 않고 필드만 빈 배열이 된다.
 *
 * 뷰 컴포저(CollectsTemplateExternals)와 이 리소스는 같은 정규화기를 쓰는
 * 두 소비자다. 한쪽만 인자를 갖추면 화면은 정상인데 API 만 비는 비대칭이
 * 생기므로, 두 경로가 같은 결과를 내는지까지 잠근다.
 */
class TemplateResourceExternalsTest extends TestCase
{
    /**
     * `asset` externals 를 선언한 템플릿 manifest 조각을 만듭니다.
     *
     * @return array<string, mixed> 리소스 입력용 배열
     */
    private function templatePayload(): array
    {
        return [
            'id' => 1,
            'identifier' => 'test-assetexternals',
            'externals' => [
                [
                    'id' => 'fontawesome',
                    'type' => 'style',
                    'asset' => 'vendor/font-awesome/6.4.0/css/all.inlined.css',
                ],
                [
                    'id' => 'pretendard',
                    'type' => 'webfont',
                    'asset' => 'vendor/pretendard/1.3.9/pretendard-variable.css',
                ],
            ],
        ];
    }

    /**
     * `asset` 항목이 응답에서 사라지지 않는지 확인합니다.
     */
    public function test_asset_externals_survive_detail_serialization(): void
    {
        $externals = (new TemplateResource($this->templatePayload()))->toDetailArray()['externals'];

        $this->assertCount(2, $externals, '`asset` externals 가 응답에서 버려졌다 (식별자 미전달)');
        $this->assertSame(
            ['fontawesome', 'pretendard'],
            array_column($externals, 'id')
        );
    }

    /**
     * 해석된 URL 이 same-origin 인지 확인합니다.
     */
    public function test_asset_externals_resolve_to_same_origin_urls(): void
    {
        $externals = (new TemplateResource($this->templatePayload()))->toDetailArray()['externals'];

        foreach ($externals as $item) {
            $url = $item['url'] ?? $item['href'] ?? '';

            $this->assertStringStartsWith('/', $url, '자체 제공 자산이 same-origin 경로로 해석되지 않았다');
            $this->assertStringNotContainsString('//', ltrim($url, '/'), 'protocol-relative URL 로 해석되었다');
        }
    }

    /**
     * 리소스 경로와 뷰 컴포저 경로가 같은 결과를 내는지 확인합니다.
     *
     * 두 소비자가 어긋나면 화면은 정상인데 API 만 비는 비대칭이 생긴다.
     */
    public function test_resource_matches_view_composer_normalization(): void
    {
        $payload = $this->templatePayload();

        $fromResource = (new TemplateResource($payload))->toDetailArray()['externals'];
        $fromComposerPath = TemplateExternals::normalize(
            $payload['externals'],
            $payload['identifier'],
            ClearsTemplateCaches::getExtensionCacheVersion(),
        );

        $this->assertEquals($fromComposerPath, $fromResource);
    }
}
