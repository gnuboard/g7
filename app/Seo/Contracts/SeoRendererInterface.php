<?php

namespace App\Seo\Contracts;

use Illuminate\Http\Request;

interface SeoRendererInterface
{
    /**
     * SEO HTML을 렌더링합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 렌더링된 HTML 또는 null (렌더링 불가 시)
     */
    public function render(Request $request): ?string;
}
