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
            'first_page_url' => 'https://g7_2.dev/api/admin/users?page=1',
            'path' => 'https://g7_2.dev/api/admin/users',
            'callback_url' => 'https://g7_2.dev:8443/callback',
        ], 'message' => null, 'error' => null];
        $probeMeta = ['status' => 200, 'skipped_reason' => null, 'base_url' => 'https://g7_2.dev', 'resolved_uri' => '/api/admin/users', 'body' => $body];

        $section = $scaffolder->endpointSection($route, $request, $schema, $probeMeta);

        // 실측 기준 호스트(g7_2.dev)는 응답 예시 어디에도 노출되지 않고 placeholder 로 치환된다.
        $this->assertStringNotContainsString('g7_2.dev', $section);
        $this->assertStringContainsString('https://api.example.com/api/admin/users?page=1', $section);
        // 포트가 붙은 절대 URL 도 호스트+포트 통째로 마스킹된다.
        $this->assertStringContainsString('https://api.example.com/callback', $section);
    }

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
}
