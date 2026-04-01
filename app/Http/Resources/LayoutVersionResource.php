<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class LayoutVersionResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     */
    public function toArray(Request $request): array
    {
        $content = $this->getValue('content', []);

        return [
            'id' => $this->getValue('id'),
            'layout_id' => $this->getValue('layout_id'),
            'version' => $this->getValue('version'),
            'endpoint' => $content['endpoint'] ?? null,
            'components' => $content['components'] ?? [],
            'data_sources' => $content['data_sources'] ?? [],
            'metadata' => $content['metadata'] ?? [],
            'changes_summary' => $this->formatChangesSummary($this->getValue('changes_summary', [])),

            ...$this->formatTimestamps(),
            // created_by는 보안상 제외
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * changes_summary를 포맷팅하여 count 필드 추가
     *
     * @param array $changesSummary 원본 changes_summary
     * @return array 포맷팅된 changes_summary
     */
    private function formatChangesSummary(array $changesSummary): array
    {
        return [
            'added' => $changesSummary['added'] ?? [],
            'removed' => $changesSummary['removed'] ?? [],
            'modified' => $changesSummary['modified'] ?? [],
            'added_count' => count($changesSummary['added'] ?? []),
            'removed_count' => count($changesSummary['removed'] ?? []),
            'modified_count' => count($changesSummary['modified'] ?? []),
            'char_diff' => $changesSummary['char_diff'] ?? 0,
        ];
    }
}
