<?php

namespace Tests\Unit\Fields;

use App\Fields\FieldContext;
use App\Fields\LegacyScreenFields;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 옛 화면 호환의 기준 칸 목록(LegacyScreenFields)이 7.1.x 레이아웃에서 뽑은 칸과 같은지 (#916).
 *
 * 목록은 런타임에 옛 레이아웃을 읽을 수 없어 고정해 두었다. 이 시험이 레이아웃에서 칸을 다시 뽑아 대조한다 — 입력칸 `name` 과
 * 폼 상태 참조(`form.country` 처럼 입력이 아닌 복합 칸)를 모아 코어 항목의 저장 컬럼으로 맞춘다.
 */
class LegacyScreenFieldsTest extends TestCase
{
    /** 7.1.x 마지막 태그 */
    private const TAG = '7.1.6';

    /** 표면 => 7.1.6 태그의 레이아웃 경로 */
    private const TAG_LAYOUTS = [
        FieldContext::SURFACE_SIGNUP => 'templates/_bundled/sirsoft-basic/layouts/partials/auth/_register_form.json',
        FieldContext::SURFACE_SELF_UPDATE => 'templates/_bundled/sirsoft-basic/layouts/partials/mypage/profile/_edit.json',
        FieldContext::SURFACE_ADMIN_CREATE => 'templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.json',
        FieldContext::SURFACE_ADMIN_UPDATE => 'templates/_bundled/sirsoft-admin_basic/layouts/admin_user_form.json',
    ];

    /** 표면 => 저장소의 7.1.x 서빙 고정물 (조각을 편 모양) */
    private const FIXTURES = [
        FieldContext::SURFACE_SIGNUP => 'tests/Fixtures/LegacyLayouts/7.1.3/auth-register.json',
        FieldContext::SURFACE_SELF_UPDATE => 'tests/Fixtures/LegacyLayouts/7.1.3/mypage-profile-edit.json',
    ];

    /**
     * 레이아웃 문자열에서 코어 항목 키를 뽑습니다.
     *
     * @param  string  $layout  레이아웃 JSON 문자열
     * @return array<int, string> 코어 항목 키 (정렬)
     */
    private function coreKeysIn(string $layout): array
    {
        preg_match_all('/"name"\s*:\s*"([a-z_][a-z0-9_]*)"/', $layout, $names);
        preg_match_all('/(?:form|profileEditForm|registerForm)\??\.([a-z_][a-z0-9_]*)/', $layout, $refs);
        $sent = array_unique([...$names[1], ...$refs[1]]);

        $keys = [];
        foreach (config('core.fields') as $declaration) {
            if (($declaration['field_set'] ?? null) !== 'core.user' || ($declaration['storage'] ?? null) !== 'column') {
                continue;
            }
            $columns = explode(',', (string) $declaration['storage_target']);
            if (array_intersect($columns, $sent) !== []) {
                $keys[] = $declaration['key'];
            }
        }
        sort($keys);

        return $keys;
    }

    /**
     * 목록을 정렬해 돌려줍니다.
     *
     * @param  string  $surface  표면
     * @return array<int, string> 정렬한 코어 항목 키
     */
    private function listed(string $surface): array
    {
        $keys = LegacyScreenFields::coreKeysFor($surface) ?? [];
        sort($keys);

        return $keys;
    }

    /**
     * 저장소의 7.1.x 서빙 고정물(가입, 내 정보 수정)에서 뽑은 코어 칸이 목록과 같다.
     *
     * @scenario surface=signup, surface=self_update, source=fixture
     *
     * @effects legacy_core_keys_match_7_1_fixtures
     *
     * @return void 반환값 없음
     */
    public function test_list_matches_7_1_fixtures(): void
    {
        foreach (self::FIXTURES as $surface => $path) {
            $this->assertSame($this->listed($surface), $this->coreKeysIn((string) file_get_contents(base_path($path))), $surface.' 목록이 7.1.x 고정물과 다르다');
        }
    }

    /**
     * 7.1.6 태그의 레이아웃(가입, 내 정보 수정, 관리자 회원 폼)에서 뽑은 코어 칸이 목록과 같다. 태그가 없는 복제본에서는 건너뛴다.
     *
     * @scenario surface=signup, surface=self_update, surface=admin_create, surface=admin_update, source=tag
     *
     * @effects legacy_core_keys_match_7_1_6_tag
     *
     * @return void 반환값 없음
     */
    public function test_list_matches_7_1_6_tag_layouts(): void
    {
        foreach (self::TAG_LAYOUTS as $surface => $path) {
            $process = new Process(['git', 'show', self::TAG.':'.$path], base_path());
            $process->run();
            if (! $process->isSuccessful()) {
                $this->markTestSkipped(self::TAG.' 태그를 읽을 수 없는 복제본이다');
            }

            $this->assertSame($this->listed($surface), $this->coreKeysIn($process->getOutput()), $surface.' 목록이 '.self::TAG.' '.$path.' 와 다르다');
        }
    }

    /**
     * 7.1.x 에 없던 표면(추가 정보 입력 등)은 목록이 없다 — 그 표면에서는 코어 항목을 늦추지 않는다.
     *
     * @scenario surface=other, source=none
     *
     * @effects legacy_core_keys_absent_for_other_surfaces
     *
     * @return void 반환값 없음
     */
    public function test_other_surfaces_have_no_list(): void
    {
        $this->assertNull(LegacyScreenFields::coreKeysFor(FieldContext::SURFACE_REQUIRED_INFO));
        $this->assertNull(LegacyScreenFields::coreKeysFor('vendor_surface'));
    }
}
