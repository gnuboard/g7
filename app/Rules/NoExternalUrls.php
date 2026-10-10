<?php

namespace App\Rules;

use App\Support\SiteAssetHosts;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 레이아웃 JSON에서 외부 URL을 차단하는 Custom Rule
 *
 * 컴포넌트 props·actions·lifecycle·onComponentEvent·slots·component_layout·responsive 와
 * 최상위 init_actions/initActions·modals·named_actions·errorHandling 내의 http://, https://,
 * data:, javascript: 등 위험한 URI 스킴을 감지하여 차단합니다.
 *
 * 순회 대상은 "액션이 실행되거나 값이 sink(컴포넌트 prop)로 흘러 들어가는 자리" 다.
 * 한 자리만 빠져도 그 키가 그대로 저장 우회로가 되며, 우회는 오류를 남기지 않는다.
 *
 * 검사 대상 구분(신뢰 경계): init_actions 는 로드 시 자동 실행되는 액션이라 외부
 * navigate/apiCall URL 이 곧 자동 리다이렉트·데이터 유출 경로가 되므로 실행 지점에서
 * 차단합니다. 반면 state/computed 는 데이터 값이며, 실제 위험은 그 값이 바인딩되는
 * sink(컴포넌트 prop = img src 등)에서 발생하고 그 sink 는 이미 여기서 검사됩니다 —
 * 예시/안내용 URL 을 담는 정당한 용례를 깨지 않기 위해 데이터 계층은 재차단하지 않습니다.
 *
 * 실행 스킴(javascript/vbscript/data/file)은 위 부분 순회와 별개로 **트리 전체**를 재귀 순회해 막는다
 * (KVE-2026-2350). top-level `slots` 처럼 부분 순회가 모르는 키가 그대로 우회로였고, 실행 스킴은
 * 어느 키에서든 정당한 용례가 없다. `{{ ... }}` 표현식 안 문자열 리터럴도 후보로 본다 — 값이 `{{` 로
 * 시작하면 접두 검사가 통째로 빗나갔다. 스킴 대조는 브라우저와 같은 정규화(선행 제어문자·tab·개행 제거)
 * 후에 한다. 같은 전체 순회에서 **액션의 이동·요청 대상**(`target`·`params.path`·`params.url`)은 위치와
 * 무관하게 외부 URL 까지 막는다 — 액션은 트리 어디에 있든 실행되는 sink 다. 표현식으로 조립한 대상은
 * 정적 검사로 다 잡을 수 없으므로, 인증 정보 유출은 엔진이 교차 출처 요청에 토큰을 싣지 않는 것으로 막는다.
 *
 * 안내 문구·링크 예외(2026-09-30 결정): 입력창 안내 문구(`placeholder`)·링크(`href`) prop 과 액션 요청
 * 본문(`params.body`)의 값은 http(s) 웹 주소를 허용한다. 안내 문구는 화면에 글자로만 그려지고, 링크는 사용자가
 * 눌러야 이동하며, 요청 본문 값은 요청 대상이 아니라 데이터다. 번들 관리자 화면 5개(모듈·플러그인·템플릿·
 * 언어팩 목록, 환경설정)가 이 값을 담고 있어 편집기로 열어 그대로 저장해도 422 였다. 이 자리라도 실행 스킴
 * (`javascript:` 등)·프로토콜 상대 주소(`//host`)·그 밖의 스킴(`ftp:`)은 종전대로 막는다.
 *
 * 외부의 기준: 사이트 자기 host(`app.url`)와 운영자가 선언한 공개 자산 디스크의 host
 * (`SiteAssetHosts`)는 외부가 아닙니다. 레이아웃 첨부 API 가 스스로 발급하는 주소(프록시
 * 서빙 URL·직접 URL)가 그 host 를 쓰므로, 여기서 차단하면 image 위젯으로 올린 파일이
 * 업로드는 되고 저장은 422 가 됩니다. 판정은 정규화 뒤 host 등가 비교이며 protocol-relative
 * 와 http/https 밖의 스킴은 host 가 같아도 종전대로 차단합니다.
 */
class NoExternalUrls implements ValidationRule
{
    /**
     * 사이트 자산 host 목록 (검증 1회당 1회 해석 후 캐시)
     *
     * @var array<int, string>|null
     */
    private ?array $siteAssetHosts = null;

    /**
     * 차단할 위험 URI 스킴 목록
     */
    private const DANGEROUS_SCHEMES = [
        'http://',
        'https://',
        'data:',
        'javascript:',
        'vbscript:',
        'file:',
        'ftp:',
    ];

    /**
     * 트리 전체에서 차단하는 실행 스킴 — 현재 문서에서 코드를 실행하는 URL.
     *
     * http(s) 외부 URL 은 sink 위치(아래 부분 순회)에서만 막는다. 데이터 계층·안내 문구의 정상 외부 링크
     * (번들 레이아웃 실측 24건: 문서 링크·PG 영수증·placeholder)를 깨지 않기 위해서다. 반면 실행 스킴은
     * 어느 키에 있든 정당한 용례가 없고, 값이 나중에 어떤 sink 로 흘러가도 곧 스크립트 실행이므로
     * 위치를 가리지 않는다(KVE-2026-2350 — top-level `slots` 등 미순회 키가 우회로였다).
     */
    private const EXECUTABLE_SCHEMES = [
        'javascript:',
        'vbscript:',
        'data:',
        'file:',
    ];

    /**
     * 액션이 이동·요청 대상으로 읽는 키 (ActionDispatcher: `target` · `params.path` · `params.url`).
     *
     * 액션은 트리 어디에 있든 실행되는 sink 다 — top-level `slots`·확장 `injections`·`itemTemplate` 처럼
     * 부분 순회가 모르는 위치에 인증 apiCall 의 외부 대상이 저장되면 관리자 브라우저가 토큰을 외부로 보냈다
     * (KVE-2026-2350 권한전이). 대상 키만 보므로 요청 본문의 예시 URL 같은 데이터 값은 건드리지 않는다.
     *
     * @var array<int, string>
     */
    private const ACTION_TARGET_KEYS = ['target', 'params.path', 'params.url'];

    /**
     * http(s) 웹 주소를 허용하는 prop 키 — 입력 안내 문구와 링크 (#727).
     *
     * @var array<int, string>
     */
    private const WEB_URL_TEXT_KEYS = ['placeholder', 'href'];

    /**
     * 허용하는 웹 주소 스킴
     *
     * @var array<int, string>
     */
    private const WEB_SCHEMES = ['http://', 'https://'];

    /**
     * 위반 메시지 → 그 위반을 낸 값(처음 하나). 소스 위치를 찾는 데 쓴다(7.1.0 P16).
     *
     * @var array<string, string>
     */
    private array $offending = [];

    /**
     * 위반 메시지별로 그 위반을 낸 값을 돌려줍니다 — 마크업 소스에서 위치를 찾는 단서.
     *
     * @return array<string, string> 메시지 → 값
     */
    public function offendingValues(): array
    {
        return $this->offending;
    }

    /**
     * 이번 검증에서 이미 보고한 (경로|값) — 부분 순회와 전체 순회가 같은 값을 두 번 보고하지 않게 한다.
     *
     * @var array<string, true>
     */
    private array $reported = [];

    /**
     * 검증 수행
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  레이아웃 JSON(배열) 또는 문자열 스칼라
     * @param  Closure  $fail  검증 실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->reported = [];
        $fail = $this->dedupingFail($fail);

        // 문자열 스칼라 필드에도 부착되므로(`content.endpoint` ·
        // `content.data_sources.*.endpoint`) 그 값을 직접 검사한다. 배열만 처리하고
        // 반환하면 그 부착이 조용한 no-op 이 된다.
        if (is_string($value)) {
            $this->checkForDangerousUrl($value, $attribute, $fail);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        // 전체 트리 재귀 — 실행 스킴은 키를 가리지 않는다 (slots·computed·state·data_sources·
        // top-level children·injections 등 부분 순회가 모르는 키 포함, `{{ }}` 안 문자열 리터럴 포함).
        // 액션의 이동·요청 대상은 위치를 가리지 않고 외부 URL 까지 막는다.
        $this->walkExecutableSchemes($value, $attribute, $fail);

        // components 배열을 재귀적으로 검사
        if (isset($value['components']) && is_array($value['components'])) {
            $this->validateComponents($value['components'], $fail);
        }

        // init_actions / initActions: 로드 시 자동 실행되는 액션 — 외부 navigate/apiCall URL 은
        // 로드 시점 자동 리다이렉트/데이터 유출 경로가 되므로 컴포넌트 actions 와 동일하게
        // 검사한다. 엔진(LayoutLoader)이 두 철자를 모두 소비하므로 두 철자 모두 검사한다 —
        // 한쪽만 보면 다른 철자가 그대로 우회로가 된다.
        foreach (['init_actions', 'initActions'] as $initKey) {
            if (isset($value[$initKey]) && is_array($value[$initKey])) {
                foreach ($value[$initKey] as $i => $action) {
                    if (is_array($action)) {
                        $this->validateObject($action, "{$initKey}[$i]", $fail);
                    }
                }
            }
        }

        // modals: 각 항목이 컴포넌트 정의다 — 모달 안의 props/actions 도 같은 sink 이므로
        // 컴포넌트와 동일하게 재귀 검사한다.
        if (isset($value['modals']) && is_array($value['modals'])) {
            foreach ($value['modals'] as $modalKey => $modal) {
                if (! is_array($modal)) {
                    continue;
                }

                $this->validateComponents([$modal], $fail, "modals.$modalKey");

                if (isset($modal['components']) && is_array($modal['components'])) {
                    $this->validateComponents($modal['components'], $fail, "modals.$modalKey.components");
                }
            }
        }

        // injections: 확장 조각이 대상 화면에 끼워 넣는 컴포넌트와 대상 컴포넌트 prop 덧씌우기 — 화면 컴포넌트와 같은
        // sink 다. 종전에는 부분 순회가 이 키를 몰라 조각 저장 경로에서 자원 prop 의 외부 주소가 그대로 저장됐다(#727).
        if (isset($value['injections']) && is_array($value['injections'])) {
            foreach ($value['injections'] as $i => $injection) {
                if (! is_array($injection)) {
                    continue;
                }

                if (isset($injection['components']) && is_array($injection['components'])) {
                    $this->validateComponents($injection['components'], $fail, "injections[$i].components");
                }

                if (isset($injection['props']) && is_array($injection['props'])) {
                    $this->validateObject($injection['props'], "injections[$i].props", $fail);
                }
            }
        }

        // named_actions: 이름으로 호출되는 액션 정의 — 실행 시 컴포넌트 actions 와 동일한 sink.
        if (isset($value['named_actions']) && is_array($value['named_actions'])) {
            foreach ($value['named_actions'] as $name => $named) {
                if (is_array($named)) {
                    $this->validateObject($named, "named_actions.$name", $fail);
                }
            }
        }

        // errorHandling: 오류 시 실행되는 액션(navigate/apiCall)을 담는다.
        if (isset($value['errorHandling']) && is_array($value['errorHandling'])) {
            $this->validateObject($value['errorHandling'], 'errorHandling', $fail);
        }
    }

    /**
     * components 배열을 재귀적으로 검증
     *
     * @param  array  $components  컴포넌트 정의 배열
     * @param  Closure  $fail  실패 콜백
     * @param  string  $basePath  오류 메시지에 실을 경로 접두 (modals/slots 경로 보존)
     * @return void 반환값 없음
     */
    private function validateComponents(array $components, Closure $fail, string $basePath = 'components'): void
    {
        foreach ($components as $index => $component) {
            if (! is_array($component)) {
                continue;
            }

            $path = "{$basePath}[$index]";

            // props 검사
            if (isset($component['props']) && is_array($component['props'])) {
                $this->validateObject($component['props'], "$path.props", $fail);
            }

            // actions 검사
            if (isset($component['actions']) && is_array($component['actions'])) {
                $this->validateActions($component['actions'], $path, $fail);
            }

            // lifecycle: 마운트/언마운트 시 자동 실행되는 액션 — init_actions 와 같은 성격이다.
            if (isset($component['lifecycle']) && is_array($component['lifecycle'])) {
                $this->validateObject($component['lifecycle'], "$path.lifecycle", $fail);
            }

            // onComponentEvent: 컴포넌트 이벤트로 발화되는 액션 배열.
            if (isset($component['onComponentEvent']) && is_array($component['onComponentEvent'])) {
                $this->validateObject($component['onComponentEvent'], "$path.onComponentEvent", $fail);
            }

            // slots: 슬롯 이름별 컴포넌트 배열 — 슬롯 안의 컴포넌트도 같은 sink 다.
            if (isset($component['slots']) && is_array($component['slots'])) {
                foreach ($component['slots'] as $slotName => $slotComponents) {
                    if (is_array($slotComponents)) {
                        $this->validateComponents($slotComponents, $fail, "$path.slots.$slotName");
                    }
                }
            }

            // component_layout: 컴포넌트가 품는 하위 레이아웃 정의.
            if (isset($component['component_layout']) && is_array($component['component_layout'])) {
                $this->validateObject($component['component_layout'], "$path.component_layout", $fail);
            }

            // responsive: breakpoint 별 props/children 오버라이드 — 그 안의 값도 같은 sink 다.
            if (isset($component['responsive']) && is_array($component['responsive'])) {
                $this->validateObject($component['responsive'], "$path.responsive", $fail);
            }

            // children 재귀 검사
            if (isset($component['children']) && is_array($component['children'])) {
                $this->validateComponents($component['children'], $fail, "$path.children");
            }
        }
    }

    /**
     * actions 배열 검증
     *
     * @param  array  $actions  액션 정의 배열
     * @param  string  $componentPath  컴포넌트 경로 (오류 메시지용)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    private function validateActions(array $actions, string $componentPath, Closure $fail): void
    {
        foreach ($actions as $actionIndex => $action) {
            if (! is_array($action)) {
                continue;
            }

            $this->validateObject($action, "{$componentPath}.actions[$actionIndex]", $fail);
        }
    }

    /**
     * 객체(배열)의 모든 값을 재귀적으로 검사
     *
     * http(s) 웹 주소는 **자리**로만 허용한다 — 컴포넌트 prop 객체(`….props`)의 `placeholder`·`href` 글자 값과, 액션(`handler`
     * 보유 객체)의 `params.body` 아래. 키 이름만으로 켜면 액션 `params.href`·`href` 객체 아래 자원 주소까지 열려, 그 키를 읽는
     * 핸들러가 생기는 순간 저장 검사가 소리 없이 뚫린다.
     *
     * @param  array  $object  검사 대상 객체(배열)
     * @param  string  $path  오류 메시지용 경로
     * @param  Closure  $fail  검증 실패 콜백
     * @param  bool  $allowWebUrl  이 객체 아래 값이 http(s) 웹 주소를 허용하는 자리인지 (액션 요청 본문 아래)
     * @param  bool  $isActionParams  이 객체가 액션의 `params` 인지
     * @return void 반환값 없음
     */
    private function validateObject(array $object, string $path, Closure $fail, bool $allowWebUrl = false, bool $isActionParams = false): void
    {
        $isAction = isset($object['handler']) && is_string($object['handler']);
        $isPropObject = str_ends_with($path, '.props');

        foreach ($object as $key => $value) {
            $isActionBody = $isActionParams && $key === 'body';

            if (is_string($value)) {
                $allowString = $allowWebUrl
                    || $isActionBody
                    || ($isPropObject && in_array($key, self::WEB_URL_TEXT_KEYS, true));
                $this->checkForDangerousUrl($value, "$path.$key", $fail, $allowString);
            } elseif (is_array($value)) {
                $this->validateObject($value, "$path.$key", $fail, $allowWebUrl || $isActionBody, $isAction && $key === 'params');
            }
        }
    }

    /**
     * 레이아웃 트리 전체를 재귀 순회하며 실행 스킴 URL 과 액션 대상 외부 URL 을 검사합니다.
     *
     * @param  array<mixed>  $node  현재 노드
     * @param  string  $path  오류 메시지용 경로
     * @param  Closure  $fail  검증 실패 콜백
     * @return void 반환값 없음
     */
    private function walkExecutableSchemes(array $node, string $path, Closure $fail): void
    {
        // 액션(handler 보유 객체)이면 이동·요청 대상을 sink 위치와 같은 강도로 검사한다
        if (isset($node['handler']) && is_string($node['handler'])) {
            foreach (self::ACTION_TARGET_KEYS as $targetKey) {
                $target = data_get($node, $targetKey);
                if (is_string($target)) {
                    $this->checkForDangerousUrl($target, "$path.$targetKey", $fail);
                }
            }
        }

        foreach ($node as $key => $item) {
            $itemPath = "$path.$key";

            if (is_array($item)) {
                $this->walkExecutableSchemes($item, $itemPath, $fail);

                continue;
            }

            if (! is_string($item)) {
                continue;
            }

            foreach ($this->urlCandidates($item) as $candidate) {
                $scheme = $this->matchScheme($candidate, self::EXECUTABLE_SCHEMES);
                if ($scheme !== null) {
                    $this->failWithScheme($scheme, $candidate, $itemPath, $fail);

                    break;
                }
            }
        }
    }

    /**
     * 문자열에서 URL 로 해석될 수 있는 후보를 뽑습니다.
     *
     * 값 자체와, `{{ ... }}` 표현식 안의 문자열 리터럴을 돌려준다. 표현식이 `{{` 로 시작하면
     * 접두 검사가 통째로 빗나가므로(`{{ cond ? 'javascript:…' : '' }}`) 리터럴을 따로 본다.
     *
     * @param  string  $value  원본 문자열
     * @return array<int, string> 검사 후보 목록
     */
    private function urlCandidates(string $value): array
    {
        $candidates = [$value];

        if (! str_contains($value, '{{')) {
            return $candidates;
        }

        if (preg_match_all('/\{\{(.*?)\}\}/s', $value, $expressions) > 0) {
            foreach ($expressions[1] as $expression) {
                if (preg_match_all('/([\'"`])((?:\\\\.|(?!\1).)*)\1/s', $expression, $literals) > 0) {
                    foreach ($literals[2] as $literal) {
                        $candidates[] = stripcslashes($literal);
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * 브라우저 URL 파서와 같은 정규화 후 스킴 접두를 대조합니다.
     *
     * 브라우저는 스킴 해석 전에 선행 C0 제어문자·공백을 버리고 ASCII tab·개행을 제거하며
     * 대소문자를 가리지 않는다 — `java\tscript:` 도 실행된다. trim 만으로는 이 변형을 놓친다.
     *
     * @param  string  $value  검사 대상
     * @param  array<int, string>  $schemes  소문자 스킴 접두 목록
     * @return string|null 일치한 스킴 (없으면 null)
     */
    private function matchScheme(string $value, array $schemes): ?string
    {
        $normalized = strtolower((string) preg_replace(['/^[\x00-\x20]+/', '/[\t\n\r]/'], '', $value));

        foreach ($schemes as $scheme) {
            if (str_starts_with($normalized, $scheme)) {
                return $scheme;
            }
        }

        return null;
    }

    /**
     * 같은 (경로, 값) 실패를 한 번만 보고하는 실패 콜백을 만듭니다.
     *
     * @param  Closure  $fail  원본 실패 콜백
     * @return Closure 중복 제거 실패 콜백
     */
    private function dedupingFail(Closure $fail): Closure
    {
        return function (string $message) use ($fail) {
            if (isset($this->reported[$message])) {
                return;
            }

            $this->reported[$message] = true;
            $fail($message);
        };
    }

    /**
     * 문자열에서 위험한 URL 패턴 검사
     *
     * @param  string  $value  검사 대상 문자열 (`{{ }}` 안 문자열 리터럴도 함께 본다)
     * @param  string  $path  오류 메시지용 경로
     * @param  Closure  $fail  검증 실패 콜백
     * @param  bool  $allowWebUrl  http(s) 웹 주소를 허용하는 자리인지 (안내 문구·링크·요청 본문)
     * @return void 반환값 없음
     */
    private function checkForDangerousUrl(string $value, string $path, Closure $fail, bool $allowWebUrl = false): void
    {
        foreach ($this->urlCandidates($value) as $candidate) {
            if ($this->checkSingleUrl($candidate, $path, $fail, $allowWebUrl)) {
                return;
            }
        }
    }

    /**
     * 단일 URL 후보를 검사합니다.
     *
     * @param  string  $value  검사 대상 URL
     * @param  string  $path  오류 메시지용 경로
     * @param  Closure  $fail  검증 실패 콜백
     * @param  bool  $allowWebUrl  http(s) 웹 주소를 허용하는 자리인지 (안내 문구·링크·요청 본문)
     * @return bool 실패를 보고했으면 true
     */
    private function checkSingleUrl(string $value, string $path, Closure $fail, bool $allowWebUrl = false): bool
    {
        // 사이트 자기 host·선언된 공개 자산 디스크 host 의 http(s) 절대 URL 은 외부가 아니다.
        // 위험 스킴·protocol-relative 는 이 판정의 대상이 아니므로 아래 검사가 그대로 적용된다.
        if (SiteAssetHosts::isSiteAssetUrl($value, $this->siteAssetHosts())) {
            return false;
        }

        // 안내 문구·링크·요청 본문 자리의 http(s) 웹 주소는 허용한다 — 같은 정규화로 스킴을 판정하므로
        // 실행 스킴·프로토콜 상대 주소는 여기서 빠지지 않고 아래 검사를 그대로 받는다.
        if ($allowWebUrl && $this->matchScheme($value, self::WEB_SCHEMES) !== null) {
            return false;
        }

        // 스킴 접두는 브라우저와 같은 정규화 후 대조한다 (`java\tscript:` · 선행 제어문자)
        $scheme = $this->matchScheme($value, self::DANGEROUS_SCHEMES);
        if ($scheme !== null) {
            $this->failWithScheme($scheme, $value, $path, $fail);

            return true;
        }

        $lowerValue = strtolower(trim($value));

        // 추가 패턴 검사: //로 시작 (프로토콜 상대 URL)
        //
        // 브라우저 URL 파서는 파싱 전에 ASCII tab·개행을 제거하고 백슬래시를 슬래시와
        // 동등하게 처리하므로, `/\/evil.com` · `/{tab}/evil.com` 도 실제로는 외부
        // origin 이 된다. 접두 검사 전에 동일하게 정규화한다(SafeLayoutExpressions 와 동형).
        if (str_starts_with(SafeLayoutExpressions::normalizeForOriginCheck($lowerValue), '//')) {
            $message = __('validation.external_url.detected_in_props', ['url' => $value]);
            $this->offending[$message] ??= $value;
            $fail($message);

            return true;
        }

        return false;
    }

    /**
     * 사이트 자산 host 목록을 반환합니다 (검증 1회당 1회 해석 후 캐시).
     *
     * @return array<int, string> 소문자 host 목록
     */
    private function siteAssetHosts(): array
    {
        return $this->siteAssetHosts ??= SiteAssetHosts::hosts();
    }

    /**
     * 스킴별 에러 메시지 출력
     *
     * @param  string  $scheme  일치한 스킴 접두
     * @param  string  $url  원본 값 — 메시지에는 싣지 않고 소스 위치 단서로 기록한다
     * @param  string  $path  오류 경로 (같은 이유로 현재 미사용)
     * @param  Closure  $fail  검증 실패 콜백
     * @return void 반환값 없음
     */
    private function failWithScheme(string $scheme, string $url, string $path, Closure $fail): void
    {
        $scheme = rtrim($scheme, ':/');

        $messageKey = match ($scheme) {
            'http' => 'validation.external_url.http_not_allowed',
            'https' => 'validation.external_url.https_not_allowed',
            'data' => 'validation.external_url.data_uri_not_allowed',
            'javascript' => 'validation.external_url.javascript_uri_not_allowed',
            default => 'validation.external_url.dangerous_scheme_detected',
        };

        $message = $messageKey === 'validation.external_url.dangerous_scheme_detected'
            ? __($messageKey, ['scheme' => $scheme])
            : __($messageKey);
        $this->offending[$message] ??= $url;
        $fail($message);
    }
}
