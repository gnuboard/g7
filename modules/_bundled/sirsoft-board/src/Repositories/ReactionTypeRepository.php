<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;

/**
 * 반응 유형 Repository
 *
 * 반응 유형 데이터 접근 계층을 담당합니다.
 */
class ReactionTypeRepository implements ReactionTypeRepositoryInterface
{
    /**
     * 아이콘 첨부 eager load 스펙.
     *
     * 아이콘 URL 조립에 필요한 컬럼만 싣는다 — 유형 목록은 화면마다 불려서
     * 첨부 전 컬럼을 끌고 오면 목록 응답이 불필요하게 커진다.
     *
     * @var array<int, string>
     */
    private const ICON_ATTACHMENT_RELATION = ['iconAttachment:id,hash,original_filename,mime_type,size'];

    /**
     * {@inheritDoc}
     */
    public function getActive(): Collection
    {
        return ReactionType::query()
            ->with(self::ICON_ATTACHMENT_RELATION)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function all(): Collection
    {
        return ReactionType::query()
            ->with(self::ICON_ATTACHMENT_RELATION)
            ->withCount('reactions')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCode(string $code): ?ReactionType
    {
        return ReactionType::query()
            ->with(self::ICON_ATTACHMENT_RELATION)
            ->where('code', $code)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByCodes(array $codes): Collection
    {
        if (empty($codes)) {
            return ReactionType::query()->whereRaw('1 = 0')->get();
        }

        return ReactionType::query()
            ->with(self::ICON_ATTACHMENT_RELATION)
            ->where('is_active', true)
            ->whereIn('code', $codes)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?ReactionType
    {
        return ReactionType::query()->with(self::ICON_ATTACHMENT_RELATION)->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return ReactionType::query()->whereIn('id', [])->get();
        }

        return ReactionType::query()
            ->with(self::ICON_ATTACHMENT_RELATION)
            ->whereIn('id', $ids)
            ->orderBy('display_order')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ReactionType
    {
        return ReactionType::create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(ReactionType $type, array $data): ReactionType
    {
        $type->update($data);

        return $type;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ReactionType $type): bool
    {
        return (bool) $type->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function clearIconAttachment(int $attachmentId): int
    {
        return ReactionType::query()
            ->where('icon_attachment_id', $attachmentId)
            ->update(['icon_attachment_id' => null]);
    }
}
