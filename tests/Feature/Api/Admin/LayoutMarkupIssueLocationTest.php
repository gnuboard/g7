<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutMarkupErrorCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Support\LayoutMarkup\DocumentCompiler;
use App\Support\LayoutMarkup\LayoutLinter;
use App\Support\LayoutMarkup\LayoutMarkupCompiler;
use App\Support\LayoutMarkup\LayoutValidator;
use App\Support\LayoutMarkup\LintWarning;
use App\Support\LayoutMarkup\MarkupTokenizer;
use App\Support\LayoutMarkup\MarkupTreeBuilder;
use App\Support\LayoutMarkup\NodeCompiler;
use App\Support\LayoutMarkup\StateTreeCompiler;
use App\Support\LayoutMarkup\StructuredValueCompiler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 코드 편집기 검사 경고·보안 위반의 소스 줄 위치 (7.1.0 P16)
 *
 * lint·보안 검사는 산출 JSON 을 보므로 `.g7.html` 소스의 줄을 몰랐다 — 경고 패널에 좌표가 없고 마커가 1행에
 * 접혔다. 이제 lint 경고는 그 노드·액션 태그의 줄·칸을, 보안 위반은 문제 값이 적힌 자리의 줄·칸을 싣는다.
 *
 * 스키마를 새로 만들지 않는다(DatabaseTransactions) — 식별자에 실행 고유 접미어를 붙인다.
 */
class LayoutMarkupIssueLocationTest extends TestCase
{
    use DatabaseTransactions;

    private string $layoutName;

    private string $token;

    /**
     * 템플릿·레이아웃과 레이아웃 편집 관리자 토큰을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $this->layoutName = 'loc_probe_'.$suffix;

        $template = Template::firstOrCreate(
            ['identifier' => 'sirsoft-admin_basic'],
            Template::factory()->make(['identifier' => 'sirsoft-admin_basic', 'type' => 'admin', 'status' => ExtensionStatus::Active->value])->getAttributes(),
        );

        TemplateLayout::factory()->create([
            'template_id' => $template->id,
            'name' => $this->layoutName,
            'content' => [
                'layout_name' => $this->layoutName,
                'version' => '1.0.0',
                'components' => [['id' => 'probe_root', 'type' => 'basic', 'name' => 'Div']],
            ],
        ]);

        $this->token = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit'], $suffix)
            ->createToken('loc')->plainTextToken;
    }

    /**
     * lint 경고가 문제 액션·노드 태그의 줄·칸을 싣는다 — 디바운스 혼재(노드)와 안쪽 액션 debounce(액션).
     *
     * @scenario issue_kind=lint_warning
     *
     * @effects lint_warning_carries_source_line, returned_json_has_no_position_markers
     *
     * @return void 반환값 없음
     */
    public function test_lint_warnings_carry_source_line_and_column(): void
    {
        $markup = implode("\n", [
            "<layout name=\"{$this->layoutName}\" version=\"1.0.0\">",   // 1
            '  <div id="probe_root">',                                    // 2
            '    <input name="q">',                                       // 3
            '      <on type="change">',                                   // 4
            '        <refetch-data-source :debounce="300" dataSourceId="search"/>', // 5
            '      </on>',                                                // 6
            '      <on type="change">',                                   // 7
            '        <set-state target="local" q="{{$event.target.value}}"/>',       // 8
            '      </on>',                                                // 9
            '    </input>',                                               // 10
            '  </div>',                                                   // 11
            '</layout>',                                                  // 12
        ]);

        $response = $this->postJson($this->compileUrl(), ['markup' => $markup], $this->headers())->assertOk();

        $warnings = collect($response->json('data.warnings'))->keyBy('code');
        $this->assertTrue($warnings->has('mixed_debounce_flushed'), json_encode($response->json('data.warnings'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(3, $warnings['mixed_debounce_flushed']['line']);
        $this->assertGreaterThan(0, $warnings['mixed_debounce_flushed']['column']);

        // 반환 JSON 에는 위치 표식이 없다
        $this->assertStringNotContainsString('__g7_source_position', (string) $response->json('data.content'));
    }

    /**
     * 같은 문서에 같은 경고가 두 노드에 있으면 처음 나온 노드의 줄을 가리킨다(종전처럼 핸들러별 한 번).
     *
     * @scenario issue_kind=lint_warning
     *
     * @effects lint_warning_carries_source_line
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_handler_warning_points_at_first_action(): void
    {
        $markup = implode("\n", [
            "<layout name=\"{$this->layoutName}\" version=\"1.0.0\">",
            '  <div id="probe_root">',
            '    <button>',
            '      <on type="click">',
            '        <action handler="noSuchHandlerQ736"/>',
            '      </on>',
            '    </button>',
            '    <button>',
            '      <on type="click">',
            '        <action handler="noSuchHandlerQ736"/>',
            '      </on>',
            '    </button>',
            '  </div>',
            '</layout>',
        ]);

        $response = $this->postJson($this->compileUrl(), ['markup' => $markup], $this->headers())->assertOk();

        $warnings = collect($response->json('data.warnings'))->where('code', 'unregistered_handler');
        $this->assertCount(1, $warnings, json_encode($response->json('data.warnings'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(5, $warnings->first()['line']);
    }

    /**
     * 보안 위반(실행 스킴 주소)이 문제 값이 적힌 줄·칸을 `compile_error` 로 싣는다.
     *
     * @scenario issue_kind=security_violation
     *
     * @effects security_violation_carries_source_line
     *
     * @return void 반환값 없음
     */
    public function test_security_violation_carries_source_line_and_column(): void
    {
        $markup = implode("\n", [
            "<layout name=\"{$this->layoutName}\" version=\"1.0.0\">",
            '  <div id="probe_root">',
            '    <span>ok</span>',
            '    <a href="javascript:alert(1)">x</a>',
            '  </div>',
            '</layout>',
        ]);

        $response = $this->postJson($this->compileUrl(), ['markup' => $markup], $this->headers())->assertStatus(422);

        $error = $response->json('errors.compile_error');
        $this->assertIsArray($error, json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(4, $error['line']);
        $this->assertSame(strpos('    <a href="javascript:alert(1)">x</a>', 'javascript:') + 1, $error['column']);
        $this->assertNotSame('', $error['message']);
        // 종전 계약(errors.content 메시지)도 유지
        $this->assertArrayHasKey('content', $response->json('errors'));
    }

    /**
     * 마크업으로 저장할 때 보안 규칙이 막아도 같은 위치를 `compile_error` 로 싣는다 — 저장은 되지 않는다.
     *
     * @scenario issue_kind=security_violation
     *
     * @effects security_violation_carries_source_line
     *
     * @return void 반환값 없음
     */
    public function test_markup_save_security_violation_carries_source_line(): void
    {
        $layout = TemplateLayout::query()->where('name', $this->layoutName)->firstOrFail();
        $markup = implode("\n", [
            "<layout name=\"{$this->layoutName}\" version=\"1.0.0\">",
            '  <div id="probe_root">',
            '    <a href="javascript:alert(2)">x</a>',
            '  </div>',
            '</layout>',
        ]);

        $response = $this->putJson("/api/admin/templates/sirsoft-admin_basic/layouts/{$this->layoutName}", [
            'format' => 'markup',
            'content' => $markup,
            'expected_lock_version' => (int) $layout->lock_version,
        ], $this->headers())->assertStatus(422);

        $this->assertSame(3, $response->json('errors.compile_error.line'), json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(14, $response->json('errors.compile_error.column'));
        $this->assertSame((int) $layout->lock_version, (int) $layout->fresh()->lock_version);
    }

    /**
     * `<init>` 액션과 위치 없는 경고가 함께 있는 레이아웃도 컴파일되고 경고는 줄을 싣는다 (#725 W12).
     *
     * 위치를 찾는 2차 컴파일은 경고에 줄을 붙이려는 보조 단계다. 10-07 에는 그 단계가 `<init>` 액션에 붙인 위치 표식을
     * 작성자 키로 오인해(`init_action_key_forbidden`) 컴파일 전체를 실패시켰다 — 설치·활성화까지 막을 수 있던 자리다.
     * 원인은 `ea5286728` 이 고쳤고, 여기서는 그 조합이 다시 깨지지 않게 잠근다.
     *
     * @scenario issue_kind=lint_warning
     *
     * @effects init_layout_with_unlocated_warning_compiles
     *
     * @return void 반환값 없음
     */
    public function test_layout_with_init_action_and_unlocated_warning_compiles(): void
    {
        $markup = implode("\n", [
            "<layout name=\"{$this->layoutName}\" version=\"1.0.0\">",   // 1
            '  <init>',                                                   // 2
            '    <set-state target="local" ready="1"/>',                  // 3
            '  </init>',                                                  // 4
            '  <div id="probe_root">',                                    // 5
            '    <input name="q">',                                       // 6
            '      <on type="change">',                                   // 7
            '        <refetch-data-source :debounce="300" dataSourceId="search"/>', // 8
            '      </on>',                                                // 9
            '      <on type="change">',                                   // 10
            '        <set-state target="local" q="{{$event.target.value}}"/>',       // 11
            '      </on>',                                                // 12
            '    </input>',                                               // 13
            '  </div>',                                                   // 14
            '</layout>',                                                  // 15
        ]);

        $response = $this->postJson($this->compileUrl(), ['markup' => $markup], $this->headers())->assertOk();

        $warnings = collect($response->json('data.warnings'))->keyBy('code');
        $this->assertTrue($warnings->has('mixed_debounce_flushed'), json_encode($response->json('data.warnings'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(6, $warnings['mixed_debounce_flushed']['line']);
    }

    /**
     * 위치를 찾는 2차 컴파일이 실패해도 1차 결과(위치 없는 경고)로 컴파일이 선다 (#725 W12).
     *
     * 2차 컴파일 협력자를 초기화되지 않은 대역으로 바꿔 그 단계가 반드시 던지게 만든다 — 보조 단계의 실패가 컴파일
     * 전체로 번지면 안 된다.
     *
     * @scenario issue_kind=lint_warning
     *
     * @effects init_layout_with_unlocated_warning_compiles
     *
     * @return void 반환값 없음
     */
    public function test_position_pass_failure_falls_back_to_unlocated_warnings(): void
    {
        $markup = implode("\n", [
            "<layout name=\"{$this->layoutName}\" version=\"1.0.0\">",
            '  <div id="probe_root">',
            '    <input name="q">',
            '      <on type="change">',
            '        <refetch-data-source :debounce="300" dataSourceId="search"/>',
            '      </on>',
            '      <on type="change">',
            '        <set-state target="local" q="{{$event.target.value}}"/>',
            '      </on>',
            '    </input>',
            '  </div>',
            '</layout>',
        ]);

        $result = $this->compilerWithFailingPositionPass()->compile($markup, 'probe.g7.html');

        $this->assertSame($this->layoutName, $result->json['layout_name'] ?? null, '1차 산출이 그대로 돌아와야 한다');
        $codes = array_map(static fn (LintWarning $warning): string => $warning->getCode()->value, $result->warnings);
        $this->assertContains('mixed_debounce_flushed', $codes, '위치를 못 붙여도 경고 자체는 남아야 한다');
    }

    /**
     * 위치 표식을 켜는 순간 던지는 문서 컴파일러를 단 마크업 컴파일러 대역을 만듭니다.
     *
     * 문서 컴파일러의 액션 컴파일러를 초기화하지 않은 채 둔다 — 1차 컴파일(초기화 블록·이름 있는 액션·오류 처리 절이 없는
     * 문서)은 그 협력자를 쓰지 않고, 2차 단계의 `markSourcePositions(true)` 만 그 협력자에 닿아 `Error` 를 던진다.
     *
     * @return LayoutMarkupCompiler 대역 컴파일러
     */
    private function compilerWithFailingPositionPass(): LayoutMarkupCompiler
    {
        $document = (new \ReflectionClass(DocumentCompiler::class))->newInstanceWithoutConstructor();
        foreach (['registry' => null, 'nodes' => new NodeCompiler(null), 'state' => new StateTreeCompiler, 'values' => new StructuredValueCompiler(LayoutMarkupErrorCode::UnexpectedStructuralChild)] as $name => $value) {
            (new \ReflectionProperty(DocumentCompiler::class, $name))->setValue($document, $value);
        }

        $compiler = (new \ReflectionClass(LayoutMarkupCompiler::class))->newInstanceWithoutConstructor();
        foreach (['registry' => null, 'tokenizer' => new MarkupTokenizer, 'treeBuilder' => new MarkupTreeBuilder, 'document' => $document, 'validator' => new LayoutValidator, 'linter' => new LayoutLinter(null)] as $name => $value) {
            (new \ReflectionProperty(LayoutMarkupCompiler::class, $name))->setValue($compiler, $value);
        }

        return $compiler;
    }

    /**
     * 마크업 변환 주소를 돌려줍니다.
     *
     * @return string 주소
     */
    private function compileUrl(): string
    {
        return "/api/admin/templates/sirsoft-admin_basic/layouts/{$this->layoutName}/markup-compile";
    }

    /**
     * 인증 헤더를 만듭니다.
     *
     * @return array<string, string> 요청 헤더
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    /**
     * 지정 권한만 가진 관리자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @param  string  $suffix  역할 식별자 고유 접미어
     * @return User 관리자
     */
    private function createAdminUser(array $permissions, string $suffix): User
    {
        $user = User::factory()->create();
        $permissionIds = [];

        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'markup_loc_'.$suffix,
            'name' => json_encode(['ko' => '마크업 위치 테스트', 'en' => 'Markup location test']),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);
        $user->roles()->attach($role->id);

        return $user;
    }
}
