<?php

namespace App\Extension\Layout;

use App\Contracts\Repositories\LayoutExtensionVersionRepositoryInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use App\Support\LayoutMarkup\EmptyMap;

/**
 * 레이아웃·조각 본문을 바꾸기 직전, 운영자 판을 편집기 버전 이력에 남깁니다.
 *
 * 파일 판으로 덮어쓰는 동기화(덮어쓰기 전략·화면별 「새 판 적용」·지운 화면 되살리기)와, 레이아웃 본문을 직접 바꾸는
 * 업그레이드 스텝이 이 한 곳을 지난다. 남긴 버전은 편집기 [버전 기록] 에서 복원할 수 있다.
 *
 * 버전 요약(`changes_summary`)의 `source` 에 무엇이 덮었는지 적는다: `extension_update`(확장 업데이트) · `refresh`
 * (레이아웃 갱신) · `decision_take_theirs`(화면별 「새 판 적용」) · `upgrade_step`(업그레이드 스텝).
 */
final class LayoutVersionRecorder
{
    /**
     * @param  LayoutVersionRepositoryInterface  $layoutVersions  레이아웃 버전 저장소
     * @param  LayoutExtensionVersionRepositoryInterface  $extensionVersions  조각 버전 저장소
     */
    public function __construct(
        private LayoutVersionRepositoryInterface $layoutVersions,
        private LayoutExtensionVersionRepositoryInterface $extensionVersions,
    ) {}

    /**
     * 본문을 바꾸기 직전의 판(지금 DB 본문)을 버전 하나로 남깁니다.
     *
     * 업그레이드 스텝에서 쓸 때는 본문을 바꾸기 전에 부른다:
     * `app(LayoutVersionRecorder::class)->recordBeforeMutation($row, 'upgrade_step');`
     *
     * @param  TemplateLayout|LayoutExtension  $row  바꿀 행 (DB 본문이 운영자 판)
     * @param  string  $source  덮는 출처 (`extension_update`·`refresh`·`decision_take_theirs`·`upgrade_step`)
     * @return void 반환값 없음
     */
    public function recordBeforeMutation(TemplateLayout|LayoutExtension $row, string $source): void
    {
        $raw = $row->getRawOriginal('content');
        $content = is_string($raw) ? EmptyMap::decodePreserving($raw) : $raw;

        if (! is_array($content)) {
            return;
        }

        $extra = ['source' => $source, 'before_overwrite' => true];

        if ($row instanceof TemplateLayout) {
            $this->layoutVersions->saveVersion((int) $row->id, $content, null, null, $extra);

            return;
        }

        $this->extensionVersions->saveVersion((int) $row->id, $content, null, null, $extra);
    }
}
