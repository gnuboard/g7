<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\TemplateManager;
use App\Extension\Traits\CollectsFragmentSources;
use App\Services\LayoutExtensionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\TestCase;

/**
 * 템플릿 확장 오버라이드(`templates/{t}/extensions/{ext}/`)의 설치 소스 선택 테스트.
 *
 * 모듈·플러그인 fragment 는 `RefreshesLayoutExtensions` 가 `.g7.html` 을 우선 컴파일하는데,
 * 템플릿 오버라이드만 `glob('*.json')` 로 남아 있어 **소스 SSoT 를 무시**하고 있었다.
 * 세 등록 경로가 같은 규약(`.g7.html` 우선 + 짝 없는 `.json`)을 쓰는지 여기서 고정한다.
 *
 * @see CollectsFragmentSources
 * @see MarkupFragmentInstallTest 모듈·플러그인 fragment 대칭 케이스
 */
class TemplateOverrideMarkupInstallTest extends TestCase
{
    private TemplateManager $manager;

    private string $tmp;

    /**
     * 생성자 의존성 없는 템플릿 관리자와 임시 디렉토리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 소스 수집·컴파일은 인스턴스 상태를 쓰지 않으므로 생성자 의존성 없이 만든다.
        $this->manager = (new ReflectionClass(TemplateManager::class))->newInstanceWithoutConstructor();

        $this->tmp = base_path('storage/framework/testing/tpl_override_markup_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
    }

    /**
     * 임시 디렉토리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 보호된 메서드를 호출합니다.
     *
     * @param  string  $method  메서드명
     * @param  array<int, mixed>  $args  인자
     * @return mixed 반환값
     */
    private function invokeProtected(string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(TemplateManager::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->manager, $args);
    }

    /**
     * 짝 `.json` 없이 소스(`.g7.html`)만 있는 오버라이드도 등록 소스로 수집된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_markup_only_is_registered
     *
     * @return void 반환값 없음
     */
    public function test_template_override_markup_without_json_sibling_is_collected(): void
    {
        // 짝 `.json` 없이 소스만 둔 상태 — 종전 `glob('*.json')` 구현은 0건으로 흘려보냈다.
        File::put("{$this->tmp}/user-address-search.g7.html", "<extension extension-point=\"address_search_slot\"/>\n");

        $sources = $this->invokeProtected('collectFragmentSources', [$this->tmp, []]);

        $this->assertCount(1, $sources, '소스만 있어도 등록 소스로 수집되어야 한다');
        $this->assertTrue($sources[0]['markup']);
        $this->assertStringEndsWith('user-address-search.g7.html', $sources[0]['path']);
    }

    /**
     * 소스와 짝 `.json` 이 병존하면 소스가 이긴다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_markup_wins_over_json_sibling
     *
     * @return void 반환값 없음
     */
    public function test_template_override_markup_wins_over_json_sibling(): void
    {
        File::put("{$this->tmp}/frag.g7.html", "<extension extension-point=\"slot\"/>\n");
        File::put("{$this->tmp}/frag.json", '{"extension_point":"stale"}');

        $sources = $this->invokeProtected('collectFragmentSources', [$this->tmp, ["{$this->tmp}/frag.json"]]);

        $this->assertCount(1, $sources, '같은 논리 fragment 는 한 소스만 — 짝 .json 은 건너뜀');
        $this->assertTrue($sources[0]['markup']);
    }

    /**
     * 마크업으로 옮기지 않은 `.json` 오버라이드는 종전대로 수집된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_json_only_still_registered
     *
     * @return void 반환값 없음
     */
    public function test_template_override_json_without_markup_is_collected(): void
    {
        File::put("{$this->tmp}/legacy.json", '{"extension_point":"slot"}');

        $sources = $this->invokeProtected('collectFragmentSources', [$this->tmp, ["{$this->tmp}/legacy.json"]]);

        $this->assertCount(1, $sources, '마크업 미이관 오버라이드는 종전대로 .json 으로 등록');
        $this->assertFalse($sources[0]['markup']);
    }

    /**
     * 오버라이드 소스가 확장 조각 JSON 으로 컴파일된다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects template_override_markup_compiles_to_extension_json
     *
     * @return void 반환값 없음
     */
    public function test_template_override_markup_compiles_to_extension_json(): void
    {
        $file = "{$this->tmp}/frag.g7.html";
        File::put($file, "<extension extension-point=\"address_search_slot\" priority=\"50\"/>\n");

        $json = $this->invokeProtected('compileMarkupFragmentFile', [$file]);

        $this->assertSame('address_search_slot', $json['extension_point']);
        $this->assertSame(50, $json['priority']);
    }

    /**
     * 읽지 못하는 오버라이드 한 건이 나머지 확장점을 데리고 죽지 않는다.
     *
     * 종전 이 경로는 소스 **선택**만 `CollectsFragmentSources` 규약을 타고 **읽기**는 인라인
     * 사본이었다. 사본은 트레이트보다 두 군데가 약했다 — ⓐ `json_last_error()` 만 보고
     * `! is_array($data)` 를 안 봐서 스칼라 JSON(`null`·숫자·문자열)이 통과했고 ⓑ 포착이
     * `\Exception` 이라 그 스칼라가 `registerTemplateOverride(array $content, …)` 에 닿아 던지는
     * `TypeError`(=`Error`)를 못 잡았다. 그래서 파일 하나가 템플릿 활성화 전체를 세웠다.
     *
     * 트레이트 {@see CollectsFragmentSources::loadFragmentSource()} 는 배열이 아니면 로그 후
     * `null` 을 돌려 그 건만 건너뛴다. 이 테스트는 **깨진 파일이 먼저 오는** 순서를 만들어
     * "뒤 파일이 살아남는가" 로 그 계약을 고정한다.
     *
     * 실패 기록은 `Log::spy()` 로 가둬 공용 로그에 남기지 않고, 정확히 1줄인지와 그 문구를 단언한다(#725) — 파싱은
     * 됐는데 배열이 아니면 「JSON 형식 오류 (객체가 아님)」 + 실제 타입이다. 종전 문구는 「JSON 파싱 실패」 에
     * `error: "No error"` 라 원인을 알 수 없었다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects template_override_unreadable_source_skips_only_that_file, scalar_fragment_json_reports_type
     *
     * @return void 반환값 없음
     */
    public function test_template_override_unreadable_source_does_not_abort_remaining(): void
    {
        $identifier = 'test-tpl-override-read-'.uniqid();
        $moduleDir = base_path("templates/{$identifier}/extensions/sirsoft-ecommerce");
        File::ensureDirectoryExists($moduleDir);

        try {
            // 유효한 JSON 이지만 배열이 아니다 — 사본의 `json_last_error()` 단독 판정을 통과한다.
            File::put("{$moduleDir}/aaa-scalar.json", 'null');
            File::put("{$moduleDir}/zzz-valid.json", '{"extension_point":"admin.order.detail"}');

            $service = $this->createMock(LayoutExtensionService::class);
            $service->method('registerTemplateOverride')->willReturn('created');

            $ref = new \ReflectionProperty(TemplateManager::class, 'layoutExtensionService');
            $ref->setAccessible(true);
            $ref->setValue($this->manager, $service);

            Log::spy();

            $stats = $this->invokeProtected('registerExtensionOverrides', [$identifier, 1]);

            $this->assertSame(1, $stats['registered'],
                '읽지 못한 오버라이드는 그 건만 건너뛰고 나머지는 등록되어야 한다');

            Log::shouldHaveReceived('error')
                ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '레이아웃 확장 JSON 형식 오류 (객체가 아님)')
                    && str_contains($message, 'aaa-scalar.json')
                    && ($context['type'] ?? null) === 'null')
                ->once();
            Log::shouldHaveReceived('error')->once();
        } finally {
            File::deleteDirectory(base_path("templates/{$identifier}"));
        }
    }

    /**
     * 내용이 빈 객체 `{}` 뿐인 조각은 건너뛰고, 원인을 「빈 객체」 로 적는다.
     *
     * 빈 객체를 보존하는 디코드(#758)는 문서 전체가 `{}` 면 배열이 아니라 빈 map 표식을 돌려준다. 그래서
     * 「객체가 아님」 분기로 떨어져 `{}` 를 객체가 아니라고 적고, 타입 자리에 내부 클래스명이 찍혔다.
     * 등록하지 않는 동작은 그대로 두고 문구만 사실대로 고정한다.
     *
     * @scenario compile_unit=install,source_health=invalid
     *
     * @effects empty_object_fragment_json_reports_no_definition
     *
     * @return void 반환값 없음
     */
    public function test_empty_object_fragment_json_reports_no_definition(): void
    {
        $file = "{$this->tmp}/empty.json";
        File::put($file, '{}');

        Log::spy();

        $result = $this->invokeProtected('loadFragmentSource', [['path' => $file, 'markup' => false]]);

        $this->assertNull($result, '빈 객체 조각은 등록하지 않는다');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains($message, '레이아웃 확장 JSON 형식 오류 (빈 객체 — 정의 없음)')
                && str_contains($message, 'empty.json')
                && ! str_contains($message, '객체가 아님')
                && ! str_contains($context['error'] ?? '', 'EmptyMap'))
            ->once();
    }
}
