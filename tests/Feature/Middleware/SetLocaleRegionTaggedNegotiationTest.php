<?php

namespace Tests\Feature\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 요청 언어 협상 — 지역·문자 표기 언어팩 (#148)
 *
 * 종전에는 Accept-Language 각 항목을 주 언어(`fil`)로 잘라 비교해, `fil-PH` 만 켠 사이트에 `fil-PH` 로
 * 접속해도 기본 언어(ko)로 응답했다. 정확일치 → 주 언어 → 같은 주 언어의 다른 지역 순으로 고른다.
 */
class SetLocaleRegionTaggedNegotiationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 현재 로케일을 돌려주는 인증 불필요 테스트 라우트를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api'])->get('/api/test-locale-public', function () {
            return response()->json([
                'locale' => App::getLocale(),
            ]);
        });
    }

    /**
     * 지역·문자 표기 언어팩만 켠 사이트에서도 Accept-Language 를 그 언어로 협상한다.
     *
     * @scenario shape=region_tagged
     *
     * @effects request_locale_negotiates_region_tagged_pack
     *
     * @return void 반환값 없음
     */
    public function test_accept_language_negotiates_region_tagged_pack_locale(): void
    {
        $cases = [
            ['fil-PH,fil;q=0.9', ['ko', 'en', 'fil-PH'], 'fil-PH'],
            ['fil', ['ko', 'en', 'fil-PH'], 'fil-PH'],
            ['zh-hant-tw', ['ko', 'zh-Hant-TW'], 'zh-Hant-TW'],
            ['en-GB,en;q=0.9', ['ko', 'en'], 'en'],
            ['fr-FR,fr;q=0.9', ['ko', 'en'], 'ko'],
        ];

        foreach ($cases as [$header, $supported, $expected]) {
            // App::setLocale() 이 app.locale 을 바꾸므로 반복마다 기본 언어를 다시 둔다
            config(['app.locale' => 'ko', 'app.supported_locales' => $supported]);

            $this->withHeaders(['Accept-Language' => $header])
                ->getJson('/api/test-locale-public')
                ->assertStatus(200)
                ->assertJson(['locale' => $expected]);
        }
    }
}
