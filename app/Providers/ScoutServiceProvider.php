<?php

namespace App\Providers;

use App\Extension\HookManager;
use App\Search\Engines\DatabaseFulltextEngine;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

/**
 * Scout 검색 엔진 서비스 프로바이더
 *
 * MySQL FULLTEXT + ngram 엔진을 기본 등록하고,
 * core.search.engine_drivers 필터 훅을 통해 플러그인이 추가 엔진을 등록할 수 있도록 합니다.
 */
class ScoutServiceProvider extends ServiceProvider
{
    /**
     * 서비스를 등록합니다.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * 서비스를 부트스트랩합니다.
     *
     * @return void
     */
    public function boot(): void
    {
        // 기본 드라이버 맵
        $drivers = [
            'mysql-fulltext' => DatabaseFulltextEngine::class,
        ];

        // 필터 훅을 통해 플러그인이 추가 검색엔진 드라이버를 등록할 수 있음
        // 사용 예: HookManager::addFilter('core.search.engine_drivers', function ($drivers) {
        //     $drivers['meilisearch'] = MeilisearchEngine::class;
        //     return $drivers;
        // });
        $drivers = HookManager::applyFilters('core.search.engine_drivers', $drivers);

        $this->app->resolving(EngineManager::class, function (EngineManager $manager) use ($drivers) {
            foreach ($drivers as $name => $engineClass) {
                $manager->extend($name, fn () => $this->app->make($engineClass));
            }
        });
    }
}
