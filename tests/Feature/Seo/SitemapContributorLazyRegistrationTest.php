<?php

namespace Tests\Feature\Seo;

use App\Seo\SitemapGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 번들 모듈 사이트맵 기여자의 지연 등록 (#860 W11)
 *
 * 게시판·이커머스·페이지 모듈은 부팅이 끝나면 곧바로 사이트맵 생성기와 기여자(리포지토리 그래프 포함)를
 * 만들어 붙였다. 사이트맵을 만들지 않는 요청에도 매번 생기던 비용이라, 생성기를 처음 해석할 때 붙도록 바꾼다.
 */
class SitemapContributorLazyRegistrationTest extends TestCase
{
    /** 이 시험이 보는 번들 모듈 provider → 기여자 식별자 */
    private const MODULES = [
        'Modules\\Sirsoft\\Board\\Providers\\BoardServiceProvider' => 'sirsoft-board',
        'Modules\\Sirsoft\\Ecommerce\\Providers\\EcommerceServiceProvider' => 'sirsoft-ecommerce',
        'Modules\\Sirsoft\\Page\\Providers\\PageServiceProvider' => 'sirsoft-page',
    ];

    /**
     * 세 모듈 provider 를 운영과 같이 등록·부팅합니다 (시험 환경은 모듈 provider 를 자동 등록하지 않는다).
     *
     * 이미 부팅이 끝난 앱에 등록하므로 provider 의 boot() 와 `booted()` 콜백이 즉시 실행된다 — 운영 요청의
     * 부팅 직후 상태와 같다.
     *
     * @return void 반환값 없음
     */
    private function assertModuleProvidersRegistered(): void
    {
        $this->assertFalse($this->app->resolved(SitemapGenerator::class), '모듈 등록 전부터 해석돼 있으면 시험이 무의미');

        foreach (array_keys(self::MODULES) as $provider) {
            if ($this->app->getProvider($provider) === null) {
                $this->app->register($provider);
            }
            $this->assertNotNull($this->app->getProvider($provider), "provider 미등록: {$provider}");
        }
    }

    /**
     * 부팅만으로는 사이트맵 생성기가 만들어지지 않습니다.
     *
     * @effects sitemap_generator_not_built_during_boot
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 부팅만으로는_생성기가_만들어지지_않는다(): void
    {
        $this->assertModuleProvidersRegistered();

        $this->assertFalse($this->app->resolved(SitemapGenerator::class));
    }

    /**
     * 생성기를 처음 해석하면 세 모듈 기여자가 한 번씩 붙고, 다시 해석해도 늘지 않습니다.
     *
     * @effects sitemap_contributors_registered_once_when_generator_resolved
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 생성기를_해석하면_기여자가_한_번씩_붙는다(): void
    {
        $this->assertModuleProvidersRegistered();

        $generator = $this->app->make(SitemapGenerator::class);
        $this->app->make(SitemapGenerator::class);

        $contributors = (new \ReflectionProperty($generator, 'contributors'))->getValue($generator);
        foreach (self::MODULES as $identifier) {
            $this->assertArrayHasKey($identifier, $contributors);
        }
        $this->assertSame(array_values(self::MODULES), array_values(array_intersect(array_keys($contributors), self::MODULES)));
        $this->assertSame($generator, $this->app->make(SitemapGenerator::class), '싱글톤 유지');
    }
}
