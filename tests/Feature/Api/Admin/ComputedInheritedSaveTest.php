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
 * ComputedInheritedSaveTest — computed 부모/자식 상속 저장 라운드트립
 *
 * computed 는 init_actions 와 정반대로 **부모 키 편집 가능·키 덮어쓰기**가 정책이다(shallow merge,
 * 자식 우선). 핵심 사실(plan):
 *  - 편집 응답 raw.computed 는 병합본(부모+자식), 자식 원본은 `__editor.original.computed` 보존.
 *  - 키별 출처 맵 `__computedSource:{ [key]: 'base'|'route' }` 가 편집 모드에만 부착(computed 객체
 *    외부 최상위 키). 운영/저장 페이로드에는 없다.
 *  - 저장 시 클라이언트가 `__editor.original` 에서 자식 computed 만 복원해 PUT:
 *      ① 자식 고유 키만 → 그대로 영속
 *      ② 부모 키를 자식이 덮으면 그 키가 자식 식으로 자식 원본에 들어가 영속(덮기 영속)
 *      ③ 미덮은 부모 키는 자식 원본에 없으므로 PUT 에서 제외
 *  - 백엔드 `validated()` 는 `content.computed` 를 `['nullable','array']` 로 통째 보존한다.
 *  - `__computedSource` 메타는 편집 응답 전용 — 저장 시 페이로드에 박히면 안 되고, 저장돼도 운영
 *    `_computed` 평가에 영향이 없다(computed 키가 아니므로 미평가).
 */
class ComputedInheritedSaveTest extends TestCase
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
     * 부모(base) 레이아웃 생성 — 슬롯 + computed 보유.
     *
     * @param  array<string, string>  $computed
     */
    private function makeParent(array $computed): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => '_admin_base',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => '_admin_base',
                'components' => [
                    ['id' => 'shell', 'name' => 'Div', 'type' => 'basic', 'props' => [], 'children' => [
                        ['id' => 'content_slot', 'name' => 'Div', 'type' => 'basic', 'slot' => 'content', 'props' => []],
                    ]],
                ],
                'data_sources' => [],
                'computed' => $computed,
            ],
            'extends' => null,
            'lock_version' => 0,
        ]);
    }

    /**
     * 자식 레이아웃 생성 — 부모를 extends, computed 보유.
     *
     * @param  array<string, string>  $computed
     */
    private function makeChild(array $computed): TemplateLayout
    {
        return TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'dashboard',
            'content' => [
                'version' => '1.0.0',
                'layout_name' => 'dashboard',
                'extends' => '_admin_base',
                'slots' => ['content' => [['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []]]],
                'data_sources' => [],
                'computed' => $computed,
            ],
            'extends' => '_admin_base',
            'lock_version' => 0,
        ]);
    }

    /**
     * 자식 content PUT 후 갱신된 DB content 반환.
     *
     * @param  array<string, string>  $childComputed
     * @return array<string, mixed>
     */
    private function putChild(TemplateLayout $child, array $childComputed): array
    {
        $content = [
            'version' => '1.0.0',
            'layout_name' => 'dashboard',
            'extends' => '_admin_base',
            'slots' => ['content' => [['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []]]],
            'data_sources' => [],
            'computed' => $childComputed,
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$child->name}",
            ['expected_lock_version' => (int) $child->lock_version, 'content' => $content],
        );
        $response->assertStatus(200);
        $child->refresh();

        return $child->content;
    }

    #[Test]
    public function it_persists_only_child_own_computed_keys(): void
    {
        // ① 자식 고유 키만 PUT → 그대로 영속, 부모 키 미포함
        // ③ 미덮은 부모 키는 자식 PUT 에서 제외(클라이언트 __editor.original 복원)
        $this->makeParent(['isReadOnly' => '{{ !canEdit }}', 'pageTitle' => '{{ "공통" }}']);
        $child = $this->makeChild(['rowCount' => '{{ items.length }}']);

        $stored = $this->putChild($child, ['rowCount' => '{{ items.length }}']);

        $this->assertArrayHasKey('computed', $stored);
        $this->assertArrayHasKey('rowCount', $stored['computed']);
        $this->assertSame('{{ items.length }}', $stored['computed']['rowCount']);
        // 미덮은 부모 키는 자식 행에 영속되면 안 됨
        $this->assertArrayNotHasKey('isReadOnly', $stored['computed']);
        $this->assertArrayNotHasKey('pageTitle', $stored['computed']);
        // 출처 맵 메타는 저장되지 않음
        $this->assertArrayNotHasKey('__computedSource', $stored);
    }

    #[Test]
    public function it_persists_overridden_parent_key_with_child_expression(): void
    {
        // ② 부모 키를 자식이 덮으면 그 키가 자식 식으로 영속(덮기 영속)
        $this->makeParent(['isReadOnly' => '{{ !canEdit }}']);
        $child = $this->makeChild(['isReadOnly' => '{{ false }}']);

        $stored = $this->putChild($child, ['isReadOnly' => '{{ false }}']);

        $this->assertArrayHasKey('isReadOnly', $stored['computed']);
        $this->assertSame('{{ false }}', $stored['computed']['isReadOnly'], '덮은 키는 자식 식으로 영속');
    }

    #[Test]
    public function it_does_not_persist_computed_source_meta(): void
    {
        // ④ __computedSource 메타가 자식 content 에 박혀 와도 저장 페이로드에 영속되지 않음을 가드.
        //    백엔드 validated() 는 content.computed 를 통째 보존하나, __computedSource 는 computed 의
        //    형제 최상위 키이며 rules() 에 미명시라 validated() 에서 탈락한다(저장 0).
        $this->makeParent(['isReadOnly' => '{{ !canEdit }}']);
        $child = $this->makeChild(['rowCount' => '{{ items.length }}']);

        $content = [
            'version' => '1.0.0',
            'layout_name' => 'dashboard',
            'extends' => '_admin_base',
            'slots' => ['content' => [['id' => 'root', 'name' => 'Div', 'type' => 'basic', 'props' => []]]],
            'data_sources' => [],
            'computed' => ['rowCount' => '{{ items.length }}'],
            // 편집 응답에서 새어 들어온 출처 맵 메타(위조/우회)
            '__computedSource' => ['isReadOnly' => 'base', 'rowCount' => 'route'],
        ];

        $response = $this->authRequest()->putJson(
            "/api/admin/templates/{$this->template->identifier}/layouts/{$child->name}",
            ['expected_lock_version' => 0, 'content' => $content],
        );
        $response->assertStatus(200);
        $child->refresh();

        $this->assertArrayNotHasKey('__computedSource', $child->content, '출처 맵 메타가 영속되면 안 됨(validated 탈락)');
        $this->assertSame(['rowCount' => '{{ items.length }}'], $child->content['computed']);
    }

    #[Test]
    public function it_excludes_reverted_key_from_payload(): void
    {
        // ⑤ 되돌린 키 PUT 제외 — 자식이 이전에 덮었던 키를 되돌리면(자식 computed 에서 제거),
        //    그 키는 자식 PUT 에 포함되지 않아 재로드 시 부모 식으로 복귀한다.
        $this->makeParent(['isReadOnly' => '{{ !canEdit }}']);
        // 초기엔 자식이 덮은 상태로 저장
        $child = $this->makeChild(['isReadOnly' => '{{ false }}', 'rowCount' => '{{ items.length }}']);
        $this->putChild($child, ['isReadOnly' => '{{ false }}', 'rowCount' => '{{ items.length }}']);

        // 되돌림 — 자식 computed 에서 isReadOnly 제거 후 재저장(자식 고유 키만 남김)
        $stored = $this->putChild($child, ['rowCount' => '{{ items.length }}']);

        $this->assertArrayNotHasKey('isReadOnly', $stored['computed'], '되돌린 키는 자식 행에서 제거');
        $this->assertArrayHasKey('rowCount', $stored['computed']);

        // 재로드 시 isReadOnly 는 부모 식으로 복귀
        $merged = app(LayoutService::class)->loadAndMergeLayout($this->template->id, 'dashboard', true);
        $this->assertSame('{{ !canEdit }}', $merged['computed']['isReadOnly'], '되돌린 키는 부모 식으로 평가');
        $this->assertSame('base', $merged['__computedSource']['isReadOnly']);
    }

    #[Test]
    public function it_leaves_parent_row_unchanged_and_remerges_on_reload(): void
    {
        // ⑥ 부모 레이아웃 행 불변 + 재로드 시 부모+자식 병합·출처 맵 재부착
        $parent = $this->makeParent(['isReadOnly' => '{{ !canEdit }}', 'pageTitle' => '{{ "공통" }}']);
        $child = $this->makeChild(['isReadOnly' => '{{ false }}', 'rowCount' => '{{ items.length }}']);

        $this->putChild($child, ['isReadOnly' => '{{ false }}', 'rowCount' => '{{ items.length }}']);

        // 부모 행 불변
        $parent->refresh();
        $this->assertSame(
            ['isReadOnly' => '{{ !canEdit }}', 'pageTitle' => '{{ "공통" }}'],
            $parent->content['computed'],
        );
        $this->assertSame(0, (int) $parent->lock_version);

        // 재로드(with_source_meta=1) — shallow merge 자식 우선 + 출처 맵
        $merged = app(LayoutService::class)->loadAndMergeLayout($this->template->id, 'dashboard', true);

        // 자식 덮은 키 = 자식 식 / 미덮은 부모 키 = 부모 식 / 자식 고유 키 = 자식 식
        $this->assertSame('{{ false }}', $merged['computed']['isReadOnly']);
        $this->assertSame('{{ "공통" }}', $merged['computed']['pageTitle']);
        $this->assertSame('{{ items.length }}', $merged['computed']['rowCount']);

        // 출처 맵: 덮은 키=route / 미덮은 부모 키=base / 자식 고유=route
        $this->assertSame('route', $merged['__computedSource']['isReadOnly']);
        $this->assertSame('base', $merged['__computedSource']['pageTitle']);
        $this->assertSame('route', $merged['__computedSource']['rowCount']);

        // 자식 원본은 __editor.original 에 자식 computed 만 보존
        $this->assertSame(
            ['isReadOnly' => '{{ false }}', 'rowCount' => '{{ items.length }}'],
            $merged['__editor']['original']['computed'],
        );
    }
}
