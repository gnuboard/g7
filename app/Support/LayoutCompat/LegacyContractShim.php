<?php

namespace App\Support\LayoutCompat;

use Illuminate\Support\Facades\Log;

/**
 * 7.1.x 화면 계약으로 만든 옛 템플릿을 서빙할 때 7.2.0 계약의 빠진 자리를 채운다 (옛 템플릿 호환 처리).
 *
 * 7.1.x 기본 템플릿을 복사해 쓰는 사이트가 7.2.0 으로 올라와 소셜 로그인·SSO 를 켜면 두 가지가 깨진다.
 * ① 로그인·가입 화면에 단추를 붙일 확장점(`auth_login_extensions` 등)이 없어 단추가 안 그려지고, 외부 인증이
 *    돌려보낸 `?login_handoff=` 를 교환하는 초기화가 없어 로그인이 끝나지 않는다.
 * ② 비밀번호 없는 회원(외부 인증 전용)이 정보 수정 진입 확인·비밀번호 첫 설정에서 막힌다.
 *
 * 원본 레이아웃(파일·DB)은 바꾸지 않고, 서빙 트리에만 7.2.0 기본 템플릿과 같은 자리를 끼운다.
 * 규칙마다 판별(그 계약이 이미 있으면 아무것도 하지 않는다)과 앵커(옛 화면의 구조로 찾는 폼)가 있다.
 * 앵커를 못 찾으면 넣지 않고(오늘과 같은 화면) 경고 로그를 한 번 남긴다 — 크게 고친 복사 템플릿의 한계다.
 *
 * 템플릿이 이 보완을 원하지 않으면 그 확장점을 직접 두면 된다(예: 빈 `<extension-point name="auth_login_extensions" data-if="{{false}}"/>`).
 * 편집 모드(`withSourceMeta`) 응답에는 적용하지 않는다 — 저장할 때 심 노드가 원본에 섞이지 않게.
 */
final class LegacyContractShim
{
    /**
     * 심이 채우는 확장점 → 그 확장점이 사는 화면(레이아웃 이름).
     *
     * 확장 조각 등록 판정({@see \App\Services\LayoutExtensionService::isExtensionApplicableToTemplate()})이
     * 이 표를 본다 — 옛 템플릿에는 확장점이 없으므로, 호스트 화면이 있으면 등록해 둬야 서빙 때 심이 끼운 자리에 붙는다.
     *
     * @var array<string, string>
     */
    public const EXTENSION_POINT_HOSTS = [
        'auth_login_extensions' => 'auth/login',
        'auth_register_extensions' => 'auth/register',
        'admin_login_extensions' => 'admin_login',
        'mypage_reauth_methods' => 'mypage/profile-edit',
        'mypage_change_password_reauth' => 'mypage/change-password',
    ];

    /**
     * 비밀번호 없는 회원 판정식 — 7.2.0 기본 템플릿과 같은 식(화면 데이터 소스 `user` 가 없으면 로그인 회원 값).
     */
    private const HAS_PASSWORD = '(user?.data?.has_password ?? _global.currentUser?.has_password)';

    /**
     * 메일을 받을 수 있는 회원인지 — 7.2.0 기본 템플릿과 같은 식.
     */
    private const EMAIL_DELIVERABLE = '(user?.data?.email_deliverable ?? _global.currentUser?.email_deliverable)';

    /**
     * 같은 (템플릿, 화면, 규칙) 경고를 요청마다 되풀이하지 않기 위한 기록.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    /**
     * 서빙 레이아웃에 빠진 7.2.0 계약을 채웁니다.
     *
     * @param  array<string, mixed>  $layout  병합된 레이아웃(`components`·`initActions`)
     * @param  int  $templateId  템플릿 ID (경고 로그용)
     * @return array<string, mixed> 보완한 레이아웃 (해당 없음이면 그대로)
     */
    public function apply(array $layout, int $templateId): array
    {
        $layoutName = (string) ($layout['layout_name'] ?? '');
        if (! is_array($layout['components'] ?? null)) {
            return $layout;
        }

        return match ($layoutName) {
            'auth/login' => $this->applyLogin($layout, $templateId, 'auth_login_extensions', '/login'),
            'admin_login' => $this->applyLogin($layout, $templateId, 'admin_login_extensions', '/admin/login'),
            'auth/register' => $this->applyRegister($layout, $templateId),
            'mypage/profile-edit' => $this->applyProfileReauth($layout, $templateId),
            'mypage/change-password' => $this->applyChangePassword($layout, $templateId),
            default => $layout,
        };
    }

    /**
     * 로그인 화면 — 로그인 폼 뒤에 확장점, 초기화에 로그인 인계 교환.
     *
     * 인계 교환의 응답 분기는 그 템플릿 로그인 폼의 `login` 분기를 그대로 빌린다 — 2단계 인증·비밀번호 변경·휴면 안내를
     * 템플릿이 아는 상태 이름으로 처리해야 그 화면이 이어서 그린다.
     *
     * @param  array<string, mixed>  $layout  레이아웃
     * @param  int  $templateId  템플릿 ID
     * @param  string  $extensionPoint  확장점 이름
     * @param  string  $loginPath  로그인 화면 주소(인계 코드를 주소에서 지울 때)
     * @return array<string, mixed> 보완한 레이아웃
     */
    private function applyLogin(array $layout, int $templateId, string $extensionPoint, string $loginPath): array
    {
        $formPath = $this->findPath($layout['components'], fn (array $node) => $this->isForm($node) && $this->findAction($node['actions'] ?? [], fn (array $a) => ($a['handler'] ?? null) === 'login') !== null);
        if ($formPath === null) {
            $this->warnOnce($templateId, $layout['layout_name'], 'login_form');

            return $layout;
        }

        if (! $this->hasExtensionPoint($layout, $extensionPoint)) {
            $layout['components'] = $this->insertAt($layout['components'], $formPath, 1, [
                'id' => $extensionPoint.'_slot',
                'type' => 'extension_point',
                'name' => $extensionPoint,
            ]);
        }

        if (! $this->usesHandler($layout, 'loginHandoff')) {
            $form = $this->nodeAt($layout['components'], $formPath);
            $handoff = $this->buildLoginHandoff($form['actions'] ?? [], $loginPath);
            if ($handoff !== null) {
                $initActions = is_array($layout['initActions'] ?? null) ? $layout['initActions'] : [];
                $initActions[] = $handoff;
                $layout['initActions'] = $initActions;
            }
        }

        return $layout;
    }

    /**
     * 가입 화면 — 가입 폼 앞에 확장점(7.2.0 기본 템플릿과 같은 자리).
     *
     * @param  array<string, mixed>  $layout  레이아웃
     * @param  int  $templateId  템플릿 ID
     * @return array<string, mixed> 보완한 레이아웃
     */
    private function applyRegister(array $layout, int $templateId): array
    {
        if ($this->hasExtensionPoint($layout, 'auth_register_extensions')) {
            return $layout;
        }

        $formPath = $this->findPath($layout['components'], fn (array $node) => $this->isForm($node)
            && (($node['dataKey'] ?? null) === 'registerForm' || ($node['props']['id'] ?? null) === 'registerForm'
                || $this->findAction($node['actions'] ?? [], fn (array $a) => ($a['handler'] ?? null) === 'register' || $this->callsApi($a, '/api/auth/register')) !== null));
        if ($formPath === null) {
            $this->warnOnce($templateId, $layout['layout_name'], 'register_form');

            return $layout;
        }

        $layout['components'] = $this->insertAt($layout['components'], $formPath, 0, [
            'id' => 'auth_register_extensions_slot',
            'type' => 'extension_point',
            'name' => 'auth_register_extensions',
        ]);

        return $layout;
    }

    /**
     * 정보 수정 진입 확인 — 비밀번호 없는 회원에게 옛 비밀번호 확인 폼 대신 「본인 확인 계속하기」와 재확인 수단 확장점을 보인다.
     *
     * 「본인 확인 계속하기」는 빈 본문으로 확인 요청을 보낸다. 서버가 비밀번호 없는 회원에게 본인 확인(428)을 요구하면
     * 엔진이 인증 창을 열고 확인 토큰을 실어 같은 요청을 다시 보낸다. 외부 수단(회사 계정·소셜 다시 로그인)으로 돌아온
     * `?verification_token=` 은 초기화에서 교환한다.
     *
     * @param  array<string, mixed>  $layout  레이아웃
     * @param  int  $templateId  템플릿 ID
     * @return array<string, mixed> 보완한 레이아웃
     */
    private function applyProfileReauth(array $layout, int $templateId): array
    {
        if ($this->hasExtensionPoint($layout, 'mypage_reauth_methods')) {
            return $layout;
        }

        $formPath = $this->findPath($layout['components'], fn (array $node) => $this->isForm($node)
            && $this->findAction($node['actions'] ?? [], fn (array $a) => $this->callsApi($a, '/api/me/verify-password')) !== null);
        if ($formPath === null) {
            $this->warnOnce($templateId, $layout['layout_name'], 'verify_password_form');

            return $layout;
        }

        $layout['components'] = $this->updateAt($layout['components'], $formPath, fn (array $form) => $this->andIf($form, self::HAS_PASSWORD.' !== false'));
        $layout['components'] = $this->insertAt($layout['components'], $formPath, 1, $this->profileReauthBlock());

        if (! str_contains((string) json_encode($layout['initActions'] ?? []), 'verification_token')) {
            $initActions = is_array($layout['initActions'] ?? null) ? $layout['initActions'] : [];
            $initActions[] = [
                'handler' => 'sequence',
                'if' => '{{!!query?.verification_token}}',
                'actions' => [
                    [
                        'handler' => 'apiCall',
                        'target' => '/api/me/verify-password',
                        'auth_required' => true,
                        'params' => ['method' => 'POST', 'body' => ['verification_token' => '{{query.verification_token}}']],
                        'onSuccess' => [['handler' => 'setState', 'params' => ['target' => 'local', 'isPasswordVerified' => true]]],
                        'onError' => [['handler' => 'setState', 'params' => ['target' => 'local', 'compatReauthError' => '{{error.message}}']]],
                    ],
                    ['handler' => 'replaceUrl', 'params' => ['path' => '/mypage/profile/edit']],
                ],
            ];
            $layout['initActions'] = $initActions;
        }

        return $layout;
    }

    /**
     * 비밀번호 변경 — 비밀번호 없는 회원에게는 현재 비밀번호 칸을 숨기고(처음 정하는 것), 폼 앞에 안내와 재확인 수단 확장점을 둔다.
     *
     * @param  array<string, mixed>  $layout  레이아웃
     * @param  int  $templateId  템플릿 ID
     * @return array<string, mixed> 보완한 레이아웃
     */
    private function applyChangePassword(array $layout, int $templateId): array
    {
        if ($this->hasExtensionPoint($layout, 'mypage_change_password_reauth')) {
            return $layout;
        }

        $formPath = $this->findPath($layout['components'], fn (array $node) => $this->isForm($node)
            && $this->findAction($node['actions'] ?? [], fn (array $a) => $this->callsApi($a, '/api/me/password')) !== null);
        if ($formPath === null) {
            $this->warnOnce($templateId, $layout['layout_name'], 'change_password_form');

            return $layout;
        }

        $layout['components'] = $this->updateAt($layout['components'], $formPath, function (array $form): array {
            foreach ($form['children'] ?? [] as $i => $child) {
                if (is_array($child) && $this->findPath([$child], fn (array $n) => ($n['props']['name'] ?? null) === 'current_password') !== null) {
                    $form['children'][$i] = $this->andIf($child, self::HAS_PASSWORD.' !== false');
                }
            }

            return $form;
        });

        $layout['components'] = $this->insertAt($layout['components'], $formPath, 0, [
            'type' => 'basic',
            'name' => 'Div',
            'id' => 'compat_password_first_time_section',
            'if' => '{{'.self::HAS_PASSWORD.' === false && !_local?.success}}',
            'props' => ['className' => 'mb-6 space-y-3'],
            'children' => [
                $this->notice('$t:core.compat.change_password.first_time_notice', 'blue'),
                [
                    'type' => 'basic',
                    'name' => 'Div',
                    'if' => '{{'.self::EMAIL_DELIVERABLE.' === false}}',
                    'children' => [[
                        'id' => 'mypage_change_password_reauth_slot',
                        'type' => 'extension_point',
                        'name' => 'mypage_change_password_reauth',
                        'default' => [$this->notice('$t:core.compat.change_password.reauth_unavailable', 'yellow')],
                    ]],
                ],
            ],
        ]);

        return $layout;
    }

    /**
     * 비밀번호 없는 회원의 정보 수정 진입 확인 블록.
     *
     * @return array<string, mixed> 노드
     */
    private function profileReauthBlock(): array
    {
        return [
            'type' => 'basic',
            'name' => 'Div',
            'id' => 'compat_profile_reauth_section',
            'if' => '{{'.self::HAS_PASSWORD.' === false}}',
            'props' => ['className' => 'max-w-md mx-auto space-y-3'],
            'children' => [
                $this->notice('$t:core.compat.reauth.no_password_notice', 'blue'),
                [
                    'type' => 'basic',
                    'name' => 'Button',
                    'id' => 'compat_profile_reauth_continue',
                    'props' => [
                        'type' => 'button',
                        'className' => 'w-full px-4 py-3 bg-primary-600 hover:bg-primary-700 text-white rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer',
                        'disabled' => '{{_local.isVerifyingPassword ?? false}}',
                    ],
                    'text' => '$t:core.compat.reauth.continue',
                    'actions' => [[
                        'type' => 'click',
                        'handler' => 'sequence',
                        'actions' => [
                            ['handler' => 'setState', 'params' => ['target' => 'local', 'isVerifyingPassword' => true, 'compatReauthError' => null]],
                            [
                                'handler' => 'apiCall',
                                'target' => '/api/me/verify-password',
                                'auth_required' => true,
                                'params' => ['method' => 'POST', 'body' => (object) []],
                                'onSuccess' => [['handler' => 'setState', 'params' => ['target' => 'local', 'isVerifyingPassword' => false, 'isPasswordVerified' => true]]],
                                'onError' => [['handler' => 'setState', 'params' => ['target' => 'local', 'isVerifyingPassword' => false, 'compatReauthError' => '{{error.message}}']]],
                            ],
                        ],
                    ]],
                ],
                [
                    'type' => 'basic',
                    'name' => 'P',
                    'if' => '{{!!_local.compatReauthError}}',
                    'props' => ['className' => 'text-sm text-red-600 dark:text-red-400', 'role' => 'alert'],
                    'text' => '{{_local.compatReauthError}}',
                ],
                [
                    'id' => 'mypage_reauth_methods_slot',
                    'type' => 'extension_point',
                    'name' => 'mypage_reauth_methods',
                ],
            ],
        ];
    }

    /**
     * 안내 문단 노드.
     *
     * @param  string  $text  문구(다국어 키)
     * @param  string  $tone  색(blue·yellow)
     * @return array<string, mixed> 노드
     */
    private function notice(string $text, string $tone): array
    {
        $classes = $tone === 'yellow'
            ? 'p-3 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 text-sm text-yellow-800 dark:text-yellow-300'
            : 'p-3 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 text-sm text-blue-700 dark:text-blue-300';

        return ['type' => 'basic', 'name' => 'P', 'props' => ['className' => $classes, 'role' => 'status'], 'text' => $text];
    }

    /**
     * 로그인 폼의 `login` 분기를 빌려 인계 교환 초기화를 만듭니다.
     *
     * 같은 순서(앞선 진행 표지 setState → 교환)로 두고, 교환 응답 분기는 `login` 의 onSuccess·onError 를 그대로 쓴다.
     * 인계 코드는 한 번만 통하므로 2단계 인증·비밀번호 변경 단계로 남거나 실패하면 주소에서 지운다(새로고침해도 다시 교환하지 않게).
     *
     * @param  mixed  $formActions  로그인 폼의 actions
     * @param  string  $loginPath  로그인 화면 주소
     * @return array<string, mixed>|null 초기화 액션 (login 분기를 못 찾으면 null)
     */
    private function buildLoginHandoff(mixed $formActions, string $loginPath): ?array
    {
        $found = $this->findActionWithSiblings($formActions, fn (array $a) => ($a['handler'] ?? null) === 'login');
        if ($found === null) {
            return null;
        }

        [$login, $before] = $found;
        $leading = array_values(array_filter($before, fn ($a) => is_array($a) && ($a['handler'] ?? null) === 'setState'));
        $stripUrl = fn (?string $if) => array_filter([
            'handler' => 'replaceUrl',
            'if' => $if,
            'params' => ['path' => $loginPath, 'query' => ['redirect' => '{{query.redirect || undefined}}']],
        ], fn ($v) => $v !== null);

        return [
            'handler' => 'sequence',
            'if' => '{{!!query?.login_handoff}}',
            'actions' => [
                ...$leading,
                [
                    'handler' => 'loginHandoff',
                    'target' => $login['target'] ?? 'user',
                    'params' => ['body' => ['code' => '{{query.login_handoff}}']],
                    'onSuccess' => [
                        $stripUrl('{{response.two_factor_required || response.password_change_required}}'),
                        ...$this->actionList($login['onSuccess'] ?? []),
                    ],
                    'onError' => [
                        ...$this->actionList($login['onError'] ?? []),
                        $stripUrl(null),
                    ],
                ],
            ],
        ];
    }

    /**
     * 단일 액션·목록 어느 쪽이든 목록으로.
     *
     * @param  mixed  $actions  액션 또는 목록
     * @return list<array<string, mixed>> 액션 목록
     */
    private function actionList(mixed $actions): array
    {
        if (! is_array($actions) || $actions === []) {
            return [];
        }

        return array_is_list($actions) ? array_values(array_filter($actions, 'is_array')) : [$actions];
    }

    /**
     * 액션 트리에서 조건에 맞는 액션을 찾습니다 (sequence·parallel·conditions·onSuccess·onError 안까지).
     *
     * @param  mixed  $actions  액션 또는 목록
     * @param  callable(array<string, mixed>): bool  $match  판별
     * @return array<string, mixed>|null 찾은 액션
     */
    private function findAction(mixed $actions, callable $match): ?array
    {
        $found = $this->findActionWithSiblings($actions, $match);

        return $found === null ? null : $found[0];
    }

    /**
     * 액션과, 같은 목록에서 그 앞에 있던 형제 액션들을 찾습니다.
     *
     * @param  mixed  $actions  액션 또는 목록
     * @param  callable(array<string, mixed>): bool  $match  판별
     * @return array{0: array<string, mixed>, 1: list<mixed>}|null [액션, 앞선 형제]
     */
    private function findActionWithSiblings(mixed $actions, callable $match): ?array
    {
        $list = $this->actionList($actions);
        foreach ($list as $i => $action) {
            if ($match($action)) {
                return [$action, array_slice($list, 0, $i)];
            }
            foreach (['actions', 'onSuccess', 'onError', 'then', 'else'] as $key) {
                $nested = $action[$key] ?? ($action['params'][$key] ?? null);
                if ($nested !== null && ($found = $this->findActionWithSiblings($nested, $match)) !== null) {
                    return $found;
                }
            }
            foreach ($action['conditions'] ?? ($action['params']['conditions'] ?? []) as $branch) {
                if (is_array($branch) && ($found = $this->findActionWithSiblings($branch['then'] ?? ($branch['actions'] ?? []), $match)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * apiCall 이 그 주소를 부르는지.
     *
     * @param  array<string, mixed>  $action  액션
     * @param  string  $path  API 경로
     * @return bool 부르면 true
     */
    private function callsApi(array $action, string $path): bool
    {
        return ($action['handler'] ?? null) === 'apiCall' && is_string($action['target'] ?? null) && str_contains($action['target'], $path);
    }

    /**
     * 폼 노드인지.
     *
     * @param  array<string, mixed>  $node  노드
     * @return bool 폼이면 true
     */
    private function isForm(array $node): bool
    {
        return ($node['name'] ?? null) === 'Form';
    }

    /**
     * 레이아웃(본문·모달)에 그 확장점이 있는지.
     *
     * @param  array<string, mixed>  $layout  레이아웃
     * @param  string  $name  확장점 이름
     * @return bool 있으면 true
     */
    private function hasExtensionPoint(array $layout, string $name): bool
    {
        $match = fn (array $node) => ($node['type'] ?? null) === 'extension_point' && ($node['name'] ?? null) === $name;

        return $this->findPath($layout['components'] ?? [], $match) !== null
            || (is_array($layout['modals'] ?? null) && $this->findPath($layout['modals'], $match) !== null);
    }

    /**
     * 레이아웃 어디에서든(초기화·본문·모달·이름 붙은 액션) 그 핸들러를 쓰는지.
     *
     * @param  array<string, mixed>  $layout  레이아웃
     * @param  string  $handler  핸들러 이름
     * @return bool 쓰면 true
     */
    private function usesHandler(array $layout, string $handler): bool
    {
        return str_contains((string) json_encode($layout, JSON_UNESCAPED_UNICODE), '"handler":"'.$handler.'"');
    }

    /**
     * 조건에 맞는 첫 노드의 경로(children 색인 목록)를 찾습니다 — 깊이 우선.
     *
     * @param  array<int|string, mixed>  $nodes  노드 목록
     * @param  callable(array<string, mixed>): bool  $match  판별
     * @param  list<int|string>  $prefix  지금까지의 경로
     * @return list<int|string>|null 경로
     */
    private function findPath(array $nodes, callable $match, array $prefix = []): ?array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            if ($match($node)) {
                return [...$prefix, $i];
            }
            if (is_array($node['children'] ?? null) && ($found = $this->findPath($node['children'], $match, [...$prefix, $i])) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 경로의 노드.
     *
     * @param  array<int|string, mixed>  $nodes  최상위 노드 목록
     * @param  list<int|string>  $path  경로
     * @return array<string, mixed> 노드
     */
    private function nodeAt(array $nodes, array $path): array
    {
        $node = ['children' => $nodes];
        foreach ($path as $index) {
            $node = $node['children'][$index];
        }

        return $node;
    }

    /**
     * 경로의 노드를 바꿉니다.
     *
     * @param  array<int|string, mixed>  $nodes  노드 목록
     * @param  list<int|string>  $path  경로
     * @param  callable(array<string, mixed>): array<string, mixed>  $update  바꾸기
     * @return array<int|string, mixed> 바뀐 노드 목록
     */
    private function updateAt(array $nodes, array $path, callable $update): array
    {
        $index = array_shift($path);
        $nodes[$index] = $path === []
            ? $update($nodes[$index])
            : array_merge($nodes[$index], ['children' => $this->updateAt($nodes[$index]['children'], $path, $update)]);

        return $nodes;
    }

    /**
     * 경로의 노드 앞(0)·뒤(1)에 형제 노드를 끼웁니다.
     *
     * @param  array<int|string, mixed>  $nodes  노드 목록
     * @param  list<int|string>  $path  기준 노드 경로
     * @param  int  $offset  0 이면 앞, 1 이면 뒤
     * @param  array<string, mixed>  $sibling  끼울 노드
     * @return array<int|string, mixed> 바뀐 노드 목록
     */
    private function insertAt(array $nodes, array $path, int $offset, array $sibling): array
    {
        $index = array_shift($path);
        if ($path !== []) {
            $nodes[$index]['children'] = $this->insertAt($nodes[$index]['children'], $path, $offset, $sibling);

            return $nodes;
        }

        $list = array_values($nodes);
        $position = array_search($index, array_keys($nodes), true);
        array_splice($list, (int) $position + $offset, 0, [$sibling]);

        return $list;
    }

    /**
     * 노드의 표시 조건에 조건을 AND 로 더합니다.
     *
     * @param  array<string, mixed>  $node  노드
     * @param  string  $condition  더할 식(중괄호 없이)
     * @return array<string, mixed> 바뀐 노드
     */
    private function andIf(array $node, string $condition): array
    {
        $existing = $node['if'] ?? null;
        if (is_string($existing) && preg_match('/^\{\{(.*)\}\}$/s', trim($existing), $m)) {
            $node['if'] = '{{('.$m[1].') && ('.$condition.')}}';
        } else {
            $node['if'] = '{{'.$condition.'}}';
        }

        return $node;
    }

    /**
     * 앵커를 못 찾았을 때 (템플릿, 화면, 규칙)마다 한 번 경고합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  화면
     * @param  string  $anchor  못 찾은 앵커
     * @return void 반환값 없음
     */
    private function warnOnce(int $templateId, string $layoutName, string $anchor): void
    {
        $key = $templateId.'|'.$layoutName.'|'.$anchor;
        if (isset(self::$warned[$key])) {
            return;
        }
        self::$warned[$key] = true;

        Log::warning('옛 템플릿 호환 보완을 건너뜀 — 화면에서 기준 폼을 찾지 못했습니다', [
            'template_id' => $templateId,
            'layout' => $layoutName,
            'anchor' => $anchor,
        ]);
    }
}
