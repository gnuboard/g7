<?php

namespace App\Services;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Models\Attachment;
use App\Models\Menu;
use App\Support\MenuIcon\MenuIconPresenter;
use App\Support\MenuIcon\MenuIconSpec;
use App\Support\MenuIcon\MenuIconSvgValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 관리자 메뉴 아이콘 업로드·저장 변환·첨부 수명 서비스.
 *
 * - 래스터(png·webp·jpg·gif) 업로드는 코어 첨부(collection `menu_icons`)로 저장하고 `<img>` 로 그린다.
 * - SVG 파일은 파일로 저장하지 않는다 — 첨부 SVG 는 KVE-2026-2366 정책상 다운로드로만 나가 사이드바에 보이지 않으므로,
 *   서버가 내용을 검사한 뒤 인라인 `svg` 코드로 돌려준다.
 * - 저장하지 않은 업로드는 미바인딩 고아 첨부로 남아 `attachments:prune-orphans`(기본 꺼짐) 가 지운다.
 *
 * @since 7.2.1
 */
class MenuIconService
{
    /** @var int 아이콘 래스터 업로드 최대 크기(KB) — 업로드 요청 규칙과 「쓸 수 있는 첨부」 판정이 같은 값을 쓴다 */
    public const UPLOAD_MAX_KB = 1024;

    /**
     * MenuIconService 생성자
     *
     * @param  AttachmentService  $attachmentService  첨부 서비스(저장·삭제)
     * @param  AttachmentRepositoryInterface  $attachmentRepository  첨부 저장소(조회·바인딩)
     */
    public function __construct(
        private AttachmentService $attachmentService,
        private AttachmentRepositoryInterface $attachmentRepository,
    ) {}

    /**
     * 아이콘 파일을 받아 icon_spec 을 돌려줍니다 (검증은 UploadMenuIconRequest 에서 끝났다).
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return array<string, mixed> 응답용 icon_spec
     */
    public function upload(UploadedFile $file): array
    {
        if (self::isSvgUpload($file)) {
            return [
                'type' => MenuIconSpec::TYPE_SVG,
                'value' => MenuIconSvgValidator::normalize((string) file_get_contents($file->getRealPath())),
            ];
        }

        $attachment = $this->attachmentService->upload(
            $file,
            null,
            null,
            MenuIconSpec::ATTACHMENT_COLLECTION,
            AttachmentSourceType::Core,
            null,
        );

        $stored = MenuIconSpec::attachmentImage((int) $attachment->id, (string) $attachment->hash)->toStored();

        return (array) MenuIconPresenter::present($stored, null, null)['icon_spec'];
    }

    /**
     * 요청의 icon_spec 을 저장값으로 바꿉니다 (검증은 MenuIconSpecRule 에서 끝났다).
     *
     * 첨부는 hash 를 DB 에서 다시 읽는다 — 클라이언트가 보낸 hash 는 믿지 않는다.
     * 확장 이미지(package)는 규칙이 「현재값과 같음」을 보장하므로 현재 저장값을 그대로 돌려준다.
     *
     * @param  mixed  $iconSpecInput  요청 icon_spec (null 이면 아이콘 지우기)
     * @param  Menu|null  $menu  수정 대상 메뉴 (생성이면 null)
     * @return string|null 저장값
     */
    public function toStorage(mixed $iconSpecInput, ?Menu $menu): ?string
    {
        if (! is_array($iconSpecInput)) {
            return null;
        }

        // 현재 저장값을 그대로 되돌려 보낸 수정은 저장값을 둔다 — 업로드 첨부가 따로 지워졌어도 이름만 고친 저장이
        // 아이콘을 지우고 수정 표식을 붙이지 않게(규칙도 같은 되돌림을 통과시킨다)
        $current = $menu?->getRawOriginal('icon');

        if ($current !== null && MenuIconSpec::isUnchanged($iconSpecInput, $current)) {
            return $current;
        }

        $spec = MenuIconSpec::fromArray($iconSpecInput);

        if ($spec === null) {
            return null;
        }

        if ($spec->type === MenuIconSpec::TYPE_IMAGE && $spec->source === MenuIconSpec::SOURCE_ATTACHMENT) {
            $attachment = $this->attachmentRepository->findById((int) $spec->attachmentId);

            return $attachment !== null
                ? MenuIconSpec::attachmentImage((int) $attachment->id, (string) $attachment->hash)->toStored()
                : null;
        }

        if ($spec->type === MenuIconSpec::TYPE_IMAGE && $spec->source === MenuIconSpec::SOURCE_PACKAGE) {
            return $menu?->iconSpec()?->toStored();
        }

        return $spec->toStored();
    }

    /**
     * 저장 뒤 첨부 바인딩을 맞춥니다 — 새 첨부는 이 메뉴에 묶고, 바뀐 이전 첨부는 커밋 뒤에 지운다.
     *
     * 묶기는 「미바인딩일 때만」 조건부 갱신이다. 같은 업로드로 두 메뉴를 동시에 저장하면 규칙은 둘 다 통과하므로,
     * 묶기에서 진 쪽(이미 다른 메뉴에 묶임)은 422 로 거절한다 — 호출부가 저장과 같은 트랜잭션으로 감싸 되돌린다.
     *
     * @param  Menu  $menu  저장된 메뉴
     * @param  string|null  $previousStored  저장 전 icon 원문
     * @return void
     *
     * @throws ValidationException 첨부가 이미 다른 메뉴에 묶여 있을 때
     */
    public function syncAttachmentBinding(Menu $menu, ?string $previousStored): void
    {
        $current = $menu->iconSpec();
        $currentId = $current?->source === MenuIconSpec::SOURCE_ATTACHMENT ? $current->attachmentId : null;

        if ($currentId !== null && ! $this->attachmentRepository->bindIfUnbound($currentId, Menu::class, (int) $menu->id)) {
            $attachment = $this->attachmentRepository->findById($currentId);

            if ($attachment !== null
                && ($attachment->attachmentable_type !== Menu::class || (int) $attachment->attachmentable_id !== (int) $menu->id)) {
                throw ValidationException::withMessages([
                    'icon_spec' => [__('validation.menu.icon_spec.attachment_invalid')],
                ]);
            }
        }

        $previous = MenuIconSpec::fromStored($previousStored);
        $previousId = $previous?->source === MenuIconSpec::SOURCE_ATTACHMENT ? $previous->attachmentId : null;

        if ($previousId === null || $previousId === $currentId) {
            return;
        }

        $previousAttachment = $this->attachmentRepository->findById($previousId);

        if ($previousAttachment === null
            || $previousAttachment->collection !== MenuIconSpec::ATTACHMENT_COLLECTION
            || $previousAttachment->attachmentable_type !== Menu::class
            || (int) $previousAttachment->attachmentable_id !== (int) $menu->id) {
            return;
        }

        DB::afterCommit(function () use ($previousId): void {
            $this->attachmentService->delete($previousId);
        });
    }

    /**
     * 메뉴에 묶인 아이콘 첨부를 모두 지웁니다 (메뉴 삭제 뒤).
     *
     * @param  int  $menuId  메뉴 ID
     * @return void
     */
    public function purgeMenuAttachments(int $menuId): void
    {
        $attachments = $this->attachmentRepository->getByAttachmentable(Menu::class, $menuId, MenuIconSpec::ATTACHMENT_COLLECTION);

        foreach ($attachments as $attachment) {
            $this->attachmentService->delete((int) $attachment->id);
        }
    }

    /**
     * 첨부가 이 메뉴의 아이콘으로 쓸 수 있는지 판정합니다.
     *
     * 여섯 조건 — 존재 · collection `menu_icons` · 코어 출처(식별자 없음) · 래스터 이미지 MIME · 업로드 상한 이하 ·
     * 미바인딩이면 현재 사용자가 올린 것, 바인딩이면 같은 메뉴.
     *
     * @param  int  $attachmentId  첨부 ID
     * @param  int|null  $menuId  수정 대상 메뉴 ID (생성이면 null)
     * @return bool 쓸 수 있으면 true
     */
    public function isUsableAttachment(int $attachmentId, ?int $menuId): bool
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);

        if (! $attachment instanceof Attachment) {
            return false;
        }

        $sourceType = $attachment->source_type instanceof AttachmentSourceType
            ? $attachment->source_type
            : AttachmentSourceType::tryFrom((string) $attachment->source_type);

        // 크기는 아이콘 업로드 상한과 같다 — 일반 첨부 올리기로 collection 만 맞춘 큰 이미지가 상한을 우회하지 못하게
        if ($attachment->collection !== MenuIconSpec::ATTACHMENT_COLLECTION
            || $sourceType !== AttachmentSourceType::Core
            || $attachment->source_identifier !== null
            || preg_match('#^image/(png|webp|jpeg|gif)$#', (string) $attachment->mime_type) !== 1
            || (int) $attachment->size > self::UPLOAD_MAX_KB * 1024) {
            return false;
        }

        // 미바인딩 첨부는 올린 사람만 묶는다(게시판 첨부 연결과 같은 규칙) — 남의 업로드를 가로채 묶었다가
        // 아이콘을 바꿀 때 지우는 경로를 막는다
        if ($attachment->attachmentable_id === null) {
            return $attachment->created_by !== null && (int) $attachment->created_by === (int) Auth::id();
        }

        return $menuId !== null
            && $attachment->attachmentable_type === Menu::class
            && (int) $attachment->attachmentable_id === $menuId;
    }

    /**
     * 업로드 파일이 SVG 인지 판정합니다 — 이름 확장자와 내용이 모두 svg 여야 한다(위장은 요청 규칙이 거부).
     *
     * @param  UploadedFile  $file  업로드 파일
     * @return bool SVG 면 true
     */
    public static function isSvgUpload(UploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'svg';
    }
}
