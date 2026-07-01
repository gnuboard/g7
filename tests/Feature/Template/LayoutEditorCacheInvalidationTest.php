<?php

namespace Tests\Feature\Template;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Cache\CoreCacheDriver;
use App\Extension\Traits\InvalidatesLayoutCache;
use App\Models\Template;
use App\Models\TemplateLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 레이아웃 편집기(`with_source_meta=1`) 캐시 무효화 회귀
 *
 * 배경: `PublicLayoutController::serve()` 는 일반 응답과 레이아웃 편집기 응답
 * (`with_source_meta=1`)을 **별도 캐시 키**(`.meta` 접미사)로 저장한다. 템플릿/
 * 레이아웃 상태 변화(refresh-layout / activate / deactivate / uninstall 등) 시
 * 공통으로 거치는 `InvalidatesLayoutCache::forgetLayoutCacheKeys()` 가 일반 키만
 * 삭제하고 `.meta` 키를 누락하면, 사용자 페이지는 갱신되지만 **편집기 캔버스만
 * stale 캐시**(옛 레이아웃)를 받는다( 재현 — 사용자는 복원, 편집기는
 * 미복원). 레이아웃 저장 경로(`LayoutService::clearPublicServingCache`)는 이미 두
 * 키를 지우므로, 본 테스트는 상태 변화 공통 지점이 두 키를 모두 무효화함을 잠근다.
 */
class LayoutEditorCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    /** trait 를 노출하는 익명 테스트 더블 */
    private function makeInvalidator(): object
    {
        return new class
        {
            use InvalidatesLayoutCache;

            public LayoutRepositoryInterface $layoutRepository;

            public function __construct()
            {
                $this->layoutRepository = app(LayoutRepositoryInterface::class);
            }

            /** protected 메서드 노출 */
            public function invalidate(int $templateId, string $identifier): void
            {
                $this->invalidateTemplateLayoutCache($templateId, $identifier);
            }
        };
    }

    private function coreCache(): CoreCacheDriver
    {
        return new CoreCacheDriver(config('cache.default', 'array'));
    }

    #[Test]
    public function 레이아웃_캐시_무효화는_일반키와_편집기meta키를_모두_삭제한다(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'sirsoft-basic',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'home',
            'content' => ['meta' => ['title' => 'Home'], 'components' => []],
        ]);

        $cache = $this->coreCache();
        $version = (int) $cache->get('ext.cache_version', 0);

        // PublicLayoutController::serve() 가 쓰는 두 캐시 키 — 일반 + 편집기(.meta)
        $generalKey = "layout.sirsoft-basic.home.v{$version}";
        $metaKey = "layout.sirsoft-basic.home.v{$version}.meta";
        $cache->put($generalKey, ['cached' => 'general'], 3600);
        $cache->put($metaKey, ['cached' => 'meta'], 3600);

        // 사전 조건: 두 키 모두 캐시에 존재
        $this->assertNotNull($cache->get($generalKey), '사전: 일반 키 캐시 존재');
        $this->assertNotNull($cache->get($metaKey), '사전: 편집기 .meta 키 캐시 존재');

        // 상태 변화 공통 지점 — 레이아웃 캐시 무효화
        $this->makeInvalidator()->invalidate($template->id, 'sirsoft-basic');

        // 두 키 모두 무효화되어야 함 (.meta 누락이면 편집기 stale 회귀)
        $this->assertNull($cache->get($generalKey), '일반 키가 무효화됨');
        $this->assertNull($cache->get($metaKey), '편집기.meta 키도 무효화됨');
    }

    #[Test]
    public function 템플릿_레이아웃_refresh_후_편집기meta_캐시가_stale로_남지_않는다(): void
    {
        $template = Template::factory()->create([
            'identifier' => 'sirsoft-basic',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
        TemplateLayout::create([
            'template_id' => $template->id,
            'name' => 'home',
            'content' => ['meta' => ['title' => 'Home'], 'components' => []],
        ]);

        $cache = $this->coreCache();
        $version = (int) $cache->get('ext.cache_version', 0);
        $metaKey = "layout.sirsoft-basic.home.v{$version}.meta";
        $cache->put($metaKey, ['stale' => 'editor-old-content'], 3600);

        // 상태 변화 공통 지점 호출 → 편집기 .meta 키가 stale 로 남으면 실패
        $this->makeInvalidator()->invalidate($template->id, 'sirsoft-basic');

        $this->assertNull(
            $cache->get($metaKey),
            'refresh/상태변화 후 편집기 .meta 캐시가 stale 로 잔존하면 편집기 캔버스가 옛 레이아웃을 표시한다',
        );
    }
}
