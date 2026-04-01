<?php

namespace App\Seo;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Seo\Contracts\SeoRendererInterface;
use Illuminate\Support\ServiceProvider;

class SeoServiceProvider extends ServiceProvider
{
    /**
     * 서비스 컨테이너 바인딩을 등록합니다.
     */
    public function register(): void
    {
        // 인터페이스 → 구현체 바인딩
        $this->app->singleton(SeoCacheManagerInterface::class, SeoCacheManager::class);
        $this->app->singleton(SeoRendererInterface::class, SeoRenderer::class);

        // 싱글톤 등록
        $this->app->singleton(SeoCacheRegenerator::class);
        $this->app->singleton(SeoInvalidationRegistry::class);
        $this->app->singleton(BotDetector::class);
        $this->app->singleton(ExpressionEvaluator::class);
        $this->app->singleton(ComponentHtmlMapper::class);
        $this->app->singleton(DataSourceResolver::class);
        $this->app->singleton(TemplateRouteResolver::class);
        $this->app->singleton(SeoMetaResolver::class);
        $this->app->singleton(SitemapGenerator::class);
        $this->app->singleton(SeoConfigMerger::class);
    }

    /**
     * 서비스 부트스트랩을 수행합니다.
     */
    public function boot(): void
    {
        // SeoMiddleware는 bootstrap/app.php에서 별칭 등록
        // routes/web.php에서 라우트 그룹에 부착
    }
}
