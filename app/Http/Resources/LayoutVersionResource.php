<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

class LayoutVersionResource extends BaseApiResource
{
    /**
     * content 원본 전체를 포함할지 여부 (단건 조회 전용).
     *
     * 목록(versions.index)은 버전이 많아(수백 건) content 전체를 다 실으면 페이로드가
     * 비대해지므로 분해된 일부 키만 노출한다. 단건 조회(showVersion)는 버전 비교 diff 가
     * content 전체(slots/extends 등 분해되지 않는 키 포함)를 필요로 하므로 full_content 를
     * 추가 노출한다. withFullContent() 로 opt-in.
     */
    private bool $includeFullContent = false;

    /**
     * content 원본 전체(full_content)를 응답에 포함하도록 표시합니다 (단건 조회용).
     *
     * @return $this
     */
    public function withFullContent(): static
    {
        $this->includeFullContent = true;

        return $this;
    }

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  현재 요청
     * @return array 버전 표시 필드 배열 (full_content 는 단건 조회 시에만 포함)
     */
    public function toArray(Request $request): array
    {
        $content = $this->getValue('content', []);

        $payload = [
            'id' => $this->getValue('id'),
            'layout_id' => $this->getValue('layout_id'),
            'version' => $this->getValue('version'),
            'endpoint' => $content['endpoint'] ?? null,
            'components' => $content['components'] ?? [],
            'data_sources' => $content['data_sources'] ?? [],
            'metadata' => $content['metadata'] ?? [],
            'changes_summary' => $this->formatChangesSummary($this->getValue('changes_summary', [])),
            // 저장자 이름만 노출 (버전 히스토리 표시용) — created_by ID 는 보안상 제외 유지.
            // creator 관계 미로딩(eager load 누락)/탈퇴 사용자 시 null.
            'created_by_name' => $this->resolveCreatorName(),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];

        // 단건 조회 시에만 content 원본 전체를 노출 — 버전 비교 diff 가 slots/extends 등
        // 분해되지 않는 키까지 비교하려면 원본 전체가 필요하다. 목록에는 제외(비대화 회피).
        if ($this->includeFullContent) {
            $payload['full_content'] = is_array($content) ? $content : [];
        }

        return $payload;
    }

    /**
     * 목록용 경량 배열로 변환합니다.
     *
     * 버전 히스토리 패널이 쓰는 값은 버전 번호·변경 요약·저장자·저장 시각뿐입니다
     * (`LayoutVersionSummary`). 분해된 본문(`components`/`data_sources`/`metadata`/`endpoint`)은
     * 버전 비교 diff 전용이라 단건 조회가 공급합니다. 목록에 실으면 **버전마다** 레이아웃 본문
     * 사본이 실려, 저장을 반복한 레이아웃일수록 목록 응답이 선형으로 커집니다.
     *
     * 종전 주석은 "목록에는 content 를 제외한다" 고 했지만 실제로 제외된 것은 `full_content`
     * 하나뿐이었습니다 — 분해된 키들이 그대로 남아 사실상 본문이 다 실렸습니다.
     *
     * @param  Request  $request  현재 요청
     * @return array<string, mixed> 목록용 직렬화 결과
     */
    public function toListArray(Request $request): array
    {
        // 이 배열은 컨트롤러가 그대로 응답에 싣는다 — Laravel 의 MissingValue 제거 단계를 거치지
        // 않으므로 조건부 필드를 직접 걸러낸다 (이 모델은 UPDATED_AT 이 없어 updated_at 이 미충족).
        return $this->withoutMissing([
            'id' => $this->getValue('id'),
            'layout_id' => $this->getValue('layout_id'),
            'version' => $this->getValue('version'),
            'changes_summary' => $this->formatChangesSummary($this->getValue('changes_summary', [])),
            'created_by_name' => $this->resolveCreatorName(),

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ]);
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

        // whenLoaded 미로딩 시 MissingValue 반환 → 삼항으로 null 처리 (MissingValue 누출 방지).
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
