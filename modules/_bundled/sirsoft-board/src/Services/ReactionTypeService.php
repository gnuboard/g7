<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Exceptions\ReactionTypeInUseException;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;

/**
 * 반응 유형 관리 서비스
 *
 * 반응 유형은 전용 CRUD 화면 없이 **게시판 환경설정 › 기본 설정 안 인라인 편집기**로
 * 관리한다 (PO 결정 R2). 설정 저장 버튼 하나로 행 추가·수정·순서 변경·삭제가 함께 반영되며,
 * 이 서비스의 syncTypes() 가 그 페이로드와 DB 를 맞춘다
 * (sirsoft-ecommerce 의 ShippingTypeService::syncShippingTypes() 와 같은 방식).
 *
 * 아이콘은 이미지 업로드만 지원한다 (PO 결정 R4) — 시드 기본값의 Font Awesome 클래스는
 * 관리자가 편집할 수 없고, 이미지가 없으면 그 FA 클래스로, 그것도 없으면 레이아웃 기본
 * 아이콘으로 폴백한다.
 */
class ReactionTypeService
{
    /**
     * 아이콘 첨부 collection 명.
     *
     * 이 collection + source_identifier 조합이 "이 모듈이 만든 반응 유형 아이콘"의 표식이며,
     * 업로드 검증·삭제 검증이 모두 이 값을 확인한다 (남의 첨부를 아이콘으로 붙이거나
     * 아이콘 삭제 엔드포인트로 남의 첨부를 지우지 못하게 한다).
     */
    public const ICON_COLLECTION = 'reaction_type_icon';

    /**
     * 아이콘 첨부 소스 식별자 (모듈 ID).
     */
    public const ICON_SOURCE_IDENTIFIER = 'sirsoft-board';

    /**
     * ReactionTypeService 생성자
     *
     * @param  ReactionTypeRepositoryInterface  $reactionTypeRepository  반응 유형 Repository
     * @param  ReactionRepositoryInterface  $reactionRepository  반응 이력 Repository
     * @param  BoardRepositoryInterface  $boardRepository  게시판 Repository
     * @param  AttachmentService  $attachmentService  코어 첨부 서비스
     * @param  AttachmentRepositoryInterface  $attachmentRepository  코어 첨부 Repository
     * @param  BoardService  $boardService  게시판 서비스 (캐시 무효화)
     */
    public function __construct(
        private ReactionTypeRepositoryInterface $reactionTypeRepository,
        private ReactionRepositoryInterface $reactionRepository,
        private BoardRepositoryInterface $boardRepository,
        private AttachmentService $attachmentService,
        private AttachmentRepositoryInterface $attachmentRepository,
        private BoardService $boardService,
    ) {}

    /**
     * 환경설정 화면이 쓸 전 유형(활성+비활성) 행을 반환합니다.
     *
     * 편집기는 비활성 유형도 보여야 하고(다시 켤 수 있어야 하므로), 행마다 반응 수가
     * 있어야 삭제 가능 여부를 화면에서 먼저 판정할 수 있다.
     *
     * @return array<int, array<string, mixed>> 유형 행 목록 (display_order 순)
     */
    public function getTypesForSettings(): array
    {
        return $this->reactionTypeRepository->all()
            ->map(fn (ReactionType $type) => $this->toSettingsRow($type))
            ->values()
            ->all();
    }

    /**
     * 편집기 행 배열과 DB 를 동기화합니다.
     *
     * 페이로드에 없는 기존 유형은 삭제 대상이며, 반응이 1건이라도 남아 있으면
     * ReactionTypeInUseException 으로 거부한다 (PO 결정 R5 — 비활성화만 가능).
     * code 는 추가 후 불변이므로 기존 행의 code 변경은 무시한다 (PO 결정 R12).
     *
     * @param  array<int, array<string, mixed>>  $rows  편집기 행 배열 (표시 순서대로)
     * @return array{types: Collection<int, ReactionType>, removed_codes: array<int, string>}
     *
     * @throws ReactionTypeInUseException 반응이 남아 있는 유형을 지우려 할 때
     */
    public function syncTypes(array $rows): array
    {
        $orphanAttachmentIds = [];

        $result = DB::transaction(function () use ($rows, &$orphanAttachmentIds) {
            $existing = $this->reactionTypeRepository->all()->keyBy('id');
            $incomingIds = array_filter(array_map(
                static fn (array $row) => isset($row['id']) ? (int) $row['id'] : null,
                $rows,
            ));

            $removedCodes = [];

            // 1. 삭제 — DB 에는 있고 페이로드에 없는 유형
            foreach ($existing as $id => $type) {
                if (in_array($id, $incomingIds, true)) {
                    continue;
                }

                $count = $this->reactionRepository->countByReactionType((int) $id);

                if ($count > 0) {
                    throw new ReactionTypeInUseException($type->getLocalizedName(), $count);
                }

                if ($type->icon_attachment_id !== null) {
                    $orphanAttachmentIds[] = (int) $type->icon_attachment_id;
                }

                $removedCodes[] = $type->code;

                try {
                    $this->reactionTypeRepository->delete($type);
                } catch (QueryException $e) {
                    // FK restrictOnDelete 가 마지막 방어선 — 카운트 조회와 삭제 사이에
                    // 다른 요청이 반응을 남겼을 때 여기로 떨어진다. 무결성 위반(23000)만
                    // 사용 중으로 번역하고 나머지는 그대로 올린다(장애를 입력 오류로 위장 금지).
                    if (($e->getCode() ?? null) !== '23000') {
                        throw $e;
                    }

                    throw new ReactionTypeInUseException(
                        $type->getLocalizedName(),
                        $this->reactionRepository->countByReactionType((int) $id),
                    );
                }
            }

            // 2. 생성/수정 — 배열 순서가 곧 display_order
            foreach ($rows as $index => $row) {
                $id = isset($row['id']) ? (int) $row['id'] : null;
                $iconAttachmentId = isset($row['icon_attachment_id']) ? (int) $row['icon_attachment_id'] : null;

                if ($id !== null && $existing->has($id)) {
                    /** @var ReactionType $type */
                    $type = $existing->get($id);

                    // 아이콘이 교체되면 이전 첨부는 고아가 된다 (source_identifier 가 있어
                    // 코어 고아 정리 대상이 아니므로 여기서 직접 지운다).
                    if ($type->icon_attachment_id !== null && $type->icon_attachment_id !== $iconAttachmentId) {
                        $orphanAttachmentIds[] = (int) $type->icon_attachment_id;
                    }

                    // code 는 불변 — 페이로드의 code 는 무시한다 (PO 결정 R12).
                    $this->reactionTypeRepository->update($type, [
                        'name' => $row['name'],
                        'icon_attachment_id' => $iconAttachmentId,
                        'display_order' => $index,
                        'is_active' => (bool) ($row['is_active'] ?? true),
                    ]);
                } else {
                    // 새 유형의 FA 클래스(icon)는 페이로드에서 받지 않는다 — 관리자는
                    // 이미지만 지정할 수 있다 (PO 결정 R4).
                    $type = $this->reactionTypeRepository->create([
                        'code' => $row['code'],
                        'name' => $row['name'],
                        'icon' => null,
                        'icon_attachment_id' => $iconAttachmentId,
                        'display_order' => $index,
                        'is_active' => (bool) ($row['is_active'] ?? true),
                    ]);
                }

                if ($iconAttachmentId !== null) {
                    $this->bindIcon($iconAttachmentId, (int) $type->id);
                }
            }

            // 3. 삭제된 유형의 code 를 게시판 활성 목록에서 제거
            if ($removedCodes !== []) {
                $this->boardRepository->stripReactionCodes($removedCodes);
            }

            return [
                'types' => $this->reactionTypeRepository->all(),
                'removed_codes' => $removedCodes,
            ];
        });

        // 커밋 후 파일 정리 — 트랜잭션이 되돌려지면 파일이 남아야 하므로 안에서 지우지 않는다.
        foreach (array_unique($orphanAttachmentIds) as $attachmentId) {
            $this->attachmentService->delete($attachmentId);
        }

        $this->boardService->clearAllBoardCaches();

        return $result;
    }

    /**
     * 반응 유형 아이콘 이미지를 업로드합니다.
     *
     * 유형이 아직 만들어지지 않은 상태(편집기에서 행을 추가한 직후)에도 올릴 수 있어야 하므로
     * reactionTypeId 는 nullable 이며, 이 경우 저장 시 syncTypes() 가 bindIcon() 으로 잇는다.
     *
     * @param  UploadedFile  $file  업로드 파일 (이미지, SVG 제외)
     * @param  int|null  $reactionTypeId  연결할 유형 ID (없으면 미연결 상태로 저장)
     * @return Attachment 생성된 첨부
     */
    public function uploadIcon(UploadedFile $file, ?int $reactionTypeId): Attachment
    {
        return $this->attachmentService->upload(
            $file,
            $reactionTypeId !== null ? ReactionType::class : null,
            $reactionTypeId,
            self::ICON_COLLECTION,
            AttachmentSourceType::Module,
            self::ICON_SOURCE_IDENTIFIER,
        );
    }

    /**
     * 반응 유형 아이콘 첨부를 삭제합니다.
     *
     * 이 모듈이 만든 아이콘 첨부만 지운다 — collection·source_identifier 가 다르면
     * 아무것도 하지 않고 false 를 돌려준다(이 엔드포인트로 남의 첨부를 지우지 못하게).
     *
     * @param  int  $attachmentId  첨부 ID
     * @return bool 삭제 여부 (대상이 아니거나 없으면 false)
     */
    public function deleteIcon(int $attachmentId): bool
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);

        if ($attachment === null
            || $attachment->collection !== self::ICON_COLLECTION
            || $attachment->source_identifier !== self::ICON_SOURCE_IDENTIFIER) {
            return false;
        }

        // 이 첨부를 쓰던 유형의 참조를 먼저 끊는다 (FK 가 nullOnDelete 지만
        // 화면 상태와 DB 를 같은 요청 안에서 맞춰 둔다).
        $this->reactionTypeRepository->clearIconAttachment($attachmentId);

        return $this->attachmentService->delete($attachmentId);
    }

    /**
     * 유형 1건을 편집기·API 응답용 행으로 변환합니다.
     *
     * @param  ReactionType  $type  반응 유형
     * @return array<string, mixed> 유형 행
     */
    private function toSettingsRow(ReactionType $type): array
    {
        $attachment = $type->iconAttachment;

        return [
            'id' => (int) $type->id,
            'code' => $type->code,
            // 편집기는 로케일별 입력을 다루므로 문자열이 아니라 맵을 그대로 내려준다.
            'name' => is_array($type->name) ? $type->name : ['ko' => (string) $type->name],
            'icon_name' => $type->getIconName(),
            'icon_url' => $type->getIconUrl(),
            'icon_attachment' => $attachment === null ? null : [
                'id' => (int) $attachment->id,
                'hash' => $attachment->hash,
                'url' => Attachment::urlForHash($attachment->hash),
                'download_url' => Attachment::urlForHash($attachment->hash),
                'original_filename' => $attachment->original_filename,
                'mime_type' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                'is_image' => true,
                'order' => 1,
            ],
            'icon_attachment_id' => $type->icon_attachment_id === null ? null : (int) $type->icon_attachment_id,
            'display_order' => (int) $type->display_order,
            'is_active' => (bool) $type->is_active,
            'reaction_count' => (int) ($type->reactions_count ?? 0),
        ];
    }

    /**
     * 아이콘 첨부를 반응 유형에 연결합니다.
     *
     * 업로드 시점에 유형이 없었던(신규 행) 첨부의 attachmentable 을 저장 시 채운다.
     * 다른 collection·소스의 첨부는 무시한다.
     *
     * @param  int  $attachmentId  첨부 ID
     * @param  int  $reactionTypeId  반응 유형 ID
     * @return void
     */
    private function bindIcon(int $attachmentId, int $reactionTypeId): void
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);

        if ($attachment === null
            || $attachment->collection !== self::ICON_COLLECTION
            || $attachment->source_identifier !== self::ICON_SOURCE_IDENTIFIER) {
            return;
        }

        if ($attachment->attachmentable_type === null || $attachment->attachmentable_id === null) {
            $this->attachmentRepository->update($attachmentId, [
                'attachmentable_type' => ReactionType::class,
                'attachmentable_id' => $reactionTypeId,
            ]);
        }
    }
}
