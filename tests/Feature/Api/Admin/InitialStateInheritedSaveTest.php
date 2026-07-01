<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Models\User;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * InitialStateInheritedSaveTest — initLocal/initGlobal/initIsolated 상속 저장 라운드트립
 *
 *
 * 초기 상태(initLocal/initGlobal/initIsolated)는 computed 와 같은 부류로 shallow merge(자식 우선,
 * 상속 키 편집 가능·덮기)다. 핵심 사실(plan):
 *  - 편집 응답 raw.initLocal 은 병합본, 자식 원본은 `__editor.original.initLocal` 보존.
 *  - 저장 시 클라이언트가 자식 원본만 복원해 PUT → 미덮은 부모 키 제외, 덮은/고유 키만 영속.
 *  - legacy `state` 키는 `initLocal` 의 deprecated alias — 자식이 `state` 만 보유하면 편집기가
 *    `initLocal` 로 정규화해 PUT(state 키 제거)한다.
 *  - 백엔드 `validated()` 는 `content.initLocal/initGlobal/initIsolated` 를 `['nullable','array']`
 *    로 통째 보존하므로 하위 키가 탈락하지 않는다(R9 부류 — 미명시 시 떨궈 격리 초기값 손실).
 *  - 부모 레이아웃 행은 자식 저장에 영향받지 않고, 재로드 시 부모+자식이 다시 병합된다.
 */
class InitialStateInheritedSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private Template $template;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit']);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;
        $this->template = Template::factory()->create();
    }

    private function createAdminUser(array $permissions = []): User
    {
        $user = User::factory()->create();
        $permissionIds = [];
        foreach ($permissions as $identifier) {
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
        $user->roles()->syncWithoutDetaching([$adminRole->id]);

        return $user;
    }

    private function authRequest()
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    /**
     * 부모(base) 레이아웃 생성 — 슬롯 + 초기 상태 키 보유.
     *
     * @param  array<string, mixed>  $initialState  initLocal/initGlobal/initIsolated 키 포함
     */
    private function makeParent(array $initialState): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_user_base',
            'content' => array_merge([
                'version' => '1.0.0',
                'layout_name' => '_user_base',
                'components' => [
                    ['id' => 'shell', 'name' => 'Div', 'type' => 'basic', 'props' => [], 'children' => [
                        ['id' => 'content_slot', 'name' => 'Div', 'type' => 'basic', 'slot' => 'content', 'props' => []],
                    ]],
                ],
                'data_sources' => [],
            ], $initialState),
            'extends' => null,
            'lock_version' => 0,
        ]);
    }

    /**
     * 자식 레이아웃 생성 — 부모를 extends, 초기 상태 키 보유.
     *
     * @param  array<string, mixed>  $initialState
     */
    private function makeChild(array $initialState): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'home',
            'content' => array_merge([
                'version' => '1.0.0',
                'layout_name' => 'home',
                'extends' => '_user_base',
                'slots' => ['content' => [['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []]]],
                'data_sources' => [],
            ], $initialState),
            'extends' => '_user_base',
            'lock_version' => 0,
        ]);
    }

    /**
     * 자식 content PUT 후 갱신된 DB content 반환.
     *
     * @param  array<string, mixed>  $childInitialState
     * @return array<string, mixed>
     */
    private function putChild(TemplateLayout $child, array $childInitialState): array
    {
        $content = array_merge([
            'version' => '1.0.0',
            'layout_name' => 'home',
            'extends' => '_user_base',
            'slots' => ['content' => [['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []]]],
            'data_sources' => [],
        ], $childInitialState);

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$child->name}",
            ['expected_lock_version' => (int) $child->lock_version, 'content' => $content],
        );
        $response->assertStatus(200);
        $child->refresh();

        return $child->content;
    }

    #[Test]
    public function it_persists_only_child_init_local_and_strips_inherited(): void
    {
        // ① 자식 [초기상태] 편집 후 PUT → 자식 initLocal 만 (부모 상속분 strip)
        // ③ 미덮은 부모 키 PUT 제외
        $this->makeParent(['initLocal' => ['theme' => 'light', 'perPage' => 20]]);
        $child = $this->makeChild(['initLocal' => ['keyword' => 'g7']]);

        $stored = $this->putChild($child, ['initLocal' => ['keyword' => 'g7']]);

        $this->assertArrayHasKey('initLocal', $stored);
        $this->assertSame(['keyword' => 'g7'], $stored['initLocal'], '자식 고유 키만 영속');
        $this->assertArrayNotHasKey('theme', $stored['initLocal'], '미덮은 부모 키 제외');
        $this->assertArrayNotHasKey('perPage', $stored['initLocal']);
    }

    #[Test]
    public function it_persists_overridden_inherited_key_with_child_value(): void
    {
        // ② 상속 키 덮은 경우 그 키는 자식 값으로 PUT(덮기 영속)
        $this->makeParent(['initLocal' => ['perPage' => 20]]);
        $child = $this->makeChild(['initLocal' => ['perPage' => 50]]);

        $stored = $this->putChild($child, ['initLocal' => ['perPage' => 50]]);

        $this->assertSame(50, $stored['initLocal']['perPage'], '덮은 상속 키는 자식 값으로 영속');
    }

    #[Test]
    public function it_migrates_legacy_state_to_init_local_on_save(): void
    {
        // ④ legacy state→initLocal 이관 PUT(state 키 제거)
        // 자식이 legacy `state` 만 보유했다가 편집기가 initLocal 로 정규화해 저장하는 시나리오.
        $this->makeParent(['initLocal' => ['theme' => 'light']]);
        $child = $this->makeChild(['state' => ['draft' => 'memo']]);

        // 정규화 후 PUT — state 제거 + initLocal 로 이관
        $stored = $this->putChild($child, ['initLocal' => ['draft' => 'memo']]);

        $this->assertArrayHasKey('initLocal', $stored);
        $this->assertSame(['draft' => 'memo'], $stored['initLocal']);
        $this->assertArrayNotHasKey('state', $stored, 'legacy state 키는 저장 후 제거');
    }

    #[Test]
    public function it_preserves_nested_subkeys_across_init_buckets(): void
    {
        // ⑤ initLocal/initGlobal/initIsolated 하위 키 validated() 미탈락(R9 부류)
        $this->makeParent([]);
        $child = $this->makeChild([]);

        $childState = [
            'initLocal' => ['filter' => ['status' => 'active', 'page' => 1], 'keyword' => 'g7'],
            'initGlobal' => ['cartCount' => 0, 'theme' => 'dark'],
            'initIsolated' => ['sliderState' => ['idx' => 0], 'wizard' => ['step' => 1]],
        ];

        $stored = $this->putChild($child, $childState);

        // 하위 키가 통째 보존되어야 함(R9 — 미명시 시 떨궈 격리 초기값 손실)
        $this->assertSame(['status' => 'active', 'page' => 1], $stored['initLocal']['filter']);
        $this->assertSame('g7', $stored['initLocal']['keyword']);
        $this->assertSame(0, $stored['initGlobal']['cartCount']);
        $this->assertSame('dark', $stored['initGlobal']['theme']);
        $this->assertSame(['idx' => 0], $stored['initIsolated']['sliderState']);
        $this->assertSame(['step' => 1], $stored['initIsolated']['wizard']);
    }

    #[Test]
    public function it_leaves_parent_row_unchanged_when_child_is_saved(): void
    {
        // ⑥ 부모 레이아웃 행 불변
        $parent = $this->makeParent(['initLocal' => ['theme' => 'light', 'perPage' => 20]]);
        $child = $this->makeChild(['initLocal' => ['perPage' => 50]]);

        $this->putChild($child, ['initLocal' => ['perPage' => 50]]);

        $parent->refresh();
        $this->assertSame(['theme' => 'light', 'perPage' => 20], $parent->content['initLocal'], '부모 행 불변');
        $this->assertSame(0, (int) $parent->lock_version);
    }

    #[Test]
    public function it_documents_empty_string_initial_value_becomes_null_on_save(): void
    {
        // 코어 동작 가드 — Laravel 글로벌 미들웨어 ConvertEmptyStringsToNull 이 요청 본문의 빈
        // 문자열을 검증 전에 null 로 변환한다. 따라서 편집기가 빈 텍스트 입력칸을 `''` 로 시드해
        // PUT 해도 저장된 initLocal 값은 null 이 된다(레이아웃 content 전 저장 경로 공통).
        // 본 작업이 도입한 동작이 아니라 프레임워크 전역 동작이며, 런타임 `_local` 주입에서
        // null 과 '' 는 동등(빈 값)하게 취급되므로 폼 자동바인딩에 영향이 없다.
        $this->makeParent([]);
        $child = $this->makeChild([]);

        $stored = $this->putChild($child, ['initLocal' => ['keyword' => '', 'tag' => 'x']]);

        $this->assertNull($stored['initLocal']['keyword'], '빈 문자열 초기값은 저장 시 null 로 변환됨(프레임워크 전역 동작)');
        $this->assertSame('x', $stored['initLocal']['tag'], '비-빈 값은 그대로 보존');
    }

    #[Test]
    public function it_remerges_parent_and_child_on_reload(): void
    {
        // ⑦ 재로드 시 부모+자식 재병합(shallow merge 자식 우선)
        $this->makeParent([
            'initLocal' => ['theme' => 'light', 'perPage' => 20],
            'initGlobal' => ['cartCount' => 0],
        ]);
        $child = $this->makeChild(['initLocal' => ['perPage' => 50, 'keyword' => 'g7']]);

        $this->putChild($child, ['initLocal' => ['perPage' => 50, 'keyword' => 'g7']]);

        // DB 자체에는 자식분만(부모 상속분 strip)
        $child->refresh();
        $this->assertSame(['perPage' => 50, 'keyword' => 'g7'], $child->content['initLocal']);

        // 재로드(with_source_meta=1) — shallow merge: 자식 덮은 키 우선 + 미덮은 부모 키 보존
        $merged = app(LayoutService::class)->loadAndMergeLayout($this->template->id, 'home', true);

        $this->assertSame('light', $merged['initLocal']['theme'], '미덮은 부모 키 보존');
        $this->assertSame(50, $merged['initLocal']['perPage'], '덮은 키는 자식 값');
        $this->assertSame('g7', $merged['initLocal']['keyword'], '자식 고유 키');
        $this->assertSame(0, $merged['initGlobal']['cartCount'], '부모 initGlobal 보존');

        // 자식 원본은 __editor.original 에 자식분만 보존
        $this->assertSame(['perPage' => 50, 'keyword' => 'g7'], $merged['__editor']['original']['initLocal']);
    }
}
