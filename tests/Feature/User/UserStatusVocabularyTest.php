<?php

namespace Tests\Feature\User;

use App\Enums\UserStatus;
use App\Support\LayoutMarkup\LayoutCorpus;
use Tests\TestCase;

/**
 * 회원 상태 어휘가 Enum 과 화면에서 어긋나지 않는지 봉인합니다.
 *
 * `UserStatus` 에 상태가 하나 더해질 때마다 **서버는 자동으로 전량을 받는데**
 * (`Rule::in(UserStatus::values())`) 화면은 손으로 적은 목록을 그대로 쓴다. 그 비대칭이
 * 실제로 세 자리에서 동시에 났다 — 회원 수정 폼의 상태 select 에 `dormant` 가 없어 휴면
 * 회원을 열면 칸이 비었고, 사용자 마이페이지의 상태 뱃지는 4갈래 삼항식이라 `info`(가입대기)가
 * 노랗게 떨어졌으며, 회원 상세는 서버가 내려주는 `dormant_at` 을 그리지 않았다.
 *
 * 그래서 이 테스트는 **모집단을 저장소에서 파생**한다. 상태 목록은 `UserStatus::cases()` 에서,
 * 뱃지를 그리는 레이아웃은 `LayoutCorpus`(왕복·이관 게이트와 같은 SSoT)에서 가져온다 —
 * 여기에 파일 목록을 손으로 적으면 다음 화면이 조용히 빠진다. 그것이 이번에 빠진 방식이다.
 */
class UserStatusVocabularyTest extends TestCase
{
    /**
     * 회원 상태 뱃지를 그리는 레이아웃 최소 개수.
     *
     * `assertNotEmpty` 만으로는 정규식이 좁아져 3 → 0 이 되어도 green 이다 — 모집단이 비면
     * red 가 되도록 하한을 둔다. 화면이 느는 것은 정상이므로 상한은 두지 않는다.
     */
    private const BADGE_LAYOUT_MINIMUM = 3;

    /**
     * status prop 을 리터럴 유니온으로 선언한 컴포넌트 최소 개수.
     *
     * 하한이 없으면 정규식이 좁아져 모집단이 0 이 되어도 foreach 가 돌지 않아 green 이다.
     * 실측 2건(`UserInfo`·`Avatar`)이며 늘어나는 것은 정상이므로 상한은 두지 않는다.
     */
    private const STATUS_UNION_SOURCE_MINIMUM = 2;

    /**
     * 로그인 성공 응답을 소비하는 레이아웃 최소 개수.
     *
     * 실측 2건(사용자 로그인 폼 · 관리자 로그인). 정규식이 좁아져 0 이 되면 foreach 가 돌지
     * 않아 green 이 되므로 하한을 둔다.
     */
    private const LOGIN_SURFACE_MINIMUM = 2;

    /**
     * 회원 수정 폼 상태 select 가 Enum 전량을 담는지 확인합니다.
     *
     * 수정 모드는 서버 검증과 같은 강도여야 한다 — 화면만 좁히면 API 로는 되고 화면으로는 안 되는
     * 비대칭이 생기고, 선택된 값이 옵션에 없어 칸이 빈 채로 렌더된다.
     *
     * @scenario surface=admin_user_form
     *
     * @effects admin_user_form_status_select_covers_every_user_status
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_form_status_select_covers_every_user_status(): void
    {
        $path = base_path('templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.g7.html');

        $this->assertFileExists($path, '회원 수정 폼 레이아웃이 없습니다 — 경로가 바뀌었다면 이 단언도 따라가야 합니다.');

        $markup = (string) file_get_contents($path);
        $missing = [];

        foreach (UserStatus::cases() as $status) {
            if (! str_contains($markup, "value: '{$status->value}'")) {
                $missing[] = $status->value;
            }
        }

        $this->assertSame(
            [],
            $missing,
            '회원 수정 폼의 상태 목록에 빠진 상태가 있습니다: '.implode(', ', $missing)
            .' — 서버는 `Rule::in(UserStatus::values())` 로 전량을 받으므로 그 상태의 회원을 열면 select 가 빈 값으로 렌더됩니다.',
        );
    }

    /**
     * 회원 상태 뱃지를 그리는 모든 레이아웃이 variant 전량을 덮는지 확인합니다.
     *
     * 모집단은 코퍼스에서 파생한다 — 계획서가 지목한 관리자 2곳만 고치고 닫았다가 사용자
     * 마이페이지에서 같은 삼항식이 다시 나왔다. 어느 한 자리에서 variant 하나가 빠지면 그 상태는
     * 그 화면에서만 다른 색으로 떨어지고, 아무 검사도 그것을 말해 주지 않았다.
     *
     * @scenario surface=badge_layouts
     *
     * @effects every_user_status_badge_layout_maps_all_variants
     *
     * @return void 반환값 없음
     */
    public function test_every_user_status_badge_layout_maps_all_variants(): void
    {
        $variants = array_values(array_unique(
            array_map(static fn (UserStatus $status): string => $status->variant(), UserStatus::cases())
        ));

        $this->assertNotEmpty($variants, 'UserStatus 가 variant 를 하나도 내지 않습니다 — 모집단이 비었습니다.');

        $layouts = $this->badgeLayouts();

        $this->assertGreaterThanOrEqual(
            self::BADGE_LAYOUT_MINIMUM,
            count($layouts),
            '회원 상태 뱃지를 그리는 레이아웃 모집단이 줄었습니다 (기대 최소 '.self::BADGE_LAYOUT_MINIMUM
            .', 실측 '.count($layouts).') — 스캔이 좁아졌거나 화면이 사라졌습니다.',
        );

        $failures = [];

        foreach ($layouts as $path => $markup) {
            $absent = array_values(array_filter(
                $variants,
                // class-map 저작 문법(`<variant when="success">…</variant>`)도 손으로 적은 매핑이다.
                static fn (string $variant): bool => ! str_contains($markup, $variant.':')
                    && ! str_contains($markup, "'".$variant."'")
                    && ! str_contains($markup, 'when="'.$variant.'"'),
            ));

            if ($absent !== []) {
                $failures[] = $this->relative($path).' → '.implode(', ', $absent);
            }
        }

        $this->assertSame(
            [],
            $failures,
            "회원 상태 뱃지가 일부 variant 를 다루지 않습니다 — 그 상태는 이 화면에서만 다른 색으로 떨어집니다:\n"
            .implode("\n", $failures),
        );
    }

    /**
     * 회원 상세가 서버에서 내려오는 휴면 전환 시각을 실제로 그리는지 확인합니다.
     *
     * `UserResource` 가 `dormant_at` 을 싣는데 화면이 그리지 않으면 API 에만 있는 값이 되고,
     * 운영자는 자동 탈퇴까지 남은 기간을 셀 수 없다.
     *
     * @scenario surface=admin_user_detail
     *
     * @effects admin_user_detail_renders_dormant_at
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_renders_dormant_at(): void
    {
        $path = base_path('templates/_bundled/sirsoft-admin_basic/layouts/admin_user_detail.g7.html');

        $this->assertFileExists($path);
        $this->assertStringContainsString(
            'dormant_at',
            (string) file_get_contents($path),
            '회원 상세가 휴면 전환 시각을 그리지 않습니다 — `UserResource` 는 내려보내고 있어 API 에만 남습니다.',
        );
    }

    /**
     * 템플릿 컴포넌트의 status prop 유니온이 Enum 전량을 담는지 확인합니다.
     *
     * 레이아웃(`.g7.html`)만 보면 이 축이 통째로 빠진다 — `UserInfo`·`Avatar` 는 TSX 라
     * `LayoutCorpus` 의 스캔 밖이고, 실제로 `Dormant` 가 더해진 뒤에도 두 파일은 4갈래로
     * 남아 있었다. 유니온이 좁으면 템플릿 개발자가 `status="dormant"` 를 넘길 때 타입이 거부한다.
     *
     * @scenario surface=template_component_status_union
     *
     * @effects template_component_status_union_covers_every_user_status
     *
     * @return void 반환값 없음
     */
    public function test_template_component_status_union_covers_every_user_status(): void
    {
        $unions = $this->statusPropUnions();

        $this->assertGreaterThanOrEqual(
            self::STATUS_UNION_SOURCE_MINIMUM,
            count($unions),
            '상태 유니온을 선언한 컴포넌트를 찾지 못했습니다 — 정규식이 좁아졌거나 파일이 옮겨졌습니다.',
        );

        foreach ($unions as $path => $members) {
            foreach (UserStatus::cases() as $case) {
                $this->assertContains(
                    $case->value,
                    $members,
                    sprintf(
                        '%s 의 status 유니온에 `%s` 가 없습니다 — 서버는 `Rule::in(UserStatus::values())` 로 전량을 받습니다.',
                        $this->relative($path),
                        $case->value,
                    ),
                );
            }
        }
    }

    /**
     * status prop 을 문자열 리터럴 유니온으로 선언한 번들 컴포넌트를 파생합니다.
     *
     * `status?: string` 처럼 열거하지 않는 선언은 어휘를 고정하지 않으므로 대상이 아니다 —
     * 리터럴을 둘 이상 나열한 것만 "이 화면이 상태 어휘를 손으로 적었다" 는 뜻이다.
     *
     * @return array<string, list<string>> 절대경로 => 유니온 멤버 목록
     */
    private function statusPropUnions(): array
    {
        $found = [];

        foreach (glob(base_path('templates/_bundled/*/src'), GLOB_ONLYDIR) ?: [] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                    continue;
                }

                if (str_contains(str_replace('\\', '/', $file->getPathname()), '/__tests__/')) {
                    continue;
                }

                $matched = preg_match(
                    "/status\?:\s*('[a-z_]+'(?:\s*\|\s*'[a-z_]+')+)\s*;/",
                    (string) file_get_contents($file->getPathname()),
                    $m,
                );

                if ($matched === 1) {
                    preg_match_all("/'([a-z_]+)'/", $m[1], $members);
                    $found[$file->getPathname()] = $members[1];
                }
            }
        }

        return $found;
    }

    /**
     * 회원 상태 뱃지를 그리는 번들 레이아웃을 코퍼스에서 파생합니다.
     *
     * `order_status_variant` 처럼 접두사가 붙은 것은 다른 도메인 값이므로 제외한다 —
     * 앞 문자가 `_` 인 경우를 부정 후방탐색으로 걸러낸다.
     *
     * 컴포넌트에 변형 값을 그대로 넘기는 자리(`<badge variant="{{…status_variant…}}">` ·
     * `<status-badge status="{{…status_variant…}}">`)도 뺀다 — 색 매핑을 컴포넌트가 소유해 화면이 손으로 적은
     * 목록이 없다(컴포넌트 쪽 상태 합집합은 {@see test_template_component_status_union_covers_every_user_status} 가 본다).
     * 클레임·주문 화면이 도메인 상태 변형을 이 형태로 넘긴다(develop-7.1.0 16차 병합에서 합류).
     *
     * @return array<string, string> 절대경로 => 원문
     */
    private function badgeLayouts(): array
    {
        $found = [];

        foreach (LayoutCorpus::scan('.g7.html') as $path) {
            $markup = (string) file_get_contents($path);

            $handwritten = (string) preg_replace('/\b(?:variant|status)="\{\{[^"]*?(?<!_)status_variant[^"]*?\}\}"/', '', $markup);

            if (preg_match('/(?<!_)status_variant/', $handwritten) === 1) {
                $found[$path] = $markup;
            }
        }

        return $found;
    }

    /**
     * 휴면 해제 안내가 **로그인 화면 전량**에 있는지 확인합니다.
     *
     * 서버는 화면을 가리지 않는다 — `AuthService::issueLoginSession()` 이 비밀번호·2단계 인증
     * 두 경로의 유일한 발급 지점이고, 거기서 해제되면 `dormancy_restored` 가 **관리자 로그인
     * 응답에도 그대로 실린다**. 관리자 계정은 휴면 전환 대상에서 제외되지 않는다(제외되는 것은
     * 자동 탈퇴뿐이다). 그런데 안내 토스트는 사용자 템플릿에만 있어, 휴면이 된 관리자는 자기
     * 계정이 잠겼다 풀린 사실을 끝내 모른 채 되돌아갔다.
     *
     * 그래서 모집단을 손으로 적지 않고 **`<login>` 을 쓰는 레이아웃 전량**에서 파생한다 —
     * 로그인 화면이 하나 더 생기면 이 검사도 함께 넓어진다.
     *
     * @scenario surface=login_dormancy_notice
     *
     * @effects every_login_surface_announces_the_dormancy_release
     *
     * @return void 반환값 없음
     */
    public function test_every_login_surface_announces_the_dormancy_release(): void
    {
        $surfaces = $this->loginSurfaces();

        $this->assertGreaterThanOrEqual(
            self::LOGIN_SURFACE_MINIMUM,
            count($surfaces),
            '로그인 응답을 소비하는 레이아웃을 찾지 못했습니다 — 정규식이 좁아졌거나 파일이 옮겨졌습니다.',
        );

        foreach ($surfaces as $path => $markup) {
            $this->assertStringContainsString(
                'response.dormancy_restored',
                $markup,
                sprintf(
                    '%s 가 휴면 해제 안내를 그리지 않습니다 — 그 화면으로 돌아온 회원은 계정이 잠겼다 풀린 사실을 모릅니다.',
                    $this->relative($path),
                ),
            );
        }
    }

    /**
     * 로그인 성공 응답을 소비하는 레이아웃을 파생합니다.
     *
     * `<login ...>` 엘리먼트를 쓰는 것만 고른다 — 그것이 "이 화면이 로그인 응답을 직접 받는다"
     * 는 뜻이다. 링크만 거는 화면은 응답을 못 보므로 대상이 아니다.
     *
     * @return array<string, string> 절대경로 => 마크업
     */
    private function loginSurfaces(): array
    {
        $found = [];

        foreach (LayoutCorpus::scan('.g7.html') as $path) {
            $markup = (string) file_get_contents($path);

            if (preg_match('/<login\s/', $markup) === 1) {
                $found[$path] = $markup;
            }
        }

        return $found;
    }

    /**
     * 실패 메시지에 쓸 저장소 상대경로로 바꿉니다.
     *
     * @param  string  $path  절대 경로
     * @return string 상대 경로
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
