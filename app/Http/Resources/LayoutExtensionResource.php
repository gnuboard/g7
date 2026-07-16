<?php

namespace App\Http\Resources;

use App\Extension\Traits\ComputesLayoutContentHash;
use Illuminate\Http\Request;

/**
 * 레이아웃 확장 리소스
 *
 * 관리자 레이아웃 편집 화면에서 사용하는 레이아웃 확장 직렬화 리소스입니다.
 */
class LayoutExtensionResource extends BaseApiResource
{
    use ComputesLayoutContentHash;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청 객체
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = $this->getValue('content', []);
        $contentJson = is_array($content) ? json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '{}';

        $extensionType = $this->getValue('extension_type');
        $sourceType = $this->getValue('source_type');

        return [
            'id' => $this->getValue('id'),
            'template_id' => $this->getValue('template_id'),
            'extension_type' => $extensionType?->value ?? $extensionType,
            'target_name' => $this->getValue('target_name'),
            'source_type' => $sourceType?->value ?? $sourceType,
            'source_identifier' => $this->getValue('source_identifier'),
            'source_label' => $this->getValue('source_label', $this->getValue('source_identifier')),
            'override_target' => $this->getValue('override_target'),
            // 템플릿 오버라이드 여부 — index 에서 부착되며, 미부착 시 source_type 으로 폴백
            'is_override' => (bool) $this->getValue(
                'is_override',
                ($sourceType?->value ?? $sourceType) === 'template'
            ),
            'priority' => $this->getValue('priority'),
            'is_active' => (bool) $this->getValue('is_active'),
            // 호스트 레이아웃 목록 — 이 확장이 주입되는 레이아웃명들.
            // overlay = [target_layout], extension_point = 그 확장점을 포함하는 레이아웃 전체.
            // index 에서 부착되며, 라우트 트리가 layoutName 매칭으로 화면별 연결 목록을 구성한다.
            'host_layouts' => $this->getValue('host_layouts', []),

            // 레이아웃 편집 페이지용 필드
            'content' => $contentJson,
            'size' => strlen($contentJson),
            'size_formatted' => $this->formatFileSize(strlen($contentJson)),
            'is_modified' => $this->resolveIsModified($content),

            // 낙관적 잠금 — 다음 저장 요청에 expected_lock_version 으로 전달
            'lock_version' => (int) $this->getValue('lock_version', 0),

            // 현재(최신) 저장 버전 번호 — index(목록 부착)/update(저장
            // 경로 transient)에서만 채워짐. 이력 없는 확장/그 외 응답은 null (배지 미표시).
            'current_version' => $this->getValue('current_version') !== null
                ? (int) $this->getValue('current_version')
                : null,

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
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
     * 관리자 수정 여부를 판단합니다.
     *
     * 현재 content 해시가 original_content_hash 와 다르면 수정된 것으로 간주합니다.
     *
     * @param  array  $content  현재 확장 content
     * @return bool 수정 여부
     */
    private function resolveIsModified(array $content): bool
    {
        $originalHash = $this->getValue('original_content_hash');

        if (! $originalHash || empty($content)) {
            return false;
        }

        return $this->computeContentHash($content) !== $originalHash;
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
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
