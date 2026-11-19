<?php

namespace App\Services;

use App\Enums\AttachmentSourceType;
use App\Extension\HookManager;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\Storage\FieldFileStorage;
use App\Fields\Types\FileType;
use App\Models\Attachment;
use App\Models\FieldDefinition;
use App\Models\User;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 회원의 파일·이미지 항목에 파일을 올리고 지우는 유스케이스.
 *
 * 파일 항목은 폼 저장으로 오가지 않는다 — 내 정보 수정과 관리자 회원 폼이 이 서비스의 전용 API 로 올리고 지운다.
 *
 * @since 7.2.0
 */
class MemberFieldFileService
{
    /** 본인이 올리고 지우는 표면 */
    public const SURFACE_SELF = 'self';

    /** 관리자가 올리고 지우는 표면 */
    public const SURFACE_ADMIN = 'admin';

    /**
     * @param  FieldRegistry  $registry  항목 등록소
     * @param  FieldFileStorage  $files  파일 항목 저장소
     * @param  AttachmentService  $attachments  첨부파일 서비스
     */
    public function __construct(
        protected FieldRegistry $registry,
        protected FieldFileStorage $files,
        protected AttachmentService $attachments,
    ) {}

    /**
     * 표면에서 올리고 지울 수 있는 파일 항목을 찾습니다.
     *
     * 본인 표면은 본인 수정 범위의 항목만, 관리자 표면은 사용 중인 파일 항목 전부다.
     *
     * @param  string  $key  항목 키
     * @param  string  $surface  표면 (SURFACE_SELF | SURFACE_ADMIN)
     * @return FieldDefinition|null 파일 항목 (없거나 그 표면에서 다룰 수 없으면 null)
     */
    public function findField(string $key, string $surface): ?FieldDefinition
    {
        $definition = $this->registry->active(FieldSet::CORE_USER)
            ->first(fn (FieldDefinition $candidate) => $candidate->key === $key);

        if ($definition === null || ! $this->registry->typeOf($definition) instanceof FileType) {
            return null;
        }
        if ($surface === self::SURFACE_SELF && $definition->edit_scope !== FieldDefinition::SCOPE_SELF) {
            return null;
        }

        return $definition;
    }

    /**
     * 항목의 업로드 검증 규칙을 반환합니다 (크기, 확장자).
     *
     * @param  FieldDefinition  $definition  파일 항목
     * @return array<int, mixed> 검증 규칙
     */
    public function uploadRules(FieldDefinition $definition): array
    {
        $type = $this->registry->typeOf($definition);

        return $type instanceof FileType ? $type->uploadRules($definition) : ['prohibited'];
    }

    /**
     * 회원의 파일 항목에 파일 한 개를 올립니다.
     *
     * @param  User  $owner  값의 소유 회원
     * @param  FieldDefinition  $definition  파일 항목
     * @param  UploadedFile  $file  올린 파일
     * @return array{file: array<string, mixed>, files: array<int, array<string, mixed>>} 올린 파일과 그 항목의 전체 파일 목록
     *
     * @throws ValidationException 항목의 최대 개수를 넘을 때
     */
    public function upload(User $owner, FieldDefinition $definition, UploadedFile $file): array
    {
        /** @var FileType $type */
        $type = $this->registry->typeOf($definition);
        $max = $type->maxCount($definition);

        if ($this->files->attachments($owner, $definition)->count() >= $max) {
            throw ValidationException::withMessages([
                'file' => [trans_choice('fields.validation.file_max_count', $max, ['max' => $max])],
            ]);
        }

        $attachment = $this->attachments->upload(
            $file,
            User::class,
            (int) $owner->getKey(),
            FieldFileStorage::collectionFor($definition),
            AttachmentSourceType::Core,
        );

        HookManager::doAction('core.user.field_file.after_upload', $owner, $definition, $attachment);

        return [
            'file' => FieldFileStorage::describe($attachment),
            'files' => $this->filesOf($owner, $definition),
        ];
    }

    /**
     * 회원의 파일 항목에서 파일 한 개를 지웁니다.
     *
     * @param  User  $owner  값의 소유 회원
     * @param  FieldDefinition  $definition  파일 항목
     * @param  int  $attachmentId  첨부파일 ID
     * @return array<int, array<string, mixed>>|null 남은 파일 목록 (그 항목의 첨부가 아니면 null)
     */
    public function delete(User $owner, FieldDefinition $definition, int $attachmentId): ?array
    {
        $attachment = $this->files->attachments($owner, $definition)
            ->first(fn (Attachment $candidate) => (int) $candidate->id === $attachmentId);

        if ($attachment === null) {
            return null;
        }

        $this->attachments->delete($attachment->id);

        HookManager::doAction('core.user.field_file.after_delete', $owner, $definition, $attachmentId);

        return $this->filesOf($owner, $definition);
    }

    /**
     * 회원의 항목 첨부를 모두 지웁니다 (탈퇴, 회원 삭제).
     *
     * 파일 삭제는 되돌릴 수 없으므로 호출자가 트랜잭션 커밋 뒤에 부른다. 한 건이 실패해도 나머지를 계속 지운다.
     *
     * @param  User  $owner  회원
     * @return int 지운 첨부 수
     */
    public function purgeFor(User $owner): int
    {
        return $this->purgeAttachments($this->files->allFor($owner)->all(), (int) $owner->getKey());
    }

    /**
     * 미리 모아 둔 항목 첨부를 지웁니다 (회원 행이 이미 지워진 뒤에 부를 때).
     *
     * @param  array<int, Attachment>  $attachments  지울 첨부
     * @param  int|null  $userId  로그용 회원 ID
     * @return int 지운 첨부 수
     */
    public function purgeAttachments(array $attachments, ?int $userId = null): int
    {
        $removed = 0;
        foreach ($attachments as $attachment) {
            try {
                $this->attachments->delete($attachment->id);
                $removed++;
            } catch (Exception $e) {
                Log::warning('회원 항목 첨부 삭제 실패', [
                    'user_id' => $userId,
                    'attachment_id' => $attachment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $removed;
    }

    /**
     * 한 회원 항목에 회원들이 올린 첨부의 ID 를 반환합니다 (항목 삭제 전에 지울 대상을 모아 둘 때 — 모델을 올리지 않는다).
     *
     * 유형을 보지 않고 컬렉션(`field:{키}`)으로 모은다 — 파일 유형을 준 확장이 먼저 빠져 유형을 알 수 없어도 남은 파일을 찾는다.
     *
     * @param  FieldDefinition  $definition  회원 항목
     * @return array<int, int> 첨부 ID 목록 (회원 집합이 아니면 빈 배열)
     */
    public function attachmentIdsForDefinition(FieldDefinition $definition): array
    {
        if ($definition->field_set !== FieldSet::CORE_USER) {
            return [];
        }

        return $this->files->attachmentIds($definition, User::class);
    }

    /**
     * 한 회원 항목에 파일을 올린 회원 수를 반환합니다 (삭제 창의 「회원 N명이 입력한 값」 — DB 집계).
     *
     * 유형을 보지 않고 컬렉션으로 센다 — 삭제({@see attachmentIdsForDefinition()})와 같은 대상을 센다.
     *
     * @param  FieldDefinition  $definition  회원 항목
     * @return int 파일을 올린 회원 수 (회원 집합이 아니면 0)
     */
    public function ownersCountForDefinition(FieldDefinition $definition): int
    {
        if ($definition->field_set !== FieldSet::CORE_USER) {
            return 0;
        }

        return $this->files->ownersCount($definition, User::class);
    }

    /**
     * ID 로 받은 항목 첨부를 지웁니다 (항목 삭제 — 첨부 삭제 경로로 저장 파일·썸네일까지).
     *
     * 파일 삭제는 되돌릴 수 없으므로 호출자가 트랜잭션 커밋 뒤에 부른다. 한 건이 실패해도 나머지를 계속 지운다.
     *
     * @param  array<int, int>  $attachmentIds  첨부 ID 목록
     * @return int 지운 첨부 수
     */
    public function purgeAttachmentIds(array $attachmentIds): int
    {
        $removed = 0;
        foreach ($attachmentIds as $attachmentId) {
            try {
                if ($this->attachments->delete((int) $attachmentId)) {
                    $removed++;
                }
            } catch (Exception $e) {
                Log::warning('회원 항목 첨부 삭제 실패', ['attachment_id' => $attachmentId, 'error' => $e->getMessage()]);
            }
        }

        return $removed;
    }

    /**
     * 회원이 지금 가진 항목 첨부를 반환합니다 (삭제 전에 모아 둘 때).
     *
     * @param  User  $owner  회원
     * @return array<int, Attachment> 첨부 목록
     */
    public function collectFor(User $owner): array
    {
        return $this->files->allFor($owner)->all();
    }

    /**
     * 회원의 한 항목 파일 목록을 화면용 값으로 반환합니다.
     *
     * @param  User  $owner  회원
     * @param  FieldDefinition  $definition  파일 항목
     * @return array<int, array<string, mixed>> 파일 목록
     */
    public function filesOf(User $owner, FieldDefinition $definition): array
    {
        return $this->files->attachments($owner, $definition)
            ->map(fn (Attachment $attachment) => FieldFileStorage::describe($attachment))
            ->values()
            ->all();
    }
}
