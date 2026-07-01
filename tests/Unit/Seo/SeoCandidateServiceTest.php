<?php

namespace Tests\Unit\Seo;

use App\Seo\Editor\SeoCandidateService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SeoCandidateService — 확장 후보 라벨 해석 단위.
 *
 * extensions 후보의 친화 라벨(localizeName)은 확장 getName()(string|array 다국어)을 로케일로
 * 해석한다. Endpoint 테스트는 RefreshDatabase 환경에서 활성 확장이 0건이라 폴백 경로를 직접
 * 보증하기 어려우므로(빈 배열 허용), 라벨 해석 규칙은 본 단위 테스트가 SSoT.
 */
class SeoCandidateServiceTest extends TestCase
{
    private SeoCandidateService $service;

    private ReflectionMethod $localize;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SeoCandidateService::class);
        $this->localize = new ReflectionMethod(SeoCandidateService::class, 'localizeName');
        $this->localize->setAccessible(true);
    }

    private function localize(mixed $name, string $locale, string $fallbackId): string
    {
        return $this->localize->invoke($this->service, $name, $locale, $fallbackId);
    }

    public function test_string_name_returned_as_is(): void
    {
        $this->assertSame('이커머스', $this->localize('이커머스', 'ko', 'sirsoft-ecommerce'));
    }

    public function test_array_name_resolves_requested_locale(): void
    {
        $name = ['ko' => '이커머스', 'en' => 'Ecommerce'];
        $this->assertSame('이커머스', $this->localize($name, 'ko', 'sirsoft-ecommerce'));
        $this->assertSame('Ecommerce', $this->localize($name, 'en', 'sirsoft-ecommerce'));
    }

    public function test_array_name_falls_back_to_app_locale_then_first_value(): void
    {
        config(['app.locale' => 'en']);
        // 요청 로케일(ja) 부재 → app.locale(en) 폴백.
        $name = ['ko' => '이커머스', 'en' => 'Ecommerce'];
        $this->assertSame('Ecommerce', $this->localize($name, 'ja', 'sirsoft-ecommerce'));

        // 요청·app.locale 둘 다 부재 → 첫 값 폴백.
        config(['app.locale' => 'fr']);
        $this->assertSame('이커머스', $this->localize($name, 'ja', 'sirsoft-ecommerce'));
    }

    public function test_empty_or_invalid_name_falls_back_to_identifier(): void
    {
        $this->assertSame('sirsoft-ecommerce', $this->localize('', 'ko', 'sirsoft-ecommerce'));
        $this->assertSame('sirsoft-ecommerce', $this->localize([], 'ko', 'sirsoft-ecommerce'));
        $this->assertSame('sirsoft-ecommerce', $this->localize(null, 'ko', 'sirsoft-ecommerce'));
        // 비문자열 값만 든 배열 → 폴백.
        $this->assertSame('sirsoft-ecommerce', $this->localize(['ko' => 123], 'ko', 'sirsoft-ecommerce'));
    }

    /**
     * collectVars 를 stub 확장으로 직접 호출(reflection). 게이팅 SSoT 검증 —
     * 선언 확장(declaredExtensions)에 있는 확장의 seoVariables()[_common]+[pageType] 만 수집.
     *
     * @param  array  $activeExtensions  [{type,id,instance}]
     * @param  array  $declaredExtensions  [{type,id}]
     */
    private function collectVars(array $activeExtensions, array $declaredExtensions, ?string $pageType): array
    {
        $m = new ReflectionMethod(SeoCandidateService::class, 'collectVars');
        $m->setAccessible(true);

        return $m->invoke($this->service, $activeExtensions, $declaredExtensions, $pageType);
    }

    private function stubExtensionWithVars(): object
    {
        // seoVariables() 가 _common + product 그룹을 반환하는 stub 확장.
        return new class
        {
            public function seoVariables(): array
            {
                return [
                    '_common' => ['commerce_name' => ['source' => 'setting', 'description' => '상점명']],
                    'product' => [
                        'product_name' => ['source' => 'data', 'required' => true, 'description' => '상품명'],
                        'product_description' => ['source' => 'data', 'description' => '상품 설명'],
                    ],
                ];
            }
        };
    }

    public function test_collect_vars_gated_by_declared_extensions(): void
    {
        $active = [['type' => 'module', 'id' => 'sirsoft-ecommerce', 'instance' => $this->stubExtensionWithVars()]];

        // 선언 확장에 ecommerce 가 있으면 _common + product 그룹 vars 수집.
        $declared = [['type' => 'module', 'id' => 'sirsoft-ecommerce']];
        $vars = $this->collectVars($active, $declared, 'product');
        $names = array_column($vars, 'name');
        $this->assertContains('commerce_name', $names, '_common vars 수집');
        $this->assertContains('product_name', $names, 'pageType(product) vars 수집');
        $this->assertContains('product_description', $names);

        // source/required 메타 보존.
        $byName = [];
        foreach ($vars as $v) {
            $byName[$v['name']] = $v;
        }
        $this->assertSame('setting', $byName['commerce_name']['source']);
        $this->assertSame('data', $byName['product_name']['source']);
        $this->assertTrue($byName['product_name']['required'] ?? false);
    }

    public function test_collect_vars_empty_when_extension_not_declared(): void
    {
        // 같은 active 확장이라도 declaredExtensions 에 없으면 게이팅으로 0건(프론트가 extensions 미전송 시 재현).
        $active = [['type' => 'module', 'id' => 'sirsoft-ecommerce', 'instance' => $this->stubExtensionWithVars()]];
        $vars = $this->collectVars($active, [], 'product');
        $this->assertSame([], $vars, '선언 확장 0 → vars 게이팅 전량 제외');
    }

    public function test_collect_vars_common_only_when_page_type_null(): void
    {
        $active = [['type' => 'module', 'id' => 'sirsoft-ecommerce', 'instance' => $this->stubExtensionWithVars()]];
        $declared = [['type' => 'module', 'id' => 'sirsoft-ecommerce']];
        $vars = $this->collectVars($active, $declared, null);
        $names = array_column($vars, 'name');
        $this->assertContains('commerce_name', $names, 'page_type 미선택 → _common 만');
        $this->assertNotContains('product_name', $names);
    }
}
