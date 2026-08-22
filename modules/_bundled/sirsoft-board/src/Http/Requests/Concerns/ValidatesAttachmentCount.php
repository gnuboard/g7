<?php

namespace Modules\Sirsoft\Board\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Modules\Sirsoft\Board\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시글 첨부 개수 선차단 Trait
 *
 * 총합은 **직접 업로드 + `attachment_ids` + `temp_key` 임시첨부 + 기존 첨부** 4소스라
 * 필드 단위 규칙(`files` 의 `max:`)만으로는 판정할 수 없습니다. 최종 불변조건은
 * `AttachmentService::assertAttachmentCountWithin()` 이 담당하며, 이 Trait 은
 * "글을 다 쓰고 저장 단계에서 실패" 를 막는 UX 선차단입니다 (역할이 다른 중복).
 */
trait ValidatesAttachmentCount
{
    /**
     * 첨부 총합이 게시판 상한을 넘으면 검증 오류를 추가합니다.
     *
     * @param  Validator  $validator  검증기
     * @param  Board|null  $board  대상 게시판
     * @param  int|null  $postId  수정 대상 게시글 ID (신규 작성이면 null)
     */
    protected function validateAttachmentTotal(Validator $validator, ?Board $board, ?int $postId = null): void
    {
        if (! $board) {
            return;
        }

        $limit = (int) ($board->max_file_count ?? 0);
        if ($limit <= 0) {
            return;
        }

        $files = $this->file('files');
        $newCount = is_array($files) ? count($files) : 0;

        $attachmentIds = $this->input('attachment_ids');
        $newCount += is_array($attachmentIds) ? count($attachmentIds) : 0;

        $tempKey = $this->input('temp_key');
        if (is_string($tempKey) && $tempKey !== '') {
            $newCount += Attachment::query()
                ->where('temp_key', $tempKey)
                ->whereNull('post_id')
                ->count();
        }

        if ($newCount <= 0) {
            return;
        }

        $existing = $postId !== null
            ? Attachment::query()->where('board_id', $board->id)->where('post_id', $postId)->count()
            : 0;

        if ($existing + $newCount > $limit) {
            $validator->errors()->add('files', __('sirsoft-board::messages.errors.attachment_limit_exceeded', [
                'limit' => $limit,
                'attempted' => $existing + $newCount,
            ]));
        }
    }

    /**
     * 단일 파일 업로드 1건이 게시판 상한을 넘으면 도메인 예외로 선차단합니다.
     *
     * 합산 기준은 `AttachmentService::assertAttachmentCountWithin()` 과 동일합니다 —
     * `post_id` 가 있으면 그 게시글의 기존 첨부, 없으면 `temp_key` 의 임시첨부를 기준으로
     * 삼습니다(둘을 더하지 않습니다). 두 계층의 판정이 갈리면 화면과 서버가 다른 답을
     * 내놓게 되므로 우선순위까지 같아야 합니다.
     *
     * 필드 검증 오류가 아니라 `AttachmentLimitExceededException` 을 던지는 이유는 응답 형태
     * 때문입니다 — 이 엔드포인트의 상한 초과 응답은 `errors.code = attachment_limit_exceeded`
     * 로 공개 문서화되어 있고, 선차단이 다른 형태를 내놓으면 업로더가 두 응답을 분기해야
     * 합니다. 예외의 `render()` 가 컨트롤러 catch 와 같은 응답을 만듭니다.
     *
     * @param  Board|null  $board  대상 게시판
     * @param  int|null  $postId  대상 게시글 ID (임시 업로드면 null)
     * @param  string|null  $tempKey  임시 업로드 키
     *
     * @throws AttachmentLimitExceededException 상한 초과 시
     */
    protected function assertSingleAttachmentSlotAvailable(
        ?Board $board,
        ?int $postId = null,
        ?string $tempKey = null
    ): void {
        if (! $board) {
            return;
        }

        $limit = (int) ($board->max_file_count ?? 0);
        if ($limit <= 0) {
            return;
        }

        $existing = 0;
        if ($postId !== null) {
            $existing = Attachment::query()
                ->where('board_id', $board->id)
                ->where('post_id', $postId)
                ->count();
        } elseif (is_string($tempKey) && $tempKey !== '') {
            // 임시첨부는 아직 게시판에 귀속되기 전이라 board_id 로 좁히지 않는다
            // (`validateAttachmentTotal()` 의 temp_key 합산과 동일 기준).
            $existing = Attachment::query()
                ->where('temp_key', $tempKey)
                ->whereNull('post_id')
                ->count();
        }

        if ($existing + 1 > $limit) {
            throw new AttachmentLimitExceededException($limit, $existing + 1);
        }
    }
}
