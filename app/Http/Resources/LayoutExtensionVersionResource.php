<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * 레이아웃 확장 버전 리소스
 *
 * 레이아웃 확장의 버전 이력을 직렬화합니다.
 */
class LayoutExtensionVersionResource extends BaseApiResource
{
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

        return [
            'id' => $this->getValue('id'),
            'extension_id' => $this->getValue('extension_id'),
            'version' => $this->getValue('version'),
            'content' => $contentJson,
            'changes_summary' => $this->formatChangesSummary($this->getValue('changes_summary', [])),
            // 저장자 이름만 노출 (버전 히스토리 표시용 — 레이아웃 본체와 패리티
            // 버전 기록). created_by ID 는 보안상 제외 유지. 관계 미로딩/탈퇴 사용자 시 null.
            'created_by_name' => $this->resolveCreatorName(),

            ...$this->formatTimestamps(),
            // created_by는 보안상 제외
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 저장자 이름을 해석합니다.
     *
     * creator 관계(eager load)에서 이름만 추출합니다. 관계 미로딩 또는 탈퇴 사용자
     * (created_by null)인 경우 null 을 반환합니다. created_by ID 자체는 노출하지 않습니다.
     *
     * @return string|null 저장자 이름 또는 null
     */
    private function resolveCreatorName(): ?string
    {
        $creator = $this->whenLoaded('creator');

        // whenLoaded 미로딩 시 MissingValue 반환 → instanceof 검사로 null 처리 (누출 방지).
        return $creator instanceof User ? $creator->name : null;
    }

    /**
     * changes_summary를 버전 목록 표시용 카운트로 노출합니다.
     *
     * changes_summary 는 추가/삭제 라인 수(added/removed 정수)와 문자 수 변화만 저장한다
     * (라인 원문 미저장 — 적재 비대화 제거). 프론트는 added_count/removed_count
     * 로 받으므로 키를 매핑한다. 구버전(라인 원문 배열을 담던 시절) 레코드는 정수가 아니라
     * 배열일 수 있어 is_array 시 count() 로 보정한다. modified 는 라인 diff 에 대응 개념이
     * 없어 노출하지 않는다.
     *
     * @param  array  $changesSummary  원본 changes_summary
     * @return array{added_count: int, removed_count: int, char_diff: int}
     */
    private function formatChangesSummary(array $changesSummary): array
    {
        return [
            'added_count' => $this->countValue($changesSummary['added'] ?? 0),
            'removed_count' => $this->countValue($changesSummary['removed'] ?? 0),
            'char_diff' => $changesSummary['char_diff'] ?? 0,
        ];
    }

    /**
     * changes_summary 값을 정수 카운트로 정규화합니다.
     *
     * 신규 구조는 정수(라인 수), 구버전 레코드는 배열(라인/경로 원문)일 수 있어 양쪽을
     * 카운트로 환산한다.
     *
     * @param  mixed  $value  added/removed 원본 값 (정수 또는 배열)
     * @return int 라인 수
     */
    private function countValue(mixed $value): int
    {
        return is_array($value) ? count($value) : (int) $value;
    }
}
