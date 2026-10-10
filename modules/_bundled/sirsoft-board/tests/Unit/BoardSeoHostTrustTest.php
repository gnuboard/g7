<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Extension\ModuleManager;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ForgesRequestHost;

/**
 * 게시글 검색엔진·SNS 미리보기 이미지 주소가 요청 Host 를 따르지 않는지 검증합니다.
 *
 * 봇 화면은 캐시되어(키는 사이트 주소 기준) 이후 모든 크롤러·SNS 미리보기에 그대로 나간다 — 위조 Host
 * 로 그린 og:image·JSON-LD image 가 캐시에 남으면 그 Host 가 퍼진다.
 */
class BoardSeoHostTrustTest extends ModuleTestCase
{
    use ForgesRequestHost;

    private Module $module;

    /**
     * 사이트 주소를 고정하고 모듈 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pinForgedHostSite();
        $this->module = app(ModuleManager::class)->getModule('sirsoft-board')
            ?? new Module(base_path('modules/sirsoft-board'));
    }

    /**
     * 신뢰 프록시 상태를 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->resetForgedHostProxies();
        parent::tearDown();
    }

    /**
     * 상대 경로 썸네일의 og:image·JSON-LD image 는 사이트 주소로 절대화된다.
     *
     * @scenario host_header=forged, sink=seo_image
     *
     * @effects seo_image_uses_site_address
     *
     * @param  string  $root  요청 루트
     * @param  array<string, string>  $server  추가 서버 변수
     * @return void 반환값 없음
     */
    #[DataProvider('forgedHosts')]
    public function test_post_seo_image_uses_site_address(string $root, array $server): void
    {
        $this->bindForgedRequest($root, $server, '/board/free/1');
        $context = [
            'post' => ['data' => [
                'subject' => '위조 Host 회귀',
                'thumbnail' => '/api/modules/sirsoft-board/boards/free/attachment/abc123/preview',
                'created_at' => '2026-10-03T00:00:00+09:00',
            ]],
            'board' => ['data' => ['name' => '자유게시판']],
        ];
        $expected = self::forgedHostSite().'/api/modules/sirsoft-board/boards/free/attachment/abc123/preview';

        $this->assertSame($expected, $this->module->seoOgDefaults('post', $context)['image'] ?? null);
        $this->assertSame($expected, $this->module->seoStructuredData('post', $context)['image'] ?? null);
    }
}
