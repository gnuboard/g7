<?php

namespace Tests\Feature\LayoutMarkup;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use App\Support\LayoutMarkup\LayoutMarkupRoundTrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 서빙 동등성(계획서 ③) — `.g7.html` 설치와 JSON 설치의 **최종 서빙 표면**이 같은지 본다.
 *
 * ② 설치 등가성은 `template_layouts.content` 까지만 본다. 그 뒤로 서빙에는 고유 변환이 더 있다 —
 * `extends` 병합(부모 components 의 slot 자리에 자식 slots 주입) · 확장 fragment 주입 ·
 * 개발자 주석 strip. 종전에는 "② 가 content 동일을 증명하니 서빙도 같다" 는 **논리적 파생**으로
 * 충족 처리해 왔는데(S11 ㉓ 선례), 그 논증은 서빙 변환이 content 이외의 것에 민감하지 않다는
 * 전제를 검사하지 않는다 — 예컨대 `extends` 체인은 `layout_name` 으로 부모를 찾고, 주입은
 * 대상 레이아웃 이름으로 붙는다.
 *
 * 그래서 두 설치 산출을 각각 DB 에 심고 **실제 서빙 파이프라인**(`LayoutService::getLayout`)을
 * 통과시켜 결과를 대조한다.
 */
class MarkupServingEquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private object $harness;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');

        $this->harness = new class
        {
            use ValidatesLayoutFiles;

            /**
             * @return array<int, array{data: array<string, mixed>, layout_name: string}>
             */
            public function run(string $path, string $id, string $type): array
            {
                return $this->validateLayoutFiles($path, $id, $type, true);
            }
        };

        $this->tmp = base_path('storage/framework/testing/serving_eq_'.uniqid());
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);

        parent::tearDown();
    }

    /**
     * 이관 실물 확장의 레이아웃 루트.
     *
     * 넷째 원소는 **부모 레이아웃 루트**다. 모듈 레이아웃은 자기 확장이 아니라 **호스트 템플릿**의
     * 베이스(`_admin_base`·`_user_base`)를 `extends` 하므로, 모듈 레이아웃만 심으면 서빙이
     * `Parent layout not found` 로 죽는다 — 즉 **extends 병합이 한 번도 실행되지 않은 채** 게이트가
     * 도는 것이라, 이 데이터셋에서 ③ 의 실효가 사라진다. 부모는 두 arm 에 **동일한 행**으로 들어가므로
     * (한 번만 설치해 양쪽에 심는다) 자식의 차이를 가리지 않는다.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>}>
     */
    public static function migratedRoots(): array
    {
        return [
            'sirsoft-admin_basic (template)' => [
                'templates/_bundled/sirsoft-admin_basic/layouts',
                'sirsoft-admin_basic',
                'template',
                [],
            ],
            'sirsoft-basic (template)' => [
                'templates/_bundled/sirsoft-basic/layouts',
                'sirsoft-basic',
                'template',
                [],
            ],
            'gnuboard7-hello_admin_template (template)' => [
                'templates/_bundled/gnuboard7-hello_admin_template/layouts',
                'gnuboard7-hello_admin_template',
                'template',
                [],
            ],
            // W5 — 모듈 설치 경로(ModuleManager). 자기 완결적인 템플릿 데이터셋과 달리 부모가
            // 확장 밖(호스트 템플릿)에 있으므로 admin/user 베이스를 함께 심는다.
            'sirsoft-board (module)' => [
                'modules/_bundled/sirsoft-board/resources/layouts',
                'sirsoft-board',
                'module',
                [
                    'templates/_bundled/sirsoft-admin_basic/layouts',
                    'templates/_bundled/sirsoft-basic/layouts',
                ],
            ],
            // W6 — 같은 이유로 호스트 베이스를 함께 심는다(모듈 설치 경로).
            'sirsoft-ecommerce (module)' => [
                'modules/_bundled/sirsoft-ecommerce/resources/layouts',
                'sirsoft-ecommerce',
                'module',
                [
                    'templates/_bundled/sirsoft-admin_basic/layouts',
                    'templates/_bundled/sirsoft-basic/layouts',
                ],
            ],
        ];
    }

    /**
     * 이관 실물의 **모든** 레이아웃에서 서빙 결과가 두 설치 소스에서 같아야 한다.
     *
     * 종전에는 루트당 대표 1건(`admin_user_list`)만 대조했다. 그 표본 제한은 **비용 때문이 아니었다** —
     * 무거운 쪽(디렉토리 두 벌 설치)은 어차피 한 번씩 돌고, 남은 것은 등록된 이름마다 `getLayout()` 을
     * 부르는 값싼 반복이다. 대표 1건만 보면 나머지 142건은 왕복(content 등가)까지만 보증되고 **서빙
     * 고유 변환**(extends 병합·fragment 주입·주석 strip)은 검사되지 않은 채 남는다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects migrated_layout_serving_output_equals_json_serving_output, serving_equivalence_seeds_cross_extension_parent_layouts
     */
    #[DataProvider('migratedRoots')]
    public function test_serving_output_is_identical_for_both_install_sources(
        string $relativeRoot,
        string $identifier,
        string $sourceType,
        array $parentRoots = [],
    ): void {
        $layoutsPath = base_path($relativeRoot);

        // 사본도 `.../layouts` 로 끝나야 `partials/…` 루트 폴백이 원본과 같은 기준으로 동작한다.
        $jsonOnly = "{$this->tmp}/json_only/layouts";
        File::copyDirectory($layoutsPath, $jsonOnly);

        foreach (File::allFiles($jsonOnly) as $file) {
            if (str_ends_with(strtolower($file->getFilename()), '.g7.html')) {
                File::delete($file->getPathname());
            }
        }

        $markupLayouts = $this->harness->run($layoutsPath, $identifier, $sourceType);
        $jsonLayouts = $this->harness->run($jsonOnly, $identifier, $sourceType);

        // 등록 집합 자체가 다르면 개별 대조 이전에 잡는다 (② 설치 등가성과 겹치지만, 여기서
        // 어긋난 채로 진행하면 "빠진 레이아웃" 이 대조 대상에서도 빠져 조용히 통과한다).
        $markupNames = array_column($markupLayouts, 'layout_name');
        $jsonNames = array_column($jsonLayouts, 'layout_name');
        sort($markupNames);
        sort($jsonNames);
        $this->assertSame($jsonNames, $markupNames, "[{$identifier}] 두 설치 소스의 등록 레이아웃 집합이 다릅니다.");
        $this->assertNotSame([], $markupNames, "[{$identifier}] 등록 레이아웃이 없습니다 — 대조가 성립하지 않습니다.");

        // 확장 밖 부모(호스트 템플릿 베이스)는 한 번만 설치해 두 arm 에 같은 행으로 넣는다.
        // 루트가 둘 이상이면 이름이 겹칠 수 있다(admin/user 템플릿이 `auth/identity_challenge` 를
        // 각자 갖는다). 한 벌 안에서는 `validateLayoutFiles` 가 이미 이름으로 접으므로, 겹침은
        // **여러 루트를 합치는 이 자리에서만** 생긴다 — 그래서 접는 것도 여기서 한다.
        $parentLayouts = [];

        foreach ($parentRoots as $parentRoot) {
            foreach ($this->harness->run(base_path($parentRoot), $identifier, 'template') as $parentLayout) {
                $parentLayouts[$parentLayout['layout_name']] = $parentLayout;
            }
        }

        // 대조 대상(자식)은 언제나 부모를 이긴다 — 부모는 `extends` 해석용 배경일 뿐이다.
        foreach ($markupNames as $name) {
            unset($parentLayouts[$name]);
        }

        $parentLayouts = array_values($parentLayouts);

        // 서빙은 `extends` 부모를 이름으로 찾으므로 **전량을 심어 둔 상태에서** 이름별로 뽑아야 한다.
        $fromMarkup = $this->serveAll([...$parentLayouts, ...$markupLayouts], $identifier, $markupNames);
        $fromJson = $this->serveAll([...$parentLayouts, ...$jsonLayouts], $identifier, $markupNames);

        foreach ($markupNames as $name) {
            $this->assertNotSame([], $fromMarkup[$name], "[{$identifier}/{$name}] 서빙 결과가 비었습니다 — 대조가 성립하지 않습니다.");
            $this->assertSame(
                $this->canonical($fromJson[$name]),
                $this->canonical($fromMarkup[$name]),
                "[{$identifier}/{$name}] `.g7.html` 설치의 서빙 표면이 JSON 설치와 다릅니다.",
            );
        }
    }

    /**
     * 등록 산출을 DB 에 한 번 심고, 이름 목록 전체를 서빙 파이프라인에 통과시킵니다.
     *
     * @param  array<int, array{data: array<string, mixed>, layout_name: string}>  $layouts  등록 산출
     * @param  string  $identifier  템플릿 식별자
     * @param  array<int, string>  $names  서빙할 레이아웃 이름 목록
     * @return array<string, array<string, mixed>> 이름 → 서빙 결과
     */
    private function serveAll(array $layouts, string $identifier, array $names): array
    {
        // 심기는 **한 번만** 한다. `seed()` 가 직전 소스 잔재를 지우고 다시 심으므로, 이름마다
        // 다시 부르면 매번 전량 delete+insert 가 돌아 143배로 느려지고, 빈 배열을 넘기면 통째로 비워진다.
        $templateId = $this->seedLayouts($layouts, $identifier);
        $served = [];

        foreach ($names as $name) {
            app(LayoutService::class)->clearLayoutCache($templateId, $name);
            $served[$name] = app(LayoutService::class)->getLayout($identifier, $name);
        }

        return $served;
    }

    /**
     * 등록 산출을 DB 에 심습니다 (직전 소스의 잔재는 비운다).
     *
     * @param  array<int, array{data: array<string, mixed>, layout_name: string}>  $layouts  등록 산출
     * @param  string  $identifier  템플릿 식별자
     * @return int 템플릿 id
     */
    private function seedLayouts(array $layouts, string $identifier): int
    {
        $template = Template::updateOrCreate(
            ['identifier' => $identifier],
            [
                'vendor' => explode('-', $identifier)[0],
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'version' => '1.0.0',
                'type' => 'admin',
                'status' => 'active',
            ],
        );

        TemplateLayout::where('template_id', $template->id)->forceDelete();

        foreach ($layouts as $layout) {
            TemplateLayout::create([
                'template_id' => $template->id,
                'name' => $layout['layout_name'],
                'content' => $layout['data'],
                'extends' => $layout['data']['extends'] ?? null,
                'source_type' => 'template',
                'source_identifier' => $identifier,
            ]);
        }

        return $template->id;
    }

    /**
     * 비교용 결정적 문자열 (키 순서 무관 + 문서화된 정규화 적용).
     *
     * `normalize` 는 컴파일러가 **결정적으로 파생·생략**하는 값(노드 `type` 레지스트리 파생,
     * 콜백 단일 원소 배열 → 객체 등)을 양쪽에서 지운다 — 코퍼스 왕복·설치 등가성 게이트와 같은
     * 계약이다. 이 테스트가 재는 것은 그 수렴 목록이 아니라 **서빙 고유 변환**(extends 병합·
     * 확장 fragment 주입·주석 strip)이 두 설치 소스에서 갈리는지다.
     *
     * 정규화 목록에 무엇을 넣을지는 이 테스트가 아니라 `LayoutMarkupRoundTrip` 이 정한다.
     * 실제로 그 목록에 잘못 들어가 있던 Select 옵션 항목은 이관이 shape 를 바꿔놓는 것을
     * 46건 가려주고 있었고, G13 으로 표기를 결정화하면서 목록에서 제거됐다.
     *
     * @param  array<string, mixed>  $layout  서빙 결과
     * @return string 정규화 문자열
     */
    private function canonical(array $layout): string
    {
        // **순서가 중요하다** — `canonicalize`(ksort) 를 마지막에 돌려야 한다. `normalize` 는 키를
        // 옮기면서 **끝에 덧붙이는** 변환을 포함하므로(제어 핸들러의 `params.actions` → top-level
        // `actions`), 정렬을 먼저 하면 그 뒤 덧붙은 키가 정렬 밖에 남아 **순서 차이가 내용 차이로
        // 오검출**된다. 실제로 `_admin_base` 가 그 형태로 걸렸다(키 집합은 같고 위치만 달랐다).
        return LayoutMarkupRoundTrip::encode(
            LayoutMarkupRoundTrip::canonicalize(LayoutMarkupRoundTrip::normalize($layout)),
        );
    }
}
