<?php

namespace Tests\Feature\Seo;

use App\Enums\ExtensionStatus;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Seo\SeoRenderer;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Public\LayoutServingTest;
use Tests\TestCase;

/**
 * 봇 서버사이드 렌더(SEO) 경로에서도 «주입은 화면에 닿지 않는다» 가 성립하는지 잠근다.
 *
 * ## 왜 이 경로를 따로 보는가
 *
 * S21 주입 단락이 지금까지 밟은 서빙 경로는 셋이다 — 공개 JSON 서빙 · 확장 주입 조각 ·
 * 미리보기 서빙({@see LayoutServingTest} 과
 * `LayoutPreviewControllerTest`). 셋 다 **브라우저에 JSON 을 내보내는** 소비자다.
 *
 * S21.6 이 처음 공개 사용자 화면(`sirsoft-basic` 의 `shop/`)에 주석을 넣는데, 그 화면에는
 * 관리자 단락이 한 번도 타지 않은 소비자가 하나 더 있다 — **검색봇에게 서버에서 HTML 을
 * 만들어 주는 경로**({@see SeoRenderer}). 범위 안에서 `<seo>` 를 선언한 파일은
 * `shop/{index,category,show}` 셋이다. 계획서 66차 보강이 *"새 파일 종류에 처음 주석을
 * 넣을 때는 그 종류의 서빙 경로가 이 명제를 지키는지 표본으로 확인한다"* 를 부과하므로,
 * 「관리자에서 초록이니 여기도 초록」으로 넘기지 않고 실측으로 세운다.
 *
 * ## 두 축을 따로 세운다
 *
 * - **축 ⓐ 제거가 이 경로에도 걸린다** — SEO 렌더는 {@see LayoutService::getLayout()} 로
 *   병합본을 받고, 그 호출이 `withSourceMeta=false` 라 재귀 제거를 함께 맞는다.
 * - **축 ⓑ 제거가 없어도 화면이 같다** — 주석이 렌더 입력으로 **소비되지 않음**을 직접
 *   잰다. 축 ⓐ 만 두면 이 경로의 안전은 «제거가 먼저 도는가» 라는 순서에 매달리는데,
 *   66차가 데인 것이 정확히 그 순서였다(확장 조각은 제거 **뒤에** 얹혔다). 축 ⓑ 는 순서와
 *   무관하게 성립하므로 순서가 바뀌어도 화면이 지켜진다.
 *
 * 부재 단언 앞에 **존재를 확정**한다 — 표본 레이아웃이 실제로 주석을 들고 있고 렌더가 실제로
 * 본문을 만들었음을 먼저 단언하지 않으면, 아무것도 렌더되지 않은 상태가 「불일치 0」으로
 * 조용히 초록이 된다.
 */
class LayoutServingSeoInvarianceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 주석이 채워질 수 있는 자리를 모두 담은 표본 레이아웃을 만듭니다.
     *
     * `$commented=false` 로 부르면 **주석만 뺀** 같은 레이아웃이 나온다 — 두 산출을 대조하는
     * 것이 축 ⓑ 의 판정식이므로, 두 표본이 주석 말고 다른 데서 갈리면 안 된다.
     *
     * @param  bool  $commented  주입 단락이 채우는 자리에 주석을 넣을지 여부
     * @return array 레이아웃 content 배열
     */
    private function sampleLayout(bool $commented): array
    {
        $c = fn (string $text): array => $commented ? ['comment' => $text] : [];

        return $c('상품 목록 화면 — 카테고리별 상품을 노출한다') + [
            'meta' => [
                'title' => '상품 목록',
                'seo' => [
                    'enabled' => true,
                    'title' => '상품 목록',
                    'description' => '전체 상품을 둘러봅니다',
                    'og' => ['type' => 'website'],
                    'data_sources' => [],
                ],
            ],
            'data_sources' => [
                $c('상품 목록을 페이지 단위로 받아온다') + [
                    'id' => 'products',
                    'type' => 'api',
                    'endpoint' => '/api/modules/sirsoft-ecommerce/products',
                ],
            ],
            'named_actions' => [
                'searchProducts' => $c('검색어를 걸어 목록을 다시 받는다') + [
                    'handler' => 'refetchDataSource',
                    'params' => ['dataSourceId' => 'products'],
                ],
            ],
            'modals' => [
                $c('장바구니 담기 완료를 알리는 창') + [
                    'id' => 'cart_added_modal',
                    'type' => 'composite',
                    'name' => 'Modal',
                    'props' => ['title' => '장바구니에 담았습니다'],
                ],
            ],
            'components' => [
                $c('본문 감싸개') + [
                    'type' => 'div',
                    'props' => ['className' => 'container'],
                    'children' => [
                        [
                            'type' => 'basic',
                            'name' => 'H1',
                            // 템플릿 `seo-config.json` 의 `text_props` 가 뽑아 가는 키다 —
                            // 다른 키에 두면 봇 HTML 이 빈 태그가 되어 존재 확정이 죽는다.
                            'props' => ['text' => '전체 상품'],
                        ],
                        $c('장바구니로 보내는 단추') + [
                            'type' => 'basic',
                            'name' => 'Button',
                            'props' => ['text' => '장바구니'],
                            'actions' => [
                                $c('장바구니 화면으로 이동한다') + [
                                    'type' => 'click',
                                    'handler' => 'navigate',
                                    'params' => ['path' => '/shop/cart'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 표본 템플릿과 두 레이아웃(주석 있음/없음)을 심습니다.
     *
     * @return Template 심어진 사용자 템플릿
     */
    private function seedTemplate(): Template
    {
        $template = Template::create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본 사용자 템플릿', 'en' => 'Basic User Template'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본 사용자 템플릿', 'en' => 'Basic User Template'],
        ]);

        foreach ([['shop_commented', true], ['shop_plain', false]] as [$name, $commented]) {
            TemplateLayout::create([
                'template_id' => $template->id,
                'name' => $name,
                'content' => $this->sampleLayout($commented),
            ]);
        }

        return $template;
    }

    /**
     * 축 ⓐ — SEO 렌더가 받는 병합본에 주석이 남아 있지 않습니다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects injected_comments_never_reach_the_seo_bot_render_input
     */
    public function test_seo_render_input_has_no_developer_comment(): void
    {
        $template = $this->seedTemplate();

        // 존재 확정 — 표본이 실제로 주석을 들고 있어야 아래 부재 단언이 무언가를 검사한다.
        $stored = TemplateLayout::where('template_id', $template->id)
            ->where('name', 'shop_commented')->firstOrFail()->content;
        $this->assertStringContainsString('"comment"', json_encode($stored, JSON_UNESCAPED_UNICODE),
            '표본 레이아웃에 주석이 없다 — 아래 부재 단언이 공허하게 참이 된다');

        $merged = app(LayoutService::class)->getLayout($template->identifier, 'shop_commented');

        $this->assertNotEmpty($merged, 'SEO 렌더가 받을 병합본이 비었다 — 표본이 서빙 경로에 닿지 않았다');
        $this->assertStringNotContainsString('"comment"', json_encode($merged, JSON_UNESCAPED_UNICODE),
            'SEO 렌더 입력에 개발자 주석이 남아 있다');
        $this->assertStringNotContainsString('"_comment"', json_encode($merged, JSON_UNESCAPED_UNICODE),
            'SEO 렌더 입력에 개발자 주석이 남아 있다');
    }

    /**
     * 축 ⓑ — 제거를 거치지 않은 원본을 그대로 태워도 봇이 받는 HTML 이 같습니다.
     *
     * 주석이 렌더 입력으로 소비되지 않음을 직접 재는 자리다. 축 ⓐ 는 «제거가 먼저 돈다» 는
     * 순서에 기대는데, 그 순서가 뒤집혀 데인 것이 66차다. 여기서는 순서를 아예 빼고 잰다.
     *
     * @scenario compile_unit=layout,source_health=valid
     *
     * @effects seo_bot_html_is_identical_with_and_without_injected_comments
     */
    public function test_seo_bot_html_is_unchanged_by_comment_injection(): void
    {
        $this->seedTemplate();

        $renderer = app(SeoRenderer::class);

        $render = fn (bool $commented): ?string => $renderer->renderFromResolved(
            $this->sampleLayout($commented),
            [],
            '/shop',
            'ko',
            'sirsoft-basic',
            $commented ? 'shop_commented' : 'shop_plain',
            null,
            null,
            ['route' => ['path' => '/shop'], 'query' => [], '_global' => [], '_local' => []],
            'ko',
        );

        $plain = $render(false);
        $withComments = $render(true);

        // 존재 확정 — 렌더가 실제로 본문을 만들었어야 «같다» 가 의미를 갖는다.
        $this->assertNotNull($plain, 'SEO 렌더가 null 을 냈다 — 두 산출이 «같다» 는 것이 공허해진다');
        $this->assertStringContainsString('전체 상품', $plain,
            'SEO HTML 에 본문이 없다 — 컴포넌트가 렌더되지 않았다면 주석 불변은 아무것도 증명하지 않는다');

        $this->assertSame($plain, $withComments,
            '주석을 넣기 전후로 봇이 받는 HTML 이 달라진다 — 주입이 화면에 닿았다');
        $this->assertStringNotContainsString('상품 목록 화면 —', (string) $withComments,
            '주석 본문이 봇 HTML 에 실려 나간다');
    }
}
