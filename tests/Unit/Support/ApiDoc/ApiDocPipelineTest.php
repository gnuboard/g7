<?php

namespace Tests\Unit\Support\ApiDoc;

use App\Support\ApiDoc\ApiDocScaffolder;
use App\Support\ApiDoc\ResponseSchemaInferrer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API 문서 생성 파이프라인 단위 테스트.
 *
 * 실측 응답 스키마 추론과 스캐폴딩 병합(사람 서술 보존)의 순수 로직을
 * HTTP 의존 없이 검증한다.
 */
class ApiDocPipelineTest extends TestCase
{
    /**
     * 목록 응답에서 배열 항목 필드와 페이지네이션을 추론한다.
     *
     * @scenario stage=schema_inference
     *
     * @effects response_schema_inferred_from_probe
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 목록_응답에서_배열_항목_필드와_페이지네이션을_추론한다(): void
    {
        $inferrer = new ResponseSchemaInferrer;

        $body = [
            'success' => true,
            'message' => '조회 성공',
            'data' => [
                'data' => [
                    ['id' => 1, 'name' => '홍길동', 'is_active' => true, 'deleted' => null],
                ],
                'pagination' => ['current_page' => 1, 'total' => 10],
            ],
        ];

        $schema = $inferrer->infer($body);

        $this->assertSame('collection', $schema['shape']);
        $this->assertTrue($schema['pagination']);
        $this->assertSame(['success', 'message', 'data'], $schema['envelope']);

        $fields = collect($schema['fields'])->keyBy('name');
        $this->assertSame('integer', $fields['id']['type']);
        $this->assertSame('string', $fields['name']['type']);
        $this->assertSame('boolean', $fields['is_active']['type']);
        $this->assertSame('null', $fields['deleted']['type']);
        $this->assertSame('홍길동', $fields['name']['sample']);
    }

    /**
     * 단건 응답에서 객체 필드를 추론한다.
     *
     * @scenario stage=schema_inference
     *
     * @effects response_schema_inferred_from_probe
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 단건_응답에서_객체_필드를_추론한다(): void
    {
        $inferrer = new ResponseSchemaInferrer;

        $body = [
            'success' => true,
            'data' => ['total' => 155, 'ratio' => 0.5, 'labels' => ['ko' => 91]],
        ];

        $schema = $inferrer->infer($body);

        $this->assertSame('object', $schema['shape']);
        $this->assertFalse($schema['pagination']);

        $fields = collect($schema['fields'])->keyBy('name');
        $this->assertSame('integer', $fields['total']['type']);
        $this->assertSame('number', $fields['ratio']['type']);
        $this->assertSame('object', $fields['labels']['type']);
    }

    /**
     * 표 셀에서 파이프 문자를 이스케이프한다.
     *
     * @scenario stage=schema_inference
     *
     * @effects response_schema_inferred_from_probe
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 표_셀에서_파이프_문자를_이스케이프한다(): void
    {
        $inferrer = new ResponseSchemaInferrer;

        $body = ['success' => true, 'data' => ['note' => 'a|b|c']];
        $schema = $inferrer->infer($body);

        $fields = collect($schema['fields'])->keyBy('name');
        $this->assertStringNotContainsString('|b', str_replace('\\|', '', $fields['note']['sample']));
        $this->assertStringContainsString('\\|', $fields['note']['sample']);
    }

    /**
     * 스캐폴더가 엔드포인트 섹션을 표준 포맷으로 생성한다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects endpoint_section_uses_standard_format
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 스캐폴더가_엔드포인트_섹션을_표준_포맷으로_생성한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET',
            'uri' => '/api/admin/users',
            'name' => 'api.admin.users.index',
            'controller' => 'App\\Http\\Controllers\\Api\\Admin\\UserController',
            'controller_method' => 'index',
            'permission' => 'core.users.read',
            'middleware' => ['auth:sanctum', 'App\\Http\\Middleware\\AdminMiddleware'],
            'path_params' => [],
        ];

        $request = ['request_class' => 'X', 'params' => [
            ['name' => 'page', 'type' => 'integer', 'required' => false, 'allowed' => 'min 1'],
        ], 'hook_filters' => ['core.user.list_validation_rules']];

        $schema = [
            'envelope' => ['success', 'data'],
            'shape' => 'collection',
            'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']],
            'pagination' => true,
        ];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        $this->assertStringContainsString('### GET /api/admin/users', $section);
        $this->assertStringContainsString('@generated:start:api.admin.users.index', $section);
        $this->assertStringContainsString('`auth:sanctum` + `admin` + `permission:core.users.read`', $section);
        $this->assertStringContainsString('| page | query | integer | 아니오 | min 1 |', $section);
        $this->assertStringContainsString('core.user.list_validation_rules', $section);
        $this->assertStringContainsString('| id | integer | `1` |', $section);
        // 에러 응답 표: auth:sanctum→401, admin+permission→403, FormRequest(params/hook)→422
        $this->assertStringContainsString('**에러 응답**', $section);
        $this->assertStringContainsString('| 401 | Unauthenticated |', $section);
        $this->assertStringContainsString('| 403 | Forbidden | 요구 권한(`core.users.read`)이 없는 경우 |', $section);
        $this->assertStringContainsString('| 422 | Unprocessable Entity |', $section);
        $this->assertStringContainsString('@generated:end', $section);
        // 에러 표는 @generated 블록 내부(재생성 대상)여야 한다
        $genStart = strpos($section, '@generated:start');
        $genEnd = strpos($section, '@generated:end');
        $errorPos = strpos($section, '**에러 응답**');
        $this->assertGreaterThan($genStart, $errorPos);
        $this->assertLessThan($genEnd, $errorPos);
    }

    /**
     * 에러 섹션이 라우트 메타에서 대표 상태코드를 추론한다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects representative_errors_follow_route_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 에러_섹션이_라우트_메타에서_대표_상태코드를_추론한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        // path param 존재 + admin + permission + FormRequest → 401/403/422/404 전부
        $route = [
            'method' => 'PUT',
            'uri' => '/api/admin/users/{user}',
            'name' => 'api.admin.users.update',
            'controller' => 'C', 'controller_method' => 'update',
            'permission' => 'core.users.update',
            'middleware' => ['auth:sanctum', 'App\\Http\\Middleware\\AdminMiddleware'],
            'path_params' => ['user'],
        ];
        $request = ['request_class' => 'X', 'params' => [
            ['name' => 'name', 'type' => 'string', 'required' => true, 'allowed' => ''],
        ], 'hook_filters' => []];

        $section = $scaffolder->endpointSection($route, $request, null, ['status' => null, 'skipped_reason' => 'write-method']);

        $this->assertStringContainsString('| 401 | Unauthenticated |', $section);
        $this->assertStringContainsString('| 403 | Forbidden | 요구 권한(`core.users.update`)이 없는 경우 |', $section);
        $this->assertStringContainsString('| 422 | Unprocessable Entity |', $section);
        $this->assertStringContainsString('| 404 | Not Found |', $section);
    }

    /**
     * optional sanctum 공개조회는 401을 유발하지 않는다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects representative_errors_follow_route_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function optional_sanctum_공개조회는_401을_유발하지_않는다(): void
    {
        // optional.sanctum(선택 인증)은 미인증도 허용 → 401 없음.
        // path param 만 있으므로 404 만 노출.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET',
            'uri' => '/api/modules/sirsoft-board/boards/{slug}',
            'name' => 'api.modules.sirsoft-board.boards.show',
            'controller' => 'C', 'controller_method' => 'show',
            'permission' => null,
            'middleware' => ['api', 'optional.sanctum'],
            'path_params' => ['slug'],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        $this->assertStringNotContainsString('| 401 |', $section);
        $this->assertStringNotContainsString('| 403 |', $section);
        $this->assertStringNotContainsString('| 422 |', $section);
        $this->assertStringContainsString('| 404 | Not Found |', $section);
    }

    /**
     * 완전 공개 조회는 대표 에러 없음으로 표기한다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects representative_errors_follow_route_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 완전_공개_조회는_대표_에러_없음으로_표기한다(): void
    {
        // 인증·권한·FormRequest·path param 전무 → 대표 에러 없음.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/locales', 'name' => 'api.locales.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'collection', 'fields' => [['name' => 'code', 'type' => 'string', 'sample' => 'ko']], 'pagination' => false];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        $this->assertStringContainsString('대표 에러 없음', $section);
    }

    /**
     * optional sanctum 라우트는 선택적 인증으로 표기한다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects endpoint_section_uses_standard_format
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function optional_sanctum_라우트는_선택적_인증으로_표기한다(): void
    {
        // optional.sanctum(회원/비회원 모두 접근)을 auth:sanctum(인증 필수)로
        // 오표기하면 공개 API 계약이 왜곡된다(게시판 공개 조회 등). 별도 표기 강제.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET',
            'uri' => '/api/modules/sirsoft-board/boards/{slug}/posts',
            'name' => 'api.modules.sirsoft-board.boards.posts.index',
            'controller' => 'C', 'controller_method' => 'index',
            'permission' => 'user,sirsoft-board.{slug}.posts.read',
            'middleware' => ['api', 'optional.sanctum', 'throttle:600,1'],
            'path_params' => ['slug'],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'collection', 'fields' => [], 'pagination' => true];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        // optional.sanctum 은 선택적 인증으로 표기되고 auth:sanctum 으로 오표기되지 않는다
        $this->assertStringContainsString('optional.sanctum', $section);
        $this->assertStringNotContainsString('`auth:sanctum`', $section);
    }

    /**
     * 컬럼 주석이 있으면 응답 필드 설명으로 채운다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects endpoint_section_uses_standard_format
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 컬럼_주석이_있으면_응답_필드_설명으로_채운다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/users', 'name' => 'api.admin.users.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = [
            'envelope' => ['data'], 'shape' => 'object',
            'fields' => [
                ['name' => 'nickname', 'type' => 'string', 'sample' => 'hong'],
                ['name' => 'unknown_field', 'type' => 'string', 'sample' => 'x'],
            ],
            'pagination' => false,
        ];
        $commentMap = ['nickname' => '닉네임'];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null], $commentMap);

        // 주석 있는 필드는 설명이 채워지고, 없는 필드는 TODO 유지
        $this->assertStringContainsString('| nickname | string | `hong` | 닉네임 |', $section);
        $this->assertStringContainsString('| unknown_field | string | `x` | <!-- TODO: 설명 --> |', $section);
    }

    /**
     * 사람이 쓴 설명에 인라인 코드 파이프가 있어도 잘리지 않고 승계된다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 사람이_쓴_설명에_인라인_코드_파이프가_있어도_잘리지_않고_승계된다(): void
    {
        // 회귀: 표 행을 셀로 나눌 때 인라인 코드(`|`) 안의 파이프까지 구분자로 보면 셀 수가 늘고,
        // "마지막 셀 = 설명" 규칙이 파이프 뒤 조각을 설명으로 승계한다. 그 결과 재생성 한 번에
        // 문장 앞부분이 통째로 사라진다 — 오류도 경고도 없이 문서만 훼손된다.
        // 실제 사례: 스케줄 문서의 command 설명("셸 메타문자(`|`, `;` …)")이 "`, `;` …" 로 잘렸다.
        $scaffolder = new ApiDocScaffolder;

        $humanDescription = '실행할 명령. 셸 메타문자(`|`, `;`, `$`)가 포함되면 거부됩니다';

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/schedules', 'name' => 'api.admin.schedules.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => 'X', 'params' => [
            ['name' => 'command', 'type' => 'string', 'required' => true, 'allowed' => 'max 2000'],
        ], 'hook_filters' => []];

        // 1회차: 생성된 문서의 command 행 설명 셀을 사람이 직접 채운 상태로 만든다
        $first = $scaffolder->endpointSection($route, $request, null, ['status' => null, 'skipped_reason' => 'write-method']);
        $humanRow = '| command | body | string | 예 | max 2000 | '.$humanDescription.' |';
        $existing = "# 문서\n\n".preg_replace(
            '/^\| command \| body \|.*$/m',
            $humanRow,
            $first
        );
        $this->assertStringContainsString($humanRow, $existing, '전제 실패: 사람 설명이 문서에 들어가지 않았다');

        // 2회차: 재생성 → 사람 설명이 원문 그대로 승계되어야 한다
        $regenerated = $scaffolder->endpointSection($route, $request, null, ['status' => null, 'skipped_reason' => 'write-method']);
        $merged = $scaffolder->mergeDocument($existing, '# 문서', [$regenerated], ['api.admin.schedules.store']);

        $this->assertStringContainsString($humanRow, $merged, '인라인 코드 안의 파이프를 셀 구분자로 보아 설명이 잘렸다');
    }

    /**
     * 쓰기 메서드는 응답 필드를 실측 제외로 표기한다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects endpoint_section_uses_standard_format
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 쓰기_메서드는_응답_필드를_실측_제외로_표기한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/users', 'name' => 'api.admin.users.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => 'core.users.create',
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];

        $section = $scaffolder->endpointSection(
            $route,
            ['request_class' => null, 'params' => [], 'hook_filters' => []],
            null,
            ['status' => null, 'skipped_reason' => 'write-method']
        );

        $this->assertStringContainsString('실측 제외: write-method', $section);
    }

    /**
     * 재생성 시 사람이 작성한 설명을 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성_시_사람이_작성한_설명을_보존한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/x', 'name' => 'api.x.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [['name' => 'a', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $header = "# X\n";

        // 최초 생성
        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.x.index']);
        $this->assertStringContainsString('TODO: 이 엔드포인트의 용도', $first);

        // 사람이 설명을 채운 상태
        $withProse = str_replace(
            '**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->',
            '**설명** 실제 사람이 작성한 설명입니다.',
            $first
        );

        // 재생성: 새 섹션으로 병합해도 사람 서술 보존
        $regenerated = $scaffolder->mergeDocument($withProse, $header, [$section], ['api.x.index']);

        $this->assertStringContainsString('실제 사람이 작성한 설명입니다.', $regenerated);
        $this->assertStringNotContainsString('TODO: 이 엔드포인트의 용도', $regenerated);
    }

    /**
     * 재생성 시 파라미터 표의 사람 용도 셀을 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성_시_파라미터_표의_사람_용도_셀을_보존한다(): void
    {
        // 회귀: 전체 재생성이 @generated 블록 안 파라미터 표를 통째로 재조립하면서
        // 정적 추출로 재현 불가능한 도메인 서술(`용도` 셀)이 TODO 로 되돌아가던 문제.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/y', 'name' => 'api.y.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        // `memo` 는 ParameterDescriber 가 설명할 수 없는 도메인 특이 파라미터 → TODO 스텁이 된다.
        $request = [
            'request_class' => null,
            'params' => [['name' => 'memo', 'location' => 'body', 'type' => 'string', 'required' => false, 'allowed' => '', 'rules' => []]],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $header = "# Y\n";

        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.y.store']);
        $this->assertStringContainsString('<!-- TODO: 용도 -->', $first);

        // 사람이 `용도` 셀을 채운다 (마지막 열만 치환).
        $withCell = preg_replace(
            '/^\| memo \|(.*)\| <!-- TODO: 용도 --> \|$/m',
            '| memo |$1| 관리자 메모 (내부 관리용, 고객 비노출) |',
            $first
        );
        $this->assertStringContainsString('관리자 메모 (내부 관리용, 고객 비노출)', $withCell);

        // 재생성해도 사람이 채운 셀이 살아남아야 한다.
        $regenerated = $scaffolder->mergeDocument($withCell, $header, [$section], ['api.y.store']);

        $this->assertStringContainsString('관리자 메모 (내부 관리용, 고객 비노출)', $regenerated);
        $this->assertStringNotContainsString('<!-- TODO: 용도 -->', $regenerated);
    }

    /**
     * 실측 제외 응답 섹션을 사람이 채우면 재생성해도 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 실측_제외_응답_섹션을_사람이_채우면_재생성해도_보존한다(): void
    {
        // 회귀: 쓰기 메서드·미치환 path 등으로 실측이 불가한 엔드포인트는 응답 필드/예시 자리에
        // `<!-- 실측 제외: ... -->` 마커만 남는다. 사람이 그 자리를 코드 근거로 채워도 다음 재생성이
        // 블록을 통째로 재조립하며 마커로 되돌려, 작성분이 통째로 사라지던 문제.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/z', 'name' => 'api.z.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];

        // 실측 실패(쓰기 메서드) → 응답 필드·예시 모두 마커
        $section = $scaffolder->endpointSection($route, $request, null, ['status' => null, 'skipped_reason' => 'write-method']);
        $header = "# Z\n";

        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.z.store']);
        $this->assertStringContainsString('실측 제외: write-method — 응답 필드는 사람이 작성하세요.', $first);
        $this->assertStringContainsString('실측 제외: write-method — 응답 예시는 사람이 작성하세요.', $first);

        // 사람이 두 자리를 코드 근거로 채운다.
        $withBody = str_replace(
            '<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->',
            "| 필드 | 타입 | 설명 |\n| --- | --- | --- |\n| issuable | boolean | 지금 발급이 가능한지 여부 |",
            $first
        );
        $withBody = str_replace(
            '<!-- 실측 제외: write-method — 응답 예시는 사람이 작성하세요. -->',
            "```json\n{\n    \"success\": true,\n    \"data\": null\n}\n```",
            $withBody
        );

        // 재생성해도 사람이 채운 응답 필드 표와 응답 예시가 살아남아야 한다.
        $regenerated = $scaffolder->mergeDocument($withBody, $header, [$section], ['api.z.store']);

        $this->assertStringContainsString('| issuable | boolean | 지금 발급이 가능한지 여부 |', $regenerated);
        $this->assertStringContainsString('"success": true', $regenerated);
        $this->assertStringNotContainsString('응답 필드는 사람이 작성하세요.', $regenerated);
        $this->assertStringNotContainsString('응답 예시는 사람이 작성하세요.', $regenerated);
    }

    /**
     * 문서에 엔드포인트가 여러 개여도 각자의 응답 본문만 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 문서에_엔드포인트가_여러_개여도_각자의_응답_본문만_보존한다(): void
    {
        // 회귀: extractGeneratedBlock() 은 앞의 `## ` 헤딩까지 거슬러 올라가는데 엔드포인트 헤딩은
        // `### ` 라서 문서 상단까지 올라가 여러 엔드포인트를 한 덩어리로 반환한다. 위치 기반으로
        // 응답 본문을 떼어내면 항상 "그 덩어리의 첫 엔드포인트" 본문을 집어, 두 번째 이후
        // 엔드포인트의 사람 작성분이 소실되고 첫 엔드포인트 본문이 복제되던 문제.
        $scaffolder = new ApiDocScaffolder;

        $makeRoute = fn (string $name, string $uri): array => [
            'method' => 'POST', 'uri' => $uri, 'name' => $name,
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $skipped = ['status' => null, 'skipped_reason' => 'write-method'];

        $keys = ['api.a.store', 'api.b.store'];
        $sections = [
            $scaffolder->endpointSection($makeRoute('api.a.store', '/api/a'), $request, null, $skipped),
            $scaffolder->endpointSection($makeRoute('api.b.store', '/api/b'), $request, null, $skipped),
        ];

        // 문서 상단에 `## ` 헤딩이 있고 엔드포인트는 `### ` 로 나열되는 실제 구조를 재현한다.
        $header = "# Multi\n\n## TL;DR (5초 요약)\n\n설명\n";
        $first = $scaffolder->mergeDocument(null, $header, $sections, $keys);

        // 두 엔드포인트의 응답 필드를 서로 다른 내용으로 채운다.
        $filled = preg_replace_callback(
            '/<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요\. -->/',
            function () {
                static $n = 0;
                $n++;

                return "| 필드 | 타입 | 설명 |\n| --- | --- | --- |\n| field_{$n} | string | 엔드포인트 {$n} 전용 필드 |";
            },
            $first
        );

        $this->assertStringContainsString('엔드포인트 1 전용 필드', $filled);
        $this->assertStringContainsString('엔드포인트 2 전용 필드', $filled);

        $regenerated = $scaffolder->mergeDocument($filled, $header, $sections, $keys);

        // 각 엔드포인트가 자기 본문을 유지해야 한다 (2번이 1번 본문으로 덮이면 안 된다).
        $this->assertStringContainsString('엔드포인트 1 전용 필드', $regenerated);
        $this->assertStringContainsString('엔드포인트 2 전용 필드', $regenerated);
        $this->assertSame(1, substr_count($regenerated, '엔드포인트 1 전용 필드'));
        $this->assertSame(1, substr_count($regenerated, '엔드포인트 2 전용 필드'));
    }

    /**
     * 실측에 성공해도 사람이 쓴 응답 예시는 덮어쓰지 않는다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 실측에_성공해도_사람이_쓴_응답_예시는_덮어쓰지_않는다(): void
    {
        // 회귀: `reissue` 는 실측 표본이 `data: null`(전액 환불) 하나뿐인데, 사람은 코드를 읽고
        // 성공 케이스 예시까지 써 두었다. "실측이 사람을 이긴다" 규칙은 그 성공 예시를 지웠다.
        // 실측은 기본값이지 덮어쓰기 권한이 아니다.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/r', 'name' => 'api.r.reissue',
            'controller' => 'C', 'controller_method' => 'reissue', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];

        // 실측은 성공하지만 표본이 빈약하다 (data: null).
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'body' => ['success' => true, 'data' => null]];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);
        $header = "# R\n";

        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.r.reissue']);

        // 실측 산출 예시에는 출처 마커가 붙는다.
        $this->assertStringContainsString('<!-- @probed -->', $first);

        // 사람이 성공 케이스 예시를 덧붙여 더 풍부하게 만든다 (출처 마커 없음).
        $humanExample = "재발급 성공:\n\n```json\n{\n    \"success\": true,\n    \"data\": {\"id\": 14}\n}\n```\n\n전액 환불 시:\n\n```json\n{\n    \"success\": true,\n    \"data\": null\n}\n```";
        $withHuman = preg_replace(
            '/<!-- @probed -->.*?(?=\*\*에러 응답\*\*)/s',
            $humanExample."\n\n",
            $first
        );
        $this->assertStringContainsString('재발급 성공:', $withHuman);
        $this->assertStringNotContainsString('<!-- @probed -->', $withHuman);

        // 같은 실측 결과로 재생성해도 사람이 쓴 예시가 살아남아야 한다.
        $regenerated = $scaffolder->mergeDocument($withHuman, $header, [$section], ['api.r.reissue']);

        $this->assertStringContainsString('재발급 성공:', $regenerated);
        $this->assertStringContainsString('전액 환불 시:', $regenerated);
        $this->assertStringNotContainsString('<!-- @probed -->', $regenerated);
    }

    /**
     * 실측이 가능해지면 비어있던 응답 섹션은 실측 결과로 갱신된다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 실측이_가능해지면_비어있던_응답_섹션은_실측_결과로_갱신된다(): void
    {
        // 위 보존 규칙이 "영원히 갱신 불가"가 되면 안 된다 — 사람이 손대지 않은 마커 상태에서
        // 실측이 성공하면 실측 표/예시로 갱신되어야 한다.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/z', 'name' => 'api.z.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $header = "# Z\n";

        $skipped = $scaffolder->endpointSection($route, $request, null, ['status' => null, 'skipped_reason' => 'write-method']);
        $first = $scaffolder->mergeDocument(null, $header, [$skipped], ['api.z.store']);
        $this->assertStringContainsString('실측 제외: write-method', $first);

        // 이번 run 에서 실측 성공
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];
        $probed = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        $regenerated = $scaffolder->mergeDocument($first, $header, [$probed], ['api.z.store']);

        $this->assertStringNotContainsString('실측 제외: write-method', $regenerated);
        $this->assertStringContainsString('| id |', $regenerated);
    }

    /**
     * 재생성 시 응답 필드 표의 사람 설명 셀을 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성_시_응답_필드_표의_사람_설명_셀을_보존한다(): void
    {
        // 파라미터 표와 동일한 회귀 — 응답 필드 표의 마지막 열(용도/설명)도 사람이 채운다.
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/z', 'name' => 'api.z.show',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        // `nickname` 은 ResourceFieldDescriber 가 설명하지 못하는 도메인 특이 필드.
        $schema = [
            'envelope' => ['data'], 'shape' => 'object',
            'fields' => [['name' => 'nickname', 'type' => 'string', 'sample' => 'gnu']],
            'pagination' => false,
        ];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $header = "# Z\n";

        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.z.show']);
        $this->assertStringContainsString('<!-- TODO: 설명 -->', $first);

        // 응답 필드 표의 마지막 열을 사람이 채운 상태로 만든다.
        $withCell = preg_replace(
            '/^\| nickname \|(.*)\| <!-- TODO: 설명 --> \|$/m',
            '| nickname |$1| 화면에 표시되는 별명 (중복 허용) |',
            $first
        );
        $this->assertStringContainsString('화면에 표시되는 별명 (중복 허용)', $withCell);

        $regenerated = $scaffolder->mergeDocument($withCell, $header, [$section], ['api.z.show']);

        $this->assertStringContainsString('화면에 표시되는 별명 (중복 허용)', $regenerated);
    }

    /**
     * 재생성 시 사람이 고쳐쓴 셀은 자동 설명으로 되돌아가지 않는다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성_시_사람이_고쳐쓴_셀은_자동_설명으로_되돌아가지_않는다(): void
    {
        // 회귀: ParameterDescriber 가 값을 만들어내는 셀(TODO 가 아닌 셀)은
        // 사람이 도메인에 맞게 고쳐도 매 재생성마다 자동 설명으로 덮어써지던 문제.
        // 예: `identifier` → "대상 확장/리소스의 식별자" (도메인 무관 일반화)
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/w', 'name' => 'api.w.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = [
            'request_class' => null,
            'params' => [['name' => 'identifier', 'location' => 'body', 'type' => 'string', 'required' => true, 'allowed' => '', 'rules' => []]],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $header = "# W\n";

        // 최초 생성: describer 가 일반 설명을 채운다 (TODO 아님).
        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.w.store']);
        $this->assertStringContainsString('식별자', $first);
        $this->assertStringNotContainsString('<!-- TODO: 용도 -->', $first);

        // 사람이 도메인 설명으로 고쳐 쓴다.
        $withCell = preg_replace(
            '/^\| identifier \|(.*)\| [^|]+ \|$/m',
            '| identifier |$1| 현금영수증 식별번호 (휴대폰/카드/사업자번호) |',
            $first
        );
        $this->assertStringContainsString('현금영수증 식별번호', $withCell);

        // 재생성해도 사람이 고친 셀이 유지되어야 한다.
        $regenerated = $scaffolder->mergeDocument($withCell, $header, [$section], ['api.w.store']);

        $this->assertStringContainsString('현금영수증 식별번호', $regenerated);
    }

    /**
     * 재생성 시 이스케이프된 파이프를 담은 셀도 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성_시_이스케이프된_파이프를_담은_셀도_보존한다(): void
    {
        // 회귀: 표 셀에 `\|` (이스케이프된 파이프)가 있으면 열 분해가 어긋나
        // 보존 대상 행으로 인식되지 못하고 TODO 로 퇴행하던 문제.
        // 실제 사례: `인증 대상 해시 필터 (SHA256(email\|phone), PII 원본 대신 해시로 추적)`
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/v', 'name' => 'api.v.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = [
            'request_class' => null,
            'params' => [['name' => 'target_hash', 'location' => 'query', 'type' => 'string', 'required' => false, 'allowed' => '', 'rules' => []]],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $header = "# V\n";

        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.v.index']);
        $this->assertStringContainsString('<!-- TODO: 용도 -->', $first);

        $human = '인증 대상 해시 필터 (SHA256(email\\|phone), PII 원본 대신 해시로 추적)';
        $stubRow = '| target_hash | query | string | 아니오 | — | <!-- TODO: 용도 --> |';
        $humanRow = '| target_hash | query | string | 아니오 | — | '.$human.' |';

        $this->assertStringContainsString($stubRow, $first);
        $withCell = str_replace($stubRow, $humanRow, $first);

        $regenerated = $scaffolder->mergeDocument($withCell, $header, [$section], ['api.v.index']);

        $this->assertStringContainsString($human, $regenerated);
        $this->assertStringNotContainsString('<!-- TODO: 용도 -->', $regenerated);
    }

    /**
     * GET 인증 엔드포인트 요청 예시가 raw HTTP 요청이다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects request_example_is_raw_http_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function ge_t_인증_엔드포인트_요청_예시가_raw_htt_p_요청이다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/me', 'name' => 'api.me.show',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];
        $probeMeta = [
            'status' => 200, 'skipped_reason' => null,
            'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/me',
            'body' => ['success' => true, 'data' => ['id' => 1], 'message' => null, 'error' => null],
        ];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);

        $this->assertStringContainsString('**요청 예시**', $section);
        // curl 이 아니라 raw HTTP 요청 라인 + 헤더
        $this->assertStringNotContainsString('curl', $section);
        $this->assertStringContainsString('GET /api/me HTTP/1.1', $section);
        // Host 는 실측 기준 URL(g7.example.com)이 아니라 공개 placeholder 로 마스킹된다.
        $this->assertStringContainsString('Host: api.example.com', $section);
        $this->assertStringNotContainsString('g7.example.com', $section);
        $this->assertStringContainsString('Accept: application/json', $section);
        $this->assertStringContainsString('Authorization: Bearer {YOUR_TOKEN}', $section);
        // 실측 토큰 평문이 유출되지 않아야 한다 (placeholder 마스킹)
        $this->assertStringNotContainsString('Bearer eyJ', $section);
    }

    /**
     * 바디 메서드 요청 예시가 json 바디를 가진 raw HTTP 요청이다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects request_example_is_raw_http_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 바디_메서드_요청_예시가_json_바디를_가진_raw_htt_p_요청이다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/users', 'name' => 'api.admin.users.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => 'core.users.create',
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => 'X', 'params' => [
            ['name' => 'name', 'type' => 'string', 'required' => true, 'allowed' => 'max 255'],
            ['name' => 'age', 'type' => 'integer', 'required' => true, 'allowed' => ''],
            ['name' => 'nickname', 'type' => 'string', 'required' => false, 'allowed' => ''],
        ], 'hook_filters' => []];
        // 쓰기 메서드는 실측 제외 → body null
        $probeMeta = [
            'status' => null, 'skipped_reason' => 'write-method',
            'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/admin/users', 'body' => null,
        ];

        $section = $scaffolder->endpointSection($route, $request, null, $probeMeta);

        // curl 이 아니라 raw HTTP 요청 라인 + Content-Type 헤더 + JSON 바디
        $this->assertStringNotContainsString('curl', $section);
        $this->assertStringContainsString('POST /api/admin/users HTTP/1.1', $section);
        $this->assertStringContainsString('Host: api.example.com', $section);
        $this->assertStringContainsString('Content-Type: application/json', $section);
        // 전체 파라미터를 바디로 조립하고, 이름 기반 현실적 값을 채운다(placeholder "string" 남발 방지).
        $this->assertStringContainsString('"name": "예시 이름"', $section);
        $this->assertStringContainsString('"age": 1', $section);
        $this->assertStringContainsString('"nickname"', $section);
        // 응답 예시는 실측 제외 마커
        $this->assertStringContainsString('실측 제외: write-method — 응답 예시는 사람이 작성', $section);
    }

    /**
     * 실측 body 응답 예시는 envelope 통짜 json 이다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects response_example_is_truncated_envelope
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 실측_body_응답_예시는_envelope_통짜_json_이다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/me', 'name' => 'api.me.show',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['success', 'data'], 'shape' => 'object', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];
        $body = ['success' => true, 'data' => ['id' => 1, 'name' => '홍길동'], 'message' => null, 'error' => null];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/me', 'body' => $body];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);

        $this->assertStringContainsString('**응답 예시**', $section);
        $this->assertStringContainsString('HTTP/1.1 200', $section);
        $this->assertStringContainsString('"success": true', $section);
        $this->assertStringContainsString('"홍길동"', $section);
        $this->assertStringContainsString('"message": null', $section);
    }

    /**
     * 목록 응답 예시는 2항목으로 절단된다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects response_example_is_truncated_envelope
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 목록_응답_예시는_2항목으로_절단된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ['id' => $i, 'name' => "user{$i}"];
        }
        $body = [
            'success' => true,
            'data' => ['data' => $rows, 'pagination' => ['total' => 5, 'current_page' => 1]],
            'message' => null, 'error' => null,
        ];

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/users', 'name' => 'api.admin.users.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['success', 'data'], 'shape' => 'collection', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => true];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/admin/users', 'body' => $body];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);

        // 대표 2항목 + 절단 주석 항목. user3~5 는 나타나지 않아야 한다.
        $this->assertStringContainsString('"user1"', $section);
        $this->assertStringContainsString('"user2"', $section);
        $this->assertStringNotContainsString('"user3"', $section);
        $this->assertStringContainsString('총 5건 중 2건 표시', $section);
    }

    /**
     * 요청 응답 예시 블록은 generated 블록 내부에 있어 재생성 대상이다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_blocks_scoped_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 요청_응답_예시_블록은_generated_블록_내부에_있어_재생성_대상이다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/me', 'name' => 'api.me.show',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['success', 'data'], 'shape' => 'object', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];
        $body = ['success' => true, 'data' => ['id' => 1], 'message' => null, 'error' => null];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/me', 'body' => $body];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);

        $genStart = strpos($section, '@generated:start');
        $genEnd = strpos($section, '@generated:end');
        $reqExample = strpos($section, '**요청 예시**');
        $respExample = strpos($section, '**응답 예시**');

        // 요청/응답 예시 블록은 모두 @generated 경계 내부(재생성 대상)
        $this->assertGreaterThan($genStart, $reqExample);
        $this->assertLessThan($genEnd, $reqExample);
        $this->assertGreaterThan($genStart, $respExample);
        $this->assertLessThan($genEnd, $respExample);
    }

    /**
     * 요청 예시가 generated 블록 안이라 재생성해도 멱등이다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_blocks_scoped_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 요청_예시가_generated_블록_안이라_재생성해도_멱등이다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/me', 'name' => 'api.me.show',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['success', 'data'], 'shape' => 'object', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];
        $body = ['success' => true, 'data' => ['id' => 1], 'message' => null, 'error' => null];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/me', 'body' => $body];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);
        $header = "# Me\n";

        // 사람 서술을 채운 뒤 재생성해도 예시(생성 블록)는 갱신되고 서술은 보존된다.
        $first = $scaffolder->mergeDocument(null, $header, [$section], ['api.me.show']);
        $withProse = str_replace(
            '**설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->',
            '**설명** 내 프로필을 조회한다.',
            $first
        );

        $regenerated = $scaffolder->mergeDocument($withProse, $header, [$section], ['api.me.show']);

        $this->assertStringContainsString('내 프로필을 조회한다.', $regenerated);
        $this->assertStringNotContainsString('curl', $regenerated);
        $this->assertStringContainsString('GET /api/me HTTP/1.1', $regenerated);
    }

    /**
     * insert example blocks 는 표와 서술을 건드리지 않고 예시만 삽입한다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_blocks_scoped_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function insert_example_blocks_는_표와_서술을_건드리지_않고_예시만_삽입한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        // 예시 블록이 아직 없는 기존 문서 (표에는 사람이 채운 도메인 서술 셀 포함)
        $existing = <<<'MD'
# Me API 레퍼런스

### GET /api/me
<!-- @generated:start:api.me.show -->
- **라우트명**: `api.me.show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| uuid | string | `a231` | 외부 노출용 UUID (사람이 채운 도메인 서술) |

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 내 프로필을 조회한다. (사람이 채운 엔드포인트 서술)

MD;

        $blocks = [
            'api.me.show' => [
                'request' => "```http\nGET /api/me HTTP/1.1\nHost: g7.example.com\nAccept: application/json\n```",
                'response' => "```http\nHTTP/1.1 200\n```\n\n```json\n{\n    \"success\": true\n}\n```",
            ],
        ];

        [$updated, $inserted] = $scaffolder->insertExampleBlocks($existing, $blocks);

        // 예시 2블록 삽입됨
        $this->assertSame(2, $inserted);
        $this->assertStringContainsString('**요청 예시**', $updated);
        $this->assertStringContainsString('**응답 예시**', $updated);
        $this->assertStringContainsString('GET /api/me HTTP/1.1', $updated);
        // 표의 사람 서술 셀은 불변
        $this->assertStringContainsString('외부 노출용 UUID (사람이 채운 도메인 서술)', $updated);
        // 엔드포인트 서술도 불변
        $this->assertStringContainsString('내 프로필을 조회한다. (사람이 채운 엔드포인트 서술)', $updated);
        // 삽입 위치: 요청 예시는 응답 필드 앞, 응답 예시는 에러 응답 앞
        $this->assertLessThan(strpos($updated, '**응답 필드**'), strpos($updated, '**요청 예시**'));
        $this->assertLessThan(strpos($updated, '**에러 응답**'), strpos($updated, '**응답 예시**'));
        // 예시는 @generated 블록 내부
        $this->assertLessThan(strpos($updated, '@generated:end'), strpos($updated, '**요청 예시**'));
    }

    /**
     * insert example blocks 는 재삽입 시 멱등이다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_blocks_scoped_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function insert_example_blocks_는_재삽입_시_멱등이다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $existing = <<<'MD'
### GET /api/me
<!-- @generated:start:api.me.show -->
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**응답 필드** (`data` 내부)

_단건._

**에러 응답**

| 401 |

<!-- @generated:end -->

MD;

        $blocks = [
            'api.me.show' => [
                'request' => "```http\nGET /api/me HTTP/1.1\nHost: x\n```",
                'response' => "```json\n{}\n```",
            ],
        ];

        [$once] = $scaffolder->insertExampleBlocks($existing, $blocks);
        [$twice] = $scaffolder->insertExampleBlocks($once, $blocks);

        // 2회 삽입해도 결과 동일 (요청/응답 예시가 중복 삽입되지 않음)
        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, '**요청 예시**'));
        $this->assertSame(1, substr_count($twice, '**응답 예시**'));
    }

    /**
     * 서술에 수기 응답예시가 있으면 블록에 응답예시를 넣지 않는다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_blocks_scoped_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 서술에_수기_응답예시가_있으면_블록에_응답예시를_넣지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        // @generated 밖 서술에 사람이 미리 작성한 응답 예시가 있는 문서
        // (파일 업로드·미설치 정적 예시처럼 실측 불가라 수기로 채운 경우)
        $existing = <<<'MD'
### GET /api/me/card
<!-- @generated:start:api.me.card -->
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**응답 필드** (`data` 내부)

_단건._

**에러 응답**

| 401 |

<!-- @generated:end -->

**설명** 카드 조회.

**응답 예시** (정적 — 실측 불가라 사람이 작성)

```json
{ "success": true, "data": { "id": 1 } }
```

MD;

        $blocks = [
            'api.me.card' => [
                'request' => "```http\nGET /api/me/card HTTP/1.1\nHost: api.example.com\n```",
                'response' => '<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->',
            ],
        ];

        [$updated] = $scaffolder->insertExampleBlocks($existing, $blocks);

        // 요청 예시는 블록 안에 삽입된다
        $this->assertStringContainsString('**요청 예시**', $updated);
        $this->assertStringContainsString('GET /api/me/card HTTP/1.1', $updated);
        // 응답 예시 헤딩은 정확히 1개 (수기 예시만, 블록 안 마커 미삽입 → 중복 헤딩 없음)
        $this->assertSame(1, substr_count($updated, '**응답 예시**'));
        // 수기 응답 예시는 보존
        $this->assertStringContainsString('정적 — 실측 불가라 사람이 작성', $updated);
        // 블록 안에 실측 제외 마커가 들어가지 않아야 한다
        $this->assertStringNotContainsString('실측 제외: http-500', $updated);
    }

    /**
     * 과거 삽입된 블록내 응답예시는 서술에 수기예시가 생기면 제거된다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_blocks_scoped_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 과거_삽입된_블록내_응답예시는_서술에_수기예시가_생기면_제거된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        // 과거 run 이 블록 안에 응답 예시 마커를 넣었고, 이후 서술에 수기 응답 예시가 추가된 상태
        $existing = <<<'MD'
### GET /api/me/card
<!-- @generated:start:api.me.card -->
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**응답 필드** (`data` 내부)

_단건._

**응답 예시**

<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 401 |

<!-- @generated:end -->

**응답 예시** (정적)

```json
{ "success": true }
```

MD;

        $blocks = [
            'api.me.card' => [
                'request' => "```http\nGET /api/me/card HTTP/1.1\nHost: api.example.com\n```",
                'response' => '<!-- 실측 제외: http-500 — 응답 예시는 사람이 작성하세요. -->',
            ],
        ];

        [$updated] = $scaffolder->insertExampleBlocks($existing, $blocks);

        // 블록 안 응답 예시가 제거되어 응답 예시 헤딩은 1개(수기 예시)만 남는다
        $this->assertSame(1, substr_count($updated, '**응답 예시**'));
        $this->assertStringNotContainsString('실측 제외: http-500', $updated);
        // 수기 예시는 보존
        $this->assertStringContainsString('**응답 예시** (정적)', $updated);
    }

    /**
     * 파일 업로드 요청 예시는 multipart form data 다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects request_example_is_raw_http_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 파일_업로드_요청_예시는_multipart_form_data_다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/me/avatar', 'name' => 'api.me.avatar.upload',
            'controller' => 'C', 'controller_method' => 'uploadAvatar', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => 'X', 'params' => [
            ['name' => 'avatar', 'type' => 'image', 'required' => true, 'allowed' => 'max 2048'],
        ], 'hook_filters' => []];
        $probeMeta = ['status' => null, 'skipped_reason' => 'write-method', 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/me/avatar', 'body' => null];

        $section = $scaffolder->endpointSection($route, $request, null, $probeMeta);

        // 파일(image) 파라미터는 application/json 이 아니라 multipart/form-data 로 표기된다.
        $this->assertStringContainsString('Content-Type: multipart/form-data', $section);
        $this->assertStringNotContainsString('"avatar": "string"', $section);
        $this->assertStringContainsString('filename=', $section);
        $this->assertStringContainsString('name="avatar"', $section);
    }

    /**
     * GET 요청 예시는 query 파라미터를 url 쿼리스트링으로 붙인다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects request_example_is_raw_http_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function ge_t_요청_예시는_query_파라미터를_url_쿼리스트링으로_붙인다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/search', 'name' => 'api.search.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => 'X', 'params' => [
            ['name' => 'q', 'type' => 'string', 'required' => true, 'allowed' => ''],
            ['name' => 'type', 'type' => 'string', 'required' => false, 'allowed' => '`post`, `product`'],
        ], 'hook_filters' => []];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/search', 'body' => ['success' => true, 'data' => []]];

        $section = $scaffolder->endpointSection($route, $request, $schema = null, $probeMeta);

        // query 파라미터가 URL 쿼리스트링으로 반영된다 (in: 열거는 첫 값 채택).
        $this->assertMatchesRegularExpression('/GET \/api\/search\?[^ ]*q=/', $section);
        $this->assertStringContainsString('type=post', $section);
    }

    /**
     * 응답 예시는 토큰 비밀번호 등 민감값을 마스킹한다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_sensitive_values_masked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시는_토큰_비밀번호_등_민감값을_마스킹한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/auth/login', 'name' => 'api.auth.login',
            'controller' => 'C', 'controller_method' => 'login', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['success', 'data'], 'shape' => 'object', 'fields' => [['name' => 'token', 'type' => 'string', 'sample' => '69|abc']], 'pagination' => false];
        $body = ['success' => true, 'data' => [
            'token' => '69|NzX4qbOT4Xns28p6Ik7d7CvGiYn8kuyi8cS0J0AL5ad91266',
            'token_type' => 'Bearer',
            'user' => ['uuid' => 'abc', 'password' => '$2y$10$abcdefghijklmnop'],
        ], 'message' => null, 'error' => null];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/auth/login', 'body' => $body];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);

        // 실제 토큰/비밀번호 해시는 노출되지 않고 마스킹된다.
        $this->assertStringNotContainsString('NzX4qbOT4Xns28p6', $section);
        $this->assertStringNotContainsString('$2y$10$abcdefghijklmnop', $section);
        $this->assertStringContainsString('{MASKED}', $section);
        // token_type 은 민감값이 아니므로 보존된다.
        $this->assertStringContainsString('"token_type": "Bearer"', $section);
    }

    /**
     * 응답 예시 body의 절대 URL 호스트는 placeholder로 마스킹된다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_sensitive_values_masked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시의_쿼리스트링_자격증명은_마스킹된다(): void
    {
        // 키 이름 대조만으로는 이 형태를 잡지 못한다 — 비밀이 값 **안**에 있고 그 값을
        // 담은 키는 `url` 처럼 무해한 이름이다. 실제로 웹 크론 호출 주소가 그 형태라,
        // 이 처리가 없으면 실측 응답의 살아 있는 호출 번호(인증 없이 예약 작업을 실행할
        // 수 있는 자격증명)가 공개 문서에 그대로 실린다.
        $scaffolder = new ApiDocScaffolder;

        $secret = 'd6ba2b4f754b0a93f5d30b288f17cb4760980eb219fe09e6273cafb418cb655d';
        $body = ['success' => true, 'data' => [
            'url' => 'https://g7.example.com/api/system/cron/run?token='.$secret,
            'header_name' => 'X-G7-Cron-Token',
        ], 'message' => null, 'error' => null];

        $block = $scaffolder->responseExampleBlock(null, [
            'status' => 200,
            'body' => $body,
            'base_url' => 'https://g7.example.com',
        ]);

        $this->assertStringNotContainsString($secret, $block);
        $this->assertStringContainsString('token={MASKED}', $block);
        // 값 전체가 아니라 비밀 자리만 가린다 — 주소의 형태가 문서의 설명이다.
        $this->assertStringContainsString('/api/system/cron/run?token=', $block);
        // 비밀이 아닌 필드는 그대로 남는다.
        $this->assertStringContainsString('X-G7-Cron-Token', $block);
    }

    /**
     * 응답 예시의 URL 에 든 page 같은 비밀이 아닌 쿼리 파라미터는 마스킹하지 않고 그대로 두는지 확인한다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_sensitive_values_masked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀이_아닌_쿼리_파라미터는_보존된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $body = ['success' => true, 'data' => [
            'next_page_url' => 'https://g7.example.com/api/users?page=2&per_page=20',
        ], 'message' => null, 'error' => null];

        $block = $scaffolder->responseExampleBlock(null, [
            'status' => 200,
            'body' => $body,
            'base_url' => 'https://g7.example.com',
        ]);

        $this->assertStringContainsString('page=2', $block);
        $this->assertStringContainsString('per_page=20', $block);
        $this->assertStringNotContainsString('{MASKED}', $block);
    }

    /**
     * 실측 응답 body 의 절대 URL 호스트가 포트까지 통째로 placeholder 호스트로 바뀌어 실측 기준 호스트가 문서에 남지 않는지 확인한다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects example_sensitive_values_masked
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시_body의_절대_ur_l_호스트는_placeholder로_마스킹된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/users', 'name' => 'api.admin.users.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['success', 'data'], 'shape' => 'collection', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => true];
        // 실측 응답의 페이지네이터 메타·콜백 URL 은 요청 base URL 의 호스트를 그대로 직렬화한다.
        $body = ['success' => true, 'data' => [
            'data' => [['id' => 1]],
            'first_page_url' => 'https://dev-site.kr/api/admin/users?page=1',
            'path' => 'https://dev-site.kr/api/admin/users',
            'callback_url' => 'https://dev-site.kr:8443/callback',
        ], 'message' => null, 'error' => null];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://dev-site.kr', 'resolved_uri' => '/api/admin/users', 'body' => $body];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);

        // 실측 기준 호스트(dev-site.kr)는 응답 예시 어디에도 노출되지 않고 placeholder 로 치환된다.
        $this->assertStringNotContainsString('dev-site.kr', $section);
        $this->assertStringContainsString('https://api.example.com/api/admin/users?page=1', $section);
        // 포트가 붙은 절대 URL 도 호스트+포트 통째로 마스킹된다.
        $this->assertStringContainsString('https://api.example.com/callback', $section);
    }

    /**
     * 파라미터 표는 path와 form request 의 동일 이름을 중복 출력하지 않는다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects endpoint_section_uses_standard_format
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 파라미터_표는_path와_form_request_의_동일_이름을_중복_출력하지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/templates/{identifier}/assets/{path}', 'name' => 'api.templates.assets',
            'controller' => 'C', 'controller_method' => 'assets', 'permission' => null,
            'middleware' => ['api'], 'path_params' => ['identifier', 'path'],
        ];
        // FormRequest 에도 identifier/path 규칙이 있는 경우 (중복 유발 조건)
        $request = ['request_class' => 'X', 'params' => [
            ['name' => 'identifier', 'type' => 'string', 'required' => true, 'allowed' => ''],
            ['name' => 'path', 'type' => 'string', 'required' => true, 'allowed' => ''],
        ], 'hook_filters' => []];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7.example.com', 'resolved_uri' => '/api/templates/x/assets/y', 'body' => ['success' => true, 'data' => []]];

        $section = $scaffolder->endpointSection($route, $request, null, $probeMeta);

        // identifier/path 는 path 행으로만 1회 출력 (query 중복 행 없음).
        $this->assertSame(1, substr_count($section, '| identifier | path |'));
        $this->assertStringNotContainsString('| identifier | query |', $section);
        $this->assertSame(1, substr_count($section, '| path | path |'));
    }

    /**
     * readme 목차는 도메인 파일과 엔드포인트 수를 표로 나열한다.
     *
     * @scenario stage=readme_index
     *
     * @effects readme_index_regenerated_preserving_prose
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function readme_목차는_도메인_파일과_엔드포인트_수를_표로_나열한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $entries = [
            ['domain' => 'products', 'file' => 'products.md', 'count' => 30],
            ['domain' => 'orders', 'file' => 'orders.md', 'count' => 22],
        ];

        $readme = $scaffolder->readmeIndex('모듈 `sirsoft-ecommerce`', $entries);

        // 소유 라벨·집계·도메인 링크가 표에 나타난다.
        $this->assertStringContainsString('# API 레퍼런스 문서 목차', $readme);
        $this->assertStringContainsString('모듈 `sirsoft-ecommerce`', $readme);
        $this->assertStringContainsString('**문서 수**: 2 · **엔드포인트 수**: 52', $readme);
        $this->assertStringContainsString('| [products.md](products.md) | `products` | 30 |', $readme);
        $this->assertStringContainsString('| [orders.md](orders.md) | `orders` | 22 |', $readme);
        // 도메인 알파벳 정렬 — orders 가 products 보다 먼저.
        $this->assertLessThan(strpos($readme, 'products.md'), strpos($readme, 'orders.md'));
    }

    /**
     * readme 재생성은 generated 블록만 갱신하고 사람 서술을 보존한다.
     *
     * @scenario stage=readme_index
     *
     * @effects readme_index_regenerated_preserving_prose
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function readme_재생성은_generated_블록만_갱신하고_사람_서술을_보존한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $entries = [['domain' => 'pages', 'file' => 'pages.md', 'count' => 5]];
        $first = $scaffolder->readmeIndex('모듈 `sirsoft-page`', $entries);

        // @generated 블록 밖에 사람 개요 서술을 덧붙인다.
        $human = $first."\n## 개요\n\n이 모듈은 정적 페이지를 관리합니다. (사람 서술)\n";

        // 엔드포인트 수가 바뀐 재생성.
        $entriesUpdated = [['domain' => 'pages', 'file' => 'pages.md', 'count' => 6]];
        $regen = $scaffolder->readmeIndex('모듈 `sirsoft-page`', $entriesUpdated, $human);

        // 표는 갱신되고(6), 사람 서술은 보존된다.
        $this->assertStringContainsString('엔드포인트 수**: 6', $regen);
        $this->assertStringContainsString('이 모듈은 정적 페이지를 관리합니다. (사람 서술)', $regen);
        // 재재생성해도 사람 서술 중복 없이 멱등.
        $regen2 = $scaffolder->readmeIndex('모듈 `sirsoft-page`', $entriesUpdated, $regen);
        $this->assertSame(
            substr_count($regen, '(사람 서술)'),
            substr_count($regen2, '(사람 서술)')
        );
    }

    /**
     * 머리말과 목차 표 사이의 사람 개요는 그 안에 인용 줄(`> …`)이 있어도 통째로 보존된다.
     *
     * 회귀 배경: 개요 추출이 head 전체에서 `>` 줄을 찾아 **마지막** 것 뒤만 개요로 삼았다.
     * 그래서 개요 안에 인용 줄이 하나라도 있으면 그 위의 서술이 재생성 때마다 조용히
     * 잘려 나갔다 — 코어 API 목차의 공통 규약(인증·로케일·페이지네이션)이 그렇게 사라졌고,
     * 남은 문서는 문법상 멀쩡해 오류도 경고도 나지 않았다.
     *
     * @scenario stage=readme_index
     *
     * @effects readme_index_regenerated_preserving_prose
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function readme_개요_안의_인용줄이_그_위_서술을_잘라내지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $entries = [['domain' => 'pages', 'file' => 'pages.md', 'count' => 5]];
        $first = $scaffolder->readmeIndex('모듈 `sirsoft-page`', $entries);

        // 머리말 인용 블록과 목차 생성 블록 사이에 사람 개요를 끼운다.
        // 그 개요 **안**에 인용 줄이 다시 나온다 — 실제 코어 목차가 그 형태다.
        $overview = '## 공통 규약

첫 문단 (보존 대상).

> 상세: [pagination.md](../pagination.md)

마지막 문단.';
        $marker = '<!-- @generated:start:api-readme-index -->';
        $human = str_replace($marker, $overview.'

'.$marker, $first);

        $entriesUpdated = [['domain' => 'pages', 'file' => 'pages.md', 'count' => 6]];
        $regen = $scaffolder->readmeIndex('모듈 `sirsoft-page`', $entriesUpdated, $human);

        $this->assertStringContainsString('엔드포인트 수**: 6', $regen, '표는 갱신되어야 한다');
        $this->assertStringContainsString('## 공통 규약', $regen, '인용 줄 위의 헤딩이 잘렸다');
        $this->assertStringContainsString('첫 문단 (보존 대상).', $regen, '인용 줄 위의 서술이 잘렸다');
        $this->assertStringContainsString('마지막 문단.', $regen);

        // 재재생성해도 중복 없이 멱등이어야 한다.
        $regen2 = $scaffolder->readmeIndex('모듈 `sirsoft-page`', $entriesUpdated, $regen);
        $this->assertSame(
            substr_count($regen, '첫 문단 (보존 대상).'),
            substr_count($regen2, '첫 문단 (보존 대상).')
        );
    }

    /**
     * 목록이 아닌 큰 응답(카탈로그·스펙)도 예시 크기 상한 안으로 절단되어야 한다.
     *
     * 회귀 배경: 절단 로직이 페이지네이션 형태(`data.data[]`)만 다뤄, 편집기 스펙처럼
     * 목록이 아닌 응답이 통째로 직렬화됐다. templates.md 한 파일이 115KB → 8.65MB 가 됐다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects response_example_is_truncated_envelope
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 목록이_아닌_큰_응답도_예시_크기_상한으로_절단된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        // 편집기 스펙 형태 — 중첩 객체 안에 큰 배열이 있고 페이지네이션 형태가 아니다.
        $palette = [];
        for ($i = 0; $i < 400; $i++) {
            $palette["component_{$i}"] = [
                'name' => "Component{$i}",
                'description' => str_repeat('설명 텍스트 ', 40),
                'props' => array_fill(0, 30, 'prop-value-'.str_repeat('x', 60)),
            ];
        }

        $body = [
            'success' => true,
            'message' => '조회 성공',
            'data' => ['palette' => $palette, 'version' => '1.0.0'],
        ];

        $rawSize = strlen((string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertGreaterThan(500_000, $rawSize, '전제: 원본이 충분히 커야 절단을 검증할 수 있다');

        $block = $scaffolder->responseExampleBlock(null, ['status' => 200, 'body' => $body]);

        // 존재를 먼저 확정한 뒤 크기를 단언한다.
        $this->assertStringContainsString('```json', $block);
        $this->assertLessThan(
            16_384,
            strlen($block),
            '목록이 아닌 큰 응답도 상한 이하로 절단되어야 한다',
        );
        $this->assertStringContainsString('생략', $block, '무엇이 잘렸는지 문서에 남아야 한다');
    }

    /**
     * 재생성이 표의 사람 서술을 TODO 로 되돌리지 않아야 한다.
     *
     * 회귀 배경: 전체 재생성이 `@generated` 블록의 파라미터/응답 필드 표를 통째로 다시
     * 조립하면서, 정적 추출로 재현할 수 없는 도메인 설명이 TODO 스텁으로 되돌아갔다
     * (54개 파일 1,181행). `--examples-only` 로만 우회 가능했던 것을 재생성 경로에서 막는다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성이_표의_사람_서술을_todo_로_되돌리지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $existing = <<<'MD'
            # API

            ### GET /api/admin/things
            <!-- @generated:start:api.admin.things.index -->
            **요청 파라미터**

            | 이름 | 위치 | 타입 | 필수 | 기본값 | 설명 |
            | --- | --- | --- | --- | --- | --- |
            | no_category | query | boolean | 아니오 | — | 카테고리 미지정 상품만 필터 |
            | keyword | query | string | 아니오 | — | <!-- TODO: 용도 --> |

            **응답 필드** (`data` 내부)

            | 필드 | 타입 | 예시 | 설명 |
            | --- | --- | --- | --- |
            | uuid | string | `abc` | 외부 노출용 UUID |
            <!-- @generated:end -->

            **설명**

            사람이 쓴 서술.
            MD;

        // 재생성 결과 — 두 설명 모두 TODO 스텁으로 조립된 상태
        $newSection = <<<'MD'
            ### GET /api/admin/things
            <!-- @generated:start:api.admin.things.index -->
            **요청 파라미터**

            | 이름 | 위치 | 타입 | 필수 | 기본값 | 설명 |
            | --- | --- | --- | --- | --- | --- |
            | no_category | query | boolean | 아니오 | — | <!-- TODO: 용도 --> |
            | keyword | query | string | 아니오 | — | <!-- TODO: 용도 --> |

            **응답 필드** (`data` 내부)

            | 필드 | 타입 | 예시 | 설명 |
            | --- | --- | --- | --- |
            | uuid | string | `abc` | <!-- TODO: 설명 --> |
            <!-- @generated:end -->

            **설명** <!-- TODO: 이 엔드포인트의 용도·주의사항·예시 시나리오를 작성하세요 -->
            MD;

        $merged = $scaffolder->mergeDocument(
            $existing,
            '# API',
            [$newSection],
            ['api.admin.things.index'],
        );

        // 사람이 채운 설명은 되살아난다.
        $this->assertStringContainsString('카테고리 미지정 상품만 필터', $merged);
        $this->assertStringContainsString('외부 노출용 UUID', $merged);
        // 원래도 비어 있던 셀은 TODO 로 남는다.
        $this->assertStringContainsString('| keyword | query | string | 아니오 | — | <!-- TODO: 용도 --> |', $merged);
        // 블록 밖 사람 서술도 그대로 보존된다.
        $this->assertStringContainsString('사람이 쓴 서술.', $merged);
    }

    /**
     * 실측 데이터가 없어 필드 표가 비면 기존 표를 유지해야 한다.
     *
     * 회귀 배경: 대상 데이터가 없는 상태로 재생성하자 응답 필드 표가 "실측 응답에 필드 없음"
     * 한 줄로 대체됐다 (reviews.md 388줄 소실). "이번에 데이터가 없었다" 는 사실은 이미 문서화된
     * 필드 목록을 지울 근거가 못 된다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 빈_실측이_기존_응답_필드_표를_지우지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $existing = <<<'MD'
            # API

            ### GET /api/admin/things
            <!-- @generated:start:api.admin.things.index -->
            **응답 필드** (`data` 내부)

            | 필드 | 타입 | 예시 | 설명 |
            | --- | --- | --- | --- |
            | id | integer | `99` | 기본 키 (내부 식별자) |
            | rating | integer | `5` | 별점 (1~5) |

            **응답 예시**

            ```json
            {}
            ```
            <!-- @generated:end -->
            MD;

        $newSection = <<<'MD'
            ### GET /api/admin/things
            <!-- @generated:start:api.admin.things.index -->
            **응답 필드** (`data` 내부)

            <!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

            **응답 예시**

            ```json
            {}
            ```
            <!-- @generated:end -->
            MD;

        $merged = $scaffolder->mergeDocument($existing, '# API', [$newSection], ['api.admin.things.index']);

        $this->assertStringContainsString('| rating | integer | `5` | 별점 (1~5) |', $merged);
        $this->assertStringNotContainsString('실측 응답에 필드 없음', $merged);
    }

    /**
     * 기존 설명이 있으면 도구가 만든 일반 문구로 덮어쓰지 않는다.
     *
     * 도구의 설명은 필드명에서 유추한 일반 문구라 사람이 적은 도메인 사실보다 정보량이 적다.
     * 회귀 배경: `플러그인 이름 (다국어 JSON)` 이 `대상의 이름/명칭 (다국어 필드는 …)` 로,
     * `desc 는 주문별 가장 늦은 발송일` 같은 서술이 일반 문구로 대체돼 224건이 후퇴했다.
     *
     * 예시값 컬럼은 타입이 그대로면 기존 값을 유지한다 (멱등성 — 실측값은 호출 시점
     * DB 표본이라 매번 덮으면 스펙이 그대로인 문서까지 재실행마다 diff 가 난다). 타입이
     * 바뀌면(스펙 변경) 새 실측값으로 갱신되므로 최신성은 그 경로로 유지된다 —
     * `재생성_시_타입이_같은_필드의_실측_예시값을_보존한다` / `신규_필드와_타입_변경은_실측_예시값을_갱신한다` 참조.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 기존_설명은_도구의_일반_문구로_덮어쓰지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $existing = <<<'MD'
            # API

            ### GET /api/admin/things
            <!-- @generated:start:api.admin.things.index -->
            | 필드 | 타입 | 예시 | 설명 |
            | --- | --- | --- | --- |
            | name | string | `옛값` | 플러그인 이름 (다국어 JSON) |
            <!-- @generated:end -->
            MD;

        $newSection = <<<'MD'
            ### GET /api/admin/things
            <!-- @generated:start:api.admin.things.index -->
            | 필드 | 타입 | 예시 | 설명 |
            | --- | --- | --- | --- |
            | name | string | `새값` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
            <!-- @generated:end -->
            MD;

        $merged = $scaffolder->mergeDocument($existing, '# API', [$newSection], ['api.admin.things.index']);

        // 설명은 사람 서술을 유지하고,
        $this->assertStringContainsString('플러그인 이름 (다국어 JSON)', $merged);
        $this->assertStringNotContainsString('대상의 이름/명칭', $merged);
        // 타입이 같으므로 실측 예시값도 기존 값이 유지된다 (멱등 — 타입 변경 시에만 갱신).
        $this->assertStringContainsString('`옛값`', $merged);
        $this->assertStringNotContainsString('`새값`', $merged);
    }

    /**
     * 상한 이하의 작은 응답은 손대지 않는다 (절단이 정상 예시를 훼손하지 않음).
     *
     * @scenario stage=request_response_examples
     *
     * @effects response_example_is_truncated_envelope
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 상한_이하_응답은_그대로_직렬화된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $body = [
            'success' => true,
            'message' => '조회 성공',
            'data' => ['id' => 7, 'identifier' => 'sirsoft-admin_basic', 'version' => '1.0.4'],
        ];

        $block = $scaffolder->responseExampleBlock(null, ['status' => 200, 'body' => $body]);

        $this->assertStringContainsString('"identifier": "sirsoft-admin_basic"', $block);
        $this->assertStringNotContainsString('생략', $block);
    }

    /**
     * 응답 필드 표의 실측 예시값은 스펙이 그대로면 재생성에도 유지된다.
     *
     * 회귀: 실측값은 호출 시점 DB 상태가 낳은 표본 하나인데 재생성마다 새 값으로 덮어써서,
     * 코드가 하나도 안 바뀐 무관한 문서 수십 개가 docgen 재실행마다 diff 에 걸렸다
     * (예: `id` 예시값 127 → 43, created_at 날짜 변경).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성_시_타입이_같은_필드의_실측_예시값을_보존한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/z', 'name' => 'api.z.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $header = "# Z\n";

        $schemaOf = fn (string $sample): array => [
            'envelope' => ['data'],
            'shape' => 'object',
            'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => $sample]],
            'pagination' => false,
        ];

        // 최초 생성 — 그 시점 DB 의 id 는 127
        $first = $scaffolder->mergeDocument(
            null,
            $header,
            [$scaffolder->endpointSection($route, $request, $schemaOf('127'), ['status' => 200, 'skipped_reason' => null])],
            ['api.z.index'],
        );
        $this->assertStringContainsString('`127`', $first);

        // 재생성 — DB 가 바뀌어 실측 id 가 43 이 되었지만 스펙(타입)은 그대로다
        $regenerated = $scaffolder->mergeDocument(
            $first,
            $header,
            [$scaffolder->endpointSection($route, $request, $schemaOf('43'), ['status' => 200, 'skipped_reason' => null])],
            ['api.z.index'],
        );

        $this->assertStringContainsString('`127`', $regenerated, '스펙이 그대로면 기존 실측 예시값을 유지해야 한다');
        $this->assertStringNotContainsString('`43`', $regenerated);
    }

    /**
     * 필드가 새로 생기거나 타입이 바뀌면(= 스펙 변경) 실측 예시값이 갱신된다.
     *
     * 예시값 보존이 "문서가 코드 변경을 영영 반영하지 않는다"로 퇴화하지 않도록 하는 반대편 가드.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 신규_필드와_타입_변경은_실측_예시값을_갱신한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/z', 'name' => 'api.z.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $header = "# Z\n";

        $build = fn (array $fields): string => $scaffolder->endpointSection(
            $route,
            $request,
            ['envelope' => ['data'], 'shape' => 'object', 'fields' => $fields, 'pagination' => false],
            ['status' => 200, 'skipped_reason' => null],
        );

        $first = $scaffolder->mergeDocument(
            null,
            $header,
            [$build([['name' => 'amount', 'type' => 'integer', 'sample' => '1000']])],
            ['api.z.index'],
        );

        // amount 의 타입이 바뀌고(스펙 변경), is_escrow 필드가 새로 추가된 리소스
        $regenerated = $scaffolder->mergeDocument(
            $first,
            $header,
            [$build([
                ['name' => 'amount', 'type' => 'string', 'sample' => '1000.00'],
                ['name' => 'is_escrow', 'type' => 'boolean', 'sample' => 'true'],
            ])],
            ['api.z.index'],
        );

        $this->assertStringContainsString('`1000.00`', $regenerated, '타입이 바뀌면 새 실측값으로 갱신해야 한다');
        $this->assertStringContainsString('is_escrow', $regenerated, '신규 필드는 표에 추가되어야 한다');
        $this->assertStringContainsString('`true`', $regenerated);
    }

    /**
     * nullable 필드가 이번 표본에서 null 로 관측돼도 기존에 관측한 실제 값을 유지한다.
     *
     * 회귀: nullable 필드는 표본에 값이 있느냐에 따라 관측 타입이 흔들린다(`loggable_type` 이
     * string ↔ null). "타입이 바뀌면 갱신" 규칙이 이 흔들림을 스펙 변경으로 오인해, 실제 값을
     * 관측해 둔 행을 `null` 로 덮어써 문서가 열화됐다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function nullable_필드가_null_로_관측돼도_기존_예시값을_유지한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/t', 'name' => 'api.t.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $header = "# T\n";

        $build = fn (string $type, string $sample): string => $scaffolder->endpointSection(
            $route,
            $request,
            [
                'envelope' => ['data'],
                'shape' => 'object',
                'fields' => [['name' => 'loggable_type', 'type' => $type, 'sample' => $sample]],
                'pagination' => false,
            ],
            ['status' => 200, 'skipped_reason' => null],
        );

        // 값이 있는 표본을 관측한 상태
        $first = $scaffolder->mergeDocument(null, $header, [$build('string', 'App\\Models\\Post')], ['api.t.index']);
        $this->assertStringContainsString('App\\Models\\Post', $first);

        // 이번 표본에는 값이 없어 null 로 관측됐다 — 스펙 변경이 아니다.
        $regenerated = $scaffolder->mergeDocument($first, $header, [$build('null', 'null')], ['api.t.index']);

        $this->assertStringContainsString('App\\Models\\Post', $regenerated, 'null 관측이 기존 값을 덮어써서는 안 된다');
        $this->assertStringContainsString('| loggable_type | string |', $regenerated, '타입도 유지되어야 한다');
    }

    /**
     * 응답 예시 JSON 은 필드 집합이 같으면 유지되고, 달라지면 갱신된다.
     *
     * 회귀: 응답 예시는 호출 시점 표본이라 `updated_at` · `cache_version` 처럼 매 실측마다 값이
     * 달라지는 필드가 섞여 들어온다. 매번 새 값으로 덮으면 코드가 하나도 안 바뀐 문서까지
     * docgen 재실행마다 diff 가 난다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 응답_예시는_필드집합이_같으면_유지되고_달라지면_갱신된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/u', 'name' => 'api.u.show',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $header = "# U\n";

        $build = fn (array $data): string => $scaffolder->endpointSection(
            $route,
            $request,
            ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false],
            [
                'status' => 200,
                'skipped_reason' => null,
                'base_url' => 'https://api.example.com',
                'resolved_uri' => '/api/u',
                'body' => ['data' => $data],
            ],
        );

        $first = $scaffolder->mergeDocument(
            null, $header, [$build(['id' => 1, 'updated_at' => '2026-07-14 13:05:11'])], ['api.u.show'],
        );
        $this->assertStringContainsString('2026-07-14 13:05:11', $first);

        // 값만 바뀐 재실측 — 필드 집합은 그대로 → 기존 예시 유지
        $sameShape = $scaffolder->mergeDocument(
            $first, $header, [$build(['id' => 1, 'updated_at' => '2026-07-14 13:07:04'])], ['api.u.show'],
        );
        $this->assertStringContainsString('2026-07-14 13:05:11', $sameShape, '값만 달라졌으면 기존 예시를 유지해야 한다');
        $this->assertStringNotContainsString('13:07:04', $sameShape);

        // 필드가 추가된 재실측 — 스펙 변경 → 새 예시 채택
        $newShape = $scaffolder->mergeDocument(
            $first,
            $header,
            [$build(['id' => 1, 'updated_at' => '2026-07-14 13:07:04', 'is_escrow' => false])],
            ['api.u.show'],
        );
        $this->assertStringContainsString('is_escrow', $newShape, '필드가 추가되면 새 실측 예시로 갱신해야 한다');
        $this->assertStringContainsString('13:07:04', $newShape);
    }

    /**
     * 이번 실측이 실패해도 기존에 관측해 둔 응답 예시는 유지된다.
     *
     * 회귀: 실측 성패는 호출 시점 데이터 유무에 좌우된다(예: DELETE /checkout 은 삭제할 체크아웃이
     * 없으면 404). 실측 실패 시 "실측 제외" 마커로 덮어써서, 이전 실행이 관측해 둔 응답 예시가
     * 통째로 사라졌다. 실측 실패는 "새로 관측하지 못했다"는 뜻이지 기존 관측이 무효라는 뜻이 아니다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 실측_실패_시_기존_실측_응답_예시를_유지한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'DELETE', 'uri' => '/api/v', 'name' => 'api.v.destroy',
            'controller' => 'C', 'controller_method' => 'destroy', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $header = "# V\n";

        // 1회차: 실측 성공 — 응답 예시가 @probed 로 기록된다.
        $probed = $scaffolder->endpointSection(
            $route,
            $request,
            ['envelope' => ['success', 'message', 'data'], 'shape' => 'object', 'fields' => [], 'pagination' => false],
            [
                'status' => 200,
                'skipped_reason' => null,
                'base_url' => 'https://api.example.com',
                'resolved_uri' => '/api/v',
                'body' => ['success' => true, 'message' => 'Deleted.', 'data' => null],
            ],
        );

        $first = $scaffolder->mergeDocument(null, $header, [$probed], ['api.v.destroy']);
        $this->assertStringContainsString('Deleted.', $first);

        // 2회차: 대상 데이터가 없어 실측이 404 로 실패한다.
        $failed = $scaffolder->endpointSection(
            $route,
            $request,
            null,
            ['status' => 404, 'skipped_reason' => 'http-404'],
        );

        $regenerated = $scaffolder->mergeDocument($first, $header, [$failed], ['api.v.destroy']);

        $this->assertStringContainsString('Deleted.', $regenerated, '기존 실측 응답 예시가 유지되어야 한다');
    }

    /**
     * 사람이 보강한 에러 응답 표는 재생성에도 보존된다.
     *
     * 회귀: 에러 표는 라우트 메타에서 대표 상태코드만 추론하는 초안인데, 재생성이 그 초안으로
     * 표를 통째로 덮어써 사람이 채운 도메인 특이 에러(403·404·422·429와 구체적 발생 조건)가
     * "대표 에러 없음" 으로 지워졌다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성_시_사람이_보강한_에러_응답_표를_보존한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        // 미들웨어·FormRequest 가 없어 자동 추론은 "대표 에러 없음" 을 낸다.
        $route = [
            'method' => 'POST', 'uri' => '/api/w', 'name' => 'api.w.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => [], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $header = "# W\n";

        $section = fn (): string => $scaffolder->endpointSection(
            $route, $request, $schema, ['status' => 200, 'skipped_reason' => null],
        );

        $first = $scaffolder->mergeDocument(null, $header, [$section()], ['api.w.store']);
        $this->assertStringContainsString('대표 에러 없음', $first);

        // 사람이 도메인 특이 에러 표를 직접 채운다.
        $humanTable = "| 상태코드 | 의미 | 발생 조건 |\n"
            ."| --- | --- | --- |\n"
            .'| 429 | Too Many Requests | 동일 IP 에서 분당 10회를 초과해 요청한 경우 |';
        $withTable = str_replace(
            '_대표 에러 없음 (공개 조회). <!-- TODO: 도메인 특이 에러가 있으면 보강 -->_',
            $humanTable,
            $first,
        );

        // 재생성해도 사람이 채운 표가 살아남는다.
        $regenerated = $scaffolder->mergeDocument($withTable, $header, [$section()], ['api.w.store']);

        $this->assertStringContainsString('429', $regenerated, '사람이 추가한 에러 행이 보존되어야 한다');
        $this->assertStringContainsString('동일 IP 에서 분당 10회', $regenerated);
        $this->assertStringNotContainsString('대표 에러 없음', $regenerated);
    }

    /**
     * 요청 예시의 path 파라미터는 실측 치환값이 아니라 placeholder 로 고정된다.
     *
     * 회귀: 실측 성공 시 `/brands/1`, 실패 시 `/brands/{brand}` 로 바뀌어 같은 엔드포인트의
     * 예시가 재실행마다 흔들렸다.
     *
     * @scenario stage=request_response_examples
     *
     * @effects request_example_is_raw_http_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 요청_예시의_path_파라미터는_placeholder_로_고정된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'DELETE', 'uri' => '/api/admin/brands/{brand}', 'name' => 'api.admin.brands.destroy',
            'controller' => 'C', 'controller_method' => 'destroy', 'permission' => null,
            'middleware' => ['auth:sanctum'], 'path_params' => ['brand'],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];

        // 실측이 실제 id 로 치환된 경우에도 예시는 placeholder 를 쓴다.
        $block = $scaffolder->requestExampleBlock(
            $route,
            $request,
            ['status' => 200, 'skipped_reason' => null, 'resolved_uri' => '/api/admin/brands/1'],
        );

        $this->assertStringContainsString('/api/admin/brands/{brand}', $block);
        $this->assertStringNotContainsString('/api/admin/brands/1', $block);
    }

    /**
     * 같은 라우트명의 GET·POST 는 서로 다른 생성블록 키를 갖는다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects example_blocks_scoped_and_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_라우트명의_ge_t_pos_t_는_서로_다른_생성블록_키를_갖는다(): void
    {
        // 회귀: Route::match(['get','post'], ...)->name('x') 는 하나의 라우트명으로 두 메서드를
        // 등록한다(KG이니시스 CBT/모바일 콜백). 생성 블록 키가 라우트명뿐이면 한 문서에 같은
        // `@generated:start:x` 가 두 번 나오고, strpos 기반 블록 조회가 언제나 첫 블록만 잡아
        // 두 번째(POST) 블록의 사람 서술이 재생성마다 유실된다.
        // (실측: pay_kginicis payment.md 의 body 파라미터 용도 12건)
        $scaffolder = new ApiDocScaffolder;

        $base = [
            'uri' => '/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
            'name' => 'web.plugins.sirsoft-pay_kginicis.payment.cbt.callback',
            'controller' => 'C', 'controller_method' => 'handle', 'permission' => null,
            'middleware' => ['web'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'pagination' => false,
            'fields' => [['name' => 'status', 'type' => 'string', 'sample' => 'ok']]];
        $probe = ['status' => 200, 'skipped_reason' => null];

        // 라우트명이 문서 안에서 중복이면 커맨드가 methodScopedKey=true 로 호출한다.
        $get = $scaffolder->endpointSection(['method' => 'GET'] + $base, $request, $schema, $probe, [], true);
        $post = $scaffolder->endpointSection(['method' => 'POST'] + $base, $request, $schema, $probe, [], true);

        $extractKey = static fn (string $s): string => (string) preg_replace(
            '/^.*<!-- @generated:start:([^ ]+) -->.*$/s', '$1', explode("\n", $s)[1]
        );

        $this->assertNotSame(
            $extractKey($get),
            $extractKey($post),
            '같은 라우트명이라도 메서드가 다르면 생성 블록 키가 달라야 한다'
        );

        // 스캐폴더의 키 생성기는 커맨드가 쓰는 섹션 키와 동일해야 한다 (mergeDocument 대조 키).
        $this->assertSame($extractKey($get), $scaffolder->generatedKey(['method' => 'GET'] + $base, true));
        $this->assertSame($extractKey($post), $scaffolder->generatedKey(['method' => 'POST'] + $base, true));

        // 중복이 아닌 라우트는 키를 그대로 둔다 (전 문서 키 변경 시 사람 서술 유실 방지).
        $this->assertSame($base['name'], $scaffolder->generatedKey(['method' => 'GET'] + $base));
    }

    /**
     * 같은 라우트명 두 메서드의 사람 서술이 각각 보존된다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_라우트명_두_메서드의_사람_서술이_각각_보존된다(): void
    {
        // 위 키 고유화가 실제 보존으로 이어지는지 — GET/POST 두 블록의 파라미터 용도가
        // 서로 섞이거나 유실되지 않고 각자 자리에 되살아나야 한다.
        $scaffolder = new ApiDocScaffolder;

        $base = [
            'uri' => '/plugins/sirsoft-pay_kginicis/payment/cbt/callback',
            'name' => 'web.plugins.sirsoft-pay_kginicis.payment.cbt.callback',
            'controller' => 'C', 'controller_method' => 'handle', 'permission' => null,
            'middleware' => ['web'], 'path_params' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'pagination' => false,
            'fields' => [['name' => 'status', 'type' => 'string', 'sample' => 'ok']]];
        $probe = ['status' => 200, 'skipped_reason' => null];

        // GET 은 query, POST 는 body 로 같은 이름의 파라미터를 받는다 (실제 콜백 계약).
        $getRequest = ['request_class' => null, 'hook_filters' => [], 'params' => [
            ['name' => 'sid', 'location' => 'query', 'type' => 'string', 'required' => false, 'allowed' => '—'],
        ]];
        $postRequest = ['request_class' => null, 'hook_filters' => [], 'params' => [
            ['name' => 'sid', 'location' => 'body', 'type' => 'string', 'required' => false, 'allowed' => '—'],
        ]];

        $getSection = $scaffolder->endpointSection(['method' => 'GET'] + $base, $getRequest, $schema, $probe, [], true);
        $postSection = $scaffolder->endpointSection(['method' => 'POST'] + $base, $postRequest, $schema, $probe, [], true);

        // 사람이 두 블록의 용도를 각각 채운 기존 문서
        $existing = "# 결제\n\n"
            .str_replace('<!-- TODO: 용도 -->', 'GET 콜백의 세션 ID', $getSection)."\n"
            .str_replace('<!-- TODO: 용도 -->', 'POST 콜백의 세션 ID', $postSection)."\n";

        $merged = $scaffolder->mergeDocument(
            $existing,
            '# 결제',
            [$getSection, $postSection],
            [
                $scaffolder->generatedKey(['method' => 'GET'] + $base, true),
                $scaffolder->generatedKey(['method' => 'POST'] + $base, true),
            ],
        );

        $this->assertStringContainsString('GET 콜백의 세션 ID', $merged);
        $this->assertStringContainsString('POST 콜백의 세션 ID', $merged, 'POST 블록의 사람 서술이 유실되면 안 된다');
        $this->assertStringNotContainsString('<!-- TODO: 용도 -->', $merged);
    }

    /**
     * 사람이 응답 필드 라벨을 고쳐도 채운 본문을 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 사람이_응답_필드_라벨을_고쳐도_채운_본문을_보존한다(): void
    {
        // 회귀: 봉투(`success`/`message`/`data`)를 쓰지 않고 JSON 을 root 에 그대로 내리는
        // 엔드포인트(LayoutPreviewController@serve 등)에서는 `**응답 필드** (`data` 내부)` 라벨이
        // 사실과 다르다. 사람이 이를 `**응답 필드**` 로 정정하면, 라벨 정확 일치를 요구하던
        // 보존 로직이 기존 본문을 찾지 못해 사람이 채운 내용이 통째로 마커로 퇴행했다.
        // (실측: docs/backend/api/layouts.md 의 미리보기 서빙 응답 필드 표)
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/layouts/{name}', 'name' => 'api.layouts.preview',
            'controller' => 'C', 'controller_method' => 'serve', 'permission' => null,
            'middleware' => ['api', 'optional.sanctum'], 'path_params' => ['name'],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => [], 'shape' => 'object', 'fields' => [], 'pagination' => false];

        // 실측 불가(unresolved-path-param) → 응답 필드 자리에 마커만 생성된다.
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => null, 'skipped_reason' => 'unresolved-path-param']);
        $this->assertStringContainsString('실측 응답에 필드 없음', $section, '마커 생성 전제 확인');

        // 사람이 라벨을 정정하고(`(data 내부)` 제거) 본문을 서술로 채운 기존 문서.
        // 표가 아니라 서술이므로 applyPreservedTableCells 의 행 키 대조로는 지켜지지 않는다 —
        // 오직 라벨로 본문 구간을 찾는 applyPreservedResponseBodies 만이 이를 되살릴 수 있고,
        // 라벨 정확 일치만 보면 사람이 고친 라벨 때문에 구간을 못 찾아 마커로 퇴행한다.
        $filled = str_replace('**응답 필드** (`data` 내부)', '**응답 필드**', $section);
        $filled = preg_replace(
            '/_단건 응답[^\n]*_\n\n<!-- 실측 응답에 필드 없음[^\n]*-->/',
            '_이 엔드포인트는 봉투를 사용하지 않고 레이아웃 JSON 을 root 에 그대로 반환합니다._',
            $filled
        );
        $this->assertStringNotContainsString('실측 응답에 필드 없음', $filled, '테스트 픽스처가 마커를 실제로 대체했는지 확인');

        $existing = "# 레이아웃\n\n".$filled;

        $merged = $scaffolder->mergeDocument($existing, '# 레이아웃', [$section], ['api.layouts.preview']);

        $this->assertStringContainsString('봉투를 사용하지 않고', $merged, '라벨을 고친 문서의 사람 본문이 보존되어야 한다');
        $this->assertStringNotContainsString('실측 응답에 필드 없음', $merged, '사람 본문이 마커로 퇴행하면 안 된다');
    }

    /**
     * 에러 응답 자리의 사람 서술은 대표 에러 없음 초안에 덮이지 않는다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 에러_응답_자리의_사람_서술은_대표_에러_없음_초안에_덮이지_않는다(): void
    {
        // 회귀: 자동 추론이 에러 행을 하나도 만들지 못하는 공개 조회 엔드포인트는
        // `_대표 에러 없음 (공개 조회). <!-- TODO ... -->_` 초안을 낸다. 사람이 그 자리를
        // "이 엔드포인트는 도메인 에러를 반환하지 않습니다 (근거...)" 같은 서술로 채우면,
        // 표 행이 없으므로 errorTableRows() 가 빈 배열을 반환해 보존이 건너뛰어지고
        // 재생성 때마다 TODO 초안으로 퇴행한다. (실측: locales.md / modules.md / plugins.md)
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/locales', 'name' => 'api.locales.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'collection', 'pagination' => false,
            'fields' => [['name' => 'code', 'type' => 'string', 'sample' => 'ko']]];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $this->assertStringContainsString('대표 에러 없음', $section, '자동 초안 전제 확인');

        $prose = '_이 엔드포인트는 도메인 에러를 반환하지 않습니다. 공개 조회이므로 인증 실패(401)가 없습니다._';
        $filled = preg_replace('/_대표 에러 없음[^\n]*_/', $prose, $section);
        $existing = "# 로케일\n\n".$filled;

        $merged = $scaffolder->mergeDocument($existing, '# 로케일', [$section], ['api.locales.index']);

        $this->assertStringContainsString('도메인 에러를 반환하지 않습니다', $merged, '사람이 쓴 에러 서술이 보존되어야 한다');
        $this->assertStringNotContainsString('대표 에러 없음', $merged, '사람 서술이 자동 초안으로 퇴행하면 안 된다');
    }

    /**
     * CRLF 문서에서도 사람이 채운 표 셀을 보존한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function crl_f_문서에서도_사람이_채운_표_셀을_보존한다(): void
    {
        // 회귀: Windows 체크아웃(core.autocrlf=true)에서 문서가 CRLF 로 저장되면
        // parseTableRow 의 `str_ends_with($line, ' |')` 가드가 줄 끝 \r 때문에 전부 실패해
        // 표 셀 보존이 통째로 무력화된다 → 사람이 채운 설명이 재생성 때마다 TODO 로 퇴행.
        // (실측: docs/backend/api/notifications.md 의 응답 필드 설명 10건 유실)
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/notifications', 'name' => 'api.admin.notifications.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = [
            'envelope' => ['data'], 'shape' => 'collection', 'pagination' => false,
            'fields' => [['name' => 'type', 'type' => 'string', 'sample' => 'new_order_admin']],
        ];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        // 사람이 설명을 채운 기존 문서를 CRLF 로 구성한다.
        $filled = str_replace(
            '<!-- TODO: 설명 -->',
            '알림 유형 식별자 (알림 정의의 type)',
            $section
        );
        $existingCrlf = str_replace("\n", "\r\n", "# 알림\n\n".$filled);

        $merged = $scaffolder->mergeDocument(
            $existingCrlf,
            '# 알림',
            [$section],
            ['api.admin.notifications.index'],
        );

        $this->assertStringContainsString('알림 유형 식별자', $merged, 'CRLF 문서의 사람 서술이 보존되어야 한다');
        $this->assertStringNotContainsString('<!-- TODO: 설명 -->', $merged, '보존된 셀이 TODO 로 퇴행하면 안 된다');
    }

    /**
     * 에러표의 권한 셀은 파이프를 이스케이프한다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects representative_errors_follow_route_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 에러표의_권한_셀은_파이프를_이스케이프한다(): void
    {
        // 회귀: permission 이 여러 권한의 OR 조합(`core.modules.read | core.menus.read`)일 때
        // 파이프를 이스케이프하지 않으면 셀이 갈라져 3열 표가 4열로 깨진다.
        // (docs/backend/api/modules.md 의 403 행 24건이 이 상태로 생성돼 있었다)
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET',
            'uri' => '/api/admin/modules',
            'name' => 'api.admin.modules.index',
            'controller' => 'C',
            'controller_method' => 'index',
            'permission' => 'core.modules.read | core.menus.read',
            'middleware' => ['api', 'auth:sanctum', 'permission:admin,core.modules.read'],
            'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'collection', 'fields' => [['name' => 'id', 'type' => 'integer', 'sample' => '1']], 'pagination' => false];

        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        // 403 행을 찾아 열 수를 센다 (이스케이프된 \| 는 셀 구분자가 아니다)
        $line = collect(explode("\n", $section))->first(fn (string $l): bool => str_starts_with($l, '| 403 |'));

        $this->assertNotNull($line, '403 에러 행이 생성되어야 한다');
        $this->assertStringContainsString('\\|', $line, '권한 셀의 파이프가 이스케이프되어야 한다');

        $cells = preg_split('/(?<!\\\\)\|/', trim($line, '| '));
        $this->assertCount(3, $cells, "403 행은 3열(상태코드/의미/발생 조건)이어야 한다: {$line}");
    }

    /**
     * 403 행의 권한 식별자는 사람 서술이 아니라 라우트에서 파생된 사실이므로, 라우트의 요구
     * 권한이 바뀌면 재생성이 그 식별자를 갱신해야 한다.
     *
     * 배경: 에러 표 병합은 같은 상태코드에서 기존 행을 이기게 둔다(409·429 같은 도메인 특이
     * 조건을 사람이 보강해 두기 때문). 그 규칙이 403 에도 그대로 걸리면 라우트 권한을 바꿔도
     * 옛 식별자가 영구히 남아, 문서가 조용히 틀린 권한을 안내한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects representative_errors_follow_route_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 라우트_권한이_바뀌면_403_행의_권한_식별자가_갱신된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $makeRoute = fn (string $permission): array => [
            'method' => 'GET',
            'uri' => '/api/admin/widgets',
            'name' => 'api.admin.widgets.index',
            'controller' => 'WidgetController',
            'controller_method' => 'index',
            'permission' => $permission,
            'middleware' => ['api', 'auth:sanctum', 'permission:admin,'.$permission],
            'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'collection', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $header = "# W\n";
        $keys = ['api.admin.widgets.index'];

        $before = $scaffolder->endpointSection($makeRoute('core.widgets.read'), $request, $schema, $probe);
        $doc = $scaffolder->mergeDocument(null, $header, [$before], $keys);
        $this->assertStringContainsString('요구 권한(`core.widgets.read`)이 없는 경우', $doc);

        // 라우트의 요구 권한이 바뀐 뒤 재생성한다.
        $after = $scaffolder->endpointSection($makeRoute('core.widgets.manage'), $request, $schema, $probe);
        $regenerated = $scaffolder->mergeDocument($doc, $header, [$after], $keys);

        $this->assertStringContainsString(
            '요구 권한(`core.widgets.manage`)이 없는 경우',
            $regenerated,
            '라우트 권한이 바뀌면 403 행의 식별자가 갱신되어야 한다'
        );
        $this->assertStringNotContainsString(
            'core.widgets.read',
            $regenerated,
            '낡은 권한 식별자가 남으면 문서가 틀린 권한을 안내한다'
        );
    }

    /**
     * 식별자 갱신이 사람이 보강한 문구까지 덮어써서는 안 된다 — 갱신 대상은 백틱 식별자뿐이다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function forbidden_행_식별자_갱신은_사람이_보강한_조건_문구를_보존한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $makeRoute = fn (string $permission): array => [
            'method' => 'DELETE',
            'uri' => '/api/admin/widgets/{widget}',
            'name' => 'api.admin.widgets.destroy',
            'controller' => 'WidgetController',
            'controller_method' => 'destroy',
            'permission' => $permission,
            'middleware' => ['api', 'auth:sanctum', 'permission:admin,'.$permission],
            'path_params' => ['widget'],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $header = "# W\n";
        $keys = ['api.admin.widgets.destroy'];

        $before = $scaffolder->endpointSection($makeRoute('core.widgets.delete'), $request, $schema, $probe);
        $doc = $scaffolder->mergeDocument(null, $header, [$before], $keys);

        // 사람이 403 행에 도메인 조건을 덧붙인다.
        $withHuman = str_replace(
            '| 403 | Forbidden | 요구 권한(`core.widgets.delete`)이 없는 경우 |',
            '| 403 | Forbidden | 요구 권한(`core.widgets.delete`)이 없거나, 시스템 위젯을 삭제하려는 경우 |',
            $doc
        );
        $this->assertStringContainsString('시스템 위젯을 삭제하려는 경우', $withHuman);

        $after = $scaffolder->endpointSection($makeRoute('core.widgets.manage'), $request, $schema, $probe);
        $regenerated = $scaffolder->mergeDocument($withHuman, $header, [$after], $keys);

        $this->assertStringContainsString(
            '요구 권한(`core.widgets.manage`)이 없거나, 시스템 위젯을 삭제하려는 경우',
            $regenerated,
            '식별자만 갱신되고 사람이 보강한 조건은 그대로 남아야 한다'
        );
    }

    /**
     * 생성 블록 **안**(표 바로 아래)의 사람 서술도 재생성에서 살아남아야 한다.
     *
     * 회귀 배경: 보존 대상이 생성 블록 **뒤**의 `**설명**` 자리뿐이라, 표를 읽는 방법을
     * 그 표 아래에 적어 둔 문단이 재생성 때마다 통째로 사라졌다 — 검색 API 문서의 정확도
     * 설명 3문단이 그렇게 없어졌고, 남은 문서는 문법상 멀쩡해 아무 신호도 나지 않았다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 생성블록_안_표_아래의_사람_서술이_재생성에서_보존된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET',
            'uri' => '/api/search',
            'name' => 'api.search',
            'controller' => 'SearchController',
            'controller_method' => 'index',
            'permission' => null,
            'middleware' => ['api'],
            'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = [
            'envelope' => ['data'],
            'shape' => 'object',
            'fields' => [['name' => 'total', 'type' => 'integer', 'sample' => '0', 'description' => '총 건수']],
            'pagination' => false,
        ];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $header = "# 검색\n";
        $keys = ['api.search'];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probe);
        $doc = $scaffolder->mergeDocument(null, $header, [$section], $keys);

        // 응답 필드 표 바로 아래(= 응답 예시 소제목 앞)에 사람이 문단을 끼운다.
        $note = "카테고리 중 하나라도 상한에 걸리면 합계도 정확하지 않습니다.\n\n> 상세: [pagination.md](../pagination.md)";
        $withProse = str_replace("\n**응답 예시**", "\n".$note."\n\n**응답 예시**", $doc);
        $this->assertStringContainsString($note, $withProse, '사전 조건: 문단이 블록 안에 놓여야 한다');

        $regenerated = $scaffolder->mergeDocument($withProse, $header, [$section], $keys);

        $this->assertStringContainsString(
            '카테고리 중 하나라도 상한에 걸리면 합계도 정확하지 않습니다.',
            $regenerated,
            '표 아래 사람 문단이 재생성에서 사라졌다'
        );
        $this->assertStringContainsString('> 상세: [pagination.md](../pagination.md)', $regenerated);
        // 문단은 여전히 응답 필드 표와 응답 예시 사이에 있어야 한다.
        $this->assertLessThan(
            strpos($regenerated, '**응답 예시**'),
            strpos($regenerated, '카테고리 중 하나라도'),
            '문단이 원래 자리를 벗어났다'
        );

        // 재재생성해도 겹쳐 쌓이지 않는다.
        $again = $scaffolder->mergeDocument($regenerated, $header, [$section], $keys);
        $this->assertSame(
            substr_count($regenerated, '카테고리 중 하나라도'),
            substr_count($again, '카테고리 중 하나라도'),
            '재생성마다 같은 문단이 겹쳐 쌓인다'
        );
    }

    /**
     * 생성 블록 밖에 사람 서술이 있는 문서는 생성 블록이 그대로면 재생성해도 한 바이트도 바뀌지 않는다.
     *
     * 회귀 배경: 재생성이 머리말(제목, 인용문, TL;DR)을 기본 문구로 갈아 끼웠고, 머리 영역을
     * 첫 `### ` 줄에서 끊어 그 뒤의 사람 소제목과 문서 전체 규약 절을 통째로 지웠다(결제
     * 플러그인 문서 178줄). 엔드포인트 서술 앞과 문서 끝에는 빈 줄이 늘었다. 문서 머리는
     * 사람이 쓴 설명을 보존한다고 약속하므로, 블록 밖은 원문 바이트 그대로여야 한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 블록_밖_사람_서술이_있는_문서는_재생성해도_바이트가_같다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = static fn (string $uri, string $name): array => [
            'method' => 'GET', 'uri' => $uri, 'name' => $name,
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = [
            'envelope' => ['data'],
            'shape' => 'object',
            'fields' => [['name' => 'total', 'type' => 'integer', 'sample' => '0', 'description' => '총 건수']],
            'pagination' => false,
        ];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $keys = ['api.pay.first', 'api.pay.second'];
        $sections = [
            $scaffolder->endpointSection($route('/api/pay/first', $keys[0]), $request, $schema, $probe),
            $scaffolder->endpointSection($route('/api/pay/second', $keys[1]), $request, $schema, $probe),
        ];

        // 기본 서술 스텁 줄을 사람 서술로 바꾼다.
        $withProse = static fn (string $section, string $prose): string => preg_replace(
            '/^\*\*설명\*\* <!-- TODO:.*-->$/mu',
            $prose,
            $section,
            1
        );

        $head = <<<'MD'
            # Pay / 통보 API 레퍼런스

            > **소유**: 사람이 쓴 머리 인용문이다. 생성 블록은 재생성이 갱신하고, 사람이 작성한 설명은 보존된다.

            ---

            ## TL;DR (5초 요약)

            ```text
            1. 사람이 쓴 요약 첫 줄
            2. 사람이 쓴 요약 둘째 줄
            ```

            ---

            ## 문서 전체에 걸리는 규약

            규약 문단.

            ### 사람이 쓴 소제목

            소제목 아래 문단.

            | 시점 | 근거 |
            | --- | --- |
            | 승인 전 | 브라우저 값 |

            ---


            MD;

        $existing = $head
            .$withProse($sections[0], "**설명**\n\n첫 엔드포인트 서술.")
            ."\n"
            .$withProse($sections[1], "**설명**\n\n둘째 엔드포인트 서술.\n\n## 문서 끝 규약\n\n끝 문단.");

        $defaultHeader = "# Pay API 레퍼런스\n\n> 기본 머리말\n\n---\n\n## TL;DR (5초 요약)\n\n```text\n1. 기본 요약\n```\n\n---\n\n";

        $regenerated = $scaffolder->mergeDocument($existing, $defaultHeader, $sections, $keys);

        $this->assertStringContainsString('### 사람이 쓴 소제목', $regenerated, '머리 영역의 사람 소제목이 지워졌다');
        $this->assertStringContainsString('1. 사람이 쓴 요약 첫 줄', $regenerated, '사람이 쓴 TL;DR 이 기본 문구로 바뀌었다');
        $this->assertSame($existing, $regenerated, '생성 블록이 그대로인데 블록 밖 바이트가 바뀌었다');

        // 재재생성도 같다.
        $this->assertSame($existing, $scaffolder->mergeDocument($regenerated, $defaultHeader, $sections, $keys));
    }

    /**
     * 사람이 더한 파라미터 표 행은 원래 자리를 지키고, 생성기가 비운 허용값 셀의 사람 값은 이어진다.
     *
     * 회귀 배경: 이번 재생성에 나타나지 않은 기존 행을 되살릴 때 표 끝에 덧붙여, 사람이 표
     * 맨 위에 둔 헤더 파라미터 행이 맨 아래로 내려갔다. 허용값 열은 생성기가 규칙에서 읽어
     * 내지 못하면 `—` 로 비우는데(상수 배열을 쓰는 in 규칙 등), 그 자리에 사람이 적어 둔
     * 값이 재생성마다 `—` 로 덮였다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 사람이_더한_표_행은_제자리를_지키고_허용값_셀의_사람_값은_이어진다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/pay/close-report', 'name' => 'api.pay.close-report',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = [
            'request_class' => null,
            'params' => [
                ['name' => 'oid', 'location' => 'body', 'type' => 'string', 'required' => true, 'allowed' => 'max 40', 'rules' => []],
                ['name' => 'deli_corp', 'location' => 'body', 'type' => 'string', 'required' => true, 'allowed' => '', 'rules' => []],
            ],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $header = "# Pay\n";
        $keys = ['api.pay.close-report'];

        $first = $scaffolder->mergeDocument(null, $header, [$section], $keys);
        $this->assertStringContainsString('| deli_corp | body | string | 예 | — |', $first, '사전 조건: 허용값을 읽어 내지 못한 셀은 `—` 다');

        // 사람이 표 맨 위에 헤더 파라미터 행을 더하고, 비어 있던 허용값을 채운다.
        $humanRow = '| X-Guest-Order-Token | header | string | 비회원 주문이면 예 | 만료 시각과 서명 | 비회원 조회 토큰. |';
        $existing = str_replace('| oid | body |', $humanRow."\n| oid | body |", $first);
        $existing = str_replace('| deli_corp | body | string | 예 | — |', '| deli_corp | body | string | 예 | 택배사 코드 목록 |', $existing);

        $regenerated = $scaffolder->mergeDocument($existing, $header, [$section], $keys);

        $this->assertLessThan(
            strpos($regenerated, '| oid | body |'),
            strpos($regenerated, $humanRow),
            '사람이 맨 위에 둔 행이 표 끝으로 밀렸다'
        );
        $this->assertStringContainsString('| deli_corp | body | string | 예 | 택배사 코드 목록 |', $regenerated, '사람이 채운 허용값이 `—` 로 덮였다');
        $this->assertSame($existing, $regenerated);
    }

    /**
     * 재생성은 기존 문서의 절 순서를 따르고, 새 절은 라우트 순서상 바로 앞 절 뒤에 둔다.
     *
     * 회귀 배경: 절을 라우트 등록 순서로만 내보내, 사람이 정한 절 순서(결제 실패 경로를 닫힘
     * 보고보다 먼저 둔 문서 등)가 재생성마다 바뀌었다. 내용은 그대로인데 절이 통째로 옮겨 가
     * 큰 diff 가 났다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 재생성은_기존_절_순서를_따르고_새_절은_라우트_순서상_앞_절_뒤에_둔다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = static fn (string $uri, string $name): array => [
            'method' => 'GET', 'uri' => $uri, 'name' => $name,
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $withProse = static fn (string $section, string $prose): string => preg_replace(
            '/^\*\*설명\*\* <!-- TODO:.*-->$/mu',
            $prose,
            $section,
            1
        );

        $sectionA = $scaffolder->endpointSection($route('/api/o/a', 'api.o.a'), $request, $schema, $probe);
        $sectionB = $scaffolder->endpointSection($route('/api/o/b', 'api.o.b'), $request, $schema, $probe);
        $sectionC = $scaffolder->endpointSection($route('/api/o/c', 'api.o.c'), $request, $schema, $probe);

        // 사람이 B 를 A 보다 앞에 두었다.
        $existing = "# 순서\n\n"
            .$withProse($sectionB, "**설명**\n\nB 서술.")
            ."\n"
            .$withProse($sectionA, "**설명**\n\nA 서술.");

        // 라우트 순서는 A, C, B 이고 C 는 새 라우트다.
        $regenerated = $scaffolder->mergeDocument(
            $existing,
            "# 순서\n",
            [$sectionA, $sectionC, $sectionB],
            ['api.o.a', 'api.o.c', 'api.o.b']
        );

        $this->assertSame($existing.$sectionC."\n", $regenerated, '기존 절 순서가 바뀌었거나 새 절이 앞 절 뒤에 오지 않았다');
    }

    /**
     * 라우트의 도메인이 바뀌어 다른 문서로 옮겨 가는 절은 원래 문서의 블록 뒤 서술을 함께 옮긴다.
     *
     * 회귀 배경: 라우트 도메인이 바뀌면 그 절은 새 문서에 기본 스텁으로 생기고, 원래 문서에서는
     * 라우트가 없는 블록으로 빠졌다. 원래 문서의 블록 뒤 서술이 어디에도 남지 않았다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 다른_문서로_옮겨_가는_절은_원래_문서의_블록_뒤_서술을_함께_옮긴다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/webhook-url', 'name' => 'api.admin.webhook-url',
            'controller' => null, 'controller_method' => null, 'permission' => null,
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.admin.webhook-url'];

        $prose = "**설명**\n\n옮겨 온 서술.\n\n- 목록 줄.\n";
        $origin = "# Webhook\n\n".preg_replace('/^\*\*설명\*\* <!-- TODO:.*-->$/mu', $prose, $section, 1);

        $regenerated = $scaffolder->mergeDocument(null, "# Webhook Url\n", [$section], $keys, ['api.admin.webhook-url' => $origin]);

        $this->assertStringContainsString(
            "<!-- @generated:end -->\n\n".$prose,
            $regenerated,
            '옮겨 간 절의 블록 뒤 서술이 사라졌다'
        );
        $this->assertStringNotContainsString('<!-- TODO: 이 엔드포인트', $regenerated);
    }

    /**
     * `**설명**` 표지가 없는 손 작성 절을 처음 변환해도 그 본문은 설명 자리에 원문 그대로 남는다.
     *
     * 회귀 배경: 생성 블록이 없던 손 작성 절의 서술은 `**설명**` 표지 뒤만 넘어갔다. 인증, 요청,
     * 응답을 문단으로 적은 절은 표지가 없어 처음 변환하는 회차에 통째로 사라졌다(개인정보 통지
     * 문서 75줄이 18줄로 줄었다).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 설명_표지가_없는_손_작성_절도_처음_변환에서_본문을_잃지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = static fn (string $method, string $uri, string $name): array => [
            'method' => $method, 'uri' => $uri, 'name' => $name,
            'controller' => 'C', 'controller_method' => 'run', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $sections = [
            $scaffolder->endpointSection($route('POST', '/api/n/test', 'api.n.test'), $request, $schema, $probe),
            $scaffolder->endpointSection($route('GET', '/api/n/preview', 'api.n.preview'), $request, $schema, $probe),
        ];
        $keys = ['api.n.test', 'api.n.preview'];

        $first = "인증: 사람이 쓴 인증 서술.\n\n```json\n{\"success\":true}\n```\n\n";
        $second = "미리보기 서술.\n";
        $existing = "# 통지\n\n### POST /api/n/test\n\n".$first."### GET /api/n/preview\n\n".$second;

        $converted = $scaffolder->mergeDocument($existing, "# 통지\n", $sections, $keys);

        $this->assertStringStartsWith("# 통지\n\n### POST /api/n/test\n<!-- @generated:start:api.n.test -->", $converted);
        $this->assertStringContainsString("<!-- @generated:end -->\n\n**설명**\n\n".$first."### GET /api/n/preview\n", $converted, '첫 절의 손 작성 본문이 사라졌다');
        $this->assertStringEndsWith("<!-- @generated:end -->\n\n**설명**\n\n".$second, $converted, '둘째 절의 손 작성 본문이 사라졌다');

        // 변환된 문서를 다시 생성해도 같다.
        $this->assertSame($converted, $scaffolder->mergeDocument($converted, "# 통지\n", $sections, $keys));
    }

    /**
     * 사람이 고친 인증 줄과 손으로 쓴 요청 예시는 인증 사실이 그대로면 유지되고, 사실이 바뀌면 갱신된다.
     *
     * 회귀 배경: 생성 블록의 인증 줄과 요청 예시는 재생성마다 자동 문구로 다시 조립돼, 사람이
     * 적어 둔 소유 확인 조건(회원 본인 로그인, 비회원 조회 토큰)과 예시의 토큰 헤더가 사라졌다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 사람이_고친_인증_줄과_요청_예시는_인증_사실이_그대로면_유지된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = static fn (array $middleware, ?string $permission = null): array => [
            'method' => 'POST', 'uri' => '/api/r/close', 'name' => 'api.r.close',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => $permission,
            'middleware' => $middleware, 'path_params' => [],
        ];
        $request = [
            'request_class' => null,
            'params' => [['name' => 'oid', 'location' => 'body', 'type' => 'string', 'required' => true, 'allowed' => 'max 40', 'rules' => []]],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $keys = ['api.r.close'];
        $header = "# R\n";

        $optional = $scaffolder->endpointSection($route(['api', 'optional.sanctum']), $request, $schema, $probe);
        $first = $scaffolder->mergeDocument(null, $header, [$optional], $keys);

        $humanAuth = '- **인증/권한**: 주문 소유자만 — 회원은 본인 로그인(Bearer, `optional.sanctum`), 비회원은 `X-Guest-Order-Token` 헤더.';
        $existing = preg_replace('/^- \*\*인증\/권한\*\*: .*$/mu', $humanAuth, $first, 1);
        $existing = preg_replace('/^Accept: application\/json$/m', "Accept: application/json\nX-Guest-Order-Token: 1759999999|abc", $existing, 1);
        $this->assertStringContainsString('X-Guest-Order-Token: 1759999999|abc', $existing, '사전 조건: 요청 예시에 사람 헤더가 있어야 한다');

        // 인증 사실이 그대로면 바이트 그대로다.
        $this->assertSame($existing, $scaffolder->mergeDocument($existing, $header, [$optional], $keys));

        // 라우트가 인증 필수로 바뀌면 인증 줄과 요청 예시가 새 생성값으로 갱신된다.
        $required = $scaffolder->endpointSection($route(['api', 'auth:sanctum']), $request, $schema, $probe);
        $changed = $scaffolder->mergeDocument($existing, $header, [$required], $keys);
        $this->assertStringContainsString('- **인증/권한**: `auth:sanctum`', $changed);
        $this->assertStringNotContainsString($humanAuth, $changed);
        $this->assertStringNotContainsString('X-Guest-Order-Token: 1759999999|abc', $changed);

        // 권한 식별자: 미들웨어 표기(`permission:admin,...,false`)로 적어 둔 같은 권한은 유지되고, 권한이 바뀌면 갱신된다.
        $read = $scaffolder->endpointSection($route(['api', 'auth:sanctum'], 'x.orders.read'), $request, $schema, $probe);
        $readDoc = $scaffolder->mergeDocument(null, $header, [$read], $keys);
        $humanPermission = '- **인증/권한**: `auth:sanctum`, `permission:admin,x.orders.read,false` (주문 화면 권한)';
        $readDoc = preg_replace('/^- \*\*인증\/권한\*\*: .*$/mu', $humanPermission, $readDoc, 1);

        $this->assertSame($readDoc, $scaffolder->mergeDocument($readDoc, $header, [$read], $keys));

        $update = $scaffolder->endpointSection($route(['api', 'auth:sanctum'], 'x.orders.update'), $request, $schema, $probe);
        $updated = $scaffolder->mergeDocument($readDoc, $header, [$update], $keys);
        $this->assertStringContainsString('`permission:x.orders.update`', $updated);
        $this->assertStringNotContainsString($humanPermission, $updated);
    }

    /**
     * 헤딩과 생성 블록 사이에 빈 줄이 있는 문서도 엔드포인트 절로 알아보고 바이트 그대로 재생성한다.
     *
     * 회귀 배경: 엔드포인트 절의 시작을 「다음 줄이 생성 블록 시작 마커인 헤딩」으로만 보아, 헤딩
     * 뒤에 빈 줄을 둔 문서는 절 경계를 하나도 찾지 못했다. 블록 뒤 서술이 문서 끝까지 이어진 것으로
     * 읽혀 같은 절이 겹쳐 쌓였다(에디터 업로드 문서).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 헤딩_뒤_빈_줄이_있는_문서도_절_경계를_찾아_바이트_그대로_재생성한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = static fn (string $uri, string $name): array => [
            'method' => 'GET', 'uri' => $uri, 'name' => $name,
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $keys = ['api.u.index', 'api.u.show'];
        $sections = [
            $scaffolder->endpointSection($route('/api/u', $keys[0]), $request, $schema, $probe),
            $scaffolder->endpointSection($route('/api/u/one', $keys[1]), $request, $schema, $probe),
        ];

        // 헤딩 다음에 빈 줄을 하나 끼우고, 각 절 뒤에 사람 서술을 둔다.
        $withGap = static fn (string $section, string $prose): string => preg_replace(
            '/^\*\*설명\*\* <!-- TODO:.*-->$/mu',
            $prose,
            preg_replace('/^(### [^\n]+)\n/', "$1\n\n", $section, 1),
            1
        );
        $existing = "# U\n\n".$withGap($sections[0], "**설명**\n\n목록 서술.")."\n".$withGap($sections[1], "**설명**\n\n단건 서술.");

        $this->assertSame($existing, $scaffolder->mergeDocument($existing, "# U\n", $sections, $keys));
    }

    /**
     * 엔드포인트 절을 하나도 알아볼 수 없는 손 작성 문서는 원문 전체를 그대로 두고 생성 절을 뒤에 붙인다.
     *
     * 회귀 배경: 엔드포인트를 `## ` 제목으로만 적은 손 작성 문서는 절 경계가 없어 머리말 대체 경로로
     * 갔고, 제목과 인용문, TL;DR 이 기본 문구로 바뀌며 본문 일부가 사라졌다(결제 플러그인 문서 154줄).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 엔드포인트_절이_없는_손_작성_문서는_원문_전체를_그대로_둔다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/e/list', 'name' => 'api.e.list',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        $existing = <<<'MD'
            # 에스크로 API 레퍼런스

            > **소유**: 사람이 쓴 인용문.

            ## TL;DR (5초 요약)

            ```text
            1. 사람이 쓴 요약
            ```

            ## 에스크로 결제 목록 조회

            `GET /api/e/list` 사람이 쓴 설명.

            | 필드 | 설명 |
            | --- | --- |
            | tid | 거래 번호 |

            MD;

        $merged = $scaffolder->mergeDocument($existing, "# Escrow API 레퍼런스\n\n> 기본 머리말\n", [$section], ['api.e.list']);

        $this->assertStringStartsWith($existing, $merged, '손 작성 원문이 바뀌었다');
        $this->assertStringContainsString('<!-- @generated:start:api.e.list -->', $merged);
    }

    /**
     * `**설명**` 표지가 있는 손 작성 절도 표지 앞의 손 작성 본문(표, 예시)을 잃지 않는다.
     *
     * 회귀 배경: 표지가 있는 손 작성 절은 표지 뒤만 넘어가, 그 앞에 사람이 적은 요청 예시와 응답 예시,
     * 표가 처음 변환하는 회차에 사라졌다(알림 템플릿 문서 13개 절).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 설명_표지가_있는_손_작성_절도_표지_앞_본문을_잃지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/t/list', 'name' => 'api.t.list',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);

        $body = "**응답 예시**\n\n```json\n{\"success\":true,\"data\":[]}\n```\n\n**설명**\n\n템플릿 목록 서술.\n";
        $existing = "# T\n\n### GET /api/t/list\n\n".$body;

        $converted = $scaffolder->mergeDocument($existing, "# T\n", [$section], ['api.t.list']);

        $this->assertStringContainsString("<!-- @generated:end -->\n\n".$body, $converted, '표지 앞 손 작성 본문이 사라졌다');
        $this->assertSame($converted, $scaffolder->mergeDocument($converted, "# T\n", [$section], ['api.t.list']));
    }

    /**
     * 경로 파라미터가 든 권한 식별자도 같은 인증 사실로 판별해 사람이 고친 인증 줄과 요청 예시를 유지한다.
     *
     * 회귀 배경: 기존 인증 줄의 권한 식별자를 읽을 때 `{` 에서 끊어, `sirsoft-board.{slug}.posts.read`
     * 같은 게시판별 권한은 늘 「사실이 바뀌었다」로 판정됐다. 손으로 쓴 요청 예시가 매번 생성 예시로 덮였다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 경로_파라미터가_든_권한_식별자도_같은_인증_사실로_판별한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/boards/{slug}/attachments', 'name' => 'api.boards.attachments.upload',
            'controller' => 'C', 'controller_method' => 'upload', 'permission' => 'board.{slug}.attachments.upload',
            'middleware' => ['api', 'optional.sanctum'], 'path_params' => ['slug'],
        ];
        $request = [
            'request_class' => null,
            'params' => [['name' => 'temp_key', 'location' => 'body', 'type' => 'string', 'required' => true, 'allowed' => '', 'rules' => []]],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.boards.attachments.upload'];

        $first = $scaffolder->mergeDocument(null, "# B\n", [$section], $keys);
        $existing = preg_replace('/"temp_key": "[^"]*"/u', '"temp_key": "5f0c3a3e-1b2c-4d5e-8f90-a1b2c3d4e5f6"', $first, 1);
        $this->assertNotSame($first, $existing, '사전 조건: 요청 예시에 사람 값이 있어야 한다');

        $this->assertSame($existing, $scaffolder->mergeDocument($existing, "# B\n", [$section], $keys));
    }

    /**
     * 에러 표에서 같은 상태코드의 사람 행이 여럿이어도 모두 남고, 권한 식별자 갱신은 「요구 권한」 행에만 걸린다.
     *
     * 회귀 배경: 에러 표 병합이 상태코드 하나에 행 하나만 두어, 사람이 덧붙인 둘째 403 행(권한 범위 밖)이
     * 첫 행을 밀어냈다. 이어진 권한 식별자 갱신은 그 행의 첫 백틱 값(`auth.scope_denied`, 오류 메시지 원문)을
     * 권한 식별자로 바꿔 사람 문구를 틀린 사실로 만들었다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_상태코드의_사람_행이_여럿이어도_에러_표에서_모두_남는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'PATCH', 'uri' => '/api/admin/posts/{id}/blind', 'name' => 'api.admin.posts.blind',
            'controller' => 'C', 'controller_method' => 'blind', 'permission' => 'board.admin.manage',
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => ['id'],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.admin.posts.blind'];

        $first = $scaffolder->mergeDocument(null, "# P\n", [$section], $keys);
        $generic = '| 403 | Forbidden | 요구 권한(`board.admin.manage`)이 없는 경우 |';
        $this->assertStringContainsString($generic, $first, '사전 조건: 생성된 403 행');

        $scope = '| 403 | Forbidden | 게시판 관리 권한의 범위 밖 게시판 (`auth.scope_denied`, 1.2.1+) |';
        $existing = str_replace($generic, $generic."\n".$scope, $first);

        $this->assertSame($existing, $scaffolder->mergeDocument($existing, "# P\n", [$section], $keys));
    }

    /**
     * 파라미터 표에서 사람이 다듬은 타입, 필수, 허용값 셀은 생성값이 그 일부일 때 이어진다.
     *
     * 회귀 배경: 파라미터 표는 용도 열만 이어받아, 사람이 다듬은 `array\<integer\>`, `조건부`,
     * `min 1, 존재하는 게시판 ID` 가 재생성마다 `array`, `예`, `min 1` 로 줄었다. 생성값과 다른 사실
     * (`예` 가 `아니오` 로 바뀜, 허용값의 수치가 바뀜)은 갱신한다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 사람이_다듬은_파라미터_타입_필수_허용값_셀은_생성값이_그_일부면_이어진다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/boards/bulk-members', 'name' => 'api.admin.boards.bulk-members',
            'controller' => 'C', 'controller_method' => 'bulk', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $param = static fn (string $name, string $type, bool $required, string $allowed): array => [
            'name' => $name, 'location' => 'body', 'type' => $type, 'required' => $required, 'allowed' => $allowed, 'rules' => [],
        ];
        $request = static fn (bool $limitChanged): array => [
            'request_class' => null,
            'params' => [
                $param('board_ids', 'array', true, 'min 1'),
                $param('user_uuids', 'array', true, $limitChanged ? 'min 1, max 50' : 'min 1, max 100'),
            ],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $keys = ['api.admin.boards.bulk-members'];
        $section = $scaffolder->endpointSection($route, $request(false), $schema, $probe);

        $first = $scaffolder->mergeDocument(null, "# B\n", [$section], $keys);
        $existing = preg_replace(
            '/^\| board_ids \| body \| array \| 예 \| min 1 \|/mu',
            '| board_ids | body | array\<integer\> | `apply_all=false` 일 때 예 | min 1, 존재하는 게시판 ID |',
            $first,
            1
        );
        $existing = preg_replace(
            '/^\| user_uuids \| body \| array \| 예 \| min 1, max 100 \|/mu',
            '| user_uuids | body | array\<string\> | 예 | min 1, max 100, 존재하는 회원 UUID |',
            $existing,
            1
        );
        $this->assertStringContainsString('`apply_all=false` 일 때 예', $existing, '사전 조건: 사람이 다듬은 셀');

        $this->assertSame($existing, $scaffolder->mergeDocument($existing, "# B\n", [$section], $keys));

        // 허용값의 수치가 바뀌면 그 셀은 새 생성값으로 갱신된다.
        $changed = $scaffolder->mergeDocument($existing, "# B\n", [$scaffolder->endpointSection($route, $request(true), $schema, $probe)], $keys);
        $this->assertStringContainsString('| user_uuids | body | array\<string\> | 예 | min 1, max 50 |', $changed);
        $this->assertStringContainsString('`apply_all=false` 일 때 예 | min 1, 존재하는 게시판 ID |', $changed);
    }

    /**
     * 인증 줄과 첫 소제목 사이에 사람이 넣은 서술은 블록 안 같은 자리에 남고, 표준 소제목을 바꿔 쓴 블록은 통째로 유지된다.
     *
     * 회귀 배경: 첫 소제목 앞 구간의 사람 서술을 되돌릴 때, 그 구간의 생성 메타 줄(라우트명, 컨트롤러,
     * 인증)까지 서술로 읽어 헤딩 바로 뒤, 생성 블록 밖에 다시 붙였다. 표준 소제목 대신 사람이 고유한
     * 소제목(`**본문 파라미터**`)과 표로 블록을 다시 쓴 경우에는 그 표가 블록 밖으로 새어 나가 겹쳤다
     * (배송정책 클레임 설정 문서).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 첫_소제목_앞_사람_서술은_블록_안에_남고_소제목을_바꿔_쓴_블록은_유지된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'PUT', 'uri' => '/api/admin/policies/{id}/claim-settings', 'name' => 'api.admin.policies.claim-settings.update',
            'controller' => 'C', 'controller_method' => 'update', 'permission' => null,
            'middleware' => ['api'], 'path_params' => ['id'],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.admin.policies.claim-settings.update'];
        $first = $scaffolder->mergeDocument(null, "# P\n", [$section], $keys);

        // 표준 블록의 인증 줄 뒤에 서술과 표를 끼운다.
        $note = "부분 수정 계약입니다 — 보낸 축만 반영합니다.\n\n| 축 | 설명 |\n| --- | --- |\n| addresses | 회수지 묶음 |";
        $standard = preg_replace('/^(- \*\*인증\/권한\*\*: [^\n]*\n)/mu', "$1\n".$note."\n", $first, 1);
        $this->assertSame($standard, $scaffolder->mergeDocument($standard, "# P\n", [$section], $keys), '첫 소제목 앞 서술이 제자리를 벗어났다');

        // 표준 소제목(요청 파라미터)을 사람 소제목으로 바꿔 쓴 블록.
        $rewritten = preg_replace(
            '/\*\*요청 파라미터\*\*\n\n.*?\n\n(?=\*\*요청 예시\*\*)/su',
            "**본문 파라미터**\n\n| 파라미터 | 타입 | 필수 | 설명 |\n| --- | --- | --- | --- |\n| addresses | object | 아니오 | 회수지 묶음 |\n\n",
            $first,
            1
        );
        $this->assertStringContainsString('**본문 파라미터**', $rewritten, '사전 조건: 소제목을 바꿔 쓴 블록');
        $this->assertSame($rewritten, $scaffolder->mergeDocument($rewritten, "# P\n", [$section], $keys), '소제목을 바꿔 쓴 블록이 바뀌었다');
    }

    /**
     * 사람이 더한 메타 줄, 소제목 꼬리표, 고유 형식의 표, 보강한 「파라미터 없음」 문구는 블록 안에서 유지된다.
     *
     * 회귀 배경: 생성 메타 목록 뒤에 사람이 둔 `- **요청 제한**`, `- **권한 메모**` 줄이 생성 메타로 읽혀
     * 사라졌다. 소제목 꼬리표(`**요청 파라미터** (Body)`)와 사람이 고유한 열로 다시 그린 표, 생성기가 규칙을
     * 읽지 못해 「요청 파라미터 없음」으로 비운 자리의 사람 표와 보강 문구도 생성 초안으로 덮였다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 사람이_더한_메타_줄과_표_형식과_파라미터_없음_보강은_블록_안에서_유지된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = static fn (string $uri, string $name): array => [
            'method' => 'POST', 'uri' => $uri, 'name' => $name,
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $none = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $withParam = [
            'request_class' => null,
            'params' => [['name' => 'source', 'location' => 'body', 'type' => 'string', 'required' => true, 'allowed' => '', 'rules' => []]],
            'hook_filters' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $keys = ['api.c.store', 'api.c.check', 'api.c.limited'];
        $sections = [
            $scaffolder->endpointSection($route('/api/c', $keys[0]), $withParam, $schema, $probe),
            $scaffolder->endpointSection($route('/api/c/check', $keys[1]), $none, $schema, $probe),
            $scaffolder->endpointSection($route('/api/c/limited', $keys[2]), $none, $schema, $probe),
        ];
        $doc = $scaffolder->mergeDocument(null, "# C\n", $sections, $keys);

        // 1) 고유 형식의 표와 소제목 꼬리표, 그리고 인증 줄 뒤의 사람 메타 줄.
        $doc = preg_replace(
            '/(<!-- @generated:start:api\.c\.store -->.*?- \*\*인증\/권한\*\*: [^\n]*\n)\n\*\*요청 파라미터\*\*\n\n.*?\n\n(?=\*\*요청 예시\*\*)/su',
            "$1- **요청 제한**: `throttle:5,1`\n\n**요청 파라미터** (Body)\n\n| 파라미터 | 타입 | 필수 | 설명 |\n| --- | --- | --- | --- |\n| source | string | 예 | 변경 경로 |\n\n",
            $doc,
            1
        );
        // 2) 「파라미터 없음」 문구 보강.
        $doc = preg_replace(
            '/(<!-- @generated:start:api\.c\.check -->.*?)_요청 파라미터 없음\._/su',
            '$1_요청 파라미터 없음. 저장된 플러그인 설정을 사용합니다._',
            $doc,
            1
        );
        // 3) 생성기가 읽지 못한 파라미터를 사람이 표로 적어 둔 자리.
        $doc = preg_replace(
            '/(<!-- @generated:start:api\.c\.limited -->.*?)_요청 파라미터 없음\._/su',
            "$1| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |\n| --- | --- | --- | --- | --- | --- |\n| redirect_uri | query | string | 예 | 이 사이트 주소 | 돌려보낼 주소 |",
            $doc,
            1
        );
        $this->assertStringContainsString('- **요청 제한**: `throttle:5,1`', $doc, '사전 조건: 사람 메타 줄');
        $this->assertStringContainsString('저장된 플러그인 설정을 사용합니다', $doc, '사전 조건: 보강 문구');
        $this->assertStringContainsString('| redirect_uri | query |', $doc, '사전 조건: 사람 표');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# C\n", $sections, $keys));
    }

    /**
     * 같은 수치를 다른 말로 적은 허용값 셀과, 생성 타입을 다듬은 응답 필드 타입 셀은 이어진다.
     *
     * 회귀 배경: `max 255` 를 사람이 `최대 255자`, `min 0, max 10080` 을 `0~10080` 으로 적어 둔 셀이
     * 글자가 달라 사실이 바뀐 것으로 판정돼 덮였다. 응답 필드의 `object\|null` 처럼 생성 타입을 다듬은 셀도
     * 실측 타입과 글자가 달라 실측값으로 덮였다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_수치를_다르게_적은_허용값과_다듬은_필드_타입은_이어진다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'PUT', 'uri' => '/api/admin/policy', 'name' => 'api.admin.policy.update',
            'controller' => 'C', 'controller_method' => 'update', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = [
            'request_class' => null,
            'params' => [
                ['name' => 'password', 'location' => 'body', 'type' => 'string', 'required' => false, 'allowed' => 'max 255', 'rules' => []],
                ['name' => 'session_max_minutes', 'location' => 'body', 'type' => 'integer', 'required' => false, 'allowed' => 'min 0, max 10080', 'rules' => []],
            ],
            'hook_filters' => [],
        ];
        $schema = [
            'envelope' => ['data'],
            'shape' => 'object',
            'fields' => [['name' => 'discount', 'type' => 'object', 'sample' => '{"KRW":1}', 'description' => '할인 맵']],
            'pagination' => false,
        ];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.admin.policy.update'];

        $doc = $scaffolder->mergeDocument(null, "# P\n", [$section], $keys);
        $doc = str_replace('| password | body | string | 아니오 | max 255 |', '| password | body | string | 아니오 | 최대 255자 |', $doc);
        $doc = str_replace('| session_max_minutes | body | integer | 아니오 | min 0, max 10080 |', '| session_max_minutes | body | integer | 아니오 | 0~10080 |', $doc);
        $doc = preg_replace('/^\| discount \| object \| [^|]+ \|/mu', '| discount | object\|null | `{"KRW":{"price":3000}}` |', $doc, 1);
        $this->assertStringContainsString('| discount | object\|null |', $doc, '사전 조건: 다듬은 필드 타입');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# P\n", [$section], $keys));
    }

    /**
     * 403 행이 OR 권한의 식별자를 나눠 적었으면, 같은 권한이면 그 문구를 그대로 둔다.
     *
     * 회귀 배경: 권한 식별자 갱신이 첫 백틱 값만 OR 결합 식별자로 바꿔, `` `a` 또는 `b` `` 로 나눠 적은
     * 문구가 `` `a|b` 또는 `b` `` 로 망가졌다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 나눠_적은_or_권한_식별자는_같은_권한이면_그대로_둔다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/board-types', 'name' => 'api.admin.board-types.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => 'board.boards.create|board.settings.read',
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.admin.board-types.index'];

        $doc = $scaffolder->mergeDocument(null, "# B\n", [$section], $keys);
        $doc = preg_replace(
            '/^\| 403 \| Forbidden \| [^\n]*$/mu',
            '| 403 | Forbidden | 요구 권한(`board.boards.create` 또는 `board.settings.read`)이 모두 없는 경우 |',
            $doc,
            1
        );
        $this->assertStringContainsString('`board.boards.create` 또는 `board.settings.read`', $doc, '사전 조건: 나눠 적은 403 행');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# B\n", [$section], $keys));
    }

    /**
     * 같은 소제목이 한 블록에 두 번 있거나 같은 이름의 응답 필드 행이 여럿이어도 어느 쪽도 잃지 않는다.
     *
     * 회귀 배경: 구간을 소제목 이름으로만 모아 두 번째 `**응답 예시**` 구간이 첫 구간을 밀어내, 첫 응답
     * 예시가 사라졌다(학습용 모듈 메모 문서). 응답 필드 표의 설명도 필드 이름으로만 대조해, 같은 이름의
     * 행(찜 레코드의 `id` 와 상품의 `id`)이 마지막 행의 설명으로 덮였다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_소제목이나_같은_이름의_필드_행이_여럿이어도_잃지_않는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/wishlist', 'name' => 'api.wishlist.index',
            'controller' => 'C', 'controller_method' => 'index', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = [
            'envelope' => ['data'],
            'shape' => 'object',
            'fields' => [
                ['name' => 'id', 'type' => 'integer', 'sample' => '1', 'description' => '기본 키'],
                ['name' => 'id', 'type' => 'integer', 'sample' => '10', 'description' => '기본 키'],
            ],
            'pagination' => false,
        ];
        $keys = ['api.wishlist.index'];
        $skipped = $scaffolder->endpointSection($route, $request, $schema, ['status' => null, 'skipped_reason' => 'not-probed']);

        $doc = $scaffolder->mergeDocument(null, "# W\n", [$skipped], $keys);
        // 두 `id` 행에 서로 다른 설명을 적는다.
        $doc = preg_replace('/^\| id \| integer \| `1` \| [^\n]*$/mu', '| id | integer | `1` | 찜 레코드 식별자 |', $doc, 1);
        $doc = preg_replace('/^\| id \| integer \| `10` \| [^\n]*$/mu', '| id | integer | `10` | 상품 식별자 |', $doc, 1);
        // 응답 예시 구간을 둘로 적는다.
        $doc = preg_replace(
            '/\*\*응답 예시\*\*\n\n<!-- 실측 제외:[^\n]*-->/u',
            "**응답 예시**\n\n```json\n{\"success\":true,\"data\":[{\"id\":1}]}\n```\n\n**응답 예시**\n\n```json\n{\"success\":true,\"data\":[]}\n```",
            $doc,
            1
        );
        $this->assertSame(2, substr_count($doc, '**응답 예시**'), '사전 조건: 응답 예시 구간이 둘이어야 한다');
        $this->assertStringContainsString('찜 레코드 식별자', $doc, '사전 조건: 같은 이름 행의 설명');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# W\n", [$skipped], $keys));
    }

    /**
     * 실측 응답의 `data` 가 null 이어도 되살린 필드 표 앞에 빈 줄이 늘지 않고, 사람이 고친 에러 표 머리 줄은 유지된다.
     *
     * 회귀 배경: `data` 가 객체도 목록도 아닌 실측은 표 머리 안내 문구 없이 빈 줄 둘로 시작하는 마커를
     * 만들어, 기존 표를 되살리면 그 앞에 빈 줄이 둘 더 생겼다. 에러 표는 병합 때마다 머리 줄을 고정 문구로
     * 다시 써, 사람이 고친 열 이름(`| 상태 | 조건 | 비고 |`)이 사라졌다(본인인증 플러그인 문서).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function null_실측에서도_빈_줄이_늘지_않고_사람이_고친_에러_표_머리_줄은_유지된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/me/identity', 'name' => 'api.me.identity',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $keys = ['api.me.identity'];
        $nullData = ['envelope' => ['data'], 'shape' => 'null', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $nullData, ['status' => 200, 'skipped_reason' => null]);

        $this->assertStringNotContainsString("(`data` 내부)\n\n\n\n", $section, '빈 안내 문구가 빈 줄로 남았다');

        $doc = $scaffolder->mergeDocument(null, "# I\n", [$section], $keys);
        $doc = preg_replace(
            '/<!-- 실측 응답에 필드 없음[^\n]*-->/u',
            "| 필드 | 타입 | 예시 | 설명 |\n| --- | --- | --- | --- |\n| `method` | string | `휴대폰` | 인증 수단 |",
            $doc,
            1
        );
        $doc = str_replace('| 상태코드 | 의미 | 발생 조건 |', '| 상태 | 조건 | 비고 |', $doc);
        $this->assertStringContainsString('| 상태 | 조건 | 비고 |', $doc, '사전 조건: 사람이 고친 에러 표 머리 줄');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# I\n", [$section], $keys));
    }

    /**
     * 실측 환경 탓으로 빈 값만 늘어난 응답 예시는 기존 예시를 두고, 응답에 새 키가 생기면 새 예시를 쓴다.
     *
     * 회귀 배경: 응답 예시는 키 구조가 조금이라도 다르면 새 실측으로 바뀌었다. 인증키가 없는 개발 환경이
     * 낸 빈 목록과 null(환율 플러그인 상태), 개발 DB 에 남은 시험 데이터와 다국어 맵의 언어 키(마케팅 설정)
     * 같은 환경 차이로도 사람이 남긴 풍부한 표본이 덮였다. 판별은 `data` 아래 항목 수준(세 단계)까지의 키
     * 집합으로 하고, null 과 빈 값은 어느 구조와도 맞는 것으로 본다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 환경_탓으로_빈_값만_늘어난_응답_예시는_기존_예시를_두고_새_키가_생기면_갱신한다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/status', 'name' => 'api.admin.status',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [['name' => 'base_currency', 'type' => 'string', 'sample' => 'KRW', 'description' => '기준 통화']], 'pagination' => false];
        $keys = ['api.admin.status'];
        $section = static fn (array $body): string => $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null, 'body' => $body, 'base_url' => null]);

        $rich = ['success' => true, 'data' => [
            'base_currency' => 'KRW',
            'managed_codes' => ['USD', 'JPY'],
            'last_success_run' => ['id' => 3, 'status' => 'success'],
            'channels' => [['key' => 'email', 'label_i18n' => ['ko' => '이메일']]],
        ]];
        $poor = ['success' => true, 'data' => [
            'base_currency' => 'KRW',
            'managed_codes' => [],
            'last_success_run' => null,
            'channels' => [['key' => 'email', 'label_i18n' => ['ko' => '이메일', 'ja' => 'メール']], ['key' => 'test', 'label_i18n' => ['ko' => '시험']]],
        ]];
        $added = $rich + ['message' => '점검을 마쳤습니다.'];

        $first = $scaffolder->mergeDocument(null, "# S\n", [$section($rich)], $keys);

        $this->assertSame($first, $scaffolder->mergeDocument($first, "# S\n", [$section($poor)], $keys), '환경 탓으로 빈 실측이 풍부한 표본을 덮었다');
        $this->assertStringContainsString('"message": "점검을 마쳤습니다."', $scaffolder->mergeDocument($first, "# S\n", [$section($added)], $keys), '응답에 새 키가 생겼는데 예시가 그대로다');
    }

    /**
     * 응답 필드 표의 실측 예시값은 수치형 차이, 빈 표본, 가린 값으로는 바뀌지 않고, 행 순서도 기존 표를 따른다.
     *
     * 회귀 배경: `15.5` 와 `29` 처럼 같은 수치형이 JSON 에서 number 와 integer 로 갈리거나, 데이터가 없어
     * 빈 `[]` 가 오거나, 민감 키 이름 때문에 값이 `{MASKED}` 로 가려지면 타입이 바뀐 것으로 판정돼 기존
     * 표본이 덮였다. 행 순서도 실측 순서를 따라 바뀌었다. 기존 표본이 비어 있었으면(`[]`) 새 표본을 들인다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 수치형_차이와_빈_표본과_가린_값은_실측_예시값을_바꾸지_않고_행_순서도_유지된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/admin/summary', 'name' => 'api.admin.summary',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $field = static fn (string $name, string $type, string $sample): array => ['name' => $name, 'type' => $type, 'sample' => $sample, 'description' => $name.' 설명'];
        $section = static fn (array $fields): string => $scaffolder->endpointSection(
            $route,
            $request,
            ['envelope' => ['data'], 'shape' => 'object', 'fields' => $fields, 'pagination' => false],
            ['status' => 200, 'skipped_reason' => null]
        );
        $keys = ['api.admin.summary'];

        $before = $section([
            $field('discount_rate', 'number', '15.5'),
            $field('results', 'object', '{"21":{"status":"pending"}}'),
            $field('password_users', 'integer', '3'),
            $field('consents', 'array', '[]'),
        ]);
        $after = $section([
            $field('discount_rate', 'integer', '29'),
            $field('password_users', 'string', '{MASKED}'),
            $field('results', 'array', '[]'),
            $field('consents', 'object', '{"analytics":true}'),
        ]);

        $first = $scaffolder->mergeDocument(null, "# S\n", [$before], $keys);
        $regenerated = $scaffolder->mergeDocument($first, "# S\n", [$after], $keys);

        $this->assertStringContainsString('| discount_rate | number | `15.5` |', $regenerated);
        $this->assertStringContainsString('| results | object | `{"21":{"status":"pending"}}` |', $regenerated);
        $this->assertStringContainsString('| password_users | integer | `3` |', $regenerated);
        $this->assertStringContainsString('| consents | object | `{"analytics":true}` |', $regenerated, '빈 기존 표본은 새 표본으로 바뀌어야 한다');
        $this->assertLessThan(strpos($regenerated, '| password_users |'), strpos($regenerated, '| results |'), '행 순서가 실측 순서로 바뀌었다');
    }

    /**
     * 사람이 고유한 열로 다시 그린 파라미터 표도 사실(필수 여부, 새 파라미터)은 갱신되고 사람 셀은 유지된다.
     *
     * 회귀 배경: 머리 줄이 생성기 형식과 다른 표는 사람 소유로 보아 통째로 두었기에, 규칙이 바뀌어 필수가
     * 되거나 새 파라미터가 생겨도 문서에 반영되지 않았다. 열 이름으로 열의 역할(이름, 타입, 필수, 허용값,
     * 설명)을 맞춰 사실 열만 갱신하고, 새 파라미터는 그 표 형식으로 덧붙인다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 고유한_열로_그린_파라미터_표도_사실은_갱신되고_사람_셀은_유지된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/consents', 'name' => 'api.consents.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $param = static fn (string $name, bool $required): array => ['name' => $name, 'location' => 'body', 'type' => 'string', 'required' => $required, 'allowed' => '', 'rules' => []];
        $section = static fn (array $params): string => $scaffolder->endpointSection(
            $route,
            ['request_class' => null, 'params' => $params, 'hook_filters' => []],
            ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false],
            ['status' => 200, 'skipped_reason' => null]
        );
        $keys = ['api.consents.store'];
        $original = $section([$param('source', true), $param('channel', false)]);

        $doc = preg_replace(
            '/\*\*요청 파라미터\*\*\n\n.*?\n\n(?=\*\*요청 예시\*\*)/su',
            "**요청 파라미터** (Body)\n\n| 파라미터 | 타입 | 필수 | 설명 |\n| --- | --- | --- | --- |\n| source | string | 예 | 변경 경로 |\n| channel | string | 아니오 | 알림 채널 |\n\n",
            $scaffolder->mergeDocument(null, "# C\n", [$original], $keys),
            1
        );

        // 사실이 그대로면 바이트 그대로다.
        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# C\n", [$original], $keys));

        // channel 이 필수가 되고 locale 이 새로 생겼다.
        $changed = $scaffolder->mergeDocument($doc, "# C\n", [$section([$param('source', true), $param('channel', true), $param('locale', false)])], $keys);
        $this->assertStringContainsString('| 파라미터 | 타입 | 필수 | 설명 |', $changed, '사람 표 형식이 유지되어야 한다');
        $this->assertStringContainsString('| channel | string | 예 | 알림 채널 |', $changed, '필수 여부가 갱신되고 사람 설명은 남아야 한다');
        $this->assertMatchesRegularExpression('/^\| locale \| string \| 아니오 \| [^|]+ \|$/mu', $changed, '새 파라미터가 사람 표 형식으로 덧붙어야 한다');
    }

    /**
     * 손으로 쓴 요청 예시는 사람 값을 두고, 새 파라미터는 더하고, 없어진 파라미터는 뺀다.
     *
     * 회귀 배경: 손으로 쓴 요청 예시를 그대로 두면 규칙에서 빠진 필드가 예시에 남고 새 필드는 끝내 실리지
     * 않았다. 판별은 기존 블록의 파라미터 표를 기준으로 한다 — 기존 표에 없던 파라미터는 새로 생긴 것이라
     * 더하고, 기존 표에 있었는데 새 표에 없는 키는 빠진 것이라 뺀다. 기존 표에 있었는데 사람이 예시에서
     * 뺀 파라미터는 그대로 뺀 채 둔다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 손으로_쓴_요청_예시는_사람_값을_두고_새_파라미터는_더하고_없어진_파라미터는_뺀다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/orders/close', 'name' => 'api.orders.close',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $param = static fn (string $name, bool $required): array => ['name' => $name, 'location' => 'body', 'type' => 'string', 'required' => $required, 'allowed' => '', 'rules' => []];
        $section = static fn (array $params): string => $scaffolder->endpointSection(
            $route,
            ['request_class' => null, 'params' => $params, 'hook_filters' => []],
            ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false],
            ['status' => 200, 'skipped_reason' => null]
        );
        $keys = ['api.orders.close'];
        $original = $section([$param('oid', true), $param('price', true), $param('memo', false)]);

        $doc = $scaffolder->mergeDocument(null, "# O\n", [$original], $keys);
        // 사람이 예시 값을 고치고 선택 항목(memo)을 뺀다.
        $doc = preg_replace(
            '/\{\n    "oid": [^\n]*\n    "price": [^\n]*\n    "memo": [^\n]*\n\}/u',
            "{\n    \"oid\": \"ORD20260902001\",\n    \"price\": \"10000\"\n}",
            $doc,
            1
        );
        $this->assertStringContainsString('"oid": "ORD20260902001"', $doc, '사전 조건: 사람이 고친 예시');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# O\n", [$original], $keys));

        // price 가 빠지고 coupon 이 새로 생겼다(규칙을 읽어 낸 재생성 — 빠진 파라미터가 사실이다).
        $changed = $scaffolder->mergeDocument(
            $doc,
            "# O\n",
            [$section([$param('oid', true), $param('memo', false), $param('coupon', false)])],
            $keys,
            [],
            ['api.orders.close' => ['params_known' => true]]
        );
        $this->assertStringNotContainsString('| price | body |', $changed, '없어진 파라미터 행이 표에 남았다');
        $this->assertStringContainsString('"oid": "ORD20260902001"', $changed, '사람 값이 사라졌다');
        $this->assertStringNotContainsString('"price"', $changed, '없어진 파라미터가 예시에 남았다');
        $this->assertStringNotContainsString('"memo"', $changed, '사람이 뺀 선택 항목이 되살아났다');
        $this->assertStringContainsString('"coupon"', $changed, '새 파라미터가 예시에 실리지 않았다');
    }

    /**
     * 수치를 설정값으로 말로 적은 허용값 셀은 이어지고, 수치를 적은 셀은 수치가 바뀌면 갱신된다.
     *
     * 회귀 배경: 업로드 상한처럼 규칙의 수치가 설정에서 오면 사람은 「첨부 최대 용량 설정」처럼 말로 적는다.
     * 생성값 `max 10240` 의 수치가 그 셀에 없어 사실이 바뀐 것으로 판정돼 설정 참조 서술이 사라졌다(페이지
     * 첨부 업로드). 수치 항목은 셀에 수치가 있으면 같은 수치여야 하고, 셀에 수치가 없으면 같은 규칙 종류
     * (최대, 최소)를 말로 다루면 담은 것으로 본다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 수치를_설정값으로_말로_적은_허용값은_이어지고_적은_수치가_바뀌면_갱신된다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/attachments', 'name' => 'api.admin.attachments.upload',
            'controller' => 'C', 'controller_method' => 'upload', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $section = static fn (string $fileAllowed, string $titleAllowed): string => $scaffolder->endpointSection(
            $route,
            ['request_class' => null, 'params' => [
                ['name' => 'file', 'location' => 'body', 'type' => 'file', 'required' => true, 'allowed' => $fileAllowed, 'rules' => []],
                ['name' => 'title', 'location' => 'body', 'type' => 'string', 'required' => false, 'allowed' => $titleAllowed, 'rules' => []],
            ], 'hook_filters' => []],
            ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false],
            ['status' => 200, 'skipped_reason' => null]
        );
        $keys = ['api.admin.attachments.upload'];

        $doc = $scaffolder->mergeDocument(null, "# A\n", [$section('max 10240', 'max 255')], $keys);
        $doc = str_replace('| file | body | file | 예 | max 10240 |', '| file | body | file | 예 | 첨부 최대 용량 설정, mimetypes 허용 형식 설정 |', $doc);
        $doc = str_replace('| title | body | string | 아니오 | max 255 |', '| title | body | string | 아니오 | 최대 255자 |', $doc);
        $this->assertStringContainsString('첨부 최대 용량 설정', $doc, '사전 조건: 설정 참조 서술');

        // 설정 참조 서술 안에 다른 수치(기본값)가 있어도 규칙 종류에 붙은 수치가 아니면 설정 참조로 본다.
        $withDefault = str_replace(
            '첨부 최대 용량 설정, mimetypes 허용 형식 설정',
            'max: 첨부 최대 용량 설정(MB 를 KB 로 환산, 기본 10MB), mimetypes: 허용 형식 설정',
            $doc
        );
        $this->assertSame($withDefault, $scaffolder->mergeDocument($withDefault, "# A\n", [$section('max 10240', 'max 255')], $keys), '기본값 수치가 든 설정 참조 서술이 덮였다');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# A\n", [$section('max 10240', 'max 255')], $keys), '설정 참조 서술이 수치로 덮였다');

        // 수치를 적은 셀은 수치가 바뀌면 갱신된다.
        $changed = $scaffolder->mergeDocument($doc, "# A\n", [$section('max 20480', 'max 100')], $keys);
        $this->assertStringContainsString('첨부 최대 용량 설정, mimetypes 허용 형식 설정', $changed);
        $this->assertStringContainsString('| title | body | string | 아니오 | max 100 |', $changed);
    }

    /**
     * 사람이 목록 접두(`data[].`)를 붙여 적은 응답 필드, 경로 파라미터 타입, 배열 요소 행, 시작 마커 뒤 빈 줄은 재생성해도 그대로다.
     *
     * 회귀 배경: 사람은 목록 항목 필드를 `data[].id` 처럼 적고 생성기는 `id` 로 적어, 같은 필드가 다른 행으로
     * 읽혔다. 그래서 생성 행이 TODO 설명을 달고 새로 끼어들었다(에디터 업로드 문서 TODO 7개). 경로 파라미터는
     * 생성기가 타입을 모른 채 `string` 으로 적어 사람이 적은 `integer` 를 덮었고, 생성기가 만들지 않는 배열 요소
     * 행(`ids.*`)은 규칙을 읽어 낸 재생성에서 없어진 파라미터로 잘못 판정됐다. 시작 마커 뒤 빈 줄도 사라졌다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 접두를_붙인_필드와_경로_타입과_배열_요소_행과_마커_뒤_빈_줄은_그대로다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/uploads/{id}', 'name' => 'api.admin.uploads.bulk',
            'controller' => 'C', 'controller_method' => 'bulk', 'permission' => null,
            'middleware' => ['api'], 'path_params' => ['id'],
        ];
        $request = ['request_class' => null, 'params' => [
            ['name' => 'ids', 'location' => 'body', 'type' => 'array', 'required' => true, 'allowed' => 'min 1', 'rules' => []],
        ], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'collection', 'pagination' => true, 'fields' => [
            ['name' => 'id', 'type' => 'integer', 'sample' => '43', 'description' => '기본 키'],
            ['name' => 'hash', 'type' => 'string', 'sample' => '522254bac00b', 'description' => ''],
        ]];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.admin.uploads.bulk'];

        $doc = $scaffolder->mergeDocument(null, "# U\n", [$section], $keys);
        $doc = str_replace("<!-- @generated:start:api.admin.uploads.bulk -->\n", "<!-- @generated:start:api.admin.uploads.bulk -->\n\n", $doc);
        $doc = preg_replace('/^\| id \| path \| string \|/mu', '| id | path | integer |', $doc, 1);
        $doc = preg_replace('/^(\| ids \| body \| array \| [^\n]*)$/mu', "$1\n| ids.* | body | integer | 예 | 존재하는 업로드 ID | 각 항목은 실재하는 기록이어야 한다 |", $doc, 1);
        $doc = preg_replace('/^\| id \| integer \| `43` \| [^\n]*$/mu', '| data[].id | integer | `12` | 업로드 기록 ID |', $doc, 1);
        $doc = preg_replace('/^\| hash \| string \| `522254bac00b` \| [^\n]*$/mu', '| data[].hash | string | `a1b2c3d4e5f6` | URL 용 고유 해시 |', $doc, 1);
        $this->assertStringContainsString('| data[].hash |', $doc, '사전 조건: 접두를 붙인 사람 필드');

        $regenerated = $scaffolder->mergeDocument($doc, "# U\n", [$section], $keys, [], ['api.admin.uploads.bulk' => ['params_known' => true]]);

        $this->assertStringNotContainsString('<!-- TODO: 설명 -->', $regenerated, '같은 필드가 TODO 행으로 끼어들었다');
        $this->assertSame($doc, $regenerated);
    }

    /**
     * 생성 에러 표의 422 문구는 실제 응답 봉투 키(`errors`)를 쓰고, 예전 생성 문구는 새 문구로 바뀌며 사람이 고친 문구는 남는다.
     *
     * 회귀 배경: 422 행이 `error.errors` 에 필드별 메시지가 실린다고 안내했지만 응답 봉투의 키는 `errors` 다.
     * 기존 문서의 같은 상태코드 행은 사람 행으로 보아 이기므로, 생성기 문구를 고쳐도 기존 문서에는 반영되지
     * 않았다. 예전 생성 문구 그대로인 행만 생성기 몫으로 보고 새 문구로 바꾼다.
     *
     * @scenario stage=section_scaffold
     *
     * @effects representative_errors_follow_route_meta
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 생성_422_행은_errors_키를_쓰고_예전_생성_문구는_바뀌며_사람_문구는_남는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = static fn (string $name): array => [
            'method' => 'POST', 'uri' => '/api/'.$name, 'name' => 'api.'.$name,
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => 'R', 'params' => [
            ['name' => 'title', 'location' => 'body', 'type' => 'string', 'required' => true, 'allowed' => '', 'rules' => []],
        ], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $sections = [
            $scaffolder->endpointSection($route('a'), $request, $schema, $probe),
            $scaffolder->endpointSection($route('b'), $request, $schema, $probe),
        ];
        $keys = ['api.a', 'api.b'];

        $current = '| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |';
        $this->assertStringContainsString($current, $sections[0]);

        $legacy = '| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |';
        $human = '| 422 | Unprocessable Entity | 제목이 비었거나 255자를 넘는 경우 (`errors.title`) |';
        $doc = $scaffolder->mergeDocument(null, "# E\n", $sections, $keys);
        $doc = preg_replace('/^'.preg_quote($current, '/').'$/mu', $legacy, $doc, 1);
        $doc = preg_replace('/^'.preg_quote($current, '/').'$/mu', $human, $doc, 1);
        $this->assertStringContainsString($legacy, $doc, '사전 조건: 예전 생성 문구');

        $regenerated = $scaffolder->mergeDocument($doc, "# E\n", $sections, $keys);

        $this->assertStringNotContainsString('`error.errors`', $regenerated, '예전 생성 문구가 남았다');
        $this->assertStringContainsString($human, $regenerated, '사람이 고친 422 문구가 바뀌었다');
    }

    /**
     * 규칙을 읽지 못했으면 요청 예시를 손대지 않고, 규칙 밖에서 읽는 파라미터의 사람 행은 남으며, 마침표가 붙은 수치도 같은 수치로 본다.
     *
     * 회귀 배경: 요청 예시의 필드 동기화가 「요청 파라미터 없음」 초안을 사실로 읽어, 규칙을 읽지 못한 재생성에서
     * 손으로 쓴 예시의 쿼리와 본문이 통째로 비었다(학습용 모듈 메모, 쿠키 동의). 규칙을 읽어 낸 재생성에서는
     * 요청 클래스가 규칙 밖에서 직접 읽는 파라미터(`$this->boolean('draft')`)의 사람 행이 없어진 파라미터로
     * 판정돼 지워졌다(알림 템플릿 저장). 허용값 셀의 `max 2.` 처럼 수치 뒤에 마침표가 오면 같은 수치로 읽지
     * 못해 사람 셀이 덮였다(개인정보 통지 채널).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 규칙을_못_읽으면_요청_예시를_두고_규칙_밖_파라미터_행은_남고_마침표_붙은_수치도_같다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/templates', 'name' => 'api.admin.templates.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $probe = ['status' => 200, 'skipped_reason' => null];
        $keys = ['api.admin.templates.store'];
        $param = static fn (string $name, string $allowed): array => ['name' => $name, 'location' => 'body', 'type' => 'array', 'required' => false, 'allowed' => $allowed, 'rules' => []];
        $known = $scaffolder->endpointSection($route, ['request_class' => 'R', 'params' => [$param('channels', 'max 2')], 'hook_filters' => []], $schema, $probe);
        $unknown = $scaffolder->endpointSection($route, ['request_class' => null, 'params' => [], 'hook_filters' => []], $schema, $probe);

        $doc = $scaffolder->mergeDocument(null, "# T\n", [$known], $keys);
        $doc = str_replace('| channels | body | array | 아니오 | max 2 |', '| channels | body | array | 아니오 | 켜면 min 1, max 2. 항목은 `mail`, `database` 중 중복 없이 |', $doc);
        $doc = preg_replace('/^(\| channels \| body \| [^\n]*)$/mu', "$1\n| draft | body | boolean | 아니오 | — | `true` 면 임시 저장 |", $doc, 1);
        $doc = preg_replace('/\{\n    "channels": \[[^\]]*\]\n\}/u', "{\n    \"channels\": [\n        \"mail\"\n    ],\n    \"draft\": true\n}", $doc, 1);
        $this->assertStringContainsString('"draft": true', $doc, '사전 조건: 사람이 쓴 요청 예시');

        // 규칙을 읽어 냈고 draft 가 요청 클래스에서 읽히는 이름이면 행과 예시가 그대로다.
        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# T\n", [$known], $keys, [], [
            'api.admin.templates.store' => ['params_known' => true, 'referenced_names' => ['channels', 'draft']],
        ]));

        // 규칙을 읽지 못했으면 요청 예시를 손대지 않는다.
        $this->assertStringContainsString('"draft": true', $scaffolder->mergeDocument($doc, "# T\n", [$unknown], $keys, [], [
            'api.admin.templates.store' => ['params_known' => false],
        ]), '규칙을 못 읽은 재생성이 손으로 쓴 예시를 비웠다');
    }

    /**
     * 이스케이프한 필드 이름, 안내 문구 뒤 사람 서술, 사람이 적은 object 타입, 403 행의 오류 문구는 재생성해도 그대로다.
     *
     * 회귀 배경: 사람이 `\_is_test_mode` 처럼 밑줄을 이스케이프해 적은 행은 생성 행 `_is_test_mode` 와 다른 행으로
     * 읽혀 TODO 행 39개가 다시 끼어들었다(결제 거래 조회). 표 위 사람 서술을 되돌릴 때 생성 안내 문구까지 서술로
     * 읽어 안내 문구가 두 번 실렸다. 사람이 적은 `object` 타입은 규칙의 `array` 로 덮였다(SSO 연동 설정). 403 행의
     * 권한 식별자 갱신은 행의 첫 백틱 값을 바꿔, 앞에 적힌 오류 문구(`비밀번호가 일치하지 않습니다.`)가 권한
     * 식별자로 덮였다(게시판 비밀번호 확인).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이스케이프한_이름과_안내_뒤_서술과_object_타입과_403_오류_문구는_그대로다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/boards/posts/verify-password', 'name' => 'api.boards.posts.verify-password',
            'controller' => 'C', 'controller_method' => 'verify', 'permission' => 'board.posts.read',
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => 'R', 'params' => [
            ['name' => 'config', 'location' => 'body', 'type' => 'array', 'required' => false, 'allowed' => '', 'rules' => []],
        ], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'pagination' => false, 'fields' => [
            ['name' => '_is_test_mode', 'type' => 'boolean', 'sample' => 'true', 'description' => ''],
        ]];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.boards.posts.verify-password'];

        $doc = $scaffolder->mergeDocument(null, "# V\n", [$section], $keys);
        $doc = preg_replace('/^\| _is_test_mode \| boolean \| `true` \| [^\n]*$/mu', '| \_is_test_mode | boolean | `true` | 테스트 모드 거래 여부 |', $doc, 1);
        $doc = str_replace("_단건 응답: `data` 객체의 필드._\n\n", "_단건 응답: `data` 객체의 필드._\n\n`data` 는 원본 응답에 표시용 필드(`_` 접두)를 덧붙인 객체다.\n\n", $doc);
        $doc = preg_replace('/^\| config \| body \| array \|/mu', '| config | body | object |', $doc, 1);
        $doc = preg_replace(
            '/^\| 403 \| Forbidden \| [^\n]*$/mu',
            '| 403 | Forbidden | 비밀번호가 일치하지 않는 경우 (`비밀번호가 일치하지 않습니다.`), 요구 권한(`board.posts.read`)이 없는 경우 |',
            $doc,
            1
        );
        $this->assertStringContainsString('| \_is_test_mode |', $doc, '사전 조건: 이스케이프한 이름');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# V\n", [$section], $keys));

        // 권한이 바뀌면 「요구 권한」 자리의 식별자만 바뀌고 앞의 오류 문구는 남는다.
        $changed = $scaffolder->mergeDocument($doc, "# V\n", [$scaffolder->endpointSection(array_merge($route, ['permission' => 'board.posts.write']), $request, $schema, ['status' => 200, 'skipped_reason' => null])], $keys);
        $this->assertStringContainsString('(`비밀번호가 일치하지 않습니다.`), 요구 권한(`board.posts.write`)', $changed);
    }

    /**
     * 접두를 떼면 같은 이름이 되는 사람 행들, 남아 있는 파라미터의 하위 행, 페이지 번호 행은 규칙을 읽어 낸 재생성에서도 남는다.
     *
     * 회귀 배경: `data[].abilities.can_update` 와 `abilities.can_update` 는 접두를 떼면 같은 이름이라, 되살릴 때
     * 앞 행이 뒤 행을 「이미 있음」으로 만들어 뒤 행이 사라졌다(본인확인 정책 목록). 규칙을 읽어 낸 재생성에서는
     * 남아 있는 파라미터(`Operations`)의 하위 행(`Operations[].op`)과, 검증 규칙 없이 페이지네이터가 직접 읽는
     * `page` 행이 없어진 파라미터로 판정돼 지워졌다(SCIM, 본인확인 정책).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 접두를_떼면_같은_사람_행과_하위_행과_페이지_행은_남는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'PATCH', 'uri' => '/api/scim/users', 'name' => 'api.scim.users.patch',
            'controller' => 'C', 'controller_method' => 'patch', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => 'R', 'params' => [
            ['name' => 'Operations', 'location' => 'body', 'type' => 'array', 'required' => true, 'allowed' => '', 'rules' => []],
        ], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'pagination' => false, 'fields' => [
            ['name' => 'total', 'type' => 'integer', 'sample' => '9', 'description' => '전체 개수'],
        ]];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.scim.users.patch'];

        $doc = $scaffolder->mergeDocument(null, "# S\n", [$section], $keys);
        $doc = preg_replace(
            '/^(\| Operations \| body \| [^\n]*)$/mu',
            "| page | body | integer | 아니오 | — | 쪽 번호 |\n$1\n| Operations[].op | body | string | 예 | `add`, `replace`, `remove` | 연산 |",
            $doc,
            1
        );
        $doc = preg_replace(
            '/^(\| total \| integer \| `9` \| [^\n]*)$/mu',
            "$1\n| data[].abilities.can_update | boolean | `true` | 이 행을 고칠 수 있는지 |\n| abilities.can_update | boolean | `false` | 편집 가능 여부 |",
            $doc,
            1
        );
        $this->assertStringContainsString('| abilities.can_update |', $doc, '사전 조건: 접두를 떼면 같은 이름의 사람 행');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# S\n", [$section], $keys, [], [
            'api.scim.users.patch' => ['params_known' => true, 'referenced_names' => []],
        ]));
    }

    /**
     * 규칙에 타입이 없는 파라미터의 사람 타입, 여러 파라미터를 묶은 사람 행, 소스에 적힌 URN 이름의 행은 규칙을 읽어 낸 재생성에서도 남는다.
     *
     * 회귀 배경: 규칙이 `['nullable']` 뿐인 파라미터는 생성기가 타입을 몰라 기본값 `string` 을 적는데, 그 기본값이
     * 사람이 적은 `boolean\|string` 을 덮었다(SCIM `active`). `key / scope / target` 처럼 여러 파라미터를 한 행에
     * 묶은 사람 행과, 규칙 밖에서 읽는 URN 이름의 행은 없어진 파라미터로 판정돼 지워졌다(본인확인 정책, SCIM).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 타입_없는_규칙의_사람_타입과_묶은_행과_urn_행은_남는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/scim/users', 'name' => 'api.scim.users.store',
            'controller' => 'C', 'controller_method' => 'store', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $request = ['request_class' => 'R', 'params' => [
            ['name' => 'active', 'location' => 'body', 'type' => 'string', 'required' => false, 'allowed' => '', 'rules' => []],
        ], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.scim.users.store'];
        $urn = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

        $doc = $scaffolder->mergeDocument(null, "# S\n", [$section], $keys);
        $doc = preg_replace('/^\| active \| body \| string \|/mu', '| active | body | boolean\|string |', $doc, 1);
        $doc = preg_replace(
            '/^(\| active \| body \| [^\n]*)$/mu',
            "$1\n| key / scope / target | body | string | 아니오 | — | 운영자 정책일 때만 반영 |\n| {$urn} | body | object | 아니오 | — | 추가 속성 |",
            $doc,
            1
        );
        $this->assertStringContainsString('| key / scope / target |', $doc, '사전 조건: 묶은 사람 행');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# S\n", [$section], $keys, [], [
            'api.scim.users.store' => ['params_known' => true, 'referenced_names' => [$urn], 'untyped_params' => ['active']],
        ]));
    }

    /**
     * 생성 표 뒤에 사람이 이어 쓴 소제목, 예시, 인용, 이스케이프 없는 `X|null` 행, 사람 설명이 있는 규칙 밖 행은 남고, 생성 설명뿐인 없어진 파라미터 행은 빠진다.
     *
     * 회귀 배경: 구간의 사람 서술을 「마지막 표나 코드 블록 뒤」로만 읽어, 생성 표 뒤에 사람이 덧붙인 예시 코드
     * 블록(「오류 응답 예시」), 인용 줄, `####` 소절이 생성 산출물로 읽혀 사라졌다(이커머스 문의 답변, 주문 상세,
     * 환경설정). 이스케이프 없이 `string|null` 로 적은 행은 표 행으로 읽히지 않아 사라졌다(회원 배송지). 규칙에서
     * 빠진 파라미터는 생성기가 낸 설명뿐인 행만 사실로 지우고, 사람이 설명을 적은 행은 둔다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 생성_표_뒤_사람_내용과_유니언_행과_사람_설명_행은_남고_생성_설명뿐인_없어진_행은_빠진다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'POST', 'uri' => '/api/admin/inquiries/reply', 'name' => 'api.admin.inquiries.reply',
            'controller' => 'C', 'controller_method' => 'reply', 'permission' => null,
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => [],
        ];
        $param = static fn (string $name): array => ['name' => $name, 'location' => 'body', 'type' => 'string', 'required' => false, 'allowed' => '', 'rules' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'pagination' => false, 'fields' => [
            ['name' => 'id', 'type' => 'integer', 'sample' => '1', 'description' => '기본 키'],
        ]];
        $section = static fn (array $params): string => $scaffolder->endpointSection(
            $route,
            ['request_class' => 'R', 'params' => $params, 'hook_filters' => []],
            $schema,
            ['status' => 200, 'skipped_reason' => null]
        );
        $keys = ['api.admin.inquiries.reply'];
        $meta = ['api.admin.inquiries.reply' => ['params_known' => true, 'referenced_names' => []]];

        $doc = $scaffolder->mergeDocument(null, "# I\n", [$section([$param('content'), $param('sort_by'), $param('memo')])], $keys);
        // 사람이 memo 의 설명을 적는다. sort_by 는 생성 설명 그대로다.
        $doc = preg_replace('/^(\| memo \| body \| string \| 아니오 \| — \|) [^\n]*$/mu', '$1 사람이 적은 메모 설명 |', $doc, 1);
        // 파라미터 표 뒤 소절, 응답 필드 표 뒤 인용과 X|null 행, 에러 표 뒤 예시 블록.
        $doc = preg_replace('/^(\| memo \| body \| [^\n]*)\n/mu', "$1\n\n#### 고를 수 없는 값\n\n이 값은 화면에서 고를 수 없다.\n", $doc, 1);
        $doc = preg_replace('/^(\| id \| integer \| `1` \| [^\n]*)\n/mu', "$1\n| member.email | string|null | `user@example.com` | 회원 이메일 |\n\n> 금액은 결제 통화 기준이다.\n", $doc, 1);
        $doc = preg_replace('/(\| 401 \| [^\n]*)\n/u', "$1\n\n**오류 응답 예시** (재등록 시 — 422)\n\n```json\n{\"success\":false}\n```\n", $doc, 1);
        $this->assertStringContainsString('**오류 응답 예시**', $doc, '사전 조건: 에러 표 뒤 사람 예시');

        // 규칙에서 sort_by 와 memo 가 빠졌다.
        $regenerated = $scaffolder->mergeDocument($doc, "# I\n", [$section([$param('content')])], $keys, [], $meta);

        $this->assertStringContainsString("**오류 응답 예시** (재등록 시 — 422)\n\n```json\n{\"success\":false}\n```", $regenerated, '에러 표 뒤 사람 예시가 사라졌다');
        $this->assertStringContainsString('> 금액은 결제 통화 기준이다.', $regenerated, '표 뒤 인용이 사라졌다');
        $this->assertStringContainsString("#### 고를 수 없는 값\n\n이 값은 화면에서 고를 수 없다.", $regenerated, '표 뒤 소절이 사라졌다');
        $this->assertStringContainsString('| member.email | string|null | `user@example.com` | 회원 이메일 |', $regenerated, '이스케이프 없는 유니언 행이 사라졌다');
        $this->assertStringContainsString('| memo | body | string | 아니오 | — | 사람이 적은 메모 설명 |', $regenerated, '사람 설명이 있는 규칙 밖 행이 사라졌다');
        $this->assertStringNotContainsString('| sort_by | body |', $regenerated, '생성 설명뿐인 없어진 파라미터 행이 남았다');
    }

    /**
     * 에러 표에서 상태코드 하나가 아닌 사람 행(`200, 400, 401` 처럼 여러 코드를 묶은 행)도 제자리에 남는다.
     *
     * 회귀 배경: 에러 표 병합은 세 자리 상태코드로 시작하는 행만 모아 다시 썼기에, 여러 상태코드를 한 행에 묶어
     * 적은 사람 행이 재생성마다 사라졌다(소셜 로그인 모의 제공자 변종 표).
     *
     * @scenario stage=regeneration_merge
     *
     * @effects human_prose_preserved_on_regeneration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 에러_표의_여러_코드를_묶은_사람_행도_남는다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/mock/token', 'name' => 'api.mock.token',
            'controller' => 'C', 'controller_method' => 'token', 'permission' => null,
            'middleware' => ['api', 'auth:sanctum'], 'path_params' => [],
        ];
        $request = ['request_class' => null, 'params' => [], 'hook_filters' => []];
        $schema = ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false];
        $section = $scaffolder->endpointSection($route, $request, $schema, ['status' => 200, 'skipped_reason' => null]);
        $keys = ['api.mock.token'];

        $doc = $scaffolder->mergeDocument(null, "# M\n", [$section], $keys);
        $doc = preg_replace('/(\| 401 \| [^\n]*)\n/u', "$1\n| 200, 400, 401 | 변종 `error_body` | 제공자 오류 본문을 흉내 낸다 |\n", $doc, 1);
        $this->assertStringContainsString('| 200, 400, 401 |', $doc, '사전 조건: 여러 코드를 묶은 사람 행');

        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# M\n", [$section], $keys));
    }

    /**
     * 생성기가 「요청 파라미터 없음」을 내면, 규칙을 읽어 냈을 때만 사실로 보고 그렇지 않으면 기존 표를 둔다.
     *
     * 회귀 배경: 생성기가 규칙을 읽지 못한 경우(FormRequest 없이 컨트롤러가 직접 읽음, 규칙 호출 실패)와
     * 규칙이 정말 비어 있는 경우를 가르지 않아, 어느 한쪽으로만 처리하면 사람 표가 사라지거나 없어진
     * 파라미터가 남았다. 커맨드가 넘기는 「규칙을 읽어 냄」 여부로 가른다.
     *
     * @scenario stage=regeneration_merge
     *
     * @effects probe_changes_refresh_generated_parts
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 파라미터_없음은_규칙을_읽어_냈을_때만_사실로_본다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $route = [
            'method' => 'GET', 'uri' => '/api/mock/authorize', 'name' => 'api.mock.authorize',
            'controller' => 'C', 'controller_method' => 'show', 'permission' => null,
            'middleware' => ['api'], 'path_params' => [],
        ];
        $section = $scaffolder->endpointSection(
            $route,
            ['request_class' => null, 'params' => [], 'hook_filters' => []],
            ['envelope' => ['data'], 'shape' => 'object', 'fields' => [], 'pagination' => false],
            ['status' => 200, 'skipped_reason' => null]
        );
        $keys = ['api.mock.authorize'];
        $human = "| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |\n| --- | --- | --- | --- | --- | --- |\n| redirect_uri | query | string | 예 | 이 사이트 주소 | 돌려보낼 주소 |";
        $doc = str_replace('_요청 파라미터 없음._', $human, $scaffolder->mergeDocument(null, "# M\n", [$section], $keys));

        // 규칙을 읽어 내지 못했으면(알 수 없음) 사람 표를 둔다.
        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# M\n", [$section], $keys, [], ['api.mock.authorize' => ['params_known' => false]]));
        $this->assertSame($doc, $scaffolder->mergeDocument($doc, "# M\n", [$section], $keys));

        // 규칙을 읽어 냈는데 비어 있으면 사실로 보고 표를 뺀다.
        $known = $scaffolder->mergeDocument($doc, "# M\n", [$section], $keys, [], ['api.mock.authorize' => ['params_known' => true]]);
        $this->assertStringContainsString('_요청 파라미터 없음._', $known);
        $this->assertStringNotContainsString('| redirect_uri |', $known);
    }

    /**
     * 엔드포인트 목차 블록이 없던 손 작성 README 는 원문을 그대로 두고 목차 블록을 뒤에 붙인다.
     *
     * 회귀 배경: 목차 블록이 없는 README 는 고정 머리말과 목차만으로 새로 써, 사람이 쓴 문서 목록 표와
     * 권한 범위 절이 사라졌다(결제 플러그인 README).
     *
     * @scenario stage=readme_index
     *
     * @effects readme_index_regenerated_preserving_prose
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 목차_블록이_없던_손_작성_readme_는_원문을_그대로_둔다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $existing = "# API 레퍼런스 문서 목차\n\n> **소유**: 사람이 쓴 인용문.\n\n| 문서 | 설명 |\n| --- | --- |\n| [escrow.md](escrow.md) | 에스크로 조회 |\n\n## 관리자 경로의 주문 권한 범위\n\n사람 서술.\n";

        $readme = $scaffolder->readmeIndex('플러그인 `sirsoft-pay`', [['domain' => 'escrow', 'file' => 'escrow.md', 'count' => 2]], $existing);

        $this->assertStringStartsWith($existing, $readme, '손 작성 README 원문이 바뀌었다');
        $this->assertStringContainsString('<!-- @generated:start:api-readme-index -->', $readme);
    }

    /**
     * readme 목차 블록 뒤의 사람 서술은 재생성해도 앞뒤 빈 줄까지 그대로다.
     *
     * 회귀 배경: 목차 뒤 사람 서술을 되붙일 때 블록과 서술 사이에 빈 줄을 하나 더 넣어,
     * 엔드포인트 수만 바뀐 재생성에서도 블록 밖에 diff 가 났다. 머리말 인용문도 사람이 고친
     * 문구(손 작성 근거 안내 등)가 고정 문구로 되돌아갔다.
     *
     * @scenario stage=readme_index
     *
     * @effects readme_index_regenerated_preserving_prose
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function readme_목차_뒤_사람_서술은_재생성해도_바이트가_같다(): void
    {
        $scaffolder = new ApiDocScaffolder;

        $entries = [['domain' => 'payment', 'file' => 'payment.md', 'count' => 5]];
        $first = $scaffolder->readmeIndex('플러그인 `sirsoft-pay`', $entries);

        $existing = $first."## 사람이 서술한 레퍼런스\n\n목차 밖 사람 서술.\n";

        // 사람이 머리말 인용문을 고쳐 쓴다.
        $existing = preg_replace(
            '/^> \*\*소유\*\*:.*$/mu',
            "> **소유**: 플러그인 `sirsoft-pay`. 표의 수치는 재생성이 갱신하고, 각 문서의 서술은 코드 근거로 손으로 썼다.\n>\n> 둘째 인용 줄.",
            $existing,
            1
        );
        $this->assertStringContainsString('둘째 인용 줄.', $existing, '사전 조건: 머리말 인용문이 사람 문구여야 한다');

        $this->assertSame(
            $existing,
            $scaffolder->readmeIndex('플러그인 `sirsoft-pay`', $entries, $existing),
            '목차가 그대로인데 블록 밖 바이트가 바뀌었다'
        );
    }
}
