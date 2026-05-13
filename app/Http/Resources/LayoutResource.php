<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class LayoutResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     */
    public function toArray(Request $request): array
    {
        $content = $this->getValue('content', []);
        $contentJson = is_array($content) ? json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';

        return [
            'id' => $this->getValue('id'),
            'template_id' => $this->getValue('template_id'),
            'name' => $this->getValue('name'),
            'description' => $content['meta']['description'] ?? $this->getValue('name'),
            'endpoint' => $content['endpoint'] ?? null,
            'components' => $content['components'] ?? [],
            'data_sources' => $content['data_sources'] ?? [],
            'metadata' => $content['metadata'] ?? [],

            // 레이아웃 편집 페이지용 필드
            'content' => $contentJson,
            'size' => strlen($contentJson),
            'size_formatted' => $this->formatFileSize(strlen($contentJson)),
            'has_update' => false, // TODO: 템플릿 파일과 DB 버전 비교 로직 추가

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
     * @param int $bytes 바이트 크기
     * @return string 포맷된 크기 (예: "12.5 KB")
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        } else {
            return round($bytes / 1048576, 1) . ' MB';
        }
    }
}
