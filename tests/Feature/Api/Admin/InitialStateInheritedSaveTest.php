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
 *
 * @see tests/scenarios/initial-state-inherited-save.yaml
 */
class InitialStateInheritedSaveTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private Template $template;

    private string $token;

    /**
     * 편집 권한 관리자 토큰과 대상 템플릿을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['core.templates.read', 'core.templates.layouts.edit']);
        $this->token = $this->adminUser->createToken('test-token')->plainTextToken;
        $this->template = Template::factory()->create();
    }

    /**
     * 지정 권한을 붙인 관리자 역할을 가진 회원을 생성합니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 관리자 회원
     */
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

    /**
     * 관리자 토큰을 실은 JSON 요청 헤더를 설정합니다.
     *
     * @return $this 헤더가 설정된 테스트 케이스
     */
    private function authRequest()
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    /**
     * 부모(base) 레이아웃 생성 — 슬롯 + 초기 상태 키 보유.
     *
     * @param  array<string, mixed>  $initialState  initLocal/initGlobal/initIsolated 키 포함
     * @return TemplateLayout 생성된 부모 레이아웃
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
     * @param  array<string, mixed>  $initialState  자식이 가진 초기 상태 섹션
     * @return TemplateLayout 생성된 자식 레이아웃
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
     * @param  TemplateLayout  $child  저장할 자식 레이아웃
     * @param  array<string, mixed>  $childInitialState  PUT 본문에 실을 초기 상태 섹션
     * @return array<string, mixed> 저장 뒤 DB 의 자식 content
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

    /**
     * 자식 초기 상태만 영속하고 미덮은 부모 키는 제외한다.
     *
     * @scenario case=child_only
     *
     * @effects child_init_local_only_persisted
     *
     * @return void 반환값 없음
     */
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

    /**
     * 덮은 상속 키는 자식 값으로 영속한다.
     *
     * @scenario case=override
     *
     * @effects overridden_inherited_key_persisted
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_persists_overridden_inherited_key_with_child_value(): void
    {
        // ② 상속 키 덮은 경우 그 키는 자식 값으로 PUT(덮기 영속)
        $this->makeParent(['initLocal' => ['perPage' => 20]]);
        $child = $this->makeChild(['initLocal' => ['perPage' => 50]]);

        $stored = $this->putChild($child, ['initLocal' => ['perPage' => 50]]);

        $this->assertSame(50, $stored['initLocal']['perPage'], '덮은 상속 키는 자식 값으로 영속');
    }

    /**
     * legacy `state` 를 `initLocal` 로 이관해 저장하면 `state` 키가 사라진다.
     *
     * @scenario case=legacy_state
     *
     * @effects legacy_state_migrated_to_init_local
     *
     * @return void 반환값 없음
     */
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

    /**
     * 세 초기 상태 섹션의 하위 키가 validated() 에서 탈락하지 않는다.
     *
     * @scenario case=nested_subkeys
     *
     * @effects nested_init_subkeys_preserved
     *
     * @return void 반환값 없음
     */
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

    /**
     * 자식을 저장해도 부모 레이아웃 행은 바뀌지 않는다.
     *
     * @scenario case=parent_untouched
     *
     * @effects parent_row_unchanged
     *
     * @return void 반환값 없음
     */
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

    /**
     * JSON 객체 본문의 빈 문자열 초기값이 그대로 저장된다 (#758 — 요청 원문 재해석).
     *
     * @scenario case=empty_string
     *
     * @effects empty_string_initial_value_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function it_documents_empty_string_initial_value_is_kept_on_save(): void
    {
        // 코어 동작 가드 — 저장 요청의 레이아웃 본문은 요청 원문에서 다시 읽는다(#758, 빈 객체 `{}` 보존).
        // 그래서 JSON 객체 본문도 Laravel 글로벌 미들웨어 ConvertEmptyStringsToNull 의 변환을 거치지 않고
        // 빈 텍스트 초기값 `''` 이 그대로 저장된다. 위지윅 편집기의 저장(JSON 문자열 본문)은 원래부터 `''` 를
        // 지켰으므로 두 본문 형태가 같아졌다. 런타임 `_local` 주입에서 null 과 '' 는 동등(빈 값)하게
        // 취급되므로 폼 자동바인딩에 영향이 없다.
        $this->makeParent([]);
        $child = $this->makeChild([]);

        $stored = $this->putChild($child, ['initLocal' => ['keyword' => '', 'tag' => 'x']]);

        $this->assertSame('', $stored['initLocal']['keyword'], '빈 문자열 초기값은 그대로 저장된다(JSON 문자열 본문과 같다)');
        $this->assertSame('x', $stored['initLocal']['tag'], '비-빈 값은 그대로 보존');
    }

    /**
     * 재로드 때 부모·자식이 다시 병합되고 편집 원본에는 자식분만 남는다.
     *
     * @scenario case=reload_merge
     *
     * @effects parent_child_remerged_on_reload
     *
     * @return void 반환값 없음
     */
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
