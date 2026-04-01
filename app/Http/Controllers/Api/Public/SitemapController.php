<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Seo\SitemapGenerator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Sitemap XML 컨트롤러
 *
 * sitemap.xml 요청을 처리하여 캐시된 또는 실시간 생성된
 * Sitemap XML을 반환합니다.
 */
class SitemapController extends Controller
{
    /**
     * sitemap.xml을 반환합니다.
     *
     * 캐시가 있으면 캐시된 XML, 없으면 실시간 생성 후 캐시 저장.
     *
     * @param  SitemapGenerator  $generator  Sitemap 생성기
     */
    public function index(SitemapGenerator $generator): Response
    {
        $enabled = (bool) g7_core_settings('seo.sitemap_enabled', true);
        if (! $enabled) {
            abort(404);
        }

        $xml = Cache::get('seo:sitemap');

        if (! $xml) {
            $xml = $generator->generate();
            $ttl = (int) g7_core_settings('seo.sitemap_cache_ttl', 86400);
            Cache::put('seo:sitemap', $xml, $ttl);
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
