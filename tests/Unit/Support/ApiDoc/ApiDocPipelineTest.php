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

    /**
     * 목록이 아닌 큰 응답(카탈로그·스펙)도 예시 크기 상한 안으로 절단되어야 한다.
     *
     * 회귀 배경 (#518): 절단 로직이 페이지네이션 형태(`data.data[]`)만 다뤄, 편집기 스펙처럼
     * 목록이 아닌 응답이 통째로 직렬화됐다. templates.md 한 파일이 115KB → 8.65MB 가 됐다.
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
     * 회귀 배경 (#518): 전체 재생성이 `@generated` 블록의 파라미터/응답 필드 표를 통째로 다시
     * 조립하면서, 정적 추출로 재현할 수 없는 도메인 설명이 TODO 스텁으로 되돌아갔다
     * (54개 파일 1,181행). `--examples-only` 로만 우회 가능했던 것을 재생성 경로에서 막는다.
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
     * 회귀 배경 (#518): 대상 데이터가 없는 상태로 재생성하자 응답 필드 표가 "실측 응답에 필드 없음"
     * 한 줄로 대체됐다 (reviews.md 388줄 소실). "이번에 데이터가 없었다" 는 사실은 이미 문서화된
     * 필드 목록을 지울 근거가 못 된다.
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
     * 회귀 배경 (#518): `플러그인 이름 (다국어 JSON)` 이 `대상의 이름/명칭 (다국어 필드는 …)` 로,
     * `desc 는 주문별 가장 늦은 발송일` 같은 서술이 일반 문구로 대체돼 224건이 후퇴했다.
     *
     * 예시값 컬럼은 타입이 그대로면 기존 값을 유지한다 (#454 멱등성 — 실측값은 호출 시점
     * DB 표본이라 매번 덮으면 스펙이 그대로인 문서까지 재실행마다 diff 가 난다). 타입이
     * 바뀌면(스펙 변경) 새 실측값으로 갱신되므로 최신성은 그 경로로 유지된다 —
     * `재생성_시_타입이_같은_필드의_실측_예시값을_보존한다` / `신규_필드와_타입_변경은_실측_예시값을_갱신한다` 참조.
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
}
