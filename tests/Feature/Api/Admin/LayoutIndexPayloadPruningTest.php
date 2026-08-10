<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 레이아웃 목록 API 페이로드 프루닝
 *
 * `GET /api/admin/templates/{templateName}/layouts` 는 코드 편집 화면의 **파일 목록**을
 * 그리는 용도다. 그런데 목록·상세 공용 Resource 를 쓰면서 목록 응답에도 각 레이아웃의
 * `content`(본문 전체) · `components` · `data_sources` · `metadata` 를 담아 왔다.
 *
 * 실측(sirsoft-admin_basic): 응답 **18.30MB**, 그중 `content` 가 **17.41MB(95%)**.
 * 화면은 102개 파일 중 편집 중인 1개의 본문만 필요하고 그것은 `current_layout` 이 따로
 * 받아오는데도, 나머지 101개 본문까지 매번 내려받아 전역 상태에 상주시켰다.
 * 디버그 모드에서는 DevTools 가 이 상태를 추적하다 렌더러가 메모리 한계를 넘었다.
 *
 * 이 테스트는 목록 응답에 본문 계열 필드가 실리지 않는 것과, 화면이 실제로 쓰는 필드는
 * 그대로 유지되는 것을 함께 잠근다.
 */
class LayoutIndexPayloadPruningTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private string $token;

    /** 코드 편집 화면이 파일 목록 행에서 실제로 소비하는 필드 */
    private const LIST_FIELDS = ['name', 'description', 'route_path', 'size_formatted', 'updated_at', 'has_update'];

    /** 목록에 실리면 안 되는 본문 계열 필드 */
    private const HEAVY_FIELDS = ['content', 'components', 'data_sources', 'metadata'];

    protected function setUp(): void
    {
        parent::setUp();

        $permissionIds = [];
        foreach (['core.templates.read', 'core.templates.layouts.edit'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'is_system' => true,
                'priority' => 0,
            ]
        );
        $adminRole->permissions()->syncWithoutDetaching($permissionIds);

        $admin = User::factory()->create();
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        $this->token = $admin->createToken('test-token')->plainTextToken;

        $this->template = Template::factory()->create();

        // 본문이 큰 레이아웃 3개 — 목록에 본문이 실리면 응답이 그만큼 부풀어야 한다
        foreach (['_admin_base', 'admin_settings', 'admin_user_list'] as $i => $name) {
            TemplateLayout::factory()->create([
                'template_id' => $this->template->id,
                'name' => $name,
                'content' => [
                    'version' => '1.0.0',
                    'layout_name' => $name,
                    'meta' => ['description' => "설명 {$name}"],
                    'data_sources' => [['id' => 'ds_'.$i, 'endpoint' => '/api/x']],
                    'components' => array_fill(0, 200, [
                        'type' => 'basic',
                        'name' => 'Div',
                        'props' => ['className' => str_repeat('padding-filler ', 40)],
                    ]),
                ],
            ]);
        }
    }

    #[Test]
    public function 목록_응답에_본문_계열_필드가_실리지_않는다(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            foreach (self::HEAVY_FIELDS as $field) {
                $this->assertArrayNotHasKey(
                    $field,
                    $row,
                    "목록 응답에 본문 계열 필드 '{$field}' 가 실렸다 — 화면은 이 필드를 쓰지 않는다"
                );
            }
        }
    }

    #[Test]
    public function 목록_응답은_화면이_쓰는_필드를_유지한다(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        $response->assertOk();
        $rows = $response->json('data');

        foreach ($rows as $row) {
            foreach (self::LIST_FIELDS as $field) {
                $this->assertArrayHasKey($field, $row, "목록 표시에 필요한 '{$field}' 가 빠졌다");
            }
            // 낙관적 잠금 버전도 목록에서 유지되어야 한다 (편집 진입 시 사용)
            $this->assertArrayHasKey('lock_version', $row);
        }

        $names = array_column($rows, 'name');
        $this->assertContains('admin_settings', $names);

        // description 은 content.meta.description 에서 파생된다 — 본문을 안 실어도 값은 나와야 한다
        $settings = collect($rows)->firstWhere('name', 'admin_settings');
        $this->assertSame('설명 admin_settings', $settings['description']);

        // size_formatted 는 본문 크기 표기 — 0 바이트로 무너지면 안 된다
        $this->assertNotSame('0 B', $settings['size_formatted']);
    }

    #[Test]
    public function 상세_응답은_본문을_그대로_제공한다(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/admin_settings");

        $response->assertOk();
        $row = $response->json('data');

        $this->assertArrayHasKey('content', $row, '상세는 편집 대상 본문을 반드시 제공해야 한다');
        $this->assertNotEmpty($row['content']);
        $this->assertStringContainsString('admin_settings', $row['content']);
    }

    /**
     * 청크 순회가 행을 누락하지 않는지 확인한다.
     *
     * `chunkById()` 는 키셋(`where id > lastId`)으로 다음 페이지를 잡는다. 여기에 다른
     * 컬럼(`name`) 정렬을 함께 걸면 정렬 순서와 커서 기준이 어긋나 **행이 조용히 누락**된다.
     * 실측으로 102행 중 58행만 반환된 적이 있다 — 예외도 오류도 없이 목록만 줄어든다.
     */
    #[Test]
    public function 청크_순회가_행을_누락하지_않는다(): void
    {
        // id 순서와 name 순서가 어긋나도록 이름을 역순으로 만든다
        for ($i = 0; $i < 60; $i++) {
            TemplateLayout::factory()->create([
                'template_id' => $this->template->id,
                'name' => sprintf('zz_layout_%03d', 100 - $i),
                'content' => ['version' => '1.0.0', 'meta' => ['description' => "d{$i}"]],
            ]);
        }

        $expected = TemplateLayout::where('template_id', $this->template->id)->count();

        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        $response->assertOk();
        $rows = $response->json('data');

        $this->assertCount(
            $expected,
            $rows,
            '목록 응답 행 수가 DB 행 수와 다르다 — 청크 순회에서 행이 누락됐다'
        );

        // 이름 오름차순 정렬 계약도 유지되어야 한다
        $names = array_column($rows, 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $names, '목록은 이름 오름차순으로 정렬되어야 한다');
    }

    #[Test]
    public function 목록_응답_크기가_본문_총량에_비례해_커지지_않는다(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        $response->assertOk();
        $payloadBytes = strlen($response->getContent());

        $contentBytes = TemplateLayout::where('template_id', $this->template->id)
            ->get()
            ->sum(fn ($layout) => strlen(json_encode($layout->content)));

        $this->assertGreaterThan(0, $contentBytes);
        $this->assertLessThan(
            $contentBytes,
            $payloadBytes,
            '목록 응답이 본문 총량보다 크다 — 본문이 그대로 실리고 있다'
        );
    }

    /**
     * 사전에 없는 `$t:` 토큰이 화면에 원문 그대로 노출되지 않는지 확인
     *
     * 레이아웃의 `meta.description` 은 그 레이아웃을 **소유한 템플릿**의 사전 키를 쓴다
     * (예: 유저 템플릿 레이아웃이 `$t:user.base_layout_description`). 그런데 코드 편집
     * 화면은 관리자 템플릿 사전으로 렌더하므로 그 키를 알지 못한다. 응답이 토큰을 그대로
     * 내보내면 파일 목록 설명 칸에 `$t:user.base_layout_description` 이 노출된다.
     * (브라우저 실측: 519개 중 83개가 이 형태)
     */
    #[Test]
    public function it_does_not_expose_raw_translation_tokens_in_description(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'errors/404',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'errors/404',
                'meta' => ['description' => '$t:error.404.description'],
                'components' => [],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'errors/404');
        $this->assertNotNull($row);
        $this->assertStringNotContainsString(
            '$t:',
            (string) $row['description'],
            '번역 토큰이 해석되지 않은 채 목록 설명으로 노출됐다'
        );
        $this->assertSame('errors/404', $row['description'], '해석 불가 시 레이아웃 이름으로 폴백해야 한다');
    }

    /**
     * 런타임 컨텍스트가 필요한 표현식 description 이 원문으로 노출되지 않는지 확인
     *
     * `{{route.id ? '$t:a' : '$t:b'}}` 형태는 목록 시점에 `route` 가 없어 서버가 해석할 수
     * 없다. 원문을 그대로 내보내면 설명 칸에 표현식이 그대로 보인다.
     */
    #[Test]
    public function it_does_not_expose_raw_expression_in_description(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'board/form',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'board/form',
                'meta' => ['description' => "{{route.id ? '\$t:board.edit' : '\$t:board.new'}}"],
                'components' => [],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'board/form');
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('{{', (string) $row['description']);
        $this->assertSame('board/form', $row['description']);
    }

    /**
     * 평문 설명은 종전대로 그대로 실리는지 확인 (회귀 보호)
     */
    #[Test]
    public function it_keeps_plain_description_as_is(): void
    {
        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts");

        $response->assertOk();

        $row = collect($response->json('data'))->firstWhere('name', 'admin_settings');
        $this->assertSame('설명 admin_settings', $row['description']);
    }

    /**
     * 상세 응답도 목록과 같은 규칙으로 설명을 해석하는지 확인
     *
     * 코드 편집 화면은 선택한 파일의 설명을 헤더에 따로 표시하고, 그 값은 상세
     * 엔드포인트(`current_layout`)에서 온다. 목록만 고치면 파일 목록에는 번역된 설명이,
     * 헤더에는 `$t:` 토큰이 보이는 불일치가 생긴다. (브라우저 실측으로 발견)
     */
    #[Test]
    public function it_resolves_description_in_detail_response_too(): void
    {
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'errors/500',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'errors/500',
                'meta' => ['description' => '$t:error.500.description'],
                'components' => [],
            ],
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/admin/templates/{$this->template->identifier}/layouts/errors/500");

        $response->assertOk();

        $description = (string) $response->json('data.description');
        $this->assertStringNotContainsString('$t:', $description, '상세 응답에 번역 토큰이 원문 노출됐다');
        $this->assertSame('errors/500', $description);
    }
}
