<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\Template\TemplateSettingsSchema;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 템플릿 설정 `menu` 칸 — 사용자 화면 메뉴(2단) 선언·저장 검증·정규화·화면 서술 (#434)
 *
 * 편집기는 끌어서 놓은 트리를 그대로 보내므로, 서버가 구조(자동 항목 1회·깊이·전체 수·id 중복·종류별 필수)를 다시 보고
 * 오류를 그 항목 위치(`header.menu.{i}` · `header.menu.{i}.children.{j}`)에 단다.
 */
class TemplateMenuFieldTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 테스트가 만든 픽스처를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanUpTemplateSettingsFixtures();

        parent::tearDown();
    }

    /**
     * 메뉴 칸을 가진 선언.
     *
     * @param  array<string, mixed>  $field  메뉴 칸 덮어쓸 값
     * @return array<string, mixed> 선언
     */
    private function menuDeclaration(array $field = []): array
    {
        $declaration = $this->defaultDeclaration();
        $declaration['_meta']['categories'][] = 'header';
        $declaration['schema']['header'] = [
            'menu' => array_merge([
                'type' => 'menu',
                'max_items' => 6,
                'max_depth' => 2,
                'label' => '헤더 메뉴',
                'auto_items' => [
                    'home' => ['label' => ['ko' => '홈', 'en' => 'Home']],
                    'boards' => ['label' => ['ko' => '게시판 전체', 'en' => 'All boards'], 'hint' => ['ko' => '자동']],
                ],
            ], $field),
        ];
        $declaration['defaults']['header'] = ['menu' => [['id' => 'home', 'kind' => 'home']]];

        return $declaration;
    }

    /**
     * 링크 항목 하나를 만듭니다(기본값 위에 덮어쓸 값만 준다).
     *
     * @param  string  $id  항목 id
     * @param  array<string, mixed>  $extra  덮어쓸 값
     * @return array<string, mixed> 링크 항목
     */
    private function link(string $id, array $extra = []): array
    {
        return array_merge(['id' => $id, 'kind' => 'link', 'label' => ['ko' => $id], 'url' => '/'.$id, 'new_tab' => false, 'is_active' => true, 'visibility' => 'all', 'roles' => [], 'children' => []], $extra);
    }

    /**
     * 메뉴·목록 칸 항목의 규칙 오류 문구는 내부 키(`header.menu.5.url`)가 아니라 칸 이름·1부터 센 자리·항목 이름으로 말한다
     *. 하위 메뉴는 상위 자리와 하위 자리를 함께, 목록 칸 행은 자리를 함께 말한다.
     *
     * @scenario case=menu_api
     *
     * @effects menu_item_errors_name_position_and_label
     *
     * @return void 반환값 없음
     */
    public function test_menu_item_rule_errors_use_field_label_position_and_item_name(): void
    {
        $declaration = $this->menuDeclaration();
        $declaration['schema']['header']['links'] = [
            'type' => 'list',
            'max_items' => 5,
            'label' => '바로가기',
            'item_fields' => ['url' => ['type' => 'link', 'label' => '주소', 'required' => true]],
        ];
        $declaration['defaults']['header']['links'] = [];
        $this->makeSettingsTemplate('qa434-menu', declaration: $declaration);
        $admin = $this->makeAdmin();
        app()->setLocale('ko');

        $errors = $this->actingAs($admin)->putJson('/api/admin/templates/qa434-menu/settings', ['header' => ['menu' => [
            $this->link('a'),
            $this->link('b', ['label' => ['ko' => 'qa 메뉴'], 'url' => 'javascript:alert(1)']),
            $this->link('p', ['url' => '', 'children' => [$this->link('c1'), $this->link('c2', ['label' => ['ko' => '하위'], 'url' => 'javascript:x'])]]),
        ], 'links' => [['url' => '/ok'], ['url' => 'javascript:y']]]])->assertStatus(422)->json('errors');

        $top = $errors['header.menu.1.url'][0] ?? '';
        $this->assertStringNotContainsString('header.menu', $top);
        $this->assertStringContainsString('헤더 메뉴', $top);
        $this->assertStringContainsString('2번째', $top);
        $this->assertStringContainsString('qa 메뉴', $top);
        $this->assertStringContainsString('이동할 주소', $top);

        $child = $errors['header.menu.2.children.1.url'][0] ?? '';
        $this->assertStringNotContainsString('header.menu', $child);
        $this->assertStringContainsString('3번째', $child);
        $this->assertStringContainsString('2번째', $child);
        $this->assertStringContainsString('하위', $child);

        $row = $errors['header.links.1.url'][0] ?? '';
        $this->assertStringNotContainsString('header.links', $row);
        $this->assertStringContainsString('바로가기', $row);
        $this->assertStringContainsString('2번째', $row);
        $this->assertStringContainsString('주소', $row);
    }

    /**
     * 2단 트리를 저장하면 알려진 키만 남기고 정리한다 — 자동 항목의 주소·새 창은 버리고, 역할은 「특정 역할」일 때만 남는다.
     *
     * @scenario case=menu_api
     *
     * @effects menu_saved_normalized
     *
     * @return void 반환값 없음
     */
    public function test_menu_saves_two_level_tree_normalized(): void
    {
        Role::create(['identifier' => 'qa434_vip', 'name' => ['ko' => 'VIP', 'en' => 'VIP'], 'description' => ['ko' => '', 'en' => ''], 'is_active' => true]);
        $this->makeSettingsTemplate('qa434-menu', declaration: $this->menuDeclaration());

        $menu = [
            ['id' => 'home', 'kind' => 'home', 'url' => '/ignored', 'new_tab' => true, 'extra' => 'x'],
            $this->link('community', ['url' => '', 'children' => [
                $this->link('free', ['visibility' => 'members', 'roles' => ['qa434_vip']]),
                ['id' => 'boards', 'kind' => 'boards', 'label' => []],
            ]]),
            $this->link('vip', ['visibility' => 'roles', 'roles' => ['qa434_vip'], 'new_tab' => '1']),
        ];

        $response = $this->actingAs($this->makeAdmin())->putJson('/api/admin/templates/qa434-menu/settings', ['header' => ['menu' => $menu]])->assertOk();

        $saved = $response->json('data.settings.header.menu');
        $this->assertSame(['id', 'kind', 'label', 'is_active', 'visibility', 'roles', 'children'], array_keys($saved[0]));
        $this->assertSame('', $saved[1]['url']);
        $this->assertSame(['free', 'boards'], array_column($saved[1]['children'], 'id'));
        $this->assertSame([], $saved[1]['children'][0]['roles'], '회원만이면 역할 목록은 비운다');
        $this->assertSame(['qa434_vip'], $saved[2]['roles']);
        $this->assertTrue($saved[2]['new_tab']);
    }

    /**
     * 구조 위반은 그 항목 위치에 오류가 달린다.
     *
     * @scenario case=menu_api
     *
     * @effects menu_structure_validated
     *
     * @return void 반환값 없음
     */
    public function test_menu_rejects_structure_errors_at_item_paths(): void
    {
        $this->makeSettingsTemplate('qa434-menu', declaration: $this->menuDeclaration());
        $admin = $this->makeAdmin();
        $put = fn (array $menu) => $this->actingAs($admin)->putJson('/api/admin/templates/qa434-menu/settings', ['header' => ['menu' => $menu]]);
        $auto = fn (string $id, string $kind, array $extra = []) => array_merge(['id' => $id, 'kind' => $kind], $extra);

        // 자동 항목 두 번 → 두 번째 자리
        $put([$auto('h1', 'home'), $auto('h2', 'home')])->assertStatus(422)->assertJsonValidationErrors(['header.menu.1'], 'errors');
        // 자동 항목 아래 하위 메뉴
        $put([$auto('b', 'boards', ['children' => [$this->link('x')]])])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
        // 3단
        $put([$this->link('a', ['url' => '', 'children' => [$this->link('b', ['url' => '', 'children' => [$this->link('c')]])]])])
            ->assertStatus(422)->assertJsonValidationErrors(['header.menu.0.children.0'], 'errors');
        // 하위 없는 링크의 빈 주소 · 빈 이름
        $put([$this->link('a', ['url' => ''])])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
        $put([$this->link('a', ['label' => ['ko' => ' ']])])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
        // 위험한 주소 · 모르는 종류 · id 중복 · 형식
        $put([$this->link('a', ['url' => 'javascript:alert(1)'])])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0.url'], 'errors');
        $put([$auto('z', 'shop')])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
        $put([$this->link('dup'), $this->link('dup')])->assertStatus(422)->assertJsonValidationErrors(['header.menu.1'], 'errors');
        $put([$this->link('bad id!')])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
        // 특정 역할인데 역할 없음 · 없는 역할
        $put([$this->link('a', ['visibility' => 'roles', 'roles' => []])])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
        $put([$this->link('a', ['visibility' => 'roles', 'roles' => ['qa434_none']])])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0.roles.0'], 'errors');
        $put([$this->link('a', ['visibility' => 'everyone'])])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
        // 하위 메뉴 포함 전체 수 상한(6)
        $put([$this->link('p', ['url' => '', 'children' => array_map(fn ($i) => $this->link("c{$i}"), range(1, 6))])])
            ->assertStatus(422)->assertJsonValidationErrors(['header.menu'], 'errors');

        // 같은 요청 안에서 정상 값은 통과
        $put([$auto('h', 'home'), $this->link('p', ['url' => '', 'children' => [$this->link('c1'), $auto('b', 'boards')]])])->assertOk();
    }

    /**
     * 1단으로 선언한 메뉴는 하위 메뉴를 받지 않는다.
     *
     * @scenario case=menu_api
     *
     * @effects menu_structure_validated
     *
     * @return void 반환값 없음
     */
    public function test_single_level_menu_rejects_children(): void
    {
        $this->makeSettingsTemplate('qa434-menu', declaration: $this->menuDeclaration(['max_depth' => 1]));

        $this->actingAs($this->makeAdmin())->putJson('/api/admin/templates/qa434-menu/settings', ['header' => ['menu' => [
            $this->link('p', ['url' => '', 'children' => [$this->link('c')]]),
        ]]])->assertStatus(422)->assertJsonValidationErrors(['header.menu.0'], 'errors');
    }

    /**
     * 화면 서술은 편집기가 쓸 자동 항목(현재 언어 이름)·깊이·전체 수를 싣는다.
     *
     * @scenario case=menu_api
     *
     * @effects menu_descriptor_for_editor
     *
     * @return void 반환값 없음
     */
    public function test_menu_descriptor_lists_auto_items_depth_and_limit(): void
    {
        $this->makeSettingsTemplate('qa434-menu', declaration: $this->menuDeclaration());
        $admin = $this->makeAdmin();
        $admin->forceFill(['language' => 'en'])->save();

        $sections = $this->actingAs($admin->fresh())->getJson('/api/admin/templates/qa434-menu/settings')->assertOk()->json('data.sections');
        $header = collect($sections)->firstWhere('key', 'header');
        $field = collect($header['fields'])->firstWhere('key', 'menu');

        $this->assertSame('menu', $field['type']);
        $this->assertSame(2, $field['max_depth']);
        $this->assertSame(6, $field['max_items']);
        $this->assertSame([['key' => 'home', 'label' => 'Home', 'hint' => null], ['key' => 'boards', 'label' => 'All boards', 'hint' => '자동']], $field['auto_items']);
    }

    /**
     * 선언 검사 — 깊이 1~2, 전체 수 1~100, 자동 항목은 객체이고 `link` 는 예약, 기본값도 구조 규칙을 지켜야 한다.
     *
     * @scenario case=menu_api
     *
     * @effects menu_declaration_validated
     *
     * @return void 반환값 없음
     */
    public function test_declaration_rules_for_menu_field(): void
    {
        $errors = fn (array $field, ?array $default = null) => TemplateSettingsSchema::validate(tap($this->menuDeclaration($field), function (&$d) use ($default) {
            if ($default !== null) {
                $d['defaults']['header']['menu'] = $default;
            }
        }));

        $this->assertSame([], $errors([]));
        $this->assertContains('schema.header.menu.max_depth: 1~2', $errors(['max_depth' => 3]));
        $this->assertContains('schema.header.menu.max_items: 1~100', $errors(['max_items' => 500]));
        $this->assertContains('schema.header.menu.auto_items.link', $errors(['auto_items' => ['link' => ['label' => 'x']]]));
        $this->assertContains('schema.header.menu.auto_items: object', $errors(['auto_items' => ['home']]));
        $this->assertContains('defaults.header.menu: type', $errors([], [['id' => 'h', 'kind' => 'home'], ['id' => 'h2', 'kind' => 'home']]));
    }

    /**
     * 번들 사용자 템플릿 선언 — 헤더 메뉴 기본값이 지금 헤더 순서(홈·인기글·쇼핑·게시판 전체)이고 규약을 지킨다.
     *
     * @scenario case=menu_api
     *
     * @effects menu_declaration_validated
     *
     * @return void 반환값 없음
     */
    public function test_bundled_basic_template_menu_default_matches_current_header(): void
    {
        $declaration = json_decode((string) file_get_contents(base_path('templates/_bundled/sirsoft-basic/config/settings/defaults.json')), true);

        $this->assertSame([], TemplateSettingsSchema::validate($declaration));
        $this->assertSame(['home', 'popular', 'shop', 'boards'], array_column($declaration['defaults']['header']['menu'], 'kind'));
        $this->assertSame('menu', $declaration['schema']['header']['menu']['type']);
        $this->assertArrayNotHasKey('links', $declaration['schema']['header']);
    }
}
