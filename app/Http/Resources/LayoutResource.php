<?php

// audit:allow api-doc-coverage reason: 자식의 중복 private formatFileSize() 를 제거해 부모 BaseApiResource 의 공용 포맷터를 상속하게 한 내부 수정(S3-D11 — 가시성 축소로 인한 FatalError 해소). 응답 키(size_formatted)·상태코드·payload 형태 모두 불변 — `api:docgen --scope=core --check` drift 없음 확인.

namespace App\Http\Resources;

use App\Models\TemplateLayout;
use App\Services\LayoutMarkupService;
use App\Support\LayoutDescription;
use Illuminate\Http\Request;

class LayoutResource extends BaseApiResource
{
    /**
     * 레이아웃 이름 → 라우트 path 매핑 (코드 편집기 URL 동기화용).
     *
     * 컨트롤러가 템플릿 routes.json 으로부터 빌드해 컬렉션 전체에 주입한다.
     * 라우트가 없는 레이아웃(base/partial 등)은 매핑에 없어 null 로 직렬화된다.
     *
     * @var array<string, string>
     */
    protected array $routePathMap = [];

    /** @var array<string, mixed> 소유 템플릿의 프론트엔드 다국어 사전 */
    protected array $translations = [];

    /**
     * 설명 해석에 쓸 소유 템플릿 사전을 주입합니다.
     *
     * @param  array<string, mixed>  $translations  템플릿 프론트엔드 다국어 데이터
     * @return $this
     */
    public function withTranslations(array $translations): static
    {
        $this->translations = $translations;

        return $this;
    }

    /**
     * 레이아웃 이름 → 라우트 path 매핑을 주입합니다.
     *
     * @param  array<string, string>  $map  레이아웃 이름 → 라우트 path
     * @return $this
     */
    public function withRoutePathMap(array $map): static
    {
        $this->routePathMap = $map;

        return $this;
    }

    /**
     * `?with_markup=1` 응답에 싣는 마크업 페이로드 — 컨트롤러가 변환 서비스로 만들어 주입한다(없으면 미부착).
     *
     * @var array{content_markup: string, markup_escapes: array<int, string>, markup_warnings: array<int, array<string, mixed>>, markup_compile_error: array<string, mixed>|null}|null
     */
    protected ?array $markup = null;

    /**
     * 마크업 페이로드를 주입합니다 (`?with_markup=1`).
     *
     * @param  array<string, mixed>  $markup  변환 결과
     * @return $this
     */
    public function withMarkup(array $markup): static
    {
        $this->markup = $markup;

        return $this;
    }

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  현재 요청 객체
     * @return array<string, mixed> 직렬화 결과
     */
    public function toArray(Request $request): array
    {
        $content = $this->getValue('content', []);
        $contentJson = is_array($content) ? json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';

        // 편집기 JSON 탭이 처음 여는 본문 — 배열 캐스트를 거친 `$content` 는 `{}` 와 `[]` 를 같게 만들어
        // 저장된 `{}` 가 `[]` 로 보인다(한 글자 치면 컴파일 결과로 `{}` 로 바뀌어 탭이 스스로 어긋난다).
        // 행 원본 문자열을 빈 map 을 보존해 다시 인코딩한다 — 컴파일 응답과 같은 인코더라 표기도 같다.
        if ($this->resource instanceof TemplateLayout) {
            $raw = $this->resource->getRawOriginal('content');

            if (is_string($raw) && $raw !== '') {
                $service = app(LayoutMarkupService::class);
                $contentJson = $service->encodeJson($service->decodeStoredContent($raw));
            }
        }
        $name = $this->getValue('name');

        return [
            'id' => $this->getValue('id'),
            'template_id' => $this->getValue('template_id'),
            'name' => $name,
            // 설명은 소유 템플릿 사전 키를 쓰므로 서버가 해석한다 — 목록(LayoutListResource)과
            // 같은 규칙이어야 파일 목록과 선택 파일 헤더의 표기가 어긋나지 않는다.
            'description' => LayoutDescription::resolve($content['meta']['description'] ?? null, $name, $this->translations),
            'endpoint' => $content['endpoint'] ?? null,
            // 이 레이아웃을 사용하는 라우트의 path (routes.json 기준). 코드 편집기가
            // 파일 선택 시 ?route= 동기화 / 위지윅에서 넘어온 ?route= 복원에 사용.
            'route_path' => $this->routePathMap[$name] ?? null,
            'components' => $content['components'] ?? [],
            'data_sources' => $content['data_sources'] ?? [],
            'metadata' => $content['metadata'] ?? [],

            // 레이아웃 편집 페이지용 필드
            'content' => $contentJson,
            'size' => strlen($contentJson),
            'size_formatted' => $this->formatFileSize(strlen($contentJson)),
            'has_update' => false, // TODO: 템플릿 파일과 DB 버전 비교 로직 추가

            // 낙관적 잠금 — 다음 저장 요청에 expected_lock_version 으로 전달
            'lock_version' => (int) $this->getValue('lock_version', 0),

            // 현재(최신) 저장 버전 번호 — updateLayout 저장 경로에서만
            // transient 로 부착됨(라우트 트리 버전 배지 동기화). 그 외 응답은 null.
            'current_version' => $this->getValue('current_version') !== null
                ? (int) $this->getValue('current_version')
                : null,

            // `?with_markup=1` — 코드 편집기 HTML 탭용 역변환 결과 (기본 응답은 무변경)
            ...($this->markup ?? []),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
            // created_by, updated_by는 보안상 제외
        ];
    }

    /**
     * 리소스별 권한 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'core.templates.layouts.edit',
        ];
    }
}
