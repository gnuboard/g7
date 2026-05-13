<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Seo;

use App\Extension\TemplateManager;
use App\Seo\SeoCacheManager;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 게시물 SEO 통합 회귀 테스트.
 *
 * 회귀: 첨부 이미지가 있는 게시물의 SEO 응답에 og:image 가 포함되어야 함.
 * (페이스북·슬랙·쓰레드 미리보기 카드 노출 조건)
 *
 * 셋업: ModuleTestCase (DatabaseTransactions — 빠름) + 의존성 모듈/플러그인/템플릿 활성화 +
 *        SeoRenderer 의 DataSourceResolver 가 사용하는 Http facade 만 같은 process 의
 *        internal kernel 로 redirect (외부 PHP-FPM 의 별도 connection 으로 인한 트랜잭션 미가시 회피).
 *        SeoRenderer / BoardModule.seoOgDefaults / DataSourceResolver 의 비즈니스 로직 자체는 실제 코드.
 */
class PostSeoOgImageRenderingTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 의존성 모듈 (sirsoft-ecommerce / sirsoft-page) 을 active 상태로 등록 — 템플릿 의존 만족용
        // (전체 install 은 비용 크므로 Module DB 행만 직접 셋업)
        foreach ([
            ['sirsoft-ecommerce', 'sirsoft', '이커머스', 'Ecommerce'],
            ['sirsoft-page', 'sirsoft', '페이지', 'Page'],
        ] as [$id, $vendor, $ko, $en]) {
            \App\Models\Module::firstOrCreate(
                ['identifier' => $id],
                [
                    'vendor' => $vendor,
                    'name' => ['ko' => $ko, 'en' => $en],
                    'status' => \App\Enums\ExtensionStatus::Active->value,
                    'version' => '1.0.0',
                    'config' => [],
                ]
            );
        }
        \App\Models\Plugin::firstOrCreate(
            ['identifier' => 'sirsoft-daum_postcode'],
            [
                'vendor' => 'sirsoft',
                'name' => ['ko' => '다음 우편번호', 'en' => 'Daum Postcode'],
                'status' => \App\Enums\ExtensionStatus::Active->value,
                'version' => '1.0.0',
                'config' => [],
            ]
        );

        // 템플릿 활성화
        $tm = app(TemplateManager::class);
        try { $tm->installTemplate('sirsoft-basic'); } catch (\Throwable) {}
        try { $tm->activateTemplate('sirsoft-basic'); } catch (\Throwable) {}

        // SEO 캐시 격리
        try { app(SeoCacheManager::class)->clearAll(); } catch (\Throwable) {}

        // Http facade redirect — DataSourceResolver 가 호출하는 외부 HTTP 를 같은 process 의 internal kernel 로
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $internal = $this->call($request->method(), $path.($query ? '?'.$query : ''));

            return Http::response($internal->getContent(), $internal->getStatusCode(), $internal->headers->all());
        });
    }

    /**
     * 회귀: 첨부 이미지가 있는 게시물 SEO 응답에 og:image 절대 URL 포함.
     */
    public function test_post_with_image_attachment_emits_og_image(): void
    {
        config([
            'g7_settings.core.seo.bot_detection_enabled' => true,
            'g7_settings.core.seo.bot_detection_library_enabled' => true,
            'g7_settings.modules.sirsoft-board.seo.seo_post_detail' => true,
        ]);

        $board = Board::factory()->create([
            'slug' => 'gallery-'.uniqid(),
            'is_active' => true,
        ]);

        // guest role 에 board posts read 권한 부여 — 봇/비로그인이 게시글 SEO 받을 수 있도록
        $readPerm = \App\Models\Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$board->slug}.posts.read"],
            ['name' => ['ko' => '게시글 조회', 'en' => 'Read Posts'], 'type' => 'user']
        );
        \App\Models\Role::where('identifier', 'guest')->first()->permissions()->syncWithoutDetaching([$readPerm->id]);

        $post = Post::create([
            'board_id' => $board->id,
            'title' => '첨부 이미지 게시글',
            'content' => '본문 내용',
            'ip_address' => '127.0.0.1',
            'is_secret' => false,
            'user_id' => null,
        ]);

        Attachment::create([
            'board_id' => $board->id,
            'post_id' => $post->id,
            'hash' => substr(md5(uniqid('a', true)), 0, 12),
            'original_filename' => 'sample.jpg',
            'stored_filename' => 'sample.jpg',
            'disk' => 'local',
            'path' => 'attachments/sample.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ]);

        $response = $this->withHeaders(['User-Agent' => 'facebookexternalhit/1.1'])
            ->get("/board/{$board->slug}/{$post->id}");

        $body = (string) $response->getContent();

        $this->assertStringContainsString('og:image', $body, 'og:image 메타태그 출력 필수');
        $this->assertMatchesRegularExpression(
            '#<meta property="og:image" content="https?://[^"]+/api/modules/sirsoft-board/[^"]*/preview"#',
            $body,
            'og:image 는 절대 URL + attachment preview 경로'
        );
    }
}
