<?php

namespace App\Support\LayoutMarkup;

use App\Enums\LayoutMarkupErrorCode;
use App\Rules\NoExternalUrls;
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
 *  - **컴포넌트 존재 그물** — 노드 층이 태그 해석으로 이미 보장하지만(UnknownTag), 산출 name 이
 *    레지스트리에 있는지 한 번 더 훑어 방어한다(ComponentExists 상당).
 *
 * 규칙은 모두 첫 실패에서 조기 종료하므로, 이 층도 정해진 순서(구조→엔드포인트→외부URL→컴포넌트
 * →wait_for→script)로 **첫 위반에서 CompileError 를 던진다** — 저작자는 하나씩 고친다.
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
     * @param  ComponentRegistry  $registry  대상 템플릿 컴포넌트 레지스트리
     * @param  CompileContext  $context  검증 문맥
     * @param  string|null  $path  소스 파일 경로 (오류 메시지용)
     *
     * @throws CompileError 검증 위반 시
     */
    public function validate(array $json, string $rootKind, ComponentRegistry $registry, CompileContext $context, ?string $path = null): void
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

        // ③ 외부 URL·위험 스킴
        $this->runRule($view, new NoExternalUrls, LayoutMarkupErrorCode::ExternalUrlForbidden, $path);

        // ④ 컴포넌트 존재 그물
        $this->assertComponentsExist($view['components'], $registry, $path);

        // ⑤ wait_for cross-field
        $this->assertWaitForResolves($json, $path);

        // ⑥ 외부 JS 로더 화이트리스트
        $this->assertScriptSrcAllowed($json, $context, $path);
    }

    /**
     * Laravel 규칙 하나를 돌려, 실패하면 지정 코드의 CompileError 로 승격합니다.
     *
     * @param  array<string, mixed>  $value  규칙에 넘길 값 (레이아웃 JSON 또는 순회 시야)
     * @param  ValidationRule  $rule  검증 규칙
     * @param  LayoutMarkupErrorCode  $code  실패 시 승격할 코드
     * @param  string|null  $path  소스 파일 경로
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
     * @param  array<int, mixed>  $components  노드 목록
     * @param  ComponentRegistry  $registry  레지스트리
     * @param  string|null  $path  소스 파일 경로
     *
     * @throws CompileError 미등록 컴포넌트 name 발견 시
     */
    private function assertComponentsExist(array $components, ComponentRegistry $registry, ?string $path): void
    {
        foreach ($components as $node) {
            if (! is_array($node)) {
                continue;
            }

            // slot·partial 참조는 컴포넌트가 아니다.
            if (isset($node['slot']) || isset($node['partial'])) {
                continue;
            }

            $type = $node['type'] ?? null;
            $name = $node['name'] ?? null;

            if (is_string($type) && in_array($type, self::REGISTRY_BACKED_TYPES, true) && is_string($name)
                && $registry->findByName($name) === null) {
                throw CompileError::inFile(LayoutMarkupErrorCode::ComponentNotInRegistry, $path, ['name' => $name]);
            }

            foreach (['children', 'cellChildren', 'expandChildren', 'subRowChildren'] as $childKey) {
                if (isset($node[$childKey]) && is_array($node[$childKey])) {
                    $this->assertComponentsExist($node[$childKey], $registry, $path);
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
