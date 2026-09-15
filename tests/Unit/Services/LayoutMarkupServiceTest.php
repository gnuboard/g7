<?php

namespace Tests\Unit\Services;

use App\Enums\LayoutMarkupErrorCode;
use App\Exceptions\TemplateOperationException;
use App\Services\LayoutMarkupService;
use App\Support\LayoutMarkup\CompileContext;
use App\Support\LayoutMarkup\CompileError;
use App\Support\LayoutMarkup\MarkupCompileContextResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 설치본(DB 행) 변환 서비스 — 코드 편집기·점검 커맨드가 쓰는 JSON ↔ `.g7.html` 공용 진입점 (#476 4.1).
 *
 * 파일 경로가 없는 문서라 표·문맥을 템플릿 식별자로 고른다({@see MarkupCompileContextResolver::registryForTemplate()}·
 * {@see MarkupCompileContextResolver::contextForInstalled()}). 두 방향 모두 설치 경로와 같은 클래스를 탄다.
 */
class LayoutMarkupServiceTest extends TestCase
{
    private LayoutMarkupService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LayoutMarkupService;
    }

    protected function tearDown(): void
    {
        DB::disconnect();

        parent::tearDown();
    }

    /**
     * @effects installed_json_round_trips_through_markup_with_the_template_registry
     */
    public function test_to_markup_and_to_json_round_trip_with_template_registry(): void
    {
        $json = [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'named_actions' => ['probe' => ['comment' => '한 번만', 'handler' => 'setState', 'params' => ['target' => 'local', 'a' => 1]]],
            'components' => [['type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'p-4']]],
        ];

        ['markup' => $markup, 'escapes' => $escapes] = $this->service->toMarkup($json, 'sirsoft-admin_basic');

        $this->assertStringStartsWith('<layout name="admin_probe"', $markup);
        $this->assertSame([], $escapes);
        $this->assertSame(1, substr_count($markup, '한 번만'));

        $result = $this->service->toJson($markup, 'sirsoft-admin_basic', 'template', 'sirsoft-admin_basic', 'admin_probe.g7.html');

        $this->assertSame('p-4', $result->json['components'][0]['props']['className']);
        $this->assertSame('한 번만', $result->json['named_actions']['probe']['comment']);
        $this->assertSame([], $this->service->describeWarnings($result->warnings));
    }

    /**
     * @effects unrepresentable_keys_are_reported_as_escapes
     */
    public function test_to_markup_reports_json_escapes(): void
    {
        $json = [
            'layout_name' => 'admin_probe',
            'version' => '1.0.0',
            'components' => [['type' => 'basic', 'name' => 'Div']],
            'scripts' => [['src' => '/js/x.js', 'comment' => '방출 자리가 없는 주석']],
        ];

        ['escapes' => $escapes] = $this->service->toMarkup($json, 'sirsoft-admin_basic');

        $this->assertNotEmpty($escapes, '표현 못 하는 키는 <json> 탈출로 계량돼야 한다');
    }

    /**
     * @effects compile_error_is_described_with_code_and_position
     */
    public function test_to_json_throws_compile_error_and_describe_error_carries_position(): void
    {
        try {
            $this->service->toJson("<layout name=\"x\" version=\"1.0.0\">\n  <Div/>\n</layout>", 'sirsoft-admin_basic', null, null, 'x.g7.html');
            $this->fail('CompileError 를 기대했습니다.');
        } catch (CompileError $error) {
            $described = $this->service->describeError($error);

            $this->assertSame(LayoutMarkupErrorCode::TagUppercase->value, $described['code']);
            $this->assertSame(2, $described['line']);
            $this->assertGreaterThan(0, $described['column']);
            $this->assertNotEmpty($described['message']);
            $this->assertStringNotContainsString('x.g7.html', $described['message'], '본문은 위치 봉투 없이 온다 (편집기가 라인·컬럼을 따로 받는다)');
        }
    }

    /**
     * @effects unknown_template_has_no_registry
     */
    public function test_unknown_template_raises_runtime_exception(): void
    {
        $this->expectException(TemplateOperationException::class);

        $this->service->toMarkup(['layout_name' => 'x', 'version' => '1.0.0', 'components' => []], 'no-such-template');
    }

    /**
     * @effects resolver_builds_registry_and_context_from_template_identifier
     */
    public function test_resolver_exposes_registry_and_installed_context_by_template(): void
    {
        $resolver = new MarkupCompileContextResolver;

        $this->assertNotNull($resolver->registryForTemplate('sirsoft-admin_basic'));
        $this->assertNull($resolver->registryForTemplate('no-such-template'));

        $context = $resolver->contextForInstalled('sirsoft-admin_basic', 'plugin', 'sirsoft-gdpr');

        $this->assertInstanceOf(CompileContext::class, $context);
        $this->assertSame('plugin', $context->sourceKind);
        $this->assertSame('sirsoft-gdpr', $context->identifier);
        $this->assertNotEmpty($context->templateHandlers, '템플릿 핸들러 목록은 경로 해석과 같은 함수에서 온다');
    }
}
