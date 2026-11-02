<?php

namespace Tests\Unit\Support\LayoutCompat;

use App\Support\LayoutCompat\LegacyContractShim;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 옛 템플릿 호환 심 — 7.1.3 기본 템플릿 화면(시험 자료)에 7.2.0 계약의 빠진 자리를 채우는지 검증한다.
 *
 * 시험 자료(tests/Fixtures/LegacyLayouts/7.1.3)는 7.1.3 태그 레이아웃의 partial 을 펴서 서빙 병합 모양
 * (`components`·`initActions`)으로 만든 것이다.
 */
class LegacyContractShimTest extends TestCase
{
    /**
     * 시험 자료를 읽습니다.
     *
     * @param  string  $name  파일 이름(확장자 없이)
     * @return array<string, mixed> 레이아웃
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/LegacyLayouts/7.1.3/{$name}.json")), true);
    }

    /**
     * 조건에 맞는 노드와 그 부모 목록·색인을 찾습니다.
     *
     * @param  array<int|string, mixed>  $nodes  노드 목록
     * @param  callable(array<string, mixed>): bool  $match  판별
     * @return array{0: array<int, mixed>, 1: int}|null [부모 목록, 색인]
     */
    private function locate(array $nodes, callable $match): ?array
    {
        $nodes = array_values($nodes);
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            if ($match($node)) {
                return [$nodes, $i];
            }
            if (is_array($node['children'] ?? null) && ($found = $this->locate($node['children'], $match)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 확장점 노드 판별식.
     *
     * @param  string  $name  확장점 이름
     * @return callable(array<string, mixed>): bool 판별
     */
    private function isExtensionPoint(string $name): callable
    {
        return fn (array $n) => ($n['type'] ?? null) === 'extension_point' && ($n['name'] ?? null) === $name;
    }

    /**
     * 레이아웃에서 그 확장점이 몇 번 나오는지.
     *
     * @param  array<string, mixed>  $layout  레이아웃
     * @param  string  $name  확장점 이름
     * @return int 개수
     */
    private function countExtensionPoint(array $layout, string $name): int
    {
        return substr_count((string) json_encode($layout), '"type":"extension_point","name":"'.$name.'"');
    }

    /**
     * 옛 사용자 로그인 화면 — 로그인 폼 바로 뒤에 확장점, 초기화 끝에 로그인 인계 교환이 생기고 응답 분기는 그 템플릿 것을 빌린다.
     *
     * @scenario template_shape=legacy_7_1, screen=user_login
     *
     * @effects login_extension_point_inserted_after_login_form, login_handoff_init_reuses_template_login_branch
     *
     * @return void 반환값 없음
     */
    public function test_user_login_gets_extension_point_and_handoff(): void
    {
        $in = $this->fixture('auth-login');
        $out = (new LegacyContractShim)->apply($in, 1);

        $this->assertSame(1, $this->countExtensionPoint($out, 'auth_login_extensions'));
        [$siblings, $index] = $this->locate($out['components'], $this->isExtensionPoint('auth_login_extensions'));
        $this->assertSame('Form', $siblings[$index - 1]['name'] ?? null, '확장점은 로그인 폼 바로 뒤');

        $handoff = end($out['initActions']);
        $this->assertSame('{{!!query?.login_handoff}}', $handoff['if']);
        $exchange = end($handoff['actions']);
        $this->assertSame('loginHandoff', $exchange['handler']);
        $this->assertSame('user', $exchange['target']);
        $this->assertSame(['code' => '{{query.login_handoff}}'], $exchange['params']['body']);
        $this->assertSame('replaceUrl', $exchange['onSuccess'][0]['handler'], '2단계 인증 단계로 남으면 인계 코드를 주소에서 지운다');
        $this->assertSame('/login', $exchange['onSuccess'][0]['params']['path']);
        $this->assertStringContainsString('"twoFactor"', (string) json_encode($exchange['onSuccess']), '응답 분기는 템플릿 로그인 폼의 login 분기');
        $this->assertSame('replaceUrl', end($exchange['onError'])['handler']);
        $this->assertSame(['setState', 'setState'], array_column(array_slice($handoff['actions'], 0, -1), 'handler'), '진행 표지 setState 를 앞에 둔다');
    }

    /**
     * 옛 관리자 로그인 화면 — 같은 보완을 관리자 대상·주소로 한다.
     *
     * @scenario template_shape=legacy_7_1, screen=admin_login
     *
     * @effects admin_login_extension_point_inserted, admin_login_handoff_targets_admin
     *
     * @return void 반환값 없음
     */
    public function test_admin_login_gets_extension_point_and_handoff(): void
    {
        $out = (new LegacyContractShim)->apply($this->fixture('admin-login'), 1);

        [$siblings, $index] = $this->locate($out['components'], $this->isExtensionPoint('admin_login_extensions'));
        $this->assertSame('login_form_component', $siblings[$index - 1]['id'] ?? null);

        $exchange = end(end($out['initActions'])['actions']);
        $this->assertSame('loginHandoff', $exchange['handler']);
        $this->assertSame('admin', $exchange['target']);
        $this->assertSame('/admin/login', $exchange['onSuccess'][0]['params']['path']);
    }

    /**
     * 옛 가입 화면 — 가입 폼 바로 앞에 확장점(7.2.0 기본 템플릿과 같은 자리).
     *
     * @scenario template_shape=legacy_7_1, screen=register
     *
     * @effects register_extension_point_inserted_before_register_form
     *
     * @return void 반환값 없음
     */
    public function test_register_gets_extension_point_before_form(): void
    {
        $out = (new LegacyContractShim)->apply($this->fixture('auth-register'), 1);

        $this->assertSame(1, $this->countExtensionPoint($out, 'auth_register_extensions'));
        [$siblings, $index] = $this->locate($out['components'], $this->isExtensionPoint('auth_register_extensions'));
        $this->assertSame('registerForm', $siblings[$index + 1]['dataKey'] ?? null);
    }

    /**
     * 옛 정보 수정 진입 확인 — 비밀번호 확인 폼은 비밀번호 있는 회원에게만, 비밀번호 없는 회원에게는 「본인 확인 계속하기」와
     * 재확인 수단 확장점을 보이고, 외부 수단에서 돌아온 확인 토큰을 초기화에서 교환한다.
     *
     * @scenario template_shape=legacy_7_1, screen=profile_reauth
     *
     * @effects verify_form_limited_to_password_members, no_password_reauth_section_inserted, verification_token_init_added
     *
     * @return void 반환값 없음
     */
    public function test_profile_reauth_gets_no_password_section(): void
    {
        $out = (new LegacyContractShim)->apply($this->fixture('mypage-profile-edit'), 1);

        [$siblings, $index] = $this->locate($out['components'], fn (array $n) => ($n['id'] ?? null) === 'compat_profile_reauth_section');
        $form = $siblings[$index - 1];
        $this->assertSame('Form', $form['name']);
        $this->assertStringContainsString('has_password) !== false', $form['if']);
        $this->assertStringContainsString('has_password) === false', $siblings[$index]['if']);

        $section = json_encode($siblings[$index]);
        $this->assertStringContainsString('"type":"extension_point","name":"mypage_reauth_methods"', $section);
        $this->assertStringContainsString('"target":"\/api\/me\/verify-password"', $section);
        $this->assertStringContainsString('$t:core.compat.reauth.continue', $section);

        $init = end($out['initActions']);
        $this->assertSame('{{!!query?.verification_token}}', $init['if']);
        $this->assertSame(['verification_token' => '{{query.verification_token}}'], $init['actions'][0]['params']['body']);
    }

    /**
     * 옛 비밀번호 변경 — 비밀번호 없는 회원에게는 현재 비밀번호 칸을 숨기고, 폼 앞에 안내와 재확인 수단 확장점을 둔다.
     *
     * @scenario template_shape=legacy_7_1, screen=change_password
     *
     * @effects current_password_field_hidden_for_no_password_member, change_password_reauth_extension_point_inserted
     *
     * @return void 반환값 없음
     */
    public function test_change_password_hides_current_password_for_no_password_member(): void
    {
        $out = (new LegacyContractShim)->apply($this->fixture('mypage-change-password'), 1);

        [$siblings, $index] = $this->locate($out['components'], fn (array $n) => ($n['id'] ?? null) === 'compat_password_first_time_section');
        $this->assertSame('Form', $siblings[$index + 1]['name']);
        $this->assertSame(1, $this->countExtensionPoint($out, 'mypage_change_password_reauth'));

        $wrapper = null;
        foreach ($siblings[$index + 1]['children'] as $child) {
            if (is_array($child) && str_contains((string) json_encode($child), '"name":"current_password"')) {
                $wrapper = $child;
            }
        }
        $this->assertNotNull($wrapper);
        $this->assertStringContainsString('has_password) !== false', $wrapper['if']);
    }

    /**
     * 이미 보완한 화면·7.2.0 계약을 가진 화면은 바꾸지 않습니다 (두 번 돌려도 같다).
     *
     * @scenario template_shape=legacy_7_1, screen=user_login
     *
     * @effects shim_is_idempotent
     *
     * @return void 반환값 없음
     */
    public function test_shim_is_idempotent(): void
    {
        $shim = new LegacyContractShim;
        foreach (['auth-login', 'admin-login', 'auth-register', 'mypage-profile-edit', 'mypage-change-password'] as $name) {
            $once = $shim->apply($this->fixture($name), 1);
            $this->assertSame($once, $shim->apply($once, 1), $name);
        }
    }

    /**
     * 확장점과 인계 교환을 이미 가진 화면(7.2.0 계약)은 그대로 둡니다.
     *
     * @scenario template_shape=current_7_2, screen=user_login
     *
     * @effects current_contract_untouched
     *
     * @return void 반환값 없음
     */
    public function test_current_contract_is_untouched(): void
    {
        $layout = [
            'layout_name' => 'auth/login',
            'components' => [
                ['type' => 'basic', 'name' => 'Form', 'actions' => [['type' => 'submit', 'handler' => 'login', 'target' => 'user']]],
                ['type' => 'extension_point', 'name' => 'auth_login_extensions'],
            ],
            'initActions' => [['handler' => 'loginHandoff', 'target' => 'user']],
        ];

        $this->assertSame($layout, (new LegacyContractShim)->apply($layout, 1));
    }

    /**
     * 기준 폼을 못 찾으면 화면을 그대로 두고, 같은 (템플릿, 화면, 앵커) 경고는 한 번만 남깁니다.
     *
     * @scenario template_shape=heavily_customized, screen=user_login
     *
     * @effects missing_anchor_keeps_layout_and_warns_once
     *
     * @return void 반환값 없음
     */
    public function test_missing_anchor_keeps_layout_and_warns_once(): void
    {
        Log::spy();
        $layout = ['layout_name' => 'auth/login', 'components' => [['type' => 'basic', 'name' => 'Div']], 'initActions' => []];
        $shim = new LegacyContractShim;

        $this->assertSame($layout, $shim->apply($layout, 987654));
        $this->assertSame($layout, $shim->apply($layout, 987654));

        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * 심이 다루지 않는 화면은 그대로입니다.
     *
     * @scenario template_shape=legacy_7_1, screen=other
     *
     * @effects other_layouts_untouched
     *
     * @return void 반환값 없음
     */
    public function test_other_layouts_are_untouched(): void
    {
        $layout = ['layout_name' => 'home', 'components' => [['type' => 'basic', 'name' => 'Form', 'actions' => [['handler' => 'login']]]]];

        $this->assertSame($layout, (new LegacyContractShim)->apply($layout, 1));
    }
}
