<?php

namespace App\Support\LayoutMarkup;

use App\Enums\MarkupTokenType;

/**
 * 왕복 하네스 — `compile(decompile(json)) ≡ json` 을 검증하는 S9 의 기계 오라클.
 *
 * 역변환기의 정확성은 이 왕복으로만 봉인된다: JSON 을 `.g7.html` 로 되돌리고(decompile→serialize),
 * 그 소스를 **프로덕션 진입점**(LayoutMarkupCompiler — 주석 귀속·강검증·lint 포함)으로 다시 컴파일해
 * 원본과 **정규화 후 deep-equal** 을 본다. 설치가 거부할 산출을 오라클이 승인하면 게이트가 사각이 된다.
 *
 * **정규화 = 키 순서 무관, 리스트 순서 유지.** 실 레이아웃은 저자 임의 순서라 top-level 키 순서가
 * 컴파일러 KEY_ORDER 와 다르다(예: 손소스 순서 vs `order()`). 객체 키는 재귀 ksort 로 순서를 지우고
 * 리스트는 순서를 지켜 비교한다 — JSON 의미론적 동등성 그대로다. 빈 `{}`↔`[]` 는 `json_decode(assoc)`
 * 가 양쪽에서 똑같이 접어 발산하지 않는다.
 */
final class LayoutMarkupRoundTrip
{
    /**
     * 사어 최상위 키 — 엔진 소비 0건이라 이관 산출에 옮기지 않는다 (설계 결정 2026-07-20).
     *
     * @var array<int, string>
     */
    private const DEAD_TOP_LEVEL_KEYS = ['schema', 'pageConfig'];

    /**
     * 사어 주석 변형 접두어 — `_comment_hidden`·`_comment_removed`·`_comment_initLocal` 등.
     *
     * 정본 주석 채널은 `comment`(그리고 `_comment`)뿐이다. 이 변형들은 `LayoutService` 의 서빙 strip
     * 대상도 아니라(그 함수는 `comment`/`_comment` 두 키만 unset) 읽히지도 않으면서 브라우저까지
     * 실려 가는 순수 잡음이다 — 이관이 옮기지 않는다.
     */
    private const DEAD_COMMENT_PREFIX = '_comment_';

    /**
     * JSON 레이아웃 하나의 왕복을 검증합니다.
     *
     * 되컴파일은 **프로덕션 진입점**({@see LayoutMarkupCompiler::compile()})을 탄다 — 주석 귀속 검사·
     * 강검증·lint 까지 설치 경로와 같은 사슬이다. 종전에는 `DocumentCompiler` 를 직접 불러 그 셋을
     * 건너뛰었고, 그래서 역변환기가 같은 주석을 두 자리에 찍는 결함(named_actions)이 코퍼스 16건에서
     * 초록으로 통과했다 — 오라클이 설치보다 관대하면 게이트는 설치가 거부할 산출을 승인한다.
     *
     * @param  array<string, mixed>  $json  원본 JSON 레이아웃
     * @param  ComponentRegistry|null  $registry  검사용 레지스트리 (산출에는 쓰지 않는다)
     * @param  string|null  $path  경로 (오류 메시지용)
     * @param  CompileContext|null  $context  검증·lint 문맥 (없으면 기본 문맥 = config 정책만)
     * @return array{ok: bool, error: string|null, markup: string|null, source: array<string, mixed>, recompiled: array<string, mixed>|null, warnings: array<int, LintWarning>}
     */
    public function verify(array $json, ?ComponentRegistry $registry = null, ?string $path = null, ?CompileContext $context = null): array
    {
        $source = $json;
        $warnings = [];

        try {
            $markup = (new LayoutMarkupDecompiler($registry))->decompile($source);

            $result = (new LayoutMarkupCompiler($registry))->compile($markup, $path, $context ?? new CompileContext);
            $recompiled = $result->json;
            $warnings = $result->warnings;
        } catch (\Throwable $error) {
            return ['ok' => false, 'error' => $error->getMessage(), 'markup' => $markup ?? null, 'source' => $source, 'recompiled' => null, 'warnings' => []];
        }

        $ok = self::encode(self::canonicalize(self::normalize(self::normalizeDocument($source))))
            === self::encode(self::canonicalize(self::normalize(self::normalizeDocument($recompiled))));

        return ['ok' => $ok, 'error' => null, 'markup' => $markup, 'source' => $source, 'recompiled' => $recompiled, 'warnings' => $warnings];
    }

    /**
     * 문서 **최상위에만** 성립하는 별칭을 정본 키로 접습니다 ("입력만 받고 정규화" 의 문서 층).
     *
     * `state` 는 `initLocal` 의 deprecated 별칭이다 — 엔진은 `layoutData.initLocal || layoutData.state`
     * 로 읽으므로([TemplateApp.ts:1177](resources/js/core/TemplateApp.ts#L1177)) 별칭만 있는 문서에서
     * 둘은 동치이고, 신포맷은 initLocal 로만 방출한다(계획 §288).
     *
     * **둘 다 있으면 접지 않는다** — 그때 `state` 는 엔진이 읽지 않는 죽은 키라, 접으면 initLocal 을
     * 덮어써 동작이 바뀐다. 접지 않으면 왕복이 어긋나 `<json>` 으로 계량되고 결함(S16)으로 분류된다.
     *
     * 노드 층에는 적용하지 않는다 — 노드 키 `state` 는 이 별칭과 무관한 남남이다(코퍼스 실사용 0건).
     *
     * @param  mixed  $value  문서 루트
     * @return mixed 정규화된 문서 루트
     */
    public static function normalizeDocument(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // 사어(死語) 최상위 키 — 엔진 소비 0건이라 이관이 옮기지 않는다(설계 결정 2026-07-20).
        // `schema`/`pageConfig` 는 plugin_settings 잔재로 런타임 도달 자체가 없고(프론트 참조 0건,
        // 설정 검증은 PHP `getSettingsSchema()` 별도 축), 최상위 `actions` 는 named_actions 만 소비하는
        // 엔진에서 읽히지 않는다(extends 조립 화이트리스트에도 부재).
        foreach (self::DEAD_TOP_LEVEL_KEYS as $dead) {
            unset($value[$dead]);
        }

        // modals 객체-맵 이형 → 배열. 엔진은 배열 전용이고 백엔드 병합의 array_values 로 우연 동작
        // 중이라, 배열이 정본이다. 맵 키는 각 모달의 `id` 로 중복 보유되므로 무손실이다.
        if (isset($value['modals']) && is_array($value['modals']) && ! array_is_list($value['modals'])) {
            $value['modals'] = array_values($value['modals']);
        }

        // `state` 별칭 승격 (조건은 아래 주석 참조).
        if (isset($value['state']) && is_array($value['state']) && ! isset($value['initLocal'])) {
            $value['initLocal'] = $value['state'];
            unset($value['state']);
        }

        return $value;
    }

    /**
     * 컴파일러가 결정론적으로 파생·주입하는 값을 지워 비교를 정규화합니다 ("입력만 받고 정규화").
     *
     * 두 값은 마크업 표면이 아니라 컴파일 산출이라, 손소스에 낡은 값이 남아 있어도 이관 산출은
     * 컴파일러의 정본으로 수렴한다 — 그 수렴을 왕복 실패로 세면 안 된다:
     *
     *  - **노드 `type`**(basic/composite/layout): 레지스트리(name)에서 파생된다. 소스에 낡은
     *    type(예: Textarea 를 composite 로 캐시)이 있어도 재컴파일은 레지스트리 정본을 낸다. name 비교는
     *    엄격히 유지하므로(태그를 잘못 내면 name 이 어긋나 잡힌다) type 만 지워도 안전하다.
     *  - **`meta.is_partial`**: `<partial>` 컴파일이 항상 주입한다. 소스에 없어도 산출엔 있으므로 양쪽에서 지운다.
     *
     * @param  mixed  $value  값
     * @return mixed 정규화된 값
     */
    public static function normalize(mixed $value): mixed
    {
        // 빈 map 표식은 등가성 판정에서 빈 배열과 같다 — 한쪽 arm 은 디스크 `.json` 을
        // `json_decode(assoc: true)` 로 읽어 `{}` 를 구조적으로 잃으므로(PHP 배열은 list/map 을
        // 구분하지 않는다) 표식을 남겨 두면 등가성 게이트가 영구히 red 가 된다. 바이트 수준의
        // `{}` 보장은 {@see \Tests\Feature\LayoutMarkup\MarkupEmptyMapSerializationTest} 가 맡는다.
        if (EmptyMap::is($value)) {
            return [];
        }

        if (! is_array($value)) {
            return $value;
        }

        // 자식에 실린 빈 map 표식을 **키별 규칙보다 먼저** 배열로 접는다.
        //
        // 아래 규칙들은 `is_array($value['meta'])` · `$value['params'] === []` 처럼 배열 모양으로
        // 판정한다. 표식이 그대로 남아 있으면 같은 뜻의 두 값이 서로 다른 갈래를 타고, 디스크
        // `.json` arm 은 `json_decode(assoc: true)` 라 언제나 배열이므로 **등가성이 한쪽에서만**
        // 깨진다(빈 `meta` 가 원본에서는 제거되고 산출에는 남아 이관 게이트가 red 가 됐다).
        foreach ($value as $key => $child) {
            if (EmptyMap::is($child)) {
                $value[$key] = [];
            }
        }

        // 사어 주석 변형(`_comment_*`)은 위치 불문 제거 — 읽히지도, 서빙에서 걷히지도 않는 잡음이다.
        foreach (array_keys($value) as $key) {
            if (is_string($key) && str_starts_with($key, self::DEAD_COMMENT_PREFIX)) {
                unset($value[$key]);
            }
        }

        // `_comment`(정확히 이 이름)는 여기서 접지 않는다 — 서빙 strip 대상이라 `comment` 와 동치이지만,
        // **정규화가 키 이름을 바꾸면 방출기도 같이 바꿔야** 한다. 방출기는 `_comment` 를 `<!-- -->` 로
        // 내지 않으므로, 여기서만 접으면 원본에 없는 `comment` 키가 escape 대상으로 지목돼 터진다
        // (`$json['comment']` undefined — 실제로 3파일이 이 방식으로 깨졌다). `<!-- -->` 방출까지
        // 함께 갖추는 것은 S16 `_comment_*`→`comment` 정규화 트랙 소관이다.
        //
        // `condition` 은 `if` 의 완전 별칭이다 — 같은 평가기를 타고 `if` 가 이긴다
        // ([RenderHelpers.ts:551-559](resources/js/core/template-engine/helpers/RenderHelpers.ts#L551)).
        // 신포맷은 `data-if` 하나로만 방출하므로 별칭을 정본으로 접는다.
        //
        // 접는 조건이 둘 있다:
        //  - `if` 가 없을 것 — 있으면 `condition` 은 엔진이 안 읽는 죽은 키라, 접으면 덮어써 동작이 바뀐다.
        //  - **`{{}}` 로 감싼 식일 것** — 맨 문자열 `condition` 은 보간만 거쳐 `Boolean("...")` = 항상 참인
        //    잠복 결함이다(AGENTS.md `if` 혼합형 함정). `data-if` 로 접으면 컴파일러가 식으로 감싸 실제
        //    평가되기 시작하므로 **동작이 바뀐다** — 기계 이관이 건드릴 수 없다. 접지 않고 `<json>` 으로
        //    남겨 결함(S16 U-COND)으로 계량한다.
        if (isset($value['condition']) && is_string($value['condition'])
            && ! array_key_exists('if', $value) && str_contains($value['condition'], '{{')) {
            $value['if'] = $value['condition'];
            unset($value['condition']);
        }

        // 노드(name + 파생 type)에서 파생 type 제거.
        if (isset($value['name']) && is_string($value['name'])
            && isset($value['type']) && in_array($value['type'], ['basic', 'composite', 'layout'], true)) {
            unset($value['type']);
        }

        // 컴파일러 주입 is_partial 제거 — 그 결과 meta 가 비면 meta 자체도 제거(빈 meta 는 방출되지 않음).
        if (isset($value['meta']) && is_array($value['meta'])) {
            unset($value['meta']['is_partial']);

            if ($value['meta'] === []) {
                unset($value['meta']);
            }
        }

        // 제어 핸들러의 params.{actions,cases,conditions} → top-level (컴파일러 정본). 런타임은 둘을
        // 동치로 읽으므로(ActionDispatcher params.actions fallback) 이관은 top-level 로 수렴한다.
        if (in_array($value['handler'] ?? null, ['sequence', 'parallel', 'switch', 'conditions'], true) && isset($value['params']) && is_array($value['params'])) {
            foreach (['actions', 'cases', 'conditions'] as $controlKey) {
                if (! isset($value[$controlKey]) && isset($value['params'][$controlKey])) {
                    $value[$controlKey] = $value['params'][$controlKey];
                    unset($value['params'][$controlKey]);
                }
            }

            if ($value['params'] === []) {
                unset($value['params']);
            }
        }

        // 노드 액션 콜백의 단일 원소 배열 → 객체 (컴파일러 compileCallbackActions 미러: 1개=객체).
        // onSuccess:[{x}] 와 onSuccess:{x} 는 런타임 동치이고 컴파일 정본은 객체다 — 이관은 객체로 수렴한다.
        foreach (['onSuccess', 'onError', 'onReceive'] as $callback) {
            if (isset($value[$callback]) && is_array($value[$callback])
                && array_is_list($value[$callback]) && count($value[$callback]) === 1) {
                $value[$callback] = $value[$callback][0];
            }
        }

        // conditions 분기의 단일 원소 `then` 배열 → 객체 (컴파일러 compileConditionBranches 미러).
        // then:[{x}] 는 액션 하나짜리 sequence 라 then:{x} 와 런타임 동치이고(handleConditions),
        // 컴파일 정본은 객체다 — 콜백 단일배열과 같은 계열로 이관은 객체로 수렴한다.
        if (isset($value['then']) && is_array($value['then'])
            && array_is_list($value['then']) && count($value['then']) === 1) {
            $value['then'] = $value['then'][0];
        }

        // Select 옵션은 **정규화하지 않는다** (G13). `props.options` 와 `children:[Option]` 은 서로
        // 다른 렌더 컴포넌트로 가는 별개 shape 이지 표기 이형이 아니다 — 여기서 한쪽으로 접으면
        // 이관이 실제로 shape 를 바꿔놓아도 왕복 게이트가 green 이 된다. 실제로 그 마스킹 아래에서
        // 46건이 뒤바뀐 채 통과했다. 이제 `<options>` 래퍼가 표기 층에서 둘을 결정적으로 가른다.

        // 컴파일러가 항상 생략하는 빈 컨테이너를 제거 — `props:[]` 는 방출되지 않는다.
        //
        // 빈 섹션(`data_sources:[]`·`modals:[]`·`computed:[]`·`footer:[]`)도 같다: 마크업 표기가 없으면
        // 방출도 없고, 엔진은 "빈 목록" 과 "키 부재" 를 구분하지 않는다(양쪽 다 순회할 것이 없음).
        // 손소스의 빈 껍데기가 산출에서 사라지는 수렴을 왕복 실패로 세면 안 된다.
        foreach (['props', 'children', 'data_sources', 'modals', 'computed', 'footer'] as $container) {
            if (array_key_exists($container, $value) && $value[$container] === []) {
                unset($value[$container]);
            }
        }

        return array_map([self::class, 'normalize'], $value);
    }

    /**
     * 마크업에서 방출된 `<json>` 탈출구 키를 추출합니다 (예산 계량 — 결함 인벤토리 대조용).
     *
     * `<json key="X">` 는 키 X 를, `<json>`(무키)은 노드 전체 탈출을 `*whole-node*` 로 집계한다.
     *
     * @param  string  $markup  `.g7.html` 소스
     * @return array<int, string> 탈출 키 목록 (중복 포함)
     */
    public static function escapedKeys(string $markup): array
    {
        preg_match_all('/<json(?:\s+key="([^"]*)")?\s*>/', $markup, $matches, PREG_SET_ORDER);

        return array_map(
            static fn (array $m): string => ($m[1] ?? '') === '' ? '*whole-node*' : $m[1],
            $matches,
        );
    }

    /**
     * 마크업에서 **원시 JSON 방출** 지점을 찾습니다 (D41 — `<json>` 태그 계량의 사각).
     *
     * `escapedKeys()` 는 `<json>` 태그만 센다. 그래서 속성값(`:query='{…}'`)이나 구조 태그 본문에
     * 통짜로 직렬화된 JSON 은 escape-0 판정을 받고도 그대로 남는다 — 마크업 형식의 존재 이유(D2
     * "속성 JSON 추방")를 정면으로 무너뜨리는 자리다. 이 함수가 그 사각을 덮는다.
     *
     * **태그 예외를 두지 않는다 (D42 — 설계 검토 지적 3회차).** D41 은 `<params>`/`<fallback>`/`<props>`/
     * `<define>`/`<prop>` 을 "`decodeJsonBody` 로 객체를 받는 **설계된 정본 표기**" 로 규정해 계량에서
     * 뺐다(ⓓ 18건). 그 구분은 **성립하지 않는다** — 컴파일러가 받아 준다는 사실과 소스 파일에 JSON 이
     * 통짜로 드러난다는 사실은 별개이고, 이 포맷의 존재 이유(D2 "속성 JSON 추방")를 무너뜨리는 것은
     * 후자다. 실제로 그 제외 구간에 DataGrid 컬럼 정의 + cellChildren 컴포넌트 트리가 통째로 들어간
     * 파일이 있었다 — 태그로 작성하라고 만든 포맷 안에서 컴포넌트 트리가 JSON 인 상태.
     *
     * 그러므로 **어느 태그의 본문이든 JSON 으로 파싱되면 센다.** 계량이 좁으면 게이트는 통과시키면서
     * 아무것도 못 본다(같은 사고가 `<json>` 태그 한정 → 엔티티 이스케이프 → 태그 본문 제외 순으로
     * 세 번 반복됐다). 문법이 아직 없는 자리는 계량에서 빼는 게 아니라 **명시 대장에 등재**한다.
     *
     * **정규식이 아니라 컴파일러와 같은 토크나이저로 훑는다 (D42 — 4차 전수조사).** 종전 구현은
     * 속성·본문을 정규식 2개로 긁었는데, 그 둘에 사각이 넷 있었다. 특히 본문 패턴
     * `<tag[^>]*>([^<]*)</tag>` 은 **속성값에 `>` 가 하나라도 있으면** 여는 태그를 그 자리에서 끊어
     * 읽어 본문 매칭이 통째로 어긋난다 — 직렬화기는 인용값 안의 `>` 를 이스케이프하지 않으므로
     * (`escapeAttr` 는 `&` 와 구분 따옴표만 건드린다) `if="{{a > b}}"` 같은 흔한 비교식 하나로
     * 그 태그의 JSON 본문이 계량에서 사라진다. 실측상 `>` 를 담은 속성은 코퍼스에 1,337곳,
     * JSON 본문은 74곳이고 교집합이 우연히 0이었을 뿐이라 **구조적으로 안전한 상태가 아니었다**.
     *
     * 나머지 셋도 같은 성격이다 — 형제 요소와 섞인 JSON 텍스트(`[^<]*` 가 못 봄), 태그명에 `_` 가
     * 섞인 경우(`[a-z0-9-]*` 밖), 본문 JSON 앞에 텍스트가 붙은 경우. 어휘 해석을 손으로 다시
     * 구현하는 한 이런 사각은 계속 생기므로, 컴파일러가 쓰는 토크나이저를 그대로 빌려 없앤다.
     *
     * 라벨과 순서는 종전과 동일하다(속성 전량 → 본문 전량). 전환 시점 코퍼스 637파일 실측에서
     * 종전 정규식과 **건수·라벨이 동일**함을 확인했다.
     *
     * **컴포넌트 노드의 텍스트는 세지 않는다 (D53 — 설계 결정).** 컴포넌트 태그의 본문은 화면에 그려질
     * 글자다. 그 글자가 JSON 모양일 수 있고(관리자에게 결제사 요청·응답 페이로드를 그대로 보여주는
     * 자리가 실재한다), 그건 **구조를 직렬화한 것이 아니라 콘텐츠**라 어떤 문법으로도 없앨 수 없다.
     * 세면 목표 0 에 영원히 도달하지 못하는 항목이 대장에 남아, 진짜 잔여와 구분되지 않는다.
     *
     * **D41 의 실수와는 판별자가 다르다.** 그때는 `<params>`/`<prop>` 같은 **태그 이름 목록**으로
     * 뺐고("설계된 정본 표기" 라는 규정), 그 목록 안에 DataGrid 컬럼 트리가 통째로 숨었다. 여기서
     * 쓰는 판별자는 목록이 아니라 **레지스트리**다 — 컴파일러가 노드를 해석할 때 쓰는 바로 그
     * SSoT 이고, 구조 태그(`<prop>`·`<params>`·`<define>`·`<field>`…)는 컴포넌트가 아니라서
     * 애초에 이 분기에 들어올 수 없다. 즉 "사람이 화면에 띄우려 쓴 글자냐, 기계가 구조를 눌러 담은
     * 결과냐" 를 가른다.
     *
     * 레지스트리를 주지 않으면 종전대로 **전부 센다**(보수적 기본값 — 판별할 수 없으면 빼지 않는다).
     *
     * @param  string  $markup  마크업 소스
     * @param  ComponentRegistry|null  $registry  대상 레지스트리 (주면 컴포넌트 노드 텍스트를 제외)
     * @return array<int, string> 방출 지점 라벨 (`:query` 속성명 / `$switch` / `<params>` 태그명)
     */
    public static function rawJsonEmissions(string $markup, ?ComponentRegistry $registry = null): array
    {
        try {
            $tokens = (new MarkupTokenizer)->tokenize($markup, null);
        } catch (\Throwable) {
            // 어휘 해석이 안 되는 문자열은 `.g7.html` 이 아니다. 계량할 대상이 없다고 보고 비운다
            // (컴파일 가능 여부는 이 계량기가 아니라 컴파일 게이트가 판정한다).
            return [];
        }

        $attributeHits = [];
        $bodyHits = [];
        $open = [];

        foreach ($tokens as $token) {
            if ($token->type === MarkupTokenType::StartTag) {
                // 객체/배열이 값으로 박힌 속성 — 스칼라(:async="true" 등)는 정상 문법이라 제외.
                foreach ($token->attributes as $attribute) {
                    if ($attribute->value === null || ! str_starts_with($attribute->name, ':')) {
                        continue;
                    }

                    $raw = self::decodeMarkupText($attribute->value);

                    if (! self::isStructuredJson($raw)) {
                        continue;
                    }

                    $attributeHits[] = str_contains($raw, '"$switch"') ? '$switch' : $attribute->name;
                }

                if (! $token->selfClosing) {
                    $open[] = $token->name;
                }

                continue;
            }

            if ($token->type === MarkupTokenType::EndTag) {
                array_pop($open);

                continue;
            }

            // 태그 본문 JSON — **태그 이름을 가리지 않는다**(D42). 본문이 JSON 객체/배열로 파싱되면
            // 그 자체가 소스 파일에 드러난 통짜 JSON 이다. 라벨은 태그명으로 남겨 어디를 고쳐야 하는지
            // 게이트 메시지만 보고 알 수 있게 한다.
            if ($token->type !== MarkupTokenType::Text || $open === []) {
                continue;
            }

            // 컴포넌트 노드의 텍스트 = 화면에 그려질 콘텐츠. 문법으로 없앨 대상이 아니다(D53).
            if ($registry?->resolveTag((string) end($open)) !== null) {
                continue;
            }

            $raw = self::decodeMarkupText((string) $token->text);

            if (! self::isStructuredJson($raw)) {
                continue;
            }

            // `$switch` 는 `<switch-value>` 가 정본이라 태그명 대신 문법 이름으로 지목한다.
            $bodyHits[] = str_contains($raw, '"$switch"') ? '$switch' : '<'.strtolower((string) end($open)).'>';
        }

        return [...$attributeHits, ...$bodyHits];
    }

    /**
     * 마크업 텍스트의 HTML 엔티티를 풀어 원값으로 되돌립니다 (계량 전처리).
     *
     * 직렬화기는 값 안에 `'` 가 섞여 작은따옴표로 감쌀 수 없으면 큰따옴표로 감싸고 내부 `"` 를
     * `&quot;` 로 이스케이프한다. 그 형태를 그대로 `json_decode` 에 넣으면 실패하므로, **원시 JSON
     * 인데도 계량에서 빠진다** — 같은 파일 안에서 인용 형태만 다른 두 blob 중 하나만 세지는
     * 비대칭이 실제로 있었다. 디코드 후 판정해야 두 형태가 같게 취급된다.
     *
     * @param  string  $value  마크업 원문 조각
     * @return string 엔티티를 푼 값 (앞뒤 공백 제거)
     */
    private static function decodeMarkupText(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * 원시 JSON 으로 셀 값인지 판정합니다 — 구조를 가진 객체/배열만 해당 (D42).
     *
     * 빈 컬렉션(`[]`·`{}`)은 **구조가 없는 값 리터럴**이다(`null`·`true`·`0` 과 동급). 직렬화된 구조가
     * 소스 파일에 드러난 것이 아니므로 세지 않는다 — `:modalStack="[]"` 는 문법으로 없앨 대상이
     * 아니라 그 자체가 정상 표기다. 그 밖의 축소는 하지 않는다(태그 이름·컨테이너 종류로 빼지 않음).
     *
     * @param  string  $raw  엔티티를 푼 값
     * @return bool 구조를 가진 JSON 이면 true
     */
    private static function isStructuredJson(string $raw): bool
    {
        if ($raw === '' || (! str_starts_with($raw, '{') && ! str_starts_with($raw, '['))) {
            return false;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && $decoded !== [];
    }

    /**
     * 값을 정규화합니다 — 연관 배열은 키 정렬(순서 무관), 리스트는 순서 유지.
     *
     * @param  mixed  $value  값
     * @return mixed 정규화된 값
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }

        ksort($value);

        return array_map([self::class, 'canonicalize'], $value);
    }

    /**
     * 정규화된 값을 결정적 JSON 문자열로 인코드합니다 (비교·diff 용).
     *
     * @param  mixed  $value  값
     * @return string JSON 문자열
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
