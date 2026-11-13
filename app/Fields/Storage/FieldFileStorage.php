<?php

namespace App\Fields\Storage;

use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Models\Attachment;
use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * 파일·이미지 항목의 저장소 — 값은 첨부파일이다.
 *
 * 첨부는 소유자(`attachmentable`)에 달리고 컬렉션 이름이 `field:{항목 키}` 다. 값 테이블에는 아무것도 쓰지 않는다.
 * 읽은 값은 파일 목록(`[{id, hash, name, size, mime_type, url}]`)이고, 올리고 지우는 일은 전용 API 가 한다(폼 저장으로 오가지 않는다).
 *
 * @since 7.2.0
 */
class FieldFileStorage
{
    /** 컬렉션 이름 접두 */
    public const COLLECTION_PREFIX = 'field:';

    /**
     * @param  AttachmentRepositoryInterface  $attachments  첨부파일 저장소
     */
    public function __construct(protected AttachmentRepositoryInterface $attachments) {}

    /**
     * 항목의 첨부 컬렉션 이름을 반환합니다.
     *
     * @param  FieldDefinition|string  $definition  항목 정의 또는 항목 키
     * @return string 컬렉션 이름
     */
    public static function collectionFor(FieldDefinition|string $definition): string
    {
        return self::COLLECTION_PREFIX.($definition instanceof FieldDefinition ? $definition->key : $definition);
    }

    /**
     * 컬렉션 이름에서 항목 키를 꺼냅니다.
     *
     * @param  string|null  $collection  컬렉션 이름
     * @return string|null 항목 키 (항목 첨부의 컬렉션이 아니면 null)
     */
    public static function keyFromCollection(?string $collection): ?string
    {
        if (! is_string($collection) || ! str_starts_with($collection, self::COLLECTION_PREFIX)) {
            return null;
        }

        $key = substr($collection, strlen(self::COLLECTION_PREFIX));

        return $key === '' ? null : $key;
    }

    /**
     * 소유자 여럿의 파일 항목 값을 한 번에 읽습니다 (쿼리 한 번).
     *
     * @param  Collection<int, Model>  $owners  소유자 목록 (같은 종류)
     * @param  Collection<int, FieldDefinition>  $definitions  파일·이미지 항목
     * @return array<int|string, array<string, array<int, array<string, mixed>>>> 소유자 ID => (항목 키 => 파일 목록)
     */
    public function read(Collection $owners, Collection $definitions): array
    {
        if ($owners->isEmpty() || $definitions->isEmpty()) {
            return [];
        }

        $rows = $this->attachments->getByAttachmentablesAndCollections(
            $owners->first()::class,
            $owners->map(fn (Model $owner) => (int) $owner->getKey())->all(),
            $definitions->map(fn (FieldDefinition $definition) => self::collectionFor($definition))->all(),
        );

        $result = [];
        foreach ($rows as $attachment) {
            $key = self::keyFromCollection($attachment->collection);
            if ($key !== null) {
                $result[$attachment->attachmentable_id][$key][] = self::describe($attachment);
            }
        }

        return $result;
    }

    /**
     * 소유자 한 명의 한 항목에 달린 첨부를 반환합니다.
     *
     * @param  Model  $owner  소유자
     * @param  FieldDefinition  $definition  파일·이미지 항목
     * @return Collection<int, Attachment> 첨부 목록 (올린 순서)
     */
    public function attachments(Model $owner, FieldDefinition $definition): Collection
    {
        return $this->attachments->getByAttachmentable($owner::class, (int) $owner->getKey(), self::collectionFor($definition));
    }

    /**
     * 소유자에게 달린 항목 첨부 전부를 반환합니다 (탈퇴·삭제 때 지울 대상).
     *
     * @param  Model  $owner  소유자
     * @return Collection<int, Attachment> 첨부 목록
     */
    public function allFor(Model $owner): Collection
    {
        return $this->attachments->getByAttachmentable($owner::class, (int) $owner->getKey())
            ->filter(fn (Attachment $attachment) => self::keyFromCollection($attachment->collection) !== null)
            ->values();
    }

    /**
     * 첨부 한 건을 화면용 값으로 바꿉니다.
     *
     * @param  Attachment  $attachment  첨부파일
     * @return array{id: int, hash: string, name: string, size: int, mime_type: string|null, url: string} 파일 정보
     */
    public static function describe(Attachment $attachment): array
    {
        return [
            'id' => (int) $attachment->id,
            'hash' => (string) $attachment->hash,
            'name' => (string) $attachment->original_filename,
            'size' => (int) $attachment->size,
            'mime_type' => $attachment->mime_type,
            'url' => $attachment->download_url,
        ];
    }
}
