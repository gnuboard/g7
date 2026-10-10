<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Rules\ValidLayoutExtensionStructure;
use App\Rules\ValidLayoutStructure;
use App\Rules\WhitelistedEndpoint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * 컴파일 산출 JSON 에 대한 강검증 (S6).
 *
 * "검증·서빙 파이프라인 사실"(계획서)이 확인한 것: **파일 설치 경로에는 구조 검증이 없다**
 * (json_decode + layout_name + partial 병합 + data_sources 중복만). 그래서 컴파일 타임 검증을
 * 현행 어느 경로보다 강하게 잡을 수 있고 잡아야 한다. 그 "동등 강도" 의 기준선은 편집기 저장
 * 경로(`UpdateLayout*Request`)가 이미 쓰는 `app/Rules/*` 다.
 *
 * 그래서 이 층은 **그 규칙 클래스를 그대로 재사용한다** — 재구현하면 규칙이 강화될 때 컴파일러가
 * 뒤처져 표류한다(계획서 리스크 "컴파일러-엔진 스키마 표류"). 규칙은 `$fail` 콜백으로 실패를
 * 알리므로 Laravel Validator 로 돌려(콜백 계약을 정확히 지킴) 실패를 CompileError 로 승격한다.
 *
 * 규칙이 못 보는 세 가지는 여기서 직접 검사한다:
 *  - **wait_for cross-field** — transition_overlay.wait_for 가 완료 신호가 안 오는 데이터소스
 *    (websocket·background)를 가리키면 오버레이가 영영 안 걷힌다(UpdateLayoutContentRequest:451 상당).
 *  - **`<script src>` 화이트리스트** — NoExternalUrls 는 최상위 scripts 키를 안 본다(사각지대).
 *    외부 JS 로더의 호스트·스킴을 config 허용 목록으로 잡는다(Q4).
 *  - **컴포넌트 존재 검사** — 검사용 레지스트리가 주어졌을 때만(편집기·템플릿 자신의 레이아웃) 산출 name 이
 *    그 템플릿에 있는지 본다. 설치 경로에는 레지스트리가 없다 — JSON 과 같이 컴포넌트 존재는 런타임이 판정한다.
 *    편집기는 **저장본에 이미 있던** 미등록 템플릿 컴포넌트를 그 개수까지 허용한다(`CompileContext::$toleratedComponents`
 *    — 7.0 이 무차별 등록한 조각 행을 저장할 수 있게, #712). 그보다 늘어난 것은 사용자가 새로 쓴 것이라 오류다.
 *    확장 컴포넌트(`식별자.이름`)는 여기서 세우지 않는다 — 설치된 확장 목록에 없으면 lint 경고
 *    (`extension_component_not_installed`)로 알리고 저장은 허용한다(2026-09-28 결정).
 *
 * 규칙은 모두 첫 실패에서 조기 종료하므로, 이 층도 정해진 순서(구조→엔드포인트→외부URL→컴포넌트
 * →wait_for→script)로 **첫 위반에서 CompileError 를 던진다** — 작성자는 하나씩 고친다.
 */
final class LayoutValidator
{
    /** 컴포넌트 존재 검사 대상 노드 타입 (slot·extension_point·partial 참조는 레지스트리 밖) */
    private const REGISTRY_BACKED_TYPES = ['basic', 'composite', 'layout'];

    /**
     * 산출 JSON 을 검증합니다. 첫 위반에서 CompileError 를 던집니다.
     *
     * @param  array<string, mixed>  $json  컴파일 산출 레이아웃 JSON
     * @param  string  $rootKind  문서 봉투 (`layout`·`partial`·`extension`·`override`)
     * @param  ComponentRegistry|null  $registry  검사용 템플릿 컴포넌트 레지스트리 (없으면 컴포넌트 존재 검사 생략 — JSON 설치와 동일)
     * @param  CompileContext  $context  검증 문맥
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     * @return void
     *
     * @throws CompileError 검증 위반 시
     */
    public function validate(array $json, string $rootKind, ?ComponentRegistry $registry, CompileContext $context, ?string $path = null): void
    {
        // ① 구조 — 완성 레이아웃은 ValidLayoutStructure, fragment 는 확장 스키마.
        if ($rootKind === 'layout') {
            $this->runRule($json, new ValidLayoutStructure, LayoutMarkupErrorCode::LayoutStructureInvalid, $path);
        } elseif ($rootKind === 'extension') {
            $this->runRule($json, new ValidLayoutExtensionStructure, LayoutMarkupErrorCode::FragmentStructureInvalid, $path);
        }

        // 엔드포인트·외부 URL 은 어느 봉투든 노드를 갖는다 — 모든 노드를 한 시야로 모아 검사.
        $view = $this->buildTraversalView($json, $rootKind);

        // ② 엔드포인트 화이트리스트
        $this->runRule($view, new WhitelistedEndpoint, LayoutMarkupErrorCode::EndpointNotWhitelisted, $path);

        // ③ 외부 URL·위험 스킴 — **이관 검사에서는 돌리지 않는다 (설계 결정 2026-07-20)**.
        //
        // 번들 결제 플러그인이 PG사 공식 주소로 나가는 링크를 정상 기능으로 들고 있다
        // (이니시스·KCP·나이스페이 영수증/파트너 사이트 4건). 설치 경로는 이 규칙을 돌리지
        // 않으므로 그 레이아웃들은 오늘도 정상 설치·동작하는데, 파사드만 강검증을 걸어
        // **정상 출하물의 이관을 막고 있었다**.
        //
        // 규칙은 **작성 API 경로에 살아 있다** — 편집기로 사용자가 제출하는 레이아웃 본문은
        // `StoreLayoutRequest`·`UpdateLayoutRequest` 가 레이아웃 배열에 `NoExternalUrls` 를 걸어
        // 컴포넌트 트리를 훑는다. 여기서 빠지는 것은 "이미 저장소에 있는 1급 출하물을 형식만
        // 옮기는" 경로뿐이다.
        //
        // 정확히 하자면 `NoExternalUrls` 부착 4곳 중 **실제로 도는 것은 배열 필드 2곳뿐**이다.
        // `UpdateLayoutContentRequest`(`content.endpoint`)·`UpdateLayoutExtensionContentRequest`
        // (`data_sources.*.endpoint`)는 **문자열** 필드에 걸려 있고 이 규칙은 `is_array` 가 아니면
        // 즉시 반환하므로 no-op 이다. 다만 **보안 공백은 아니다** — 그 두 필드에는 같은 자리에
        // `WhitelistedEndpoint` 가 함께 붙어 있고, 그쪽 `validateEndpointString` 이 `^https?://` 를
        // 명시 차단한 뒤 `/api/(admin|auth|public)/` 화이트리스트와 path traversal 까지 건다.
        // 즉 두 문자열 경로의 `NoExternalUrls` 는 더 강한 규칙에 흡수된 중복 선언이다(S11 이전부터).

        // ④ 컴포넌트 존재 그물 — 저장본에 이미 있던 미등록 컴포넌트는 그 개수까지 허용 (편집기 전용, #712)
        if ($registry !== null) {
            $this->assertComponentsExist($view['components'], $registry, $path, $context->toleratedComponents);
        }

        // ⑤ wait_for cross-field
        $this->assertWaitForResolves($json, $path);

        // ⑥ 외부 JS 로더 화이트리스트 — 설치 경로는 JSON 설치와 같이 검사하지 않는다. 확장이 싣는
        // 레이아웃의 호스트는 그 확장이 스스로 선언하므로 설치 시점 대조는 순환이고, 설치 순서(오버라이드
        // 대상 확장이 아직 없음)에 따라 조각이 조용히 빠지는 부작용만 낳는다. 판정은 런타임 게이트가 한다.
        if ($context->checksScriptSrc) {
            $this->assertScriptSrcAllowed($json, $context, $path);
        }
    }

    /**
     * Laravel 규칙 하나를 돌려, 실패하면 지정 코드의 CompileError 로 승격합니다.
     *
     * @param  array<string, mixed>  $value  규칙에 넘길 값 (레이아웃 JSON 또는 순회 시야)
     * @param  ValidationRule  $rule  검증 규칙
     * @param  LayoutMarkupErrorCode  $code  실패 시 승격할 코드
     * @param  string|null  $path  소스 파일 경로
     * @return void
     *
     * @throws CompileError 규칙 실패 시
     */
    private function runRule(array $value, object $rule, LayoutMarkupErrorCode $code, ?string $path): void
    {
        $validator = Validator::make(['content' => $value], ['content' => [$rule]]);

        if ($validator->fails()) {
            throw CompileError::inFile($code, $path, [
                'reason' => (string) $validator->errors()->first('content'),
            ]);
        }
    }

    /**
     * 봉투와 무관하게 모든 노드·data_sources 를 `{data_sources, components}` 시야로 모읍니다.
     *
     * WhitelistedEndpoint·NoExternalUrls 는 레이아웃 형태(`components`/`data_sources` 보유)를
     * 기대한다. partial(루트가 노드)·fragment(injections 안에 노드) 처럼 형태가 다른 봉투도
     * 이 시야로 정규화하면 두 규칙이 빠짐없이 순회한다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string  $rootKind  문서 봉투
     * @return array{data_sources: array<int, mixed>, components: array<int, mixed>} 순회 시야
     */
    private function buildTraversalView(array $json, string $rootKind): array
    {
        $dataSources = $json['data_sources'] ?? [];
        $components = [];

        // 완성/오버라이드 레이아웃: components + slots.* + modals.
        foreach ($json['components'] ?? [] as $node) {
            $components[] = $node;
        }

        foreach ($json['slots'] ?? [] as $slotNodes) {
            foreach ((array) $slotNodes as $node) {
                $components[] = $node;
            }
        }

        foreach ($json['modals'] ?? [] as $node) {
            $components[] = $node;
        }

        // fragment: injections[*].components.
        foreach ($json['injections'] ?? [] as $injection) {
            foreach ($injection['components'] ?? [] as $node) {
                $components[] = $node;
            }
        }

        // partial: 루트 자체가 노드다 — 노드 필드가 최상위에 있으면 하나의 컴포넌트로 싣는다.
        if ($rootKind === 'partial' && isset($json['name'])) {
            $node = $json;
            unset($node['meta'], $node['data_sources']);
            $components[] = $node;
        }

        return ['data_sources' => is_array($dataSources) ? $dataSources : [], 'components' => $components];
    }

    /**
     * 산출 노드의 컴포넌트 name 이 레지스트리에 있는지 재귀로 확인합니다 (ComponentExists 상당).
     *
     * 템플릿 컴포넌트(점 없는 이름)만 세운다. 확장 컴포넌트(`식별자.이름`)는 확장이 지워졌거나 편집 도중
     * 꺼졌을 수 있어 막지 않고, `LayoutLinter` ⑩ 이 경고한다.
     *
     * 미등록 이름을 문서 순서로 모은 뒤, 허용 개수(`$tolerated` — 편집기가 넘기는 저장본 기준)를 넘는 첫 이름에서
     * 실패한다. 허용 목록이 비면 종전과 같이 문서 순서로 처음 만난 미등록 이름에서 실패한다.
     *
     * @param  array<int, mixed>  $components  노드 목록
     * @param  ComponentRegistry  $registry  레지스트리
     * @param  string|null  $path  소스 파일 경로
     * @param  array<string, int>  $tolerated  허용 개수 (이름 → 개수)
     * @return void
     *
     * @throws CompileError 허용을 넘는 미등록 컴포넌트 name 발견 시
     */
    private function assertComponentsExist(array $components, ComponentRegistry $registry, ?string $path, array $tolerated = []): void
    {
        $found = [];
        $this->collectUnregisteredComponents($components, $registry, $found);

        if ($found === []) {
            return;
        }

        $counts = array_count_values($found);

        foreach (array_unique($found) as $name) {
            if ($counts[$name] > ($tolerated[$name] ?? 0)) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ComponentNotInRegistry, $path, ['name' => $name]);
            }
        }
    }

    /**
     * 문서(저장본)에 들어 있는 미등록 템플릿 컴포넌트를 이름별로 셉니다 (#712).
     *
     * 편집기가 저장본을 기준선으로 삼을 때 쓴다 — 저장·변환·열기 재컴파일은 이 개수까지 같은 이름을 허용한다.
     * 순회 규칙은 {@see assertComponentsExist()} 와 같다(같은 함수를 쓴다).
     *
     * @param  array<string, mixed>  $json  저장본 JSON (레이아웃 또는 조각)
     * @param  ComponentRegistry  $registry  편집 대상 템플릿의 검사용 레지스트리
     * @return array<string, int> 이름 → 개수
     */
    public function unregisteredComponentCounts(array $json, ComponentRegistry $registry): array
    {
        $found = [];
        $this->collectUnregisteredComponents($this->buildTraversalView($json, 'layout')['components'], $registry, $found);

        return array_count_values($found);
    }

    /**
     * 노드 트리를 문서 순서로 돌며 레지스트리에 없는 템플릿 컴포넌트 이름을 모읍니다.
     *
     * @param  array<int, mixed>  $components  노드 목록
     * @param  ComponentRegistry  $registry  레지스트리
     * @param  array<int, string>  $found  모은 이름 (참조, 문서 순서)
     * @return void 반환값 없음
     */
    private function collectUnregisteredComponents(array $components, ComponentRegistry $registry, array &$found): void
    {
        foreach ($components as $node) {
            if (! is_array($node)) {
                continue;
            }

            // partial 참조는 컴포넌트가 아니다. `slot` 은 슬롯 래퍼 컴포넌트(extends 의 슬롯 자리·data-slot 등록 노드)에
            // 붙고 이름·props 를 그대로 가지므로 그 노드와 하위 트리를 검사한다(LayoutService::replaceSlots 참고).
            if (isset($node['partial'])) {
                continue;
            }

            $type = $node['type'] ?? null;
            $name = $node['name'] ?? null;

            if (is_string($type) && in_array($type, self::REGISTRY_BACKED_TYPES, true) && is_string($name)
                && ! str_contains($name, '.') && $registry->findByName($name) === null) {
                $found[] = $name;
            }

            foreach (['children', 'cellChildren', 'expandChildren', 'subRowChildren'] as $childKey) {
                if (isset($node[$childKey]) && is_array($node[$childKey])) {
                    $this->collectUnregisteredComponents($node[$childKey], $registry, $found);
                }
            }
        }
    }

    /**
     * transition_overlay.wait_for 가 완료 신호가 오지 않는 데이터소스를 가리키는지 검사합니다.
     *
     * wait_for 는 오버레이가 걷힐 때까지 기다릴 데이터소스 ID 목록인데, websocket(끝이 없음)이나
     * loading_strategy=background(사용자 차단 불가)를 가리키면 오버레이가 영영 안 걷힌다.
     * 미존재 ID 는 엔진이 무시하므로 여기서도 넘긴다(UpdateLayoutContentRequest:471 상당).
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  string|null  $path  소스 파일 경로
     * @return void
     *
     * @throws CompileError 완료 불가 데이터소스를 가리키는 경우
     */
    private function assertWaitForResolves(array $json, ?string $path): void
    {
        $overlay = $json['transition_overlay'] ?? null;
        $waitFor = is_array($overlay) ? ($overlay['wait_for'] ?? null) : null;

        if (! is_array($waitFor) || $waitFor === []) {
            return;
        }

        $byId = [];
        foreach ($json['data_sources'] ?? [] as $source) {
            if (is_array($source) && isset($source['id'])) {
                $byId[$source['id']] = $source;
            }
        }

        foreach ($waitFor as $id) {
            if (! is_string($id) || ! isset($byId[$id])) {
                continue;
            }

            $source = $byId[$id];
            $type = $source['type'] ?? 'api';
            $strategy = $source['loading_strategy'] ?? 'progressive';

            if ($type === 'websocket' || $strategy === 'background') {
                throw CompileError::inFile(LayoutMarkupErrorCode::WaitForSourceNeverResolves, $path, [
                    'id' => $id,
                    'reason' => $type === 'websocket' ? 'websocket' : 'background',
                ]);
            }
        }
    }

    /**
     * `<script src>` 의 호스트·스킴이 허용 목록 안인지 검사합니다 (외부 JS 로더 화이트리스트).
     *
     * 같은 origin 상대경로(`/`·`/api/…`)는 외부가 아니므로 통과, protocol-relative(`//host`)와
     * `https://host` 는 host 가 허용 목록에 있어야 하며, `http://`(비암호)는 거부한다.
     *
     * @param  array<string, mixed>  $json  산출 JSON
     * @param  CompileContext  $context  검증 문맥 (허용 호스트)
     * @param  string|null  $path  소스 파일 경로
     * @return void
     *
     * @throws CompileError 허용 목록 밖 호스트·스킴인 경우
     */
    private function assertScriptSrcAllowed(array $json, CompileContext $context, ?string $path): void
    {
        $scripts = $json['scripts'] ?? null;

        if (! is_array($scripts)) {
            return;
        }

        $allowlist = $context->scriptSrcAllowlist();

        foreach ($scripts as $script) {
            $src = is_array($script) ? ($script['src'] ?? null) : null;

            if (! is_string($src) || $src === '') {
                continue;
            }

            $host = $this->externalHost($src);

            // 같은 origin 상대경로 — 외부가 아니라 검사 대상 아님.
            if ($host === null) {
                continue;
            }

            if ($host === false || ! in_array($host, $allowlist, true)) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ScriptSrcNotAllowed, $path, [
                    'src' => $src,
                ]);
            }
        }
    }

    /**
     * 스크립트 src 에서 외부 호스트를 뽑습니다.
     *
     * @param  string  $src  script src 원문
     * @return string|false|null 호스트명(허용 대상), null(같은 origin 상대경로), false(거부 스킴)
     */
    private function externalHost(string $src): string|false|null
    {
        // protocol-relative: //host/path
        if (str_starts_with($src, '//')) {
            return $this->hostOf(substr($src, 2));
        }

        // https 만 외부 로더로 허용, http 는 비암호라 거부.
        if (preg_match('#^https://#i', $src) === 1) {
            return $this->hostOf(substr($src, 8));
        }

        if (preg_match('#^https?://#i', $src) === 1) {
            return false; // http:// — 거부
        }

        // 스킴이 있으나 http(s) 가 아님 (data:·javascript: 등) → 거부.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $src) === 1) {
            return false;
        }

        // 상대경로·같은 origin — 외부 아님.
        return null;
    }

    /**
     * `host/path...` 문자열에서 host 부분만 뽑습니다.
     *
     * @param  string  $rest  스킴을 제거한 나머지
     * @return string 호스트명
     */
    private function hostOf(string $rest): string
    {
        $slash = strpos($rest, '/');

        return $slash === false ? $rest : substr($rest, 0, $slash);
    }
}
