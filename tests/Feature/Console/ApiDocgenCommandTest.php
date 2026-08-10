<?php

namespace Tests\Feature\Console;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Support\ApiDoc\ApiDocScaffolder;
use App\Support\ApiDoc\ApiRouteInventory;
use App\Support\ApiDoc\FormRequestIntrospector;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * api:docgen 커맨드 통합 동작 Feature 테스트.
 *
 * 계획서(#447) 단계 5 가 명시한 커맨드 5대 동작을 검증한다:
 * (a) 라우트 전수 수집, (b) 확장별 파일 라우팅, (c) FormRequest rules 추출,
 * (d) idempotent 재생성(사람 서술 보존), (e) --check drift 검출.
 *
 * 라이브 실측(HTTP 프로브)에 의존하지 않는 결정론적 경로(--dry-run·정적 추출·
 * 스캐폴더 병합)로 커맨드 표면을 검증한다. 실측 자체는 ApiDocPipelineTest·
 * ApiDocSampleServiceTest 가 별도 커버한다.
 */
class ApiDocgenCommandTest extends TestCase
{
    /**
     * (a) 코어 스코프 실행 시 라우트를 전수 수집해 개수를 보고합니다.
     */
    #[Test]
    public function 코어_스코프는_라우트를_전수_수집한다(): void
    {
        // 인벤토리가 코어 라우트를 수집하는지 직접 확인 (커맨드가 이 결과를 소비).
        $routes = app(ApiRouteInventory::class)->collect('core');

        $this->assertNotEmpty($routes, '코어 API 라우트가 수집되어야 한다.');
        // 대표 코어 라우트(사용자 목록)가 포함되는지 (전수 수집 실증).
        $uris = array_column($routes, 'uri');
        $this->assertContains('/api/admin/users', $uris);

        // --dry-run 실행이 수집 개수를 보고하고 생성 없이 종료한다.
        $this->artisan('api:docgen', ['--scope' => 'core', '--dry-run' => true])
            ->expectsOutputToContain('라우트 수집 (scope=core)')
            ->assertSuccessful();
    }

    /**
     * (b) 확장 소유 라우트는 확장 디렉토리 파일로 라우팅됩니다.
     */
    #[Test]
    public function 확장_라우트는_확장_디렉토리_파일로_라우팅된다(): void
    {
        $inventory = app(ApiRouteInventory::class);

        // 코어 라우트의 소유는 core, 출력 파일은 docs/backend/api 하위여야 한다.
        $coreRoutes = $inventory->collect('core');
        foreach ($coreRoutes as $route) {
            $this->assertSame('core', $route['owner']['type']);
        }

        // 번들 확장 라우트가 있으면 그 소유 type 이 module/plugin 이고 id 가 있어야 한다
        // (targetFile 이 이 owner 로 modules|plugins/_bundled/{id}/docs/api 경로를 만든다).
        $all = $inventory->collect('all');
        $extRoutes = array_filter($all, fn ($r) => $r['owner']['type'] !== 'core');

        if ($extRoutes === []) {
            // 이 환경에 활성 확장이 없으면(라우트 0) 스킵 — core 라우팅 검증만으로 충분.
            $this->markTestSkipped('활성 확장 라우트 없음 — 확장 라우팅 검증 스킵.');
        }

        foreach ($extRoutes as $route) {
            $this->assertContains($route['owner']['type'], ['module', 'plugin']);
            $this->assertNotEmpty($route['owner']['id']);
        }
    }

    /**
     * (c) 컨트롤러 메서드의 FormRequest rules() 를 정적 리플렉션으로 추출합니다.
     */
    #[Test]
    public function form_request_rules를_정적_추출한다(): void
    {
        $introspector = app(FormRequestIntrospector::class);

        // 로그인 컨트롤러는 email/password rules 를 가진 FormRequest 를 받는다.
        $result = $introspector->introspect(
            AuthController::class,
            'login'
        );

        $names = array_column($result['params'], 'name');
        $this->assertContains('email', $names, 'FormRequest rules 의 email 파라미터가 추출되어야 한다.');
        $this->assertContains('password', $names);
    }

    /**
     * (c-2) 중첩 객체 파라미터(`refund_bank.bank_code`)도 추출합니다.
     *
     * 회귀: 배열 요소 규칙(`items.*.id`)을 상위 필드로 대표시키려는 스킵 조건이
     * 점(`.`) 포함 여부만 봐서, 와일드카드가 없는 중첩 **객체** 필드까지 함께 버렸다.
     * 그 결과 코어 환경설정(`general.*`·`mail.*`), 이커머스 주문(`orderer.*`·`refund_bank.*`) 등
     * 17개 엔드포인트의 파라미터 수백 개가 문서에서 통째로 빠졌고, 사람이 수기로 채워 넣은
     * 행은 재생성 때마다 다시 삭제됐다.
     */
    #[Test]
    public function 중첩_객체_파라미터를_추출하고_배열_요소는_상위로_대표시킨다(): void
    {
        $introspector = app(FormRequestIntrospector::class);

        $rules = [
            'payment_method' => 'required|string',
            // 중첩 객체 — 문서에 개별 행으로 노출되어야 한다
            'refund_bank.bank_code' => ['nullable', 'string', 'max:10'],
            'refund_bank.holder' => ['nullable', 'string', 'max:50'],
            // 배열 요소 — 상위(items)만 대표로 노출하고 개별 행은 만들지 않는다
            'items' => 'array',
            'items.*.id' => 'required|integer',
            'items.*.qty' => 'required|integer',
        ];

        $params = $this->invokeRulesToParams($introspector, $rules);
        $names = array_column($params, 'name');

        $this->assertContains('refund_bank.bank_code', $names, '중첩 객체 필드가 추출되어야 한다.');
        $this->assertContains('refund_bank.holder', $names);
        $this->assertContains('payment_method', $names);
        $this->assertContains('items', $names);

        $this->assertNotContains('items.*.id', $names, '배열 요소 규칙은 상위(items)로 대표시킨다.');
        $this->assertNotContains('items.*.qty', $names);

        // 추출된 중첩 필드도 타입/필수 메타를 갖는다
        $bankCode = collect($params)->firstWhere('name', 'refund_bank.bank_code');
        $this->assertSame('string', $bankCode['type']);
        $this->assertFalse($bankCode['required']);
    }

    /**
     * private rulesToParams 를 호출합니다 (규칙 배열 → 파라미터 메타 변환만 검증).
     *
     * @param  FormRequestIntrospector  $introspector  대상 인스턴스
     * @param  array<string, mixed>  $rules  검증 규칙 배열
     * @return array<int, array<string, mixed>> 파라미터 메타데이터 목록
     */
    private function invokeRulesToParams(FormRequestIntrospector $introspector, array $rules): array
    {
        $method = new \ReflectionMethod($introspector, 'rulesToParams');
        $method->setAccessible(true);

        return $method->invoke($introspector, $rules);
    }

    /**
     * (d) 재생성해도 사람이 채운 서술은 보존되고 추출 블록만 갱신됩니다(멱등).
     */
    #[Test]
    public function 재생성은_사람_서술을_보존하는_멱등_동작이다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/me', 'name' => 'api.me.show',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['success', 'data'], 'shape' => 'object', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://api.example.com', 'resolved_uri' => '/api/me', 'body' => ['success' => true, 'data' => ['id' => 1], 'message' => null, 'error' => null]];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);
        $header = "# Me\n";
        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.me.show']);

        // @generated 블록 밖 `**설명**` 슬롯의 TODO 스텁을 사람 서술로 채운다
        // (extractHumanProse 는 기본 TODO 스텁이 남아 있으면 보존 대상 없음으로 판정).
        $human = str_replace(
            '**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->',
            '**설명** 이 엔드포인트는 현재 로그인 사용자를 반환합니다. (사람 서술)',
            $first
        );

        // 재생성 시 사람 서술은 보존되고 생성 블록만 갱신된다.
        $second = $scaffolder->mergeDocument($human, $header, [$section], ['api.me.show']);

        $this->assertStringContainsString('이 엔드포인트는 현재 로그인 사용자를 반환합니다. (사람 서술)', $second);
        $this->assertStringNotContainsString('TODO: 이 엔드포인트의 용도', $second);
        // 다시 병합해도 사람 서술 중복 없이 멱등.
        $third = $scaffolder->mergeDocument($second, $header, [$section], ['api.me.show']);
        $this->assertSame(
            substr_count($second, '(사람 서술)'),
            substr_count($third, '(사람 서술)'),
            '재병합 시 사람 서술이 중복되지 않아야 한다.'
        );
    }

    /**
     * (e) --check 는 대응 문서가 없으면 drift 로 실패 코드를 반환합니다.
     */
    #[Test]
    public function check는_문서_누락을_drift로_검출한다(): void
    {
        // 존재하지 않는 확장 스코프(문서 파일 부재 보장)로 --check 실행.
        // 라우트가 0이면 커맨드는 성공으로 조기 종료하므로, 라우트가 있는 core 로 검증한다.
        // core 문서는 이미 존재하므로(35파일), 임시로 한 파일을 백업/삭제해 누락을 유발한다.
        $target = base_path('docs/backend/api/me.md');
        $backup = null;
        if (File::exists($target)) {
            $backup = File::get($target);
            File::delete($target);
        }

        try {
            // me.md 가 없으면 --check 는 "문서 파일 없음" drift 로 FAILURE 를 반환한다.
            $this->artisan('api:docgen', ['--scope' => 'core', '--check' => true])
                ->assertFailed();
        } finally {
            if ($backup !== null) {
                File::put($target, $backup);
            }
        }
    }

    /**
     * (e-2) --check 는 실측 제외를 drift 로 보지 않습니다.
     *
     * 회귀: `--check` 가 `skipped_reason` 이 있는 엔드포인트(쓰기 메서드·부수효과·미치환 path)를
     * 전부 drift 로 집계해 **항상 FAILURE** 를 반환했다. 실측 제외는 설계상 정상 동작이며
     * (부수효과 쓰기는 의도적으로 호출하지 않는다), 그 자리는 사람이 코드 근거로 채운다.
     * 실측 제외를 결함으로 세면 문서가 완전해도 통과할 수 없어 기준이 무의미해진다.
     *
     * drift 판정 대상은 (1) 문서 파일 부재 (2) 미채움 마커 잔존 두 가지다.
     */
    #[Test]
    public function check는_실측제외를_drift로_보지_않는다(): void
    {
        // 문서가 완비된 확장(미채움 0건)에서 --check 는 성공해야 한다.
        // ckeditor5 는 엔드포인트 2건 모두 실측 제외(unresolved-path-param / side-effectful-write)지만
        // 사람이 응답 필드/예시를 채워 두었으므로 문서 자체는 완전하다.
        $this->artisan('api:docgen', ['--scope' => 'plugin:sirsoft-ckeditor5', '--check' => true])
            ->assertSuccessful();
    }

    /**
     * (e-3) --check 는 미채움 마커가 남아 있으면 drift 로 검출합니다.
     */
    #[Test]
    public function check는_미채움_마커_잔존을_drift로_검출한다(): void
    {
        $target = base_path('plugins/_bundled/sirsoft-ckeditor5/docs/api/upload.md');

        if (! File::exists($target)) {
            $this->markTestSkipped('대상 문서 없음');
        }

        $backup = File::get($target);

        try {
            // 사람이 채운 응답 필드 자리를 미채움 마커로 되돌린다.
            File::put(
                $target,
                $backup."\n<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->\n"
            );

            $this->artisan('api:docgen', ['--scope' => 'plugin:sirsoft-ckeditor5', '--check' => true])
                ->assertFailed();
        } finally {
            File::put($target, $backup);
        }
    }

    /**
     * README 목차 항목/확장 목록 샘플을 반환합니다.
     *
     * @return array{0: array<int, array{domain: string, file: string, count: int}>, 1: array<int, array{id: string, type: string, path: string, docs: int, endpoints: int}>}
     */
    private function readmeFixtures(): array
    {
        $entries = [
            ['domain' => 'users', 'file' => 'users.md', 'count' => 12],
            ['domain' => 'auth', 'file' => 'auth.md', 'count' => 15],
        ];

        $extensions = [
            ['id' => 'sirsoft-page', 'type' => 'module', 'path' => '../../../modules/_bundled/sirsoft-page/docs/api/README.md', 'docs' => 2, 'endpoints' => 17],
            ['id' => 'sirsoft-gdpr', 'type' => 'plugin', 'path' => '../../../plugins/_bundled/sirsoft-gdpr/docs/api/README.md', 'docs' => 4, 'endpoints' => 15],
        ];

        return [$entries, $extensions];
    }

    /**
     * 코어 README 는 도메인 목차와 확장 API 목차를 함께 싣고, 재생성해도 멱등합니다.
     *
     * 최상위 README 의 "API 레퍼런스" 진입점이 이 문서이므로, 확장 API 문서의 발견 경로가
     * 여기 없으면 개발자/AI 가 확장 엔드포인트에 도달하지 못한다.
     */
    #[Test]
    public function 코어_readme는_확장_목차를_함께_싣고_멱등하게_재생성된다(): void
    {
        $scaffolder = new ApiDocScaffolder;
        [$entries, $extensions] = $this->readmeFixtures();

        $first = $scaffolder->readmeIndex('코어', $entries, null, $extensions);

        $this->assertStringContainsString('## 확장 API 레퍼런스', $first);
        $this->assertStringContainsString('`sirsoft-page`', $first);
        $this->assertStringContainsString('`sirsoft-gdpr`', $first);
        $this->assertStringContainsString('**확장 수**: 2 · **엔드포인트 수**: 32', $first);

        $second = $scaffolder->readmeIndex('코어', $entries, $first, $extensions);
        $this->assertSame($first, $second, '동일 입력 재생성은 멱등해야 한다.');
        $this->assertSame(1, substr_count($second, '## 확장 API 레퍼런스'), '확장 목차가 중복 생성되면 안 된다.');
    }

    /**
     * 코어 README 상단의 사람 개요(공통 규약)는 재생성해도 보존됩니다.
     *
     * 개요는 목차 표보다 먼저 읽혀야 하므로 @generated 블록 앞에 둔다. 생성기가 이 구간을
     * 덮어쓰면 인증·응답 봉투·에러 규약 서술이 매 재생성마다 소실된다.
     */
    #[Test]
    public function 코어_readme의_사람_개요는_생성_블록_앞에서_보존된다(): void
    {
        $scaffolder = new ApiDocScaffolder;
        [$entries, $extensions] = $this->readmeFixtures();

        $base = $scaffolder->readmeIndex('코어', $entries, null, $extensions);
        $withOverview = str_replace(
            '<!-- @generated:start:api-readme-index -->',
            "## 공통 규약\n\nBearer 토큰 전용입니다. (사람 개요)\n\n<!-- @generated:start:api-readme-index -->",
            $base
        );

        $regenerated = $scaffolder->readmeIndex('코어', $entries, $withOverview, $extensions);

        $this->assertStringContainsString('Bearer 토큰 전용입니다. (사람 개요)', $regenerated);
        $this->assertSame(1, substr_count($regenerated, '## 공통 규약'), '개요가 중복 삽입되면 안 된다.');
        // 개요는 목차 표보다 앞에 있어야 한다.
        $this->assertLessThan(
            strpos($regenerated, '<!-- @generated:start:api-readme-index -->'),
            strpos($regenerated, '## 공통 규약')
        );

        // 재생성 멱등 (개요 포함).
        $this->assertSame($regenerated, $scaffolder->readmeIndex('코어', $entries, $regenerated, $extensions));
    }

    /**
     * 확장 스코프 실행이라 확장 목록을 넘기지 않아도 기존 확장 목차는 소실되지 않습니다.
     *
     * `--scope=core` 축소 실행이 코어 README 의 확장 표를 지워버리면, 매 실행마다 진입점이
     * 사라졌다 살아나는 drift 가 생긴다.
     */
    #[Test]
    public function 확장_목록_미전달_시_기존_확장_목차를_보존한다(): void
    {
        $scaffolder = new ApiDocScaffolder;
        [$entries, $extensions] = $this->readmeFixtures();

        $withExtensions = $scaffolder->readmeIndex('코어', $entries, null, $extensions);
        $regenerated = $scaffolder->readmeIndex('코어', $entries, $withExtensions, null);

        $this->assertStringContainsString('## 확장 API 레퍼런스', $regenerated);
        $this->assertStringContainsString('`sirsoft-page`', $regenerated);
        $this->assertSame(1, substr_count($regenerated, '## 확장 API 레퍼런스'));
    }

    /**
     * refreshExtensionIndex 는 코어 목차·개요를 건드리지 않고 확장 표만 갱신합니다.
     *
     * 확장 스코프 실행(`--scope=module:...`) 시 그 확장의 엔드포인트 수 변동을 코어 진입점에
     * 반영하는 경로다. 변경이 없으면 null 을 반환해 불필요한 파일 쓰기를 피한다.
     */
    #[Test]
    public function 확장_목차만_in_place_갱신하고_변경_없으면_null을_반환한다(): void
    {
        $scaffolder = new ApiDocScaffolder;
        [$entries, $extensions] = $this->readmeFixtures();

        $existing = $scaffolder->readmeIndex('코어', $entries, null, $extensions);

        $this->assertNull(
            $scaffolder->refreshExtensionIndex($existing, $extensions),
            '동일 확장 목록이면 변경 없음(null)이어야 한다.'
        );

        // 엔드포인트 수가 늘어난 경우 표만 갱신된다.
        $extensions[0]['endpoints'] = 20;
        $updated = $scaffolder->refreshExtensionIndex($existing, $extensions);

        $this->assertNotNull($updated);
        $this->assertStringContainsString('2 / 20', $updated);
        // 코어 목차와 도메인 항목은 그대로 남는다.
        $this->assertStringContainsString('[users.md](users.md)', $updated);
        $this->assertStringContainsString('<!-- @generated:start:api-readme-index -->', $updated);
        $this->assertSame(1, substr_count($updated, '## 확장 API 레퍼런스'));
    }
}
