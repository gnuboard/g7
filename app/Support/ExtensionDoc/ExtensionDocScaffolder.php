<?php

namespace App\Support\ExtensionDoc;

/**
 * 확장 문서 스캐폴더
 *
 * 자동 생성 블록의 **안쪽만** 교체하고, 블록 밖 전부를 사람 영역으로 보존합니다.
 * 파괴적 재생성 플래그를 두지 않는 것이 이 클래스의 계약입니다 — 기본 동작이 "블록 안쪽
 * 교체" 이므로 사람이 쓴 서술이 소실될 경로 자체가 없습니다. 신규 파일 생성은 별도 경로
 * (`skeleton()`)로 분리되어 있고 기존 파일을 덮어쓰지 않습니다.
 *
 * 문서에 없는 블록 키는 **주입하지 않습니다.** 생성기가 임의 위치에 표를 끼워 넣으면
 * 사람이 잡아 둔 문서 구조가 흔들리기 때문입니다. 대신 누락으로 보고하여 사람이 마커를
 * 놓을 자리를 정하게 합니다.
 */
class ExtensionDocScaffolder
{
    /**
     * @var string 자동 생성 블록 시작 마커 접두
     */
    public const GEN_PREFIX = '<!-- @generated:';

    /**
     * @var string 미채움 축 라벨 — 마커를 지우고 서술을 쓰지 않은 빈 `@intent` 블록
     */
    public const EMPTY_INTENT_LABEL = '(빈 서술)';

    /**
     * @var string 미채움 마커 — 설계 의도 / 소개 서술
     */
    public const TODO_INTENT = 'TODO: 의도';

    /**
     * @var string 미채움 마커 — 핵심 흐름 / 동작 방식 다이어그램
     */
    public const TODO_FLOW = 'TODO: 흐름';

    /**
     * @var string 미채움 마커 — 금지 패턴
     */
    public const TODO_FORBIDDEN = 'TODO: 금지패턴';

    /**
     * @var string 미채움 마커 — 운영자 사용 시나리오
     */
    public const TODO_USAGE = 'TODO: 사용방법';

    /**
     * @var string 미채움 마커 — 트러블슈팅
     */
    public const TODO_TROUBLESHOOTING = 'TODO: 트러블슈팅';

    /**
     * 문서별 필수 섹션 헤딩과 자동 생성 블록 키.
     *
     * 검사 스크립트·계약 테스트가 같은 정의를 소비하도록 이 배열이 SSoT 입니다.
     *
     * @var array<string, array{types: array<int, string>, sections: array<int, string>, blocks: array<int, string>}>
     */
    public const DOCUMENTS = [
        'AGENTS.md' => [
            'types' => ['module', 'plugin', 'template'],
            'sections' => ['TL;DR (5초 요약)', '1. 이 확장은 무엇인가', '2. 디렉토리 지도', '3. 핵심 흐름', '4. 확장점', '5. 수정 시 동반 의무', '6. 금지 패턴', '7. 테스트 실행', '8. 문서 목차'],
            'blocks' => ['directory-map', 'extension-points-summary', 'test-commands', 'docs-index'],
        ],
        'README.md' => [
            'types' => ['module', 'plugin', 'template'],
            'sections' => ['소개', '주요 기능', '동작 방식', '요구 사항', '설치', '관리자 설정', '사용 방법', '다른 확장과의 연동', '문서', '트러블슈팅', '변경 이력', '라이선스'],
            // 템플릿은 관리자 설정 화면을 갖지 않는다 — 같은 자리에 제공 컴포넌트 요약을 둔다.
            'sectionOverrides' => [
                'template' => ['관리자 설정' => '제공 컴포넌트'],
            ],
            'blocks' => ['badges', 'requirements', 'install', 'settings-summary', 'integrations', 'docs-index'],
        ],
        'docs/README.md' => [
            'types' => ['module', 'plugin', 'template'],
            'sections' => ['문서 목차'],
            'blocks' => ['stats', 'doc-toc'],
        ],
        'docs/architecture.md' => [
            'types' => ['module', 'plugin', 'template'],
            'sections' => ['설계 의도', '계층 지도', '디렉토리'],
            'blocks' => ['directory-map'],
        ],
        'docs/extension-points.md' => [
            'types' => ['module', 'plugin'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '발행 훅' => 'hooks-published',
                '구독 훅' => 'hooks-subscribed',
                '훅 리스너' => 'listeners',
                '레이아웃 확장' => 'layout-extensions',
                '미들웨어' => 'middleware',
                '브로드캐스트 채널' => 'channels',
                '스케줄' => 'schedules',
                '알림 정의' => 'notifications',
            ],
        ],
        'docs/data-model.md' => [
            'types' => ['module', 'plugin'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '모델' => 'models',
                '소유 테이블' => 'tables',
                '마이그레이션' => 'migrations',
                'Enum' => 'enums',
                'Repository' => 'repositories',
            ],
        ],
        'docs/settings.md' => [
            'types' => ['module', 'plugin'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '설정 스키마' => 'settings-schema',
                '권한' => 'permissions',
                '메뉴' => 'menus',
                '라우트' => 'routes',
                '의존 관계' => 'dependencies',
            ],
        ],
        'docs/frontend.md' => [
            'types' => ['module', 'plugin'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '레이아웃' => 'layouts',
                '액션 핸들러' => 'handlers',
                '전역 진입점' => 'frontend-entry',
                '에셋' => 'assets',
            ],
        ],
        'docs/components.md' => [
            'types' => ['template'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '제공 컴포넌트' => 'components',
            ],
        ],
        'docs/layouts.md' => [
            'types' => ['template'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '레이아웃 목록' => 'layouts',
                '라우트 매핑' => 'layout-map',
                // 템플릿의 `extensions/{module-identifier}/*.json` 은 그 모듈/플러그인이
                // 발행한 레이아웃 확장 조각을 이 템플릿이 오버라이드한 것이다(모듈/플러그인
                // 쪽 `resources/extensions/` 와는 반대 방향 — 발행이 아니라 대체). 모듈/플러그인
                // 문서의 `docs/extension-points.md` 는 템플릿에 존재하지 않아 자리가 없었다.
                '확장 오버라이드' => 'template-overrides',
            ],
        ],
        'docs/handlers.md' => [
            'types' => ['template'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '템플릿 전용 핸들러' => 'handlers',
                '부트스트랩' => 'frontend-entry',
            ],
        ],
        // 편집기 스펙은 **세 유형 모두** 가진다. 스펙을 두지 않은 확장에도 문서를 두는 것은
        // 미보유가 곧 정상일 수 있기 때문이다 — "이 확장은 왜 편집기 스펙이 없어도 되는가 /
        // 언제 필요해지는가" 를 적을 자리가 없으면, 다음 사람이 그 부재를 누락으로 오해하거나
        // 반대로 필요한 시점을 놓친다. 미보유 확장의 블록은 그 사실을 명시하고 사람 서술로
        // 이어진다.
        'docs/editor-spec.md' => [
            'types' => ['module', 'plugin', 'template'],
            // 절 ↔ 블록은 **키로** 묶는다. 순번 결합은 절을 하나 끼우는 순간 그 뒤 블록이
            // 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재·블록 존재를 각각만 보는 게이트는
            // 그 어긋남을 잡지 못한다. 필수 절 목록은 이 배열의 키에서 파생한다(중복 정의 없음).
            'blocks' => [
                '선언 요약' => 'editor-spec-summary',
                '선언 블록' => 'editor-spec-blocks',
                '컴포넌트 팔레트' => 'editor-spec-palette',
                '샘플 데이터와 페이지 상태' => 'editor-spec-samples',
                '수정 시 동반 의무' => 'editor-spec-obligations',
            ],
        ],
    ];

    /**
     * 확장 유형에 해당하는 문서 목록을 반환합니다.
     *
     * @param  string  $type  확장 유형
     * @return array<int, string> 문서 상대 경로 목록
     */
    public static function documentsForType(string $type): array
    {
        $docs = [];

        foreach (self::DOCUMENTS as $rel => $meta) {
            if (in_array($type, $meta['types'], true)) {
                $docs[] = $rel;
            }
        }

        return $docs;
    }

    /**
     * 문서의 필수 섹션 목록을 확장 유형에 맞춰 반환합니다.
     *
     * 같은 문서라도 유형에 따라 한 절의 정체가 달라집니다 — 템플릿 README 의 `관리자 설정`
     * 자리는 설정 화면이 없으므로 `제공 컴포넌트` 가 됩니다. 검사 스크립트·계약 테스트가
     * 같은 판정을 쓰도록 이 메서드를 단일 출처로 둡니다.
     *
     * @param  string  $doc  문서 상대 경로
     * @param  string  $type  확장 유형
     * @return array<int, string> 필수 섹션 목록
     */
    /**
     * 선언형 표면(`AbstractModule`/`AbstractPlugin` 의 getter)을 읽어야 렌더되는 블록 키.
     *
     * 이 목록의 블록은 표면 수집이 실패했을 때 "없음" 이 아니라 "확인하지 못함" 으로
     * 렌더된다. 판정은 `renderBlock()` 한 곳에서만 하며, 개별 렌더러는 가드를 갖지 않는다
     * — 가드를 흩어 놓으면 새 렌더러가 조용히 빠지고 그 누락이 "0개" 로 보인다.
     *
     * `stats` 는 수치를 남기고 경고를 덧붙이는 형태라 별도 취급한다(`renderStats`).
     * `hooks-published` / `hooks-subscribed` 는 선언 외에 소스 스캔 결과도 실려 있어
     * 통째로 대체하면 읽어낸 사실까지 버리므로, 빈 결과일 때만 사유를 갈라 적는다.
     */
    /**
     * 표면을 읽지 못했음을 알리는 두 통지가 공유하는 판정 어구.
     *
     * 통지는 둘이다 — 표면 전체를 못 읽은 경우(`surfaceUnavailable`)와 개별 getter 만
     * 던진 경우(`surfaceErrorsNotice`). 코어 인덱스 스캐너는 집계 블록에서 이 어구를
     * 찾아 수치를 "점검 불가" 로 가르는데, 두 문장이 서로 다른 어구로 시작하면 한쪽만
     * 잡힌다. 실제로 후자가 스캐너에 잡히지 않아 `라우트 0` 이 확장의 사실로 인덱스에
     * 실릴 수 있었다 — 두 문장이 이 상수를 함께 쓰게 해서 갈라질 자리를 없앤다.
     *
     * 프로세스 경계를 넘는 리터럴이므로 스캐너와의 일치는 계약 테스트가 잠근다.
     */
    public const SURFACE_NOTICE_MARKER = '**읽지 못했다**는 뜻입니다';

    /**
     * 세지 못한 지표의 표시 문자열.
     *
     * 수집기는 셀 수 없는 지표를 `null` 로 올립니다. 그 자리에 0 을 넣으면 "없다" 는
     * 사실 주장이 되고, 코어 문서 인덱스는 그 0 을 실측으로 옮깁니다 — 배지와 콘솔이
     * 같은 문자열을 쓰도록 여기 한 곳에 둡니다.
     */
    public const STAT_UNMEASURED = '확인 못함';

    private const SURFACE_DEPENDENT_BLOCKS = [
        'requirements',
        'extension-points-summary',
        'listeners',
        'assets',
        'layout-extensions',
        'middleware',
        'channels',
        'schedules',
        'notifications',
        'settings-schema',
        'settings-summary',
        'permissions',
        'menus',
        'routes',
    ];

    /**
     * 표면에 의존하지만 본문을 통째로 대체하지는 않는 블록 키.
     *
     * `stats` 는 수치를, `hooks-*` 는 소스 스캔 결과를 함께 실으므로 표면 실패에도
     * 읽어낸 사실을 남긴다. 다만 **사유는 붙어야 한다** — 붙지 않으면 `getHooks()` 가
     * 던졌을 때 선언 훅 전량이 표에서 빠진 채 "발행 훅 N종" 이 경고 없이 실린다.
     * 세 키를 여기 모아 두어 통지 대상이 `renderBlock()` 한 곳에서만 정해지게 한다.
     */
    private const SURFACE_NOTICE_EXTRA_BLOCKS = [
        'stats',
        'hooks-published',
        'hooks-subscribed',
    ];

    public static function sectionsFor(string $doc, string $type): array
    {
        $meta = self::DOCUMENTS[$doc] ?? null;
        if ($meta === null) {
            return [];
        }

        $overrides = $meta['sectionOverrides'][$type] ?? [];

        // 절 ↔ 블록을 키로 묶은 문서는 `sections` 를 따로 두지 않는다 — 두 벌을 두면
        // 한쪽만 고쳐도 게이트가 초록인 채로 골격이 어긋난다.
        $sections = $meta['sections'] ?? array_keys($meta['blocks']);

        return array_map(
            static fn (string $section): string => $overrides[$section] ?? $section,
            $sections,
        );
    }

    /**
     * 문서의 절과 자동 생성 블록이 키로 짝지어져 있는지 판정합니다.
     *
     * @param  string  $doc  문서 상대 경로
     * @return bool 연관 배열이면 true
     */
    public static function pairsSectionsWithBlocks(string $doc): bool
    {
        $blocks = self::DOCUMENTS[$doc]['blocks'] ?? [];

        return $blocks !== [] && ! array_is_list($blocks);
    }

    /**
     * 문서가 담아야 하는 자동 생성 블록 키 목록을 반환합니다.
     *
     * `DOCUMENTS[$doc]['blocks']` 는 문서에 따라 목록이거나 `절 => 블록` 연관 배열입니다.
     * 소비자가 그 형태를 알 필요가 없도록 이 접근자가 항상 목록으로 돌려줍니다.
     *
     * @param  string  $doc  문서 상대 경로
     * @return array<int, string> 블록 키 목록
     */
    public static function blocksFor(string $doc): array
    {
        return array_values(self::DOCUMENTS[$doc]['blocks'] ?? []);
    }

    /**
     * 문서에 그 절의 **헤딩**이 있는지 판정합니다.
     *
     * 절 이름을 단순 부분문자열로 찾으면 이 축은 사실상 실패할 수 없습니다 — 절 이름이
     * `모델` · `테이블` · `Enum` · `구독 훅` · `미들웨어` · `스케줄` · `레이아웃` · `문서`
     * 처럼 같은 문서의 자동 생성 표 헤더에 필연적으로 등장하는 낱말이고, README 는 자동
     * 생성 인라인 목차가 12개 절 이름을 전부 담기 때문입니다. 헤딩을 통째로 지워도
     * 통과하므로 "검사했다" 와 "검사하지 못했다" 가 구분되지 않습니다.
     *
     * @param  string  $content  문서 본문
     * @param  string  $section  절 이름 (헤딩 접두 `#` 없이)
     * @return bool 헤딩 존재 여부
     */
    public static function hasSection(string $content, string $section): bool
    {
        // PCRE 의 \h 는 CR 을 포함하지 않는다 — CRLF 문서는 줄 끝이 CR 이라
        // 줄끝 앵커가 매치되지 않아 **모든 헤딩을 놓친다**(있는 절을 없다고 보고).
        // 같은 결함군을 generate-docs-index.cjs 에서 이미 겪었으므로 여기서도 줄 끝 CR 을 허용한다.
        $pattern = '/^[^\S\r\n]*#{1,6}[^\S\r\n]+'.preg_quote($section, '/').'[^\S\r\n]*\r?$/mu';

        return preg_match($pattern, $content) === 1;
    }

    /**
     * 미채움 마커 5종을 반환합니다.
     *
     * @return array<int, string> 마커 리터럴
     */
    public static function todoMarkers(): array
    {
        return [
            self::TODO_INTENT,
            self::TODO_FLOW,
            self::TODO_FORBIDDEN,
            self::TODO_USAGE,
            self::TODO_TROUBLESHOOTING,
        ];
    }

    /**
     * 본문이 빈 사람 영역(`@intent`) 블록 수를 셉니다.
     *
     * 미채움은 `TODO:` 마커로만 드러나지 않습니다. 스캐폴딩 직후 마커를 **지우기만 하고**
     * 서술을 쓰지 않으면 마커 검사는 0 을 돌려주고 골격·블록 검사도 전부 통과합니다 —
     * 결과가 "다 채웠다" 와 구분되지 않아, 비어 있는 문서가 완비로 집계됩니다.
     *
     * 그래서 마커 잔량과 빈 본문을 **같은 축**(미채움)으로 함께 봅니다.
     *
     * @param  string  $content  문서 전문
     * @return int 빈 `@intent` 블록 수
     */
    public static function emptyIntentBlocks(string $content): int
    {
        if (preg_match_all('/<!-- @intent START -->(.*?)<!-- @intent END -->/s', $content, $m) === false) {
            return 0;
        }

        $empty = 0;

        foreach ($m[1] as $body) {
            if (trim($body) === '') {
                $empty++;
            }
        }

        return $empty;
    }

    /**
     * 자동 생성 블록을 마커로 감쌉니다.
     *
     * @param  string  $key  블록 키
     * @param  string  $body  블록 본문
     * @return string 마커를 포함한 블록
     */
    public static function wrap(string $key, string $body): string
    {
        return self::startMarker($key)."\n".trim($body)."\n".self::endMarker($key);
    }

    /**
     * 블록 시작 마커를 만듭니다.
     *
     * @param  string  $key  블록 키
     * @return string 시작 마커
     */
    public static function startMarker(string $key): string
    {
        return self::GEN_PREFIX.$key.' START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->';
    }

    /**
     * 블록 종료 마커를 만듭니다.
     *
     * @param  string  $key  블록 키
     * @return string 종료 마커
     */
    public static function endMarker(string $key): string
    {
        return self::GEN_PREFIX.$key.' END -->';
    }

    /**
     * 문서에 존재하는 자동 생성 블록 키를 찾습니다.
     *
     * @param  string  $content  문서 내용
     * @return array<int, string> 블록 키 목록
     */
    public static function presentBlockKeys(string $content): array
    {
        if (! preg_match_all('/<!--\s*@generated:([a-z0-9-]+)\s+START\b/i', $content, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /**
     * 문서의 자동 생성 블록 본문을 교체합니다.
     *
     * 블록 밖은 한 글자도 건드리지 않습니다. 문서에 없는 키는 주입하지 않고 `missing` 으로
     * 돌려주어, 사람이 마커 위치를 정하도록 합니다.
     *
     * @param  string  $content  기존 문서 내용
     * @param  array<string, string>  $bodies  블록 키 => 새 본문
     * @return array{content: string, replaced: array<int, string>, missing: array<int, string>, unchanged: bool}
     */
    public static function replaceBlocks(string $content, array $bodies): array
    {
        $original = $content;
        $replaced = [];
        $missing = [];

        foreach ($bodies as $key => $body) {
            $start = self::startMarker($key);
            $startPattern = '/<!--\s*@generated:'.preg_quote($key, '/').'\s+START\b.*?-->/s';
            $endPattern = '/<!--\s*@generated:'.preg_quote($key, '/').'\s+END\s*-->/s';

            if (! preg_match($startPattern, $content, $sm, PREG_OFFSET_CAPTURE)) {
                $missing[] = $key;

                continue;
            }

            $startPos = (int) $sm[0][1];
            $searchFrom = $startPos + strlen($sm[0][0]);

            if (! preg_match($endPattern, $content, $em, PREG_OFFSET_CAPTURE, $searchFrom)) {
                $missing[] = $key;

                continue;
            }

            $endPos = (int) $em[0][1];
            $endLen = strlen($em[0][0]);

            $content = substr($content, 0, $startPos)
                .$start."\n".trim($body)."\n".self::endMarker($key)
                .substr($content, $endPos + $endLen);

            $replaced[] = $key;
        }

        return [
            'content' => $content,
            'replaced' => $replaced,
            'missing' => $missing,
            'unchanged' => $content === $original,
        ];
    }

    /**
     * 확장의 모든 자동 생성 블록 본문을 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return array<string, string> 블록 키 => 본문
     */
    public function renderBlocks(array $ctx): array
    {
        $keys = [];
        foreach (self::documentsForType($ctx['record']['type']) as $doc) {
            foreach (self::blocksFor($doc) as $key) {
                $keys[$key] = true;
            }
        }

        $bodies = [];
        foreach (array_keys($keys) as $key) {
            $bodies[$key] = $this->renderBlock($key, $ctx);
        }

        return $bodies;
    }

    /**
     * 단일 자동 생성 블록 본문을 렌더합니다.
     *
     * @param  string  $key  블록 키
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운 본문
     */
    public function renderBlock(string $key, array $ctx): string
    {
        // 선언형 표면에 의존하는 블록은 여기 한 곳에서 갈린다.
        //
        // 가드를 렌더러마다 손으로 달면 새 렌더러가 추가될 때마다 새는데, 결과가 "0개" ·
        // "없습니다" 라 이상으로 보이지 않는다. 실제로 확장점 요약 · 요구 사항 · 리스너 ·
        // 에셋 4개가 그렇게 빠져 있었고, 같은 실행이 만든 상세 문서는 "확인하지 못했습니다"
        // 라 한 산출물 안에서 두 문서가 반대되는 사실을 말하고 있었다.
        $surfaceDependent = in_array($key, self::SURFACE_DEPENDENT_BLOCKS, true);

        if ($surfaceDependent && ($unavailable = $this->surfaceUnavailable($ctx)) !== null) {
            return $unavailable;
        }

        $body = $this->renderBlockBody($key, $ctx);

        // 본문을 통째로 대체하지 않는 블록(수치·훅 표)에도 사유는 붙어야 한다.
        //
        // 표면 실패는 두 갈래다 — 진입 클래스를 통째로 읽지 못한 경우(`surfaceUnavailable`)와
        // 클래스는 읽고 개별 getter 가 던진 경우(`surfaceErrorsNotice`). 뒤쪽만 배선하면
        // 앞쪽에서 `stats` 는 수치를, 훅 표는 "선언에 없어 소스에서 자동 감지" 를 경고 없이
        // 싣고, 코어 인덱스 스캐너는 그 수치를 실측으로 옮긴다. 두 갈래를 같은 자리에서 고른다.
        $noticeEligible = $surfaceDependent
            || in_array($key, self::SURFACE_NOTICE_EXTRA_BLOCKS, true);

        // 렌더러가 자체 인라인 가드로 이미 사유를 실었으면 겹쳐 붙이지 않는다.
        $notice = $noticeEligible && ! str_contains($body, self::SURFACE_NOTICE_MARKER)
            ? ($this->surfaceUnavailable($ctx) ?? $this->surfaceErrorsNotice($ctx))
            : null;

        if ($notice !== null) {
            $body .= '

'.$notice;
        }

        return $body;
    }

    /**
     * 블록 본문을 렌더합니다 (표면 가용성 판정은 호출자가 담당).
     *
     * @param  string  $key  블록 키
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderBlockBody(string $key, array $ctx): string
    {
        return match ($key) {
            'badges' => $this->renderBadges($ctx),
            'requirements' => $this->renderRequirements($ctx),
            'install' => $this->renderInstall($ctx),
            'integrations' => $this->renderIntegrations($ctx),
            'docs-index' => $this->renderDocsIndex($ctx),
            'directory-map' => $this->renderDirectoryMap($ctx),
            'extension-points-summary' => $this->renderExtensionPointsSummary($ctx),
            'test-commands' => $this->renderTestCommands($ctx),
            'stats' => $this->renderStats($ctx),
            'doc-toc' => $this->renderDocToc($ctx),
            'hooks-published' => $this->renderHooksPublished($ctx),
            'hooks-subscribed' => $this->renderHooksSubscribed($ctx),
            'listeners' => $this->renderListeners($ctx),
            'layout-extensions' => $this->renderLayoutExtensions($ctx),
            'template-overrides' => $this->renderLayoutExtensions($ctx),
            'middleware' => $this->renderMiddleware($ctx),
            'channels' => $this->renderChannels($ctx),
            'schedules' => $this->renderSchedules($ctx),
            'notifications' => $this->renderNotifications($ctx),
            'models' => $this->renderModels($ctx),
            'tables' => $this->renderTables($ctx),
            'migrations' => $this->renderMigrations($ctx),
            'enums' => $this->renderEnums($ctx),
            'repositories' => $this->renderRepositories($ctx),
            'settings-schema' => $this->renderSettingsSchema($ctx),
            'settings-summary' => $this->renderSettingsSummary($ctx),
            'permissions' => $this->renderPermissions($ctx),
            'menus' => $this->renderMenus($ctx),
            'routes' => $this->renderRoutes($ctx),
            'dependencies' => $this->renderDependencies($ctx),
            'layouts' => $this->renderLayouts($ctx),
            'handlers' => $this->renderHandlers($ctx),
            'frontend-entry' => $this->renderFrontendEntry($ctx),
            'assets' => $this->renderAssets($ctx),
            'components' => $this->renderComponents($ctx),
            'layout-map' => $this->renderLayoutMap($ctx),
            'editor-spec-summary' => $this->renderEditorSpecSummary($ctx),
            'editor-spec-blocks' => $this->renderEditorSpecBlocks($ctx),
            'editor-spec-palette' => $this->renderEditorSpecPalette($ctx),
            'editor-spec-samples' => $this->renderEditorSpecSamples($ctx),
            'editor-spec-obligations' => $this->renderEditorSpecObligations($ctx),
            default => $this->none('알 수 없는 블록 키: '.$key),
        };
    }

    // -----------------------------------------------------------------------
    // 블록 렌더러
    // -----------------------------------------------------------------------

    /**
     * README 히어로 배지를 렌더합니다.
     *
     * 값은 전부 manifest 에서 옵니다. 정적 이미지 배지라 브라우저가 화면을 그리기 위해
     * 도달해야 하는 구동 자산이 아닙니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderBadges(array $ctx): string
    {
        $record = $ctx['record'];
        $manifest = $record['manifest'];

        $badges = [];
        $badges[] = $this->badge('version', (string) ($manifest['version'] ?? '-'), '0066FF');
        $badges[] = $this->badge('type', ExtensionInventory::typeLabel($record['type']), '555555');

        $core = $ctx['deps']['coreVersion'] ?? null;
        if ($core !== null) {
            $badges[] = $this->badge('그누보드7', $core, '1F883D');
        }

        $license = $manifest['license'] ?? null;
        if (is_string($license) && $license !== '') {
            $badges[] = $this->badge('license', $license, '8250DF');
        }

        foreach ($ctx['deps']['requires'] as $dep) {
            $badges[] = $this->badge('requires', $dep['id'], 'BF8700');
        }

        return '<p align="center">'."\n  ".implode("\n  ", $badges)."\n".'</p>';
    }

    /**
     * shields.io 정적 배지 마크다운을 만듭니다.
     *
     * @param  string  $label  라벨
     * @param  string  $message  값
     * @param  string  $color  색상 (hex, `#` 없음)
     * @return string 이미지 마크다운
     */
    private function badge(string $label, string $message, string $color): string
    {
        $encode = static fn (string $s): string => rawurlencode(str_replace(['-', '_'], ['--', '__'], $s));

        return sprintf(
            '<img src="https://img.shields.io/badge/%s-%s-%s?style=flat-square" alt="%s %s">',
            $encode($label),
            $encode($message),
            $color,
            $this->escape($label),
            $this->escape($message),
        );
    }

    /**
     * 요구 사항 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderRequirements(array $ctx): string
    {
        $rows = [];
        $rows[] = ['그누보드7 코어', $this->code($ctx['deps']['coreVersion'] ?? '(제약 없음)')];
        $rows[] = ['PHP', $this->code($this->composerPhp($ctx) ?? '^8.2')];

        foreach ($ctx['deps']['requires'] as $dep) {
            $label = ExtensionInventory::typeLabel(rtrim($dep['type'], 's'));
            $rows[] = ["의존 {$label}", $this->code($dep['id']).' '.$this->code($dep['constraint'])];
        }

        $hosts = $ctx['surface']['values']['getTrustedScriptHosts'] ?? [];
        if (is_array($hosts) && $hosts !== []) {
            $rows[] = ['외부 스크립트 호스트', implode(', ', array_map(fn ($h) => $this->code((string) $h), $hosts))];
        }

        return $this->table(['항목', '값'], $rows);
    }

    /**
     * 설치 절차를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderInstall(array $ctx): string
    {
        $record = $ctx['record'];
        $type = $record['type'];
        $id = $record['id'];

        $lines = [];
        $lines[] = '```bash';
        $lines[] = '# 번들 설치 (코어에 동봉된 소스에서 설치)';
        $lines[] = "php artisan {$type}:install {$id}";
        $lines[] = '';
        $lines[] = '# 활성화';
        $lines[] = "php artisan {$type}:activate {$id}";
        $lines[] = '';
        $lines[] = '# 업데이트 (번들 소스 기준 강제 반영)';
        $lines[] = "php artisan {$type}:update {$id} --force";
        $lines[] = '```';

        $github = $record['manifest']['github_url'] ?? null;
        if (is_string($github) && $github !== '') {
            $lines[] = '';
            $lines[] = '저장소: '.$github;
        }

        return implode("\n", $lines);
    }

    /**
     * 다른 확장과의 연동(정방향·역방향)을 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderIntegrations(array $ctx): string
    {
        $sections = [];

        $requires = $ctx['deps']['requires'];
        $sections[] = '**이 확장이 의존하는 확장**';
        $sections[] = '';
        $sections[] = $requires === []
            ? '없음 — 코어만으로 동작합니다.'
            : $this->table(
                ['확장', '유형', '버전 제약', '번들'],
                array_map(fn (array $d): array => [
                    $this->code($d['id']),
                    ExtensionInventory::typeLabel(rtrim($d['type'], 's')),
                    $this->code($d['constraint']),
                    $d['bundled'] ? '✅' : '—',
                ], $requires),
            );

        $requiredBy = $ctx['deps']['requiredBy'];
        $sections[] = '';
        $sections[] = '**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)';
        $sections[] = '';
        $sections[] = $requiredBy === []
            ? '없음.'
            : $this->table(
                ['확장', '유형', '요구 버전'],
                array_map(fn (array $d): array => [
                    $this->code($d['id']),
                    ExtensionInventory::typeLabel($d['type']),
                    $this->code($d['constraint']),
                ], $requiredBy),
            );

        return implode("\n", $sections);
    }

    /**
     * 문서 목차를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderDocsIndex(array $ctx): string
    {
        $record = $ctx['record'];
        $rows = [];

        foreach (self::documentsForType($record['type']) as $doc) {
            if ($doc === 'AGENTS.md' || $doc === 'README.md') {
                continue;
            }

            $exists = is_file($record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc));
            $rows[] = [
                $exists ? "[{$doc}]({$doc})" : $this->code($doc),
                $this->documentPurpose($doc),
                $exists ? '✅' : '미작성',
            ];
        }

        if (is_dir($record['docsPath'].DIRECTORY_SEPARATOR.'api')) {
            $rows[] = ['[docs/api/](docs/api/README.md)', 'API 레퍼런스 (엔드포인트별 파라미터·응답 필드)', '✅'];
        }

        // 다른 행은 전부 `is_file()` 로 판정하는데 이 행만 무조건 ✅ 였다 — CHANGELOG 가
        // 없는 확장(21번째 시나리오)에서 문서가 "있음" 이라고 거짓말하고 링크가 404 가 된다.
        $hasChangelog = is_file($record['path'].DIRECTORY_SEPARATOR.'CHANGELOG.md');
        $rows[] = [
            $hasChangelog ? '[CHANGELOG.md](CHANGELOG.md)' : $this->code('CHANGELOG.md'),
            '변경 이력',
            $hasChangelog ? '✅' : '미작성',
        ];

        return $this->table(['문서', '내용', '상태'], $rows);
    }

    /**
     * `docs/README.md` 의 목차를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderDocToc(array $ctx): string
    {
        $record = $ctx['record'];
        $rows = [];

        foreach (self::documentsForType($record['type']) as $doc) {
            if (! str_starts_with($doc, 'docs/') || $doc === 'docs/README.md') {
                continue;
            }

            $name = basename($doc);
            $exists = is_file($record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $doc));
            $rows[] = [
                $exists ? "[{$name}]({$name})" : $this->code($name),
                $this->documentPurpose($doc),
            ];
        }

        if (is_dir($record['docsPath'].DIRECTORY_SEPARATOR.'api')) {
            $rows[] = ['[api/](api/README.md)', 'API 레퍼런스'];
        }

        // 진입점 링크도 존재 확인 후 건다 — 없는 파일로 링크하면 404 가 된다.
        foreach ([['AGENTS.md', '에이전트·확장개발자 진입점'], ['README.md', '사람(도입검토자·운영자) 진입점']] as [$name, $purpose]) {
            $exists = is_file($record['path'].DIRECTORY_SEPARATOR.$name);
            $rows[] = [$exists ? "[../{$name}](../{$name})" : $this->code("../{$name}"), $purpose];
        }

        return $this->table(['문서', '내용'], $rows);
    }

    /**
     * 문서의 용도 설명을 반환합니다.
     *
     * @param  string  $doc  문서 상대 경로
     * @return string 용도
     */
    private function documentPurpose(string $doc): string
    {
        return match ($doc) {
            'docs/README.md' => '문서 통합 목차와 실측 집계',
            'docs/architecture.md' => '설계 의도·계층 지도·디렉토리 맵',
            'docs/extension-points.md' => '발행/구독 훅·미들웨어·채널·스케줄',
            'docs/data-model.md' => '모델·소유 테이블·마이그레이션·Enum',
            'docs/settings.md' => '설정 스키마·권한·메뉴·라우트·의존 관계',
            'docs/frontend.md' => '레이아웃·액션 핸들러·전역 진입점·에셋',
            'docs/components.md' => '템플릿이 제공하는 컴포넌트',
            'docs/layouts.md' => '레이아웃 목록과 라우트 매핑',
            'docs/handlers.md' => '템플릿 전용 핸들러와 부트스트랩',
            'docs/editor-spec.md' => '레이아웃 편집기에 선언한 팔레트·컨트롤·샘플 데이터',
            default => '-',
        };
    }

    /**
     * 디렉토리 지도를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderDirectoryMap(array $ctx): string
    {
        $record = $ctx['record'];
        $rows = [];

        foreach ($this->directoryCandidates($record['type'], $record['id']) as $path => [$role, $procedure]) {
            $abs = $record['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, rtrim($path, '/'));
            if (! file_exists($abs)) {
                continue;
            }

            $rows[] = [$this->code($path), $role, $procedure];
        }

        return $this->table(['경로', '역할', '수정 시 필요한 절차'], $rows);
    }

    /**
     * 유형별 디렉토리 후보와 설명을 반환합니다.
     *
     * @param  string  $type  확장 유형
     * @param  string  $id  확장 식별자
     * @return array<string, array{0: string, 1: string}> 경로 => [역할, 절차]
     */
    private function directoryCandidates(string $type, string $id): array
    {
        $updateCmd = "`php artisan {$type}:update {$id} --force`";
        $buildCmd = "`php artisan {$type}:build` → {$updateCmd}";

        $common = [
            'CHANGELOG.md' => ['변경 이력', '버전 상향 시 항목 추가 (미기재 시 버전 상향 불가)'],
            // 편집기 컴포넌트 선언은 레이아웃 저작자가 읽는 props 계약이다(실측 15파일).
            // 자리가 없으면 그 선언을 고쳐도 `docs/components.md` 갱신 요구가 걸리지 않는다.
            'components.json' => ['편집기 컴포넌트 선언 (레이아웃 저작자가 읽는 props 계약)', $updateCmd],
            // 확장이 셸로 호출하는 외부 실행파일·인증서·WSDL 자리다(실측 6파일, 결제 플러그인).
            // 비면 그 기능이 죽는데 코드에는 "bin/ 에 복사하세요" 안내만 있고 문서에는 자리가 없었다.
            'bin/' => ['확장이 실행하는 외부 바이너리·인증서', '교체 시 OS별 파일과 권한을 함께 확인 (비면 해당 기능 정지)'],
            'docs/' => ['개발자 문서', '표면 변경 시 `php artisan ext:docgen` 재실행'],
            'lang/' => ['다국어', '키 추가 시 ko·en 동시 반영 + 번들 ja 팩 동기화'],
            'custom/' => ['운영자 추가 에셋 자리', '저작자는 두지 않는다 (운영자 소유 — 보존 계층이 덮지 않음)'],
        ];

        if ($type === ExtensionInventory::TYPE_TEMPLATE) {
            return [
                'template.json' => ['manifest (버전 SSoT)', 'version 변경 시 package.json·package-lock.json 동기화'],
                'routes.json' => ['라우트 → 레이아웃 매핑', $updateCmd],
                'layouts/' => ['레이아웃 JSON', $updateCmd.' (빌드 불필요)'],
                // 모듈·플러그인의 `resources/extensions/` 와 같은 개념인데 템플릿은
                // 확장 루트 직속에 둔다. 유형 분기를 놓치면 다른 확장 화면에 끼워 넣는
                // 조각을 고쳐도 문서에 반영할 자리가 없다.
                'extensions/' => ['다른 확장 화면에 주입하는 레이아웃 조각', $updateCmd.' (빌드 불필요)'],
                'seo-config.json' => ['SEO 렌더 설정', $updateCmd],
                'src/components/' => ['React 컴포넌트', $buildCmd],
                'src/handlers/' => ['템플릿 전용 액션 핸들러', $buildCmd],
                'dist/' => ['커밋되는 빌드 산출물', '`--production` 으로 재빌드 (sourceMappingURL 잔존 금지)'],
                'editor-spec.json' => ['레이아웃 편집기 스펙', $updateCmd],
                'editor-spec/' => ['분할 편집기 스펙', $updateCmd],
                'tests/' => ['테스트', '변경 범위만 필터 실행'],
                ...$common,
            ];
        }

        $entry = $type === ExtensionInventory::TYPE_MODULE ? 'module' : 'plugin';

        return [
            "{$entry}.json" => ['manifest (버전 SSoT)', 'version 변경 시 package.json·package-lock.json·composer.json 동기화'],
            "{$entry}.php" => ['진입 클래스 (선언형 표면 SSoT)', '표면 변경 시 `ext:docgen` 재실행 + 코어 최소 버전 검토'],
            // 컨트롤러 자리는 두 갈래다. 번들 플러그인 5개(pay_kginicis · pay_nhnkcp ·
            // pay_nicepayments · message_bizppurio · tosspayments, 49파일)는 `src/Controllers/`
            // 를 쓴다. 한 갈래만 적으면 그 5개 문서에서 컨트롤러 행이 통째로 사라져
            // "API 표면 변경 시 `api:docgen` 재실행" 절차가 어디에도 남지 않는다.
            // 렌더러가 `file_exists` 로 거르므로 두 행을 함께 두어도 실재하는 쪽만 나온다.
            'src/Http/Controllers/' => ['컨트롤러', 'API 표면 변경 시 `api:docgen` 재실행'],
            'src/Controllers/' => ['컨트롤러', 'API 표면 변경 시 `api:docgen` 재실행'],
            'src/Http/Requests/' => ['FormRequest (검증 SSoT)', '검증 규칙은 Service 가 아니라 여기에 둔다'],
            'src/Http/Resources/' => ['API 리소스', '목록 응답은 화면이 실제로 그리는 것만 싣는다'],
            'src/Services/' => ['비즈니스 로직', 'Repository 인터페이스 주입 (구체 클래스 금지)'],
            'src/Repositories/' => ['데이터 접근', '목록 쿼리는 컬럼 프루닝·정렬 화이트리스트 확인'],
            'src/Models/' => ['Eloquent 모델', '스키마 변경 시 마이그레이션 + 업그레이드 스텝 동반'],
            'src/Listeners/' => ['훅 리스너', 'Repository 경유 (Model·DB 파사드 직접 접근 금지)'],
            'src/Enums/' => ['상태·타입·분류', '문자열 리터럴 대신 Enum 을 SSoT 로 둔다'],
            'src/routes/' => ['라우트', '모든 라우트에 `name()` 필수'],
            'src/lang/' => ['백엔드 다국어', 'ko·en 동시 반영 + 번들 ja 팩 동기화'],
            'database/migrations/' => ['마이그레이션', '한국어 comment + `down()` 필수, 기설치본은 업그레이드 스텝으로 백필'],
            'database/seeders/' => ['시더', 'composer autoload 등록 + `extension:update-autoload`'],
            'upgrades/' => ['업그레이드 스텝', 'DB·설정 구조 변경 시 작성 (모듈/플러그인 전용)'],
            'resources/layouts/' => ['레이아웃 JSON', $updateCmd.' (빌드 불필요)'],
            // 모듈·플러그인의 라우트 → 레이아웃 매핑은 `resources/routes.json`(단일) 또는
            // `resources/routes/*.json`(분할)에 있다(실측 7파일). 여기 자리가 없으면
            // "라우트를 고쳤는데 문서에 반영할 곳이 없다" 가 된다 — editor-spec 과 같은 형태다.
            'resources/routes.json' => ['라우트 → 레이아웃 매핑', $updateCmd],
            'resources/routes/' => ['라우트 → 레이아웃 매핑 (분할)', $updateCmd],
            'resources/js/' => ['프론트 엔트리·핸들러', $buildCmd],
            'resources/extensions/' => ['다른 확장 레이아웃에 주입하는 조각', $updateCmd],
            // 모듈·플러그인도 편집기 스펙을 소유한다(실측 10개). 여기 자리가 없으면
            // "editor-spec 을 고쳤는데 문서에 반영할 곳이 없다" 가 된다.
            'editor-spec.json' => ['레이아웃 편집기 스펙', $updateCmd],
            'editor-spec/' => ['분할 편집기 스펙', $updateCmd],
            'dist/' => ['커밋되는 빌드 산출물', '`--production` 으로 재빌드 (sourceMappingURL 잔존 금지)'],
            'config/' => ['확장 config', '설정 기본값은 settings 스키마와 어긋나지 않게'],
            'tests/' => ['테스트', '변경 범위만 필터 실행'],
            ...$common,
        ];
    }

    /**
     * AGENTS.md 확장점 요약을 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderExtensionPointsSummary(array $ctx): string
    {
        $hooks = $ctx['hooks'];
        $surface = $ctx['surface']['values'];
        $frontend = $ctx['frontend'];

        $rows = [
            ['발행 훅', (string) count($hooks['published']).'개', $this->docLink($ctx, 'docs/extension-points.md', '발행 훅')],
            ['구독 훅', (string) count($hooks['subscribed']).'개', $this->docLink($ctx, 'docs/extension-points.md', '구독 훅')],
            ['훅 리스너', (string) count($hooks['listeners']).'개', $this->docLink($ctx, 'docs/extension-points.md', '훅 리스너')],
            ['레이아웃 확장', (string) count($frontend['layoutExtensions']).'개', $this->docLink($ctx, 'docs/extension-points.md', '레이아웃 확장')],
            ['미들웨어', (string) $this->countOf($surface['getMiddleware'] ?? []).'개', $this->docLink($ctx, 'docs/extension-points.md', '미들웨어')],
            ['브로드캐스트 채널', (string) $this->countOf($surface['getChannels'] ?? []).'개', $this->docLink($ctx, 'docs/extension-points.md', '브로드캐스트 채널')],
            ['스케줄', (string) $this->countOf($surface['getSchedules'] ?? []).'개', $this->docLink($ctx, 'docs/extension-points.md', '스케줄')],
            ['알림 정의', (string) $this->countOf($surface['getNotificationDefinitions'] ?? []).'개', $this->docLink($ctx, 'docs/extension-points.md', '알림 정의')],
        ];

        if ($ctx['record']['type'] === ExtensionInventory::TYPE_TEMPLATE) {
            $rows = [
                ['제공 컴포넌트', (string) $frontend['components']['total'].'개', $this->docLink($ctx, 'docs/components.md', '제공 컴포넌트')],
                ['레이아웃', (string) count($frontend['layouts']).'개', $this->docLink($ctx, 'docs/layouts.md', '레이아웃 목록')],
                ['전용 핸들러', (string) count($frontend['handlers']['names']).'개', $this->docLink($ctx, 'docs/handlers.md', '템플릿 전용 핸들러')],
                ['확장 오버라이드', (string) count($frontend['layoutExtensions']).'개', $this->docLink($ctx, 'docs/layouts.md', '확장 오버라이드')],
            ];
        }

        return $this->table(['확장점', '수', '상세'], $rows);
    }

    /**
     * 테스트 실행 명령을 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderTestCommands(array $ctx): string
    {
        $tests = $ctx['tests'];
        $lines = [];

        $rows = [
            ['PHPUnit', (string) $tests['phpunit']['count'].'개', $tests['phpunit']['count'] > 0 ? $this->code($ctx['record']['relPath'].'/tests') : '—'],
            ['Vitest', (string) $tests['vitest']['files'].'개', $tests['vitest']['config'] !== null ? $this->code($tests['vitest']['config']) : '—'],
            ['Playwright', (string) $tests['playwright']['count'].'개', $tests['playwright']['count'] > 0 ? $this->code('tests/Playwright') : '—'],
            ['시나리오 매니페스트', (string) count($tests['scenarios']).'개', $tests['scenarios'] === [] ? '—' : $this->code('tests/scenarios')],
        ];

        $lines[] = $this->table(['종류', '개수', '위치'], $rows);

        if ($tests['testCaseBase'] !== null) {
            $lines[] = '';
            $lines[] = '기저 TestCase: '.$this->code($tests['testCaseBase']).' — 확장 테스트는 이 클래스를 상속합니다 (`Tests\TestCase` 직접 상속 금지).';
        }

        if ($tests['commands'] !== []) {
            $lines[] = '';
            $lines[] = '```bash';
            foreach ($tests['commands'] as $command) {
                $lines[] = '# '.$command['label'].' ('.$command['shell'].')';
                $lines[] = $command['command'];
                $lines[] = '';
            }
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '무필터 전체 실행은 금지되어 있습니다 — 변경 범위에 걸리는 대상만 지정해 실행합니다.';
        }

        return implode("\n", $lines);
    }

    /**
     * `docs/README.md` 집계 배지 라인을 렌더합니다.
     *
     * 코어 문서 인덱스 생성기가 이 라인을 읽어 확장 문서 표를 채우므로, 형식이 계약입니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderStats(array $ctx): string
    {
        $stats = self::statsOf($ctx);

        $parts = [];
        $unmeasured = [];

        foreach ($stats as $label => $value) {
            if ($value === null) {
                $unmeasured[] = $label;
                $parts[] = "**{$label}**: ".self::STAT_UNMEASURED;

                continue;
            }

            $parts[] = "**{$label}**: {$value}";
        }

        $line = implode(' · ', $parts);

        // 선언형 표면을 못 읽었으면 0 은 실측이 아니다 — 수치만 내보내면 "없음" 으로 읽힌다.
        if (($unavailable = $this->surfaceUnavailable($ctx)) !== null) {
            return $line."\n\n".$unavailable;
        }

        // 표면과 무관하게 개별 지표를 세지 못하는 경우가 있다 — 템플릿은 선언형 표면을
        // 갖지 않아 위 안내의 대상이 아니고(`surfaceUnavailable` 이 null 을 돌려준다),
        // 그 대신 `routes.json` 을 읽지 못하면 여기서 단서를 남겨야 한다. 남기지 않으면
        // 코어 인덱스 스캐너가 이 블록을 실측으로 읽어 세지 못한 수치를 사실로 옮긴다.
        if ($unmeasured !== []) {
            $names = array_map(static fn (string $l): string => '`'.$l.'`', $unmeasured);

            $line .= "\n\n".$this->none(sprintf(
                '아래 수치를 세지 못했습니다 (%s). 항목이 없다는 뜻이 아니라 %s.',
                implode(' · ', $names),
                self::SURFACE_NOTICE_MARKER,
            ));
        }

        return $line;
    }

    /**
     * 집계 배지에 쓰이는 실측 수치를 반환합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return array<string, int|null> 라벨 => 수치 (세지 못한 지표는 null)
     */
    public static function statsOf(array $ctx): array
    {
        return [
            '훅 수' => count($ctx['hooks']['published']),
            '구독 훅 수' => count($ctx['hooks']['subscribed']),
            // 주소를 어디에 선언하는지가 유형마다 다르다. 모듈·플러그인은 `getRoutes()` 가
            // 가리키는 라우트 파일이고, 템플릿은 `routes.json` 이다. 선언형 표면만 보면
            // 템플릿은 구조적으로 항상 0 이 되는데(실측 40·29) 템플릿에는 "확인하지 못함"
            // 안내도 붙지 않아 그 0 이 단서 없이 사실로 읽힌다.
            // 템플릿은 셀 수 없으면 `null` 이 온다 — `?? 0` 으로 받으면 수집기가 구분해
            // 올린 "읽지 못함" 이 이 자리에서 "주소 0개" 라는 사실 주장으로 바뀐다.
            '라우트 수' => $ctx['record']['type'] === 'template'
                ? ($ctx['frontend']['routeCount'] === null
                    ? null
                    : (int) $ctx['frontend']['routeCount'])
                : (int) ($ctx['surface']['endpoints'] ?? 0),
            '모델 수' => count($ctx['data']['models']),
            '테이블 수' => count($ctx['data']['tables']),
            '마이그레이션 수' => count($ctx['data']['migrations']),
            '레이아웃 수' => count($ctx['frontend']['layouts']),
            '핸들러 수' => count($ctx['frontend']['handlers']['names']),
        ];
    }

    /**
     * 발행 훅 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderHooksPublished(array $ctx): string
    {
        $hooks = $ctx['hooks'];

        // 표가 비어 있어도 호출 지점이 있으면 "발행하지 않는다" 가 아니다 — 이름을 정적으로
        // 읽지 못했을 뿐이다. 이 두 상태를 같은 문장으로 보고하면 훅을 발행하는 확장이
        // 발행하지 않는다고 문서화된다.
        if ($hooks['published'] === []) {
            if ($hooks['publishedSites'] > 0) {
                return $this->none(sprintf(
                    '훅 발행 호출이 %d곳 있으나 훅 이름을 확인하지 못했습니다 — 이름이 상수·변수로 조립되어 '
                    .'정적으로 읽을 수 없습니다. 이 확장의 진입 클래스에 `getHooks()` 로 발행 훅을 선언하면 '
                    .'이 표가 채워집니다.',
                    $hooks['publishedSites'],
                ));
            }

            // 발행 훅의 1차 출처는 진입 클래스의 `getHooks()` 선언이다. 그 클래스를 읽지
            // 못했으면 남는 것은 리터럴 스캔 결과뿐이고, 스캔은 이름 조립형 발행을 원리상
            // 읽지 못한다 — 그 조합에서 "발행하지 않습니다" 는 거짓이 된다.
            if (($unavailable = $this->surfaceUnavailable($ctx)) !== null) {
                return $unavailable;
            }

            return $this->none('이 확장은 훅을 발행하지 않습니다.');
        }

        $rows = [];
        foreach ($hooks['published'] as $hook) {
            $sites = $hook['sites'];

            if ($sites === []) {
                // 선언에는 있으나 리터럴 호출이 잡히지 않은 훅 — 이름 조립형 발행이다.
                $where = '선언 (호출 위치 미확인)';
            } else {
                $extra = count($sites) > 1 ? ' 외 '.(count($sites) - 1).'곳' : '';
                $where = $this->code($sites[0]['file'].':'.$sites[0]['line']).$extra;
            }

            $rows[] = [
                $this->code($hook['name']),
                $hook['type'],
                // 자동 생성 블록 안이라 `TODO:` 마커를 쓰지 않는다 — 손으로 채워도 다음
                // 재생성에서 지워지고, 채울 수 없는 자리가 미채움 잔량만 부풀린다.
                $hook['description'] ?? '—',
                $where,
            ];
        }

        $notes = [sprintf(
            '발행 훅 %d종 / 호출 지점 %d곳.',
            count($hooks['published']),
            $hooks['publishedSites'],
        )];

        if (($hooks['publishedUndeclared'] ?? 0) > 0) {
            $notes[] = sprintf(
                '이 중 %d종은 `getHooks()` 선언에 없어 소스에서 자동 감지한 것입니다 — 선언에 추가하면 유형과 설명이 함께 실립니다.',
                $hooks['publishedUndeclared'],
            );
        }

        if ($hooks['publishedDynamic'] > 0) {
            $notes[] = sprintf(
                '훅 이름이 상수·변수로 조립된 호출이 %d곳 있어 호출 위치가 표에 다 실리지 않을 수 있습니다.',
                $hooks['publishedDynamic'],
            );
        }

        return implode(' ', $notes)."\n\n".$this->table(['훅 이름', '유형', '설명', '발행 위치'], $rows);
    }

    /**
     * 구독 훅 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderHooksSubscribed(array $ctx): string
    {
        $subscribed = $ctx['hooks']['subscribed'];

        if ($subscribed === []) {
            // 구독 훅은 `getHookListeners()` 선언과 리스너 스캔을 합쳐 만든다 — 진입 클래스를
            // 읽지 못한 상태에서 "구독하지 않습니다" 는 확인 결과가 아니라 미확인이다.
            if (($unavailable = $this->surfaceUnavailable($ctx)) !== null) {
                return $unavailable;
            }

            return $this->none('이 확장은 훅을 구독하지 않습니다.');
        }

        $rows = [];
        foreach ($subscribed as $hook) {
            $rows[] = [
                $this->code($hook['name']),
                $hook['typeDeclared'] ? $hook['type'] : $hook['type'].' (미선언)',
                $this->code($this->shortName($hook['listener'])),
                $hook['method'] !== null ? $this->code($hook['method']) : '-',
                $hook['priority'] !== null ? (string) $hook['priority'] : '-',
            ];
        }

        return $this->table(['훅 이름', '유형', '리스너', '메서드', '우선순위'], $rows);
    }

    /**
     * 훅 리스너 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderListeners(array $ctx): string
    {
        $listeners = $ctx['hooks']['listeners'];

        if ($listeners === []) {
            return $this->none('훅 리스너가 없습니다.');
        }

        $registered = $ctx['surface']['values']['getHookListeners'] ?? [];
        $registeredShort = [];
        if (is_array($registered)) {
            foreach ($registered as $class) {
                if (is_string($class)) {
                    $registeredShort[$this->shortName($class)] = true;
                }
            }
        }

        $rows = [];
        foreach ($listeners as $listener) {
            $rows[] = [
                $this->code($listener['shortClass']),
                (string) count($listener['hooks']).'개',
                isset($registeredShort[$listener['shortClass']]) ? '명시 등록' : '자동 발견',
                $listener['implementsContract'] ? '✅' : '❌',
                $this->code($listener['relFile']),
            ];
        }

        return $this->table(['리스너', '구독 훅', '등록 방식', 'HookListenerInterface', '파일'], $rows);
    }

    /**
     * 레이아웃 확장 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderLayoutExtensions(array $ctx): string
    {
        $files = $ctx['frontend']['layoutExtensions'];
        $declared = $ctx['surface']['values']['getLayoutExtensions'] ?? [];
        // 템플릿의 `extensions/{module-identifier}/*.json` 은 그 모듈/플러그인이 발행한
        // 조각을 이 템플릿이 대체하는 것이지, 모듈/플러그인처럼 밖으로 발행하는 것이 아니다.
        $isTemplate = ($ctx['record']['type'] ?? null) === ExtensionInventory::TYPE_TEMPLATE;

        if ($files === [] && ! is_array($declared)) {
            return $this->none($isTemplate ? '오버라이드하는 레이아웃 확장 조각이 없습니다.' : '레이아웃 확장이 없습니다.');
        }

        if ($files === [] && $declared === []) {
            return $this->none($isTemplate ? '오버라이드하는 레이아웃 확장 조각이 없습니다.' : '레이아웃 확장이 없습니다.');
        }

        $known = array_flip($files);

        $rows = [];
        foreach ($files as $file) {
            $rows[] = [$this->code($file), $isTemplate ? '모듈/플러그인 확장 조각을 대체하는 오버라이드' : '다른 확장/템플릿 레이아웃에 주입되는 조각'];
        }

        // `getLayoutExtensions()` 기본 구현(AbstractModule/AbstractPlugin)은 위 파일 목록과
        // 같은 디렉토리를 glob() 한 절대경로라 100% 중복이다. 확장-상대 경로로 정규화한 뒤
        // 이미 실린 파일은 건너뛰고, 확장이 오버라이드해 다른 대상을 선언한 경우만 싣는다.
        if (is_array($declared)) {
            foreach ($declared as $key => $value) {
                $raw = is_string($key) ? $key : (is_string($value) ? $value : (string) $value);
                $rel = $this->relativeToExtension($ctx, $raw);
                if (isset($known[$rel])) {
                    continue;
                }
                $known[$rel] = true;
                $rows[] = [$this->code($rel), '`getLayoutExtensions()` 선언'];
            }
        }

        return $this->table(['대상', '설명'], $rows);
    }

    /**
     * 미들웨어 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderMiddleware(array $ctx): string
    {
        $middleware = $ctx['surface']['values']['getMiddleware'] ?? [];

        if (! is_array($middleware) || $middleware === []) {
            return $this->none('등록하는 미들웨어가 없습니다.');
        }

        $rows = [];
        foreach ($middleware as $key => $entry) {
            $class = is_array($entry) ? ($entry['class'] ?? $entry['middleware'] ?? null) : $entry;
            $targets = is_array($entry) ? ($entry['targets'] ?? null) : null;

            $rows[] = [
                $this->code(is_string($class) ? $this->shortName($class) : (is_string($key) ? $key : '-')),
                is_array($targets) ? implode(', ', array_map(fn ($t) => $this->code((string) $t), $targets)) : '-',
                is_array($entry) && isset($entry['priority']) ? (string) $entry['priority'] : '-',
            ];
        }

        return $this->table(['미들웨어', '부착 대상(targets)', '우선순위'], $rows);
    }

    /**
     * 브로드캐스트 채널 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderChannels(array $ctx): string
    {
        $channels = $ctx['surface']['values']['getChannels'] ?? [];

        if (! is_array($channels) || $channels === []) {
            return $this->none('등록하는 브로드캐스트 채널이 없습니다.');
        }

        $rows = [];
        foreach ($channels as $name => $value) {
            $rows[] = [$this->code(is_string($name) ? $name : (string) $value), is_string($name) ? '인가 콜백 등록' : '채널 선언'];
        }

        return $this->table(['채널', '비고'], $rows);
    }

    /**
     * 스케줄 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderSchedules(array $ctx): string
    {
        $schedules = $ctx['surface']['values']['getSchedules'] ?? [];

        if (! is_array($schedules) || $schedules === []) {
            return $this->none('등록하는 스케줄이 없습니다.');
        }

        $rows = [];
        foreach ($schedules as $key => $schedule) {
            if (! is_array($schedule)) {
                $rows[] = [$this->code((string) $key), $this->code((string) $schedule), '-'];

                continue;
            }

            $rows[] = [
                $this->code((string) ($schedule['name'] ?? $schedule['command'] ?? $key)),
                // 표준 계약 키는 `schedule` 이다 (AbstractModule/AbstractPlugin 의 getSchedules()
                // 주석과 routes/console.php 소비부가 SSoT). 이 키를 빼면 주기 열이 모든 확장에서
                // 영구히 '-' 가 되는데, "주기를 선언하지 않았다" 와 구분되지 않는다.
                $this->code((string) ($schedule['schedule'] ?? $schedule['expression'] ?? $schedule['cron'] ?? $schedule['frequency'] ?? '-')),
                (string) ($schedule['description'] ?? '-'),
            ];
        }

        return $this->table(['스케줄', '주기', '설명'], $rows);
    }

    /**
     * 알림 정의 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderNotifications(array $ctx): string
    {
        $definitions = $ctx['surface']['values']['getNotificationDefinitions'] ?? [];

        if (! is_array($definitions) || $definitions === []) {
            return $this->none('등록하는 알림 정의가 없습니다.');
        }

        $rows = [];
        foreach ($definitions as $key => $definition) {
            // getNotificationDefinitions() 의 표준 계약(AbstractModule/AbstractPlugin 소비처인
            // NotificationSyncHelper 기준)은 리스트 배열 + 각 원소의 'type' 키다. 'key'/'event'
            // 는 어떤 확장도 쓰지 않아, 문자열 키가 아닌 한(list 배열이면 전부 정수 키) 이 표는
            // 항상 '-' 만 찍고 있었다.
            $name = is_string($key) ? $key : (is_array($definition) ? ($definition['type'] ?? $definition['key'] ?? $definition['event'] ?? '-') : (string) $definition);
            $channels = is_array($definition) ? ($definition['channels'] ?? null) : null;

            $rows[] = [
                $this->code((string) $name),
                is_array($channels) ? implode(', ', array_map(fn ($c) => $this->code((string) $c), $channels)) : '-',
            ];
        }

        return $this->table(['알림 키', '채널'], $rows);
    }

    /**
     * 모델 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderModels(array $ctx): string
    {
        $models = $ctx['data']['models'];

        if ($models === []) {
            return $this->none('소유 모델이 없습니다.');
        }

        $rows = [];
        foreach ($models as $model) {
            $flags = [];
            if ($model['softDeletes']) {
                $flags[] = 'SoftDeletes';
            }
            if ($model['userOverrides']) {
                $flags[] = 'HasUserOverrides';
            }
            if ($model['searchable']) {
                $flags[] = '검색 색인';
            }

            $relations = array_map(
                fn (array $r): string => $r['method'].'→'.($r['target'] ?? '?'),
                array_slice($model['relations'], 0, 6),
            );
            if (count($model['relations']) > 6) {
                $relations[] = '외 '.(count($model['relations']) - 6).'개';
            }

            $rows[] = [
                $this->code($model['class']),
                $model['table'] !== null ? $this->code($model['table']) : '(규약)',
                $model['fillable'] !== null ? (string) $model['fillable'] : '-',
                $relations === [] ? '-' : implode(', ', $relations),
                $flags === [] ? '-' : implode(', ', $flags),
            ];
        }

        return $this->table(['모델', '테이블', 'fillable', '관계', '특성'], $rows);
    }

    /**
     * 소유 테이블 목록을 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderTables(array $ctx): string
    {
        $tables = $ctx['data']['tables'];

        if ($tables === []) {
            return $this->none('소유 테이블이 없습니다.');
        }

        $byTable = [];
        foreach ($ctx['data']['models'] as $model) {
            if ($model['table'] !== null) {
                $byTable[$model['table']][] = $model['class'];
            }
        }

        $rows = [];
        foreach ($tables as $table) {
            $rows[] = [
                $this->code($table),
                isset($byTable[$table]) ? implode(', ', array_map(fn ($c) => $this->code($c), $byTable[$table])) : '-',
            ];
        }

        return $this->table(['테이블', '모델'], $rows);
    }

    /**
     * 마이그레이션 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderMigrations(array $ctx): string
    {
        $migrations = $ctx['data']['migrations'];

        if ($migrations === []) {
            return $this->none('마이그레이션이 없습니다.');
        }

        $rows = [];
        foreach ($migrations as $migration) {
            $rows[] = [
                $this->code($migration['file']),
                $migration['creates'] === [] ? '-' : implode(', ', array_map(fn ($t) => $this->code($t), $migration['creates'])),
                $migration['alters'] === [] ? '-' : implode(', ', array_map(fn ($t) => $this->code($t), $migration['alters'])),
                $migration['hasDown'] ? '✅' : '❌',
            ];
        }

        return sprintf('마이그레이션 %d개.', count($migrations))."\n\n"
            .$this->table(['파일', '생성 테이블', '변경 테이블', 'down()'], $rows);
    }

    /**
     * Enum 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderEnums(array $ctx): string
    {
        $enums = $ctx['data']['enums'];

        if ($enums === []) {
            return $this->none('Enum 이 없습니다.');
        }

        $rows = [];
        foreach ($enums as $enum) {
            $cases = array_map(fn (array $c): string => $c['value'] ?? $c['name'], array_slice($enum['cases'], 0, 8));
            if (count($enum['cases']) > 8) {
                $cases[] = '외 '.(count($enum['cases']) - 8).'개';
            }

            $rows[] = [
                $this->code($enum['class']),
                $enum['backing'] !== null ? $this->code($enum['backing']) : '(pure)',
                (string) count($enum['cases']),
                $cases === [] ? '-' : implode(', ', array_map(fn ($c) => $this->code((string) $c), $cases)),
            ];
        }

        return $this->table(['Enum', 'backing', 'case 수', 'case'], $rows);
    }

    /**
     * Repository 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderRepositories(array $ctx): string
    {
        $repositories = $ctx['data']['repositories'];

        if ($repositories === []) {
            return $this->none('Repository 가 없습니다.');
        }

        $rows = [];
        foreach ($repositories as $repository) {
            $rows[] = [
                $this->code($repository['class']),
                $repository['isInterface'] ? '인터페이스' : '구현',
                $repository['summary'] ?? '-',
            ];
        }

        return $this->table(['클래스', '종류', '설명'], $rows);
    }

    /**
     * 설정 스키마 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderSettingsSchema(array $ctx): string
    {
        $schema = $ctx['surface']['values']['getSettingsSchema'] ?? [];
        $defaultsPath = $ctx['surface']['values']['getSettingsDefaultsPath'] ?? null;
        $layout = $ctx['surface']['values']['getSettingsLayout'] ?? null;

        $lines = [];

        if (is_array($schema) && $schema !== []) {
            $rows = [];
            foreach ($this->flattenSchema($schema) as $key => $meta) {
                // label/description 은 다국어 배열일 수 있다 — 문자열 캐스팅하면 경고가 예외로
                // 승격되어 생성 전체가 중단된다.
                $label = ExtensionInventory::localized($meta['label'] ?? '');
                if ($label === '') {
                    $label = ExtensionInventory::localized($meta['description'] ?? '');
                }

                $rows[] = [
                    $this->code($key),
                    $this->scalarLabel($meta['type'] ?? null),
                    $this->scalar($meta['default'] ?? null),
                    $label !== '' ? $label : '-',
                ];
            }
            $lines[] = $this->table(['키', '타입', '기본값', '설명'], $rows);
        } else {
            $lines[] = $this->none('`getSettingsSchema()` 선언이 없습니다.');
        }

        $extra = [];
        if (is_string($defaultsPath) && $defaultsPath !== '') {
            $extra[] = '기본값 파일: '.$this->code($this->relativeToExtension($ctx, $defaultsPath));
        }
        if (is_string($layout) && $layout !== '') {
            $extra[] = '설정 화면 레이아웃: '.$this->code($this->relativeToExtension($ctx, $layout));
        }

        if ($extra !== []) {
            $lines[] = '';
            $lines[] = implode(' · ', $extra);
        }

        return implode("\n", $lines);
    }

    /**
     * README 의 관리자 설정 요약을 렌더합니다.
     *
     * 운영자가 읽는 자리이므로 `docs/settings.md` 의 개발자용 스키마 표보다 얕게 — 키·의미·
     * 기본값만 둡니다. 템플릿은 관리자 설정 화면을 갖지 않으므로 같은 자리에 제공 컴포넌트
     * 요약을 놓습니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderSettingsSummary(array $ctx): string
    {
        if ($ctx['record']['type'] === ExtensionInventory::TYPE_TEMPLATE) {
            return $this->renderComponents($ctx);
        }

        $schema = $ctx['surface']['values']['getSettingsSchema'] ?? [];

        if (! is_array($schema) || $schema === []) {
            $route = $ctx['surface']['values']['getSettingsRoute'] ?? null;
            $layout = $ctx['surface']['values']['getSettingsLayout'] ?? null;

            if (is_string($layout) && $layout !== '') {
                return '관리자 설정 화면이 있습니다 (레이아웃: '.$this->code($this->relativeToExtension($ctx, $layout)).'). 설정 항목은 화면에서 확인하세요.'
                    .(is_string($route) && $route !== '' ? ' 경로: '.$this->code($route) : '');
            }

            return $this->none('별도의 관리자 설정 항목이 없습니다.');
        }

        $rows = [];
        foreach ($this->flattenSchema($schema) as $key => $meta) {
            $label = ExtensionInventory::localized($meta['label'] ?? '');
            if ($label === '') {
                $label = ExtensionInventory::localized($meta['description'] ?? '');
            }

            $rows[] = [
                $this->code($key),
                $label !== '' ? $label : '-',
                $this->scalar($meta['default'] ?? null),
            ];
        }

        return $this->table(['키', '의미', '기본값'], $rows)
            ."\n\n".'개발자용 상세(타입·검증·저장 위치)는 '.$this->docLink($ctx, 'docs/settings.md', '설정 스키마').' 를 보세요.';
    }

    /**
     * 권한 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderPermissions(array $ctx): string
    {
        $permissions = $ctx['surface']['values']['getPermissions'] ?? [];
        $categories = is_array($permissions) ? ($permissions['categories'] ?? []) : [];

        if (! is_array($categories) || $categories === []) {
            return $this->none('선언된 권한이 없습니다.');
        }

        $rows = [];
        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $actions = [];
            foreach ($category['permissions'] ?? [] as $permission) {
                if (is_array($permission) && isset($permission['action'])) {
                    $actions[] = (string) $permission['action'];
                }
            }

            $rows[] = [
                $this->code((string) ($category['identifier'] ?? '-')),
                ExtensionInventory::localized($category['name'] ?? ''),
                $actions === [] ? '-' : implode(', ', array_map(fn ($a) => $this->code($a), $actions)),
                isset($category['resource_route_key']) ? $this->code((string) $category['resource_route_key']) : '-',
            ];
        }

        return $this->table(['카테고리', '이름', '액션', '라우트 키'], $rows);
    }

    /**
     * 메뉴 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderMenus(array $ctx): string
    {
        $rows = [];

        foreach ([['getAdminMenus', '관리자'], ['getCustomMenus', '사용자']] as [$getter, $label]) {
            $menus = $ctx['surface']['values'][$getter] ?? [];
            if (! is_array($menus)) {
                continue;
            }

            foreach ($menus as $menu) {
                if (! is_array($menu)) {
                    continue;
                }

                $children = is_array($menu['children'] ?? null) ? count($menu['children']) : 0;

                $rows[] = [
                    $label,
                    $this->code((string) ($menu['slug'] ?? '-')),
                    ExtensionInventory::localized($menu['name'] ?? ''),
                    isset($menu['url']) && is_string($menu['url']) ? $this->code($menu['url']) : '-',
                    $children > 0 ? (string) $children.'개' : '-',
                ];
            }
        }

        if ($rows === []) {
            return $this->none('등록하는 메뉴가 없습니다.');
        }

        return $this->table(['구분', 'slug', '이름', 'URL', '하위'], $rows);
    }

    /**
     * 라우트 파일 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderRoutes(array $ctx): string
    {
        $routes = $ctx['surface']['values']['getRoutes'] ?? [];

        if (! is_array($routes) || $routes === []) {
            return $this->none('라우트 파일이 없습니다.');
        }

        $type = $ctx['record']['type'] === ExtensionInventory::TYPE_MODULE ? 'modules' : 'plugins';
        $id = $ctx['record']['id'];

        $rows = [];
        foreach ($routes as $kind => $path) {
            $prefix = $kind === 'api' ? "/api/{$type}/{$id}/..." : "/{$type}/{$id}/...";

            $rows[] = [
                $this->code((string) $kind),
                $this->code($this->relativeToExtension($ctx, (string) $path)),
                $this->code($prefix),
            ];
        }

        $note = '확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.';

        return $this->table(['종류', '파일', 'URL prefix'], $rows)."\n\n".$note;
    }

    /**
     * 의존 관계 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderDependencies(array $ctx): string
    {
        return $this->renderIntegrations($ctx);
    }

    /**
     * 레이아웃 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderLayouts(array $ctx): string
    {
        $frontend = $ctx['frontend'];
        $layouts = $frontend['layouts'];

        if ($layouts === []) {
            return $this->none('레이아웃 JSON 이 없습니다.');
        }

        $groupRows = [];
        foreach ($frontend['layoutGroups'] as $group => $count) {
            $groupRows[] = [$this->code($group), (string) $count.'개'];
        }

        $rows = [];
        foreach ($layouts as $layout) {
            $rows[] = [
                $this->code($layout['name']),
                $this->code($layout['group']),
                $layout['partial'] ? 'partial' : '화면',
                $layout['extends'] !== null ? $this->code($layout['extends']) : '-',
            ];
        }

        return sprintf('레이아웃 %d개 (루트: `%s`).', count($layouts), $frontend['layoutRoot'])."\n\n"
            .$this->table(['그룹', '개수'], $groupRows)."\n\n"
            .$this->table(['레이아웃', '그룹', '종류', 'extends'], $rows);
    }

    /**
     * 템플릿 라우트 → 레이아웃 매핑을 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderLayoutMap(array $ctx): string
    {
        $record = $ctx['record'];
        $routesJson = $record['path'].DIRECTORY_SEPARATOR.'routes.json';

        if (! is_file($routesJson)) {
            return $this->none('`routes.json` 이 없습니다.');
        }

        $data = json_decode((string) file_get_contents($routesJson), true);
        $routes = is_array($data) ? ($data['routes'] ?? $data) : [];

        if (! is_array($routes) || $routes === []) {
            return $this->none('`routes.json` 에 라우트 선언이 없습니다.');
        }

        $rows = [];
        foreach ($routes as $key => $route) {
            if (is_string($route)) {
                $rows[] = [$this->code((string) $key), $this->code($route), '-'];

                continue;
            }

            if (! is_array($route)) {
                continue;
            }

            $rows[] = [
                $this->code((string) ($route['path'] ?? $key)),
                $this->code((string) ($route['layout'] ?? '-')),
                (string) ($route['name'] ?? '-'),
            ];
        }

        return $this->table(['경로', '레이아웃', '이름'], $rows);
    }

    /**
     * 액션 핸들러 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderHandlers(array $ctx): string
    {
        $handlers = $ctx['frontend']['handlers'];

        if ($handlers['names'] === []) {
            return $this->none('등록하는 액션 핸들러가 없습니다.');
        }

        $namespace = $handlers['namespace'];
        $rows = [];

        foreach ($handlers['names'] as $name) {
            // 등록 키가 이미 네임스페이스를 포함하면(따옴표 키) 그 값 자체가 호출 이름이다.
            // 템플릿은 namespace 가 null 이지만 네임스페이스를 붙여 등록하는 핸들러를 함께
            // 가질 수 있어, 그 경우 "네임스페이스 없음" 으로 적으면 사실과 반대가 된다.
            $dot = strrpos($name, '.');
            $qualified = $dot !== false
                ? $name
                : ($namespace !== null ? "{$namespace}.{$name}" : null);

            $rows[] = [
                $this->code($dot !== false ? substr($name, $dot + 1) : $name),
                $qualified !== null ? $this->code($qualified) : '(템플릿 전용 — 네임스페이스 없음)',
            ];
        }

        return sprintf('핸들러 %d개 (정의: `%s`).', count($handlers['names']), (string) $handlers['source'])."\n\n"
            .$this->table(['핸들러', '레이아웃에서 부르는 이름'], $rows);
    }

    /**
     * 프론트 전역 진입점을 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderFrontendEntry(array $ctx): string
    {
        $entry = $ctx['frontend']['entryPoints'];

        if ($entry['source'] === null) {
            return $this->none('프론트 엔트리포인트가 없습니다.');
        }

        $rows = [
            ['엔트리 파일', $this->code((string) $entry['source'])],
            ['전역 객체', $entry['global'] !== null ? $this->code('window.'.$entry['global']) : '**미노출**'],
            ['재등록 진입점', $entry['initFunction'] !== null ? $this->code($entry['initFunction'].'()') : '**미노출**'],
        ];

        $note = $entry['global'] === null || $entry['initFunction'] === null
            ? '재등록 진입점이 전역에 고정 이름으로 노출되지 않으면 로케일 전환 후 이 확장의 액션이 전부 무반응이 됩니다 (오류·토스트 없음).'
            : '로케일 전환 시 코어가 이 진입점을 호출해 핸들러를 다시 등록합니다. 진입점은 핸들러 재등록만 수행하고 1회성 부팅 작업을 포함하지 않습니다.';

        return $this->table(['항목', '값'], $rows)."\n\n".$note;
    }

    /**
     * 에셋 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderAssets(array $ctx): string
    {
        $frontend = $ctx['frontend'];
        $loading = $ctx['surface']['values']['getAssetLoadingConfig'] ?? [];

        $rows = [];
        foreach ($frontend['builtAssets'] as $asset) {
            $rows[] = [$this->code($asset), '빌드 산출물 (커밋 대상)'];
        }
        foreach ($frontend['vendoredAssets'] as $asset) {
            $rows[] = [$this->code('dist/vendor/'.$asset), '동봉 제3자 자산 (자체 제공)'];
        }
        if ($frontend['customDir']) {
            $rows[] = [$this->code('custom/'), '운영자 추가 에셋 (확장 교체 시 보존)'];
        }

        // 편집기 스펙은 수집만 하고 렌더하지 않으면 죽은 수집이 된다. 모듈·플러그인도
        // 소유하며(실측 10개), 검사 룰이 그 파일 변경에 문서 동반을 요구한다.
        $editorSpec = $frontend['editorSpec'] ?? ['manifest' => false, 'split' => 0];
        if (! empty($editorSpec['manifest'])) {
            $rows[] = [$this->code('editor-spec.json'), '레이아웃 편집기 스펙 (manifest)'];
        }
        if (($editorSpec['split'] ?? 0) > 0) {
            $rows[] = [
                $this->code('editor-spec/'),
                sprintf('분할 편집기 스펙 %d개', (int) $editorSpec['split']),
            ];
        }

        // manifest 가 선언했는데 디스크에 없는 산출물은 그 자체가 신호다 — 브라우저에서는
        // 404 가 되고 서버 로그에는 흔적이 없다. 위 스캔은 `dist/` 관례만 보므로, 관례를
        // 벗어난 경로를 선언한 확장은 여기서만 드러난다.
        $declared = $ctx['surface']['values']['getBuiltAssetPaths'] ?? [];
        $missing = [];

        if (is_array($declared)) {
            foreach ($declared as $declaredPath) {
                if (! is_string($declaredPath) || $declaredPath === '') {
                    continue;
                }

                $rel = ltrim(str_replace('\\', '/', $declaredPath), '/');
                if (in_array($rel, $frontend['builtAssets'], true)) {
                    continue;
                }

                $abs = $ctx['record']['path'].DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (! is_file($abs)) {
                    $missing[] = $rel;

                    continue;
                }

                $rows[] = [$this->code($rel), '빌드 산출물 (manifest 선언)'];
            }
        }

        if ($rows === [] && $missing === []) {
            return $this->none('프론트 에셋이 없습니다.');
        }

        $lines = $rows === [] ? [] : [$this->table(['경로', '구분'], $rows)];

        if ($missing !== []) {
            $lines[] = '';
            $lines[] = $this->none(sprintf(
                'manifest 가 선언했으나 디스크에 없는 산출물: %s — 빌드하지 않았거나 경로가 어긋났습니다.',
                implode(', ', array_map(fn (string $p): string => $this->code($p), $missing)),
            ));
        }

        if (is_array($loading) && $loading !== []) {
            $lines[] = '';
            $lines[] = '로딩 설정: '.$this->code(json_encode($loading, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-');
        }

        return implode("\n", $lines);
    }

    /**
     * 템플릿 제공 컴포넌트 표를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderComponents(array $ctx): string
    {
        $components = $ctx['frontend']['components'];

        if ($components['total'] === 0) {
            return $this->none('제공 컴포넌트가 없습니다.');
        }

        $rows = [];
        foreach ($components['byCategory'] as $category => $count) {
            $rows[] = [$this->code($category), (string) $count.'개'];
        }

        return sprintf('컴포넌트 %d개 (루트: `%s`).', $components['total'], (string) $components['root'])."\n\n"
            .$this->table(['분류', '개수'], $rows);
    }

    // -----------------------------------------------------------------------
    // 골격 생성
    // -----------------------------------------------------------------------

    /**
     * 문서 골격 전문을 만듭니다 (신규 파일 전용).
     *
     * @param  string  $doc  문서 상대 경로
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 문서 전문
     */
    public function skeleton(string $doc, array $ctx): string
    {
        return match ($doc) {
            'AGENTS.md' => $this->skeletonAgents($ctx),
            'README.md' => $this->skeletonReadme($ctx),
            'docs/README.md' => $this->skeletonDocsReadme($ctx),
            'docs/architecture.md' => $this->skeletonSectioned($doc, $ctx, '설계 의도와 계층 구조'),
            default => $this->skeletonSectioned($doc, $ctx, $this->documentPurpose($doc)),
        };
    }

    /**
     * AGENTS.md 골격을 만듭니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 문서 전문
     */
    private function skeletonAgents(array $ctx): string
    {
        $record = $ctx['record'];
        $name = $record['name'];
        $label = ExtensionInventory::typeLabel($record['type']);

        $lines = [];
        $lines[] = "# {$name} — 에이전트 가이드";
        $lines[] = '';
        $lines[] = "> 이 문서는 이 {$label}을 수정하는 에이전트·확장개발자를 위한 것입니다. 도입 검토·운영 관점은 [README.md](README.md) 를 보세요.";
        $lines[] = '';
        $lines[] = '## TL;DR (5초 요약)';
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = "1. 유형: {$label} ({$record['id']}) — ".self::TODO_INTENT.' (소유 도메인 한 줄)';
        $lines[] = '2. 확장 방식: '.self::TODO_INTENT.' (이 확장을 건드리지 않고 붙이는 방법)';
        $lines[] = '3. 건드리면 안 되는 것: '.self::TODO_FORBIDDEN;
        $lines[] = '4. 작업 위치: `'.$record['relPath'].'` — 활성 디렉토리 직접 수정 금지';
        $lines[] = "5. 반영: `php artisan {$record['type']}:update {$record['id']} --force`";
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## 1. 이 확장은 무엇인가';
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_INTENT.' — 개발 의도, 해결하는 문제, 설계 원칙, 의도적으로 하지 않는 것을 적습니다.',
        ]);
        $lines[] = '';
        $lines[] = '## 2. 디렉토리 지도';
        $lines[] = '';
        $lines[] = self::wrap('directory-map', $this->renderBlock('directory-map', $ctx));
        $lines[] = '';
        $lines[] = '## 3. 핵심 흐름';
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_FLOW.' — 대표 시나리오 2~3개를 Controller → FormRequest → Service → Repository → Model 경로로 적습니다.',
        ]);
        $lines[] = '';
        $lines[] = '## 4. 확장점';
        $lines[] = '';
        $lines[] = self::wrap('extension-points-summary', $this->renderBlock('extension-points-summary', $ctx));
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_INTENT.' — 이 확장을 수정하지 않고 동작을 바꾸는 방법(어느 훅을 잡는가)을 적습니다.',
        ]);
        $lines[] = '';
        $lines[] = '## 5. 수정 시 동반 의무';
        $lines[] = '';
        $lines[] = $this->obligationChecklist($ctx);
        $lines[] = '';
        $lines[] = '## 6. 금지 패턴';
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_FORBIDDEN.' — 이 확장에서 실제로 발생했거나 발생할 수 있는 오용을 표로 적습니다.',
            '',
            '| 금지 | 올바른 사용 | 이유 |',
            '|---|---|---|',
            '| - | - | - |',
        ]);
        $lines[] = '';
        $lines[] = '## 7. 테스트 실행';
        $lines[] = '';
        $lines[] = self::wrap('test-commands', $this->renderBlock('test-commands', $ctx));
        $lines[] = '';
        $lines[] = '## 8. 문서 목차';
        $lines[] = '';
        $lines[] = self::wrap('docs-index', $this->renderBlock('docs-index', $ctx));
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * 수정 시 동반 의무 체크리스트 초안을 만듭니다.
     *
     * 확장이 실제로 보유한 표면만 항목으로 남깁니다 — 걸리지 않는 의무를 나열하면
     * 체크리스트 전체가 형식적으로 읽힙니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function obligationChecklist(array $ctx): string
    {
        $record = $ctx['record'];
        $items = [];

        $items[] = "`_bundled` 에서만 수정하고 `php artisan {$record['type']}:update {$record['id']} --force` 로 반영";

        // 동기화 대상은 유형마다 다르다. 템플릿은 PHP 패키지가 아니라 `composer.json` 을
        // 갖지 않는데(번들 템플릿 4개 전부 0건), 3유형 공통 문구를 내면 **없는 파일**을
        // 체크 항목으로 요구하게 된다. 이 절은 골격에 한 번만 쓰이고 자동 생성 블록이
        // 아니라 재생성으로 고쳐지지도 않으므로, 틀린 채로 20세트에 굳는다.
        $items[] = $record['type'] === 'template'
            ? 'manifest version 상향 시 `package.json` · `package-lock.json` 동기화 + CHANGELOG 기재'
            : 'manifest version 상향 시 `package.json` · `package-lock.json` · `composer.json` 동기화 + CHANGELOG 기재';

        if ($ctx['data']['migrations'] !== []) {
            $items[] = '스키마 변경 시 마이그레이션(한국어 comment + `down()`) + 기설치본 백필용 업그레이드 스텝';
        }

        if ($ctx['hooks']['published'] !== []) {
            $items[] = '발행 훅 추가·이름 변경 시 `php artisan ext:docgen` 재실행 (구독하는 확장의 계약이 바뀝니다)';
        }

        // 표면을 못 읽었으면 "라우트가 없다" 가 아니라 "모른다" 다. 여기서 조건만 보고
        // 항목을 빼면 그 누락에 아무 신호가 남지 않는데, 이 절은 골격에 한 번만 쓰이고
        // 자동 생성 블록이 아니라 재생성으로 복구되지도 않는다.
        $errors = $ctx['surface']['errors'] ?? [];
        $routesKnown = ($ctx['surface']['available'] ?? false) === true
            && ! array_key_exists('getRoutes', $errors)
            && ! array_key_exists('__path_injection', $errors);

        if (! $routesKnown && $record['type'] !== 'template') {
            $items[] = '라우트 선언을 읽지 못했습니다 — API 표면이 있다면 `php artisan api:docgen --scope='.$record['type'].':'.$record['id'].'` 동반 여부를 직접 확인하세요.';
        } elseif (($ctx['surface']['values']['getRoutes'] ?? []) !== []) {
            $items[] = 'API 표면 변경 시 `php artisan api:docgen --scope='.$record['type'].':'.$record['id'].'` 재실행 + `docs/api/**` 갱신';
        }

        if ($ctx['frontend']['layouts'] !== []) {
            $items[] = '레이아웃 JSON 변경 시 빌드 없이 update 만 — 신규 Tailwind 클래스는 빌드된 CSS 에 존재하는지 확인';
        }

        if ($ctx['frontend']['builtAssets'] !== []) {
            $items[] = 'TSX/TS 변경 시 `--production` 재빌드 후 `dist/` 커밋 (sourceMappingURL 잔존 금지)';
        }

        if (is_dir($record['path'].DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'lang')
            || is_dir($record['path'].DIRECTORY_SEPARATOR.'lang')) {
            $items[] = '다국어 키 추가 시 ko·en 동시 반영 + 번들 ja 언어팩 증분 동기화';
        }

        $items[] = self::TODO_INTENT.' — 이 확장에만 걸리는 코어 횡단 규정을 추려 추가합니다.';

        $lines = [];
        foreach ($items as $item) {
            $lines[] = '- [ ] '.$item;
        }

        return implode("\n", $lines);
    }

    /**
     * README.md 골격을 만듭니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 문서 전문
     */
    private function skeletonReadme(array $ctx): string
    {
        $record = $ctx['record'];
        $name = $record['name'];
        $label = ExtensionInventory::typeLabel($record['type']);
        $description = $record['description'] !== '' ? $record['description'] : self::TODO_INTENT;

        // 인라인 TOC 는 필수 섹션 목록에서 만든다 — 유형별 절 이름 차이(관리자 설정 ↔ 제공
        // 컴포넌트)를 두 곳에 적으면 한쪽만 고쳐 링크가 끊긴다.
        $sections = self::sectionsFor('README.md', $record['type']);
        // 절 이름은 override 를 거쳐 나오므로 위치가 아니라 **원래 절 이름**으로 되짚는다.
        // 인덱스로 집으면 README 절을 하나 끼워 넣는 순간 엉뚱한 절이 설정 자리가 되는데,
        // 헤딩은 그 자리에서 만들어지므로 필수 섹션 검사도 함께 통과해 드러나지 않는다.
        $settingsSection = self::sectionsFor('README.md', $record['type'])[
            array_search('관리자 설정', self::DOCUMENTS['README.md']['sections'], true)
        ];
        $toc = array_map(
            fn (string $s): string => '['.$s.'](#'.str_replace(' ', '-', $s).')',
            $sections,
        );

        // 확장명은 히어로 이미지가 아니라 평범한 H1 이다 (PO 결정 2026-08-31). 확장 20개는
        // 대등하게 병렬로 존재하는 구성요소이고, 각자가 코어와 같은 히어로 브랜딩을 받으면
        // "이 확장이 곧 독립 프로젝트" 라는 착시를 준다. `@generated:badges` 블록의
        // flat-square 정보 배지는 manifest 에서 오는 것이라 그대로 둔다.
        $lines = [];
        $lines[] = '# '.$name;
        $lines[] = '';
        $lines[] = "**그누보드7 {$label} · {$record['id']}**";
        $lines[] = $this->escape($description);
        $lines[] = '';
        $lines[] = self::wrap('badges', $this->renderBlock('badges', $ctx));
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = implode(' · ', $toc);
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## 소개';
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_INTENT.' — 무엇을 해결하는가 · 어떤 상황에 쓰는가 · 의도적으로 하지 않는 것.',
        ]);
        $lines[] = '';
        $lines[] = '## 주요 기능';
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_INTENT.' — 영역별 기능을 표로 적습니다.',
            '',
            '| 영역 | 설명 |',
            '|---|---|',
            '| - | - |',
        ]);
        $lines[] = '';
        $lines[] = '## 동작 방식';
        $lines[] = '';
        // 템플릿은 요청 흐름 대신 레이아웃 상속 구조가 이 자리의 답이다.
        $lines[] = $record['type'] === ExtensionInventory::TYPE_TEMPLATE
            ? $this->intentBlock([
                self::TODO_FLOW.' — 레이아웃 상속도를 둡니다 (베이스 레이아웃 → 자식 레이아웃).',
                '',
                '```mermaid',
                'flowchart TD',
                '  base[_base] --> child1[목록 화면]',
                '  base --> child2[상세 화면]',
                '```',
            ])
            : $this->intentBlock([
                self::TODO_FLOW.' — 운영자 눈높이의 mermaid 흐름도를 1~2개 둡니다 (요청 흐름 / 상태 전이 / 확장 간 관계).',
                '',
                '```mermaid',
                'flowchart LR',
                '  A[운영자] --> B[화면]',
                '  B --> C[처리]',
                '```',
            ]);
        $lines[] = '';
        $lines[] = '## 요구 사항';
        $lines[] = '';
        $lines[] = self::wrap('requirements', $this->renderBlock('requirements', $ctx));
        $lines[] = '';
        $lines[] = '## 설치';
        $lines[] = '';
        $lines[] = self::wrap('install', $this->renderBlock('install', $ctx));
        $lines[] = '';
        $lines[] = '## '.$settingsSection;
        $lines[] = '';
        $lines[] = self::wrap('settings-summary', $this->renderBlock('settings-summary', $ctx));
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_USAGE.' — 위 표가 답하지 않는 것(각 항목을 언제 바꾸는가 · 바꾸면 무엇이 달라지는가)을 적습니다.',
        ]);
        $lines[] = '';
        $lines[] = '## 사용 방법';
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_USAGE.' — 대표 시나리오 2~3개를 운영자 관점 단계로 적습니다.',
        ]);
        $lines[] = '';
        $lines[] = '## 다른 확장과의 연동';
        $lines[] = '';
        $lines[] = self::wrap('integrations', $this->renderBlock('integrations', $ctx));
        $lines[] = '';
        $lines[] = '## 문서';
        $lines[] = '';
        $lines[] = self::wrap('docs-index', $this->renderBlock('docs-index', $ctx));
        $lines[] = '';
        $lines[] = '## 트러블슈팅';
        $lines[] = '';
        $lines[] = $this->intentBlock([
            self::TODO_TROUBLESHOOTING.' — 운영 중 자주 만나는 증상 → 원인 → 조치를 표로 적습니다.',
            '',
            '| 증상 | 원인 | 조치 |',
            '|---|---|---|',
            '| - | - | - |',
        ]);
        $lines[] = '';
        $lines[] = '## 변경 이력';
        $lines[] = '';
        $lines[] = '[CHANGELOG.md](CHANGELOG.md)';
        $lines[] = '';
        $lines[] = '## 라이선스';
        $lines[] = '';
        $lines[] = (string) ($record['manifest']['license'] ?? 'MIT');
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * `docs/README.md` 골격을 만듭니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 문서 전문
     */
    private function skeletonDocsReadme(array $ctx): string
    {
        $record = $ctx['record'];

        $lines = [];
        $lines[] = "# {$record['name']} 개발자 문서";
        $lines[] = '';
        $lines[] = "> {$record['relPath']} · ".ExtensionInventory::typeLabel($record['type']);
        $lines[] = '';
        $lines[] = self::wrap('stats', $this->renderBlock('stats', $ctx));
        $lines[] = '';
        $lines[] = '## 문서 목차';
        $lines[] = '';
        $lines[] = self::wrap('doc-toc', $this->renderBlock('doc-toc', $ctx));
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * 섹션 기반 문서 골격을 만듭니다.
     *
     * @param  string  $doc  문서 상대 경로
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @param  string  $purpose  문서 용도 한 줄
     * @return string 문서 전문
     */
    private function skeletonSectioned(string $doc, array $ctx, string $purpose): string
    {
        $meta = self::DOCUMENTS[$doc];
        $record = $ctx['record'];
        $overrides = $meta['sectionOverrides'][$record['type']] ?? [];

        $lines = [];
        $lines[] = '# '.$record['name'].' — '.$this->documentTitle($doc);
        $lines[] = '';
        $lines[] = '> '.$purpose.' · 진입점: [AGENTS.md](../AGENTS.md)';
        $lines[] = '';

        // `docs/architecture.md` 만 절 수와 블록 수가 다르다(서술 2 + 블록 1).
        if (! self::pairsSectionsWithBlocks($doc)) {
            foreach (self::sectionsFor($doc, $record['type']) as $section) {
                $lines[] = '## '.$section;
                $lines[] = '';

                if ($section === '디렉토리') {
                    $lines[] = self::wrap('directory-map', $this->renderBlock('directory-map', $ctx));
                } else {
                    $lines[] = $this->intentBlock([
                        ($section === '설계 의도' ? self::TODO_INTENT : self::TODO_FLOW).' — '.$section.' 를 서술합니다.',
                    ]);
                }

                $lines[] = '';
            }

            return implode('
', $lines);
        }

        // 절 ↔ 블록은 배열의 **키**가 짝을 정한다. 순번(`$blocks[$i]`)으로 짝지으면 절을
        // 하나 끼우는 순간 그 뒤 블록이 전부 엉뚱한 헤딩 밑으로 들어가는데, 헤딩 존재와
        // 블록 존재를 각각만 보는 게이트는 그 어긋남을 잡지 못한다.
        foreach ($meta['blocks'] as $section => $blockKey) {
            $lines[] = '## '.($overrides[$section] ?? $section);
            $lines[] = '';
            $lines[] = self::wrap($blockKey, $this->renderBlock($blockKey, $ctx));
            $lines[] = '';
            $lines[] = $this->intentBlock([
                self::TODO_INTENT.' — 위 표가 답하지 않는 것(왜 이렇게 설계했는가 · 어느 것을 잡아야 하는가)을 적습니다.',
            ]);
            $lines[] = '';
        }

        return implode('
', $lines);
    }

    /**
     * 문서 제목을 반환합니다.
     *
     * @param  string  $doc  문서 상대 경로
     * @return string 제목
     */
    private function documentTitle(string $doc): string
    {
        return match ($doc) {
            'docs/architecture.md' => '아키텍처',
            'docs/extension-points.md' => '확장점',
            'docs/data-model.md' => '데이터 모델',
            'docs/settings.md' => '설정·권한·라우트',
            'docs/frontend.md' => '프론트엔드',
            'docs/components.md' => '컴포넌트',
            'docs/layouts.md' => '레이아웃',
            'docs/handlers.md' => '핸들러',
            'docs/editor-spec.md' => '레이아웃 편집기 스펙',
            default => basename($doc, '.md'),
        };
    }

    // -----------------------------------------------------------------------
    // 편집기 스펙 블록 렌더러
    // -----------------------------------------------------------------------

    /**
     * 편집기 스펙 보유 여부와 형태를 렌더합니다.
     *
     * 미보유는 결함이 아니라 정상일 수 있으므로 "없음" 을 사실로 적고 사람 서술에 넘깁니다.
     * 다만 manifest 가 있는데 읽지 못한 경우(malformed)는 구분해 적습니다 — 뭉뚱그리면
     * 깨진 JSON 이 "이 확장은 편집기 스펙을 두지 않는다" 로 굳습니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderEditorSpecSummary(array $ctx): string
    {
        $spec = $ctx['editorSpec'] ?? null;

        if (! is_array($spec)) {
            return $this->none('편집기 스펙을 수집하지 못했습니다.');
        }

        if (($spec['malformed'] ?? false) === true) {
            return $this->none('`editor-spec.json` 이 존재하지만 JSON 으로 읽지 못했습니다 — 편집기가 이 확장의 선언을 무시하고 있습니다.');
        }

        if (($spec['present'] ?? false) !== true) {
            return $this->none('이 확장은 편집기 스펙(`editor-spec.json`)을 두지 않습니다. 편집기는 코어 기본 팔레트와 활성 템플릿의 스펙만으로 이 확장의 화면을 다룹니다.');
        }

        $rows = [];
        $rows[] = ['manifest', $this->code((string) $spec['manifest'])];
        $rows[] = ['형태', $spec['split'] === true
            ? '분할 — manifest + `editor-spec/*.json` '.count($spec['includes']).'개 블록'
            : '단일 파일 (인라인)'];
        $rows[] = ['스펙 버전', $this->code((string) ($spec['version'] ?? ''))];
        $rows[] = ['스타일 시스템', $this->code((string) ($spec['styleSystem'] ?? ''))];
        $rows[] = ['다크 모드 전략', $this->code((string) ($spec['darkMode'] ?? ''))];

        $table = $this->table(['항목', '값'], $rows);

        $description = $spec['description'] ?? null;

        if (is_string($description) && $description !== '') {
            return $table."\n\n> ".$this->escape($description);
        }

        return $table;
    }

    /**
     * 스펙이 선언한 블록별 항목 수를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderEditorSpecBlocks(array $ctx): string
    {
        $spec = $ctx['editorSpec'] ?? null;

        if (! is_array($spec) || ($spec['present'] ?? false) !== true) {
            return $this->none('선언된 편집기 스펙 블록이 없습니다.');
        }

        $rows = [];

        foreach ($spec['blocks'] as $block) {
            $rows[] = [
                $this->code($block['key']),
                $this->editorSpecBlockRole($block['key']),
                $block['count'] === null ? '-' : (string) $block['count'],
                $this->code($block['source']),
            ];
        }

        return $this->table(['블록', '역할', '항목 수', '출처'], $rows);
    }

    /**
     * 편집기 스펙 블록의 역할을 한 줄로 설명합니다.
     *
     * 블록 키는 코어가 정한 어휘이므로 확장마다 다시 설명할 필요가 없고, 반대로 설명이
     * 없으면 확장 문서만 읽는 쪽이 키 이름만으로 의미를 추측하게 됩니다.
     *
     * @param  string  $key  블록 키
     * @return string 역할 설명
     */
    private function editorSpecBlockRole(string $key): string
    {
        return match ($key) {
            'componentPalette', 'componentPalette.entries' => '편집기 "요소 추가" 팔레트에 나타나는 항목',
            'componentPalette.groups' => '팔레트 좌측 목록의 묶음',
            'nesting.draggable' => '캔버스에서 끌어 옮길 수 있는 컴포넌트',
            'nesting.containers' => '자식을 담을 수 있는 컴포넌트와 그 허용 규칙',
            'sampleData.byDataSourceId' => '레이아웃 `data_sources` ID 로 붙는 프리뷰 응답',
            'sampleData.byEndpointPattern' => '엔드포인트 패턴으로 붙는 프리뷰 응답',
            'states.groups' => '상태 변종을 적용할 범위(라우트·베이스 레이아웃)',
            'conditionRecipes.operators' => '조건 표현식에 쓸 수 있는 연산자',
            'controls' => '재사용 스타일 컨트롤 정의',
            'componentCapabilities' => '컴포넌트별 편집 역량(어떤 속성을 편집기가 다루는가)',
            'nesting' => '어떤 컴포넌트 안에 무엇을 넣을 수 있는가',
            'sampleData' => '캔버스 프리뷰용 샘플 응답',
            'sampleGlobal' => '`_global.*` 프리뷰 baseline 시드',
            'states' => '페이지 상태 변종(빈 목록·오류 등)',
            'stateLabels' => '상태값 친화 명칭 카탈로그',
            'actionRecipes' => '친화 명칭 → 액션 JSON 레시피',
            'conditionRecipes' => '친화 조건 → `if` 표현식 레시피',
            'computedRecipes' => '계산값 레시피',
            'errorRecipes' => '오류 처리 레시피',
            'loadingComponents' => '로딩 표시 컴포넌트 후보',
            'actionChipCandidates' => '동작 데이터 칩 컨텍스트 후보',
            default => '-',
        };
    }

    /**
     * ID 목록을 표 한 칸에 담을 수 있게 줄입니다.
     *
     * 70개를 한 줄로 늘어놓으면 표가 읽히지 않고, 그렇다고 개수만 남기면 어떤 ID 인지
     * 확인할 길이 사라집니다. 앞쪽을 보이고 나머지는 수로 줄입니다.
     *
     * @param  array<int, string>  $ids  ID 목록
     * @param  int  $limit  나열할 최대 개수
     * @return string 마크다운 셀 내용
     */
    private function idListCell(array $ids, int $limit = 12): string
    {
        if ($ids === []) {
            return '-';
        }

        $shown = array_slice($ids, 0, $limit);
        $cell = implode(' · ', array_map(fn (string $id): string => $this->code($id), $shown));

        $rest = count($ids) - count($shown);

        return $rest > 0 ? $cell." … 외 {$rest}개" : $cell;
    }

    /**
     * 팔레트 그룹별 항목 수를 렌더합니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderEditorSpecPalette(array $ctx): string
    {
        $spec = $ctx['editorSpec'] ?? null;

        if (! is_array($spec) || ($spec['present'] ?? false) !== true) {
            return $this->none('이 확장은 편집기 팔레트에 항목을 추가하지 않습니다.');
        }

        $rows = [];

        foreach ($spec['paletteGroups'] as $group) {
            $rows[] = [
                $this->escape($group['group']),
                $this->code($group['kind']),
                (string) $group['count'],
            ];
        }

        if ($rows === []) {
            return $this->none('이 확장은 `componentPalette` 를 선언하지 않습니다 — 편집기 팔레트에 추가되는 항목이 없습니다.');
        }

        return $this->table(['그룹', '종류', '컴포넌트 수'], $rows);
    }

    /**
     * 샘플 데이터 ID 와 페이지 상태 변종을 렌더합니다.
     *
     * 이 두 축은 편집기 캔버스가 실제 API 없이 화면을 그릴 때 쓰는 값입니다. 레이아웃의
     * `data_sources` ID 와 어긋나면 편집기 프리뷰만 빈 화면이 되는데, 실제 화면은 정상이라
     * 어긋남이 드러나지 않습니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderEditorSpecSamples(array $ctx): string
    {
        $spec = $ctx['editorSpec'] ?? null;

        if (! is_array($spec)) {
            return $this->none('편집기 스펙을 수집하지 못했습니다.');
        }

        // 스펙이 없어도 **미커버 목록은 낸다.** 스펙이 없는 확장이야말로 프리뷰가 비는
        // 자리를 가질 가능성이 크고, 여기서 조기 반환하면 정작 필요한 확장에서 그 목록이
        // 통째로 사라진다 — 그런데 결과는 "빈 자리가 없다" 와 구분되지 않는다.
        $sections = [];

        if (($spec['present'] ?? false) === true) {
            // 세 자리를 한 행으로 합치지 않는다 — `data_sources` ID 로 붙는 샘플과 엔드포인트
            // 패턴으로 붙는 샘플은 어긋났을 때 고칠 자리가 다르고, 페이지 상태는 ID 가 아니라
            // 적용 범위(라우트/베이스 레이아웃)로 식별된다.
            $rows = [];

            foreach ([
                ['sampleData.byDataSourceId', $spec['sampleDataIds']],
                ['sampleData.byEndpointPattern', $spec['sampleEndpointPatterns']],
                ['states.groups', $spec['stateScopes']],
            ] as [$label, $ids]) {
                $declared = ($spec['declaredPaths'][$label] ?? false) === true;

                $rows[] = [
                    $this->code($label),
                    $this->editorSpecBlockRole($label),
                    $declared ? (string) count($ids) : '미선언',
                    $declared ? $this->idListCell($ids) : '-',
                ];
            }

            $sections[] = $this->table(['자리', '역할', '개수', 'ID'], $rows);
        } else {
            $sections[] = $this->none('이 확장은 편집기 스펙을 두지 않아 선언된 샘플 데이터·페이지 상태가 없습니다.');
        }

        $uncovered = $spec['uncovered'] ?? [];

        if ($uncovered === []) {
            $sections[] = $this->none('이 확장 레이아웃의 `data_source` 는 전부 프리뷰 샘플이 붙습니다 (이 확장 또는 번들 템플릿 스펙이 커버).');
        } else {
            $sections[] = '**프리뷰 샘플이 없는 `data_source` '.count($uncovered).'개** — 편집기 캔버스에서 이 자리만 빈 화면이 됩니다. 실제 화면은 정상이라 오류도 경고도 남지 않습니다.'
                ."\n\n".$this->idListCell($uncovered, 20);
        }

        return implode("\n\n", $sections);
    }

    /**
     * 편집기 스펙을 함께 고쳐야 하는 변경과 그 절차를 렌더합니다.
     *
     * 이 표가 없으면 확장에 화면 요소를 추가해도 편집기 팔레트에 나타나지 않는 상태가
     * 오류도 경고도 없이 남습니다 — 편집기는 선언되지 않은 컴포넌트를 "없는 것" 으로
     * 다룰 뿐 실패를 보고하지 않습니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string 마크다운
     */
    private function renderEditorSpecObligations(array $ctx): string
    {
        $record = $ctx['record'];
        $spec = $ctx['editorSpec'] ?? null;
        $hasSpec = is_array($spec) && ($spec['present'] ?? false) === true;

        $update = "php artisan {$record['type']}:update {$record['id']} --force";

        $rows = [
            ['컴포넌트를 새로 만들었다', '`componentPalette` 에 항목 추가 · `componentCapabilities` 에 편집 역량 선언 · `nesting` 에 담길 자리 규정'],
            ['레이아웃에 `data_sources` 를 추가했다', '`sampleData` 에 같은 ID 로 프리뷰 응답 추가 (없으면 편집기 캔버스만 빈 화면)'],
            ['`_global.*` 을 새로 읽는다', '`sampleGlobal` 에 baseline 값 추가'],
            ['빈 목록·오류 같은 화면 변종을 추가했다', '`states` 에 변종 추가 · `stateLabels` 에 친화 명칭'],
            ['새 액션·조건 패턴을 도입했다', '`actionRecipes` / `conditionRecipes` 에 친화 명칭 등록'],
        ];

        $table = $this->table(['이런 변경을 했다면', '편집기 스펙에서 함께 할 일'], $rows);

        if (! $hasSpec) {
            // 스펙이 없는 확장에도 이 표를 남긴다. 지금은 해당 없음이지만, 위 사건 중 하나가
            // 일어나는 순간 스펙을 **신설**해야 한다는 것이 이 문서가 전할 내용이다.
            return $this->none('이 확장은 아직 편집기 스펙을 두지 않습니다. 아래 변경이 생기면 `editor-spec.json` 을 신설합니다.')
                ."\n\n".$table;
        }

        // `_bundled` 편집분은 update 커맨드로 활성 디렉토리에 반영된 뒤에만 편집기에 보인다
        // (`EditorSpecAssembler` 는 활성 디렉토리만 합본한다 — `_bundled` 폴백이 없다).
        return $table."\n\n"
            .'편집기 스펙은 JSON 이므로 빌드가 필요 없습니다. 다만 편집기 서빙은 **활성 디렉토리만** 읽으므로(`_bundled` 폴백 없음) 편집 후 반드시 반영합니다:'
            ."\n\n```bash\n".$update."\n```";
    }

    // -----------------------------------------------------------------------
    // 마크다운 유틸
    // -----------------------------------------------------------------------

    /**
     * 사람 영역(`@intent`) 블록을 만듭니다.
     *
     * @param  array<int, string>  $lines  본문 줄
     * @return string 마크다운
     */
    private function intentBlock(array $lines): string
    {
        return "<!-- @intent START -->\n".implode("\n", $lines)."\n<!-- @intent END -->";
    }

    /**
     * 마크다운 표를 만듭니다.
     *
     * @param  array<int, string>  $headers  헤더
     * @param  array<int, array<int, string>>  $rows  행
     * @return string 마크다운 표 (행이 없으면 안내 문구)
     */
    private function table(array $headers, array $rows): string
    {
        if ($rows === []) {
            return $this->none('해당 항목이 없습니다.');
        }

        $lines = [];
        $lines[] = '| '.implode(' | ', $headers).' |';
        $lines[] = '|'.str_repeat('---|', count($headers));

        foreach ($rows as $row) {
            $cells = [];
            for ($i = 0; $i < count($headers); $i++) {
                $cells[] = $this->cell($row[$i] ?? '-');
            }
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }

    /**
     * 표 셀 값을 안전하게 만듭니다 (파이프·개행 이스케이프).
     *
     * @param  string  $value  값
     * @return string 셀 문자열
     */
    private function cell(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = str_replace('|', '\\|', $value);

        return trim($value) === '' ? '-' : trim($value);
    }

    /**
     * 인라인 코드로 감쌉니다.
     *
     * @param  string  $value  값
     * @return string 마크다운
     */
    private function code(string $value): string
    {
        return $value === '' ? '-' : '`'.$value.'`';
    }

    /**
     * 항목 없음 안내를 만듭니다.
     *
     * @param  string  $message  안내 문구
     * @return string 마크다운
     */
    private function none(string $message): string
    {
        return '_'.$message.'_';
    }

    /**
     * 개별 getter 수집 실패를 알리는 문장을 만듭니다.
     *
     * `available` 은 **진입 클래스**를 읽었는지만 말합니다. 클래스를 읽고도 개별 getter 가
     * 던지면(`errors`) 그 항목만 빈 값이 되는데, 렌더러가 그 빈 값을 그대로 "선언된 권한이
     * 없습니다" · "훅을 발행하지 않습니다" 로 서술하면 **읽지 못한 것이 없는 것으로 굳는다**.
     * 경로 주입 실패(`__path_injection`)는 경로 기반 getter 전부를 동시에 비우므로 특히 그렇다.
     *
     * 표면을 통째로 못 읽은 경우(`surfaceUnavailable`)와 달리 여기서는 본문을 **대체하지
     * 않고 덧붙인다** — 나머지 getter 가 돌려준 실측을 버릴 이유가 없다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string|null 통지 문장, 실패가 없으면 null
     */
    private function surfaceErrorsNotice(array $ctx): ?string
    {
        if (($ctx['record']['type'] ?? null) === 'template') {
            return null;
        }

        $errors = $ctx['surface']['errors'] ?? [];
        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $names = array_map(static fn (string $g): string => '`'.$g.'`', array_keys($errors));

        return $this->none(sprintf(
            '아래 표면을 읽지 못했습니다 (%s). 이 절의 "없음" 은 사실이 아닐 수 있습니다 — 항목이 없다는 뜻이 아니라 %s.',
            implode(' · ', $names),
            self::SURFACE_NOTICE_MARKER,
        ));
    }

    /**
     * 선언형 표면을 읽지 못한 경우의 안내를 만듭니다 (읽었으면 null).
     *
     * 수집기는 진입 클래스 로드·인스턴스화 실패를 `available=false` + `reason` 으로 올립니다.
     * 그 신호를 읽지 않으면 "권한이 없습니다" · "라우트 파일이 없습니다" 처럼 **사실이 아닌
     * 문장**이 문서에 박힙니다 — 콘솔 경고는 사라지고 커밋된 문서만 남으므로, 읽는 사람에게는
     * 그것이 확장의 사실로 보입니다. "없음" 과 "확인하지 못함" 은 구분해서 보고합니다.
     *
     * 템플릿은 선언형 표면 자체를 갖지 않으므로(`available=false` 가 정상) 대상이 아닙니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string|null 확인 불가 안내, 정상 수집이면 null
     */
    private function surfaceUnavailable(array $ctx): ?string
    {
        if (($ctx['surface']['available'] ?? false) === true) {
            return null;
        }

        if (($ctx['record']['type'] ?? null) === 'template') {
            return null;
        }

        $reason = $ctx['surface']['reason'] ?? null;

        return $this->none(sprintf(
            '이 항목을 확인하지 못했습니다 (%s). 항목이 없다는 뜻이 아니라 %s.',
            is_string($reason) && $reason !== '' ? $reason : '사유 미상',
            self::SURFACE_NOTICE_MARKER,
        ));
    }

    /**
     * 스칼라 값을 표시용 문자열로 만듭니다.
     *
     * @param  mixed  $value  값
     * @return string 표시 문자열
     */
    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (is_bool($value)) {
            return $this->code($value ? 'true' : 'false');
        }
        if (is_scalar($value)) {
            return $this->code((string) $value);
        }

        return $this->code((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 값을 인라인 코드 라벨로 만듭니다 (배열/객체도 안전하게 처리).
     *
     * @param  mixed  $value  값
     * @return string 마크다운
     */
    private function scalarLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }
        if (is_scalar($value)) {
            return $this->code((string) $value);
        }

        return $this->code((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * HTML 속성용으로 문자열을 이스케이프합니다.
     *
     * @param  string  $value  값
     * @return string 이스케이프된 값
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * 배열/문자열의 항목 수를 셉니다.
     *
     * @param  mixed  $value  값
     * @return int 항목 수
     */
    private function countOf(mixed $value): int
    {
        return is_array($value) ? count($value) : 0;
    }

    /**
     * FQCN 의 짧은 이름을 반환합니다.
     *
     * @param  string  $fqcn  클래스명
     * @return string 짧은 이름
     */
    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', trim($fqcn, '\\'));

        return end($parts) ?: $fqcn;
    }

    /**
     * 절대 경로를 확장 루트 기준 상대 경로로 바꿉니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @param  string  $path  경로
     * @return string 상대 경로
     */
    private function relativeToExtension(array $ctx, string $path): string
    {
        $base = rtrim((string) $ctx['record']['path'], '/\\').DIRECTORY_SEPARATOR;
        $normalized = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $base);

        return str_starts_with($normalized, $normalizedBase)
            ? substr($normalized, strlen($normalizedBase))
            : $normalized;
    }

    /**
     * 문서 링크를 만듭니다 (AGENTS.md 기준 상대 경로).
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @param  string  $doc  문서 상대 경로
     * @param  string  $anchor  섹션 제목
     * @return string 마크다운 링크
     */
    private function docLink(array $ctx, string $doc, string $anchor): string
    {
        $slug = strtolower(str_replace([' ', '.', '(', ')'], ['-', '', '', ''], $anchor));

        return "[{$anchor}]({$doc}#{$slug})";
    }

    /**
     * composer.json 의 PHP 제약을 읽습니다.
     *
     * @param  array<string, mixed>  $ctx  수집 컨텍스트
     * @return string|null PHP 제약 (없으면 null)
     */
    private function composerPhp(array $ctx): ?string
    {
        $path = $ctx['record']['path'].DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($path)) {
            return null;
        }

        $composer = json_decode((string) file_get_contents($path), true);
        $php = $composer['require']['php'] ?? null;

        return is_string($php) ? $php : null;
    }

    /**
     * 설정 스키마를 점 표기 키로 평탄화합니다.
     *
     * @param  array<string, mixed>  $schema  스키마
     * @param  string  $prefix  키 접두
     * @return array<string, array<string, mixed>> 평탄화된 키 => 메타
     */
    private function flattenSchema(array $schema, string $prefix = ''): array
    {
        $flat = [];

        foreach ($schema as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && isset($value['type'])) {
                $flat[$path] = $value;

                continue;
            }

            if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
                $flat += $this->flattenSchema($value, $path);

                continue;
            }

            $flat[$path] = ['type' => gettype($value), 'default' => $value];
        }

        return $flat;
    }
}
