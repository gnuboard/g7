<?php

namespace App\Http\Resources;

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
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  현재 요청 객체
     * @return array<string, mixed> 직렬화 결과
     */
    public function toArray(Request $request): array
    {
        $content = $this->getValue('content', []);
        $contentJson = is_array($content) ? json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';
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

    /**
     * 파일 크기를 사람이 읽기 쉬운 형태로 변환
     *
     * @param  int  $bytes  바이트 크기
     * @return string 포맷된 크기 (예: "12.5 KB")
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        } else {
            return round($bytes / 1048576, 1).' MB';
        }
    }
}
