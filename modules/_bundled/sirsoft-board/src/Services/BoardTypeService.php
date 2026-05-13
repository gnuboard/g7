<?php

namespace Modules\Sirsoft\Board\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardTypeRepositoryInterface;

class BoardTypeService
{
    public function __construct(
        protected BoardTypeRepositoryInterface $boardTypeRepository,
        protected BoardRepositoryInterface $boardRepository,
        protected BoardSettingsService $boardSettingsService
    ) {}

    /**
     * @return Collection<int, BoardType>
     */
    public function getBoardTypes(): Collection
    {
        return $this->boardTypeRepository->getAll();
    }

    /**
     * @param array $data
     * @return BoardType
     */
    public function createBoardType(array $data): BoardType
    {
        HookManager::doAction('sirsoft-board.board_type.before_create', $data);

        $data = HookManager::applyFilters('sirsoft-board.board_type.filter_create_data', $data);

        $boardType = $this->boardTypeRepository->create($data);

        HookManager::doAction('sirsoft-board.board_type.after_create', $boardType, $data);

        Log::info('게시판 유형 생성', ['id' => $boardType->id, 'slug' => $boardType->slug]);

        return $boardType;
    }

    /**
     * @param int $id
     * @param array $data
     * @return BoardType
     */
    public function updateBoardType(int $id, array $data): BoardType
    {
        $boardType = $this->boardTypeRepository->findById($id);

        if (! $boardType) {
            throw (new ModelNotFoundException())->setModel(BoardType::class, $id);
        }

        HookManager::doAction('sirsoft-board.board_type.before_update', $boardType, $data);

        $snapshot = $boardType->toArray();

        $data = HookManager::applyFilters('sirsoft-board.board_type.filter_update_data', $data, $boardType);

        $updatedBoardType = $this->boardTypeRepository->update($id, $data);

        HookManager::doAction('sirsoft-board.board_type.after_update', $updatedBoardType, $data, $snapshot);

        Log::info('게시판 유형 수정', ['id' => $updatedBoardType->id, 'slug' => $updatedBoardType->slug]);

        return $updatedBoardType;
    }

    /**
     * @param int $id
     * @return bool
     *
     * @throws \Exception 사용 중인 유형 삭제 시도 시
     */
    public function deleteBoardType(int $id): bool
    {
        $boardType = $this->boardTypeRepository->findById($id);

        if (! $boardType) {
            throw (new ModelNotFoundException())->setModel(BoardType::class, $id);
        }

        $usageCount = $this->boardRepository->countByType($boardType->slug);
        if ($usageCount > 0) {
            throw new \Exception(
                __('sirsoft-board::messages.board_type.delete_in_use', [
                    'count' => $usageCount,
                ])
            );
        }

        $defaultType = $this->boardSettingsService->getSetting('basic_defaults.type');
        if ($defaultType === $boardType->slug) {
            throw new \Exception(
                __('sirsoft-board::messages.board_type.delete_is_default')
            );
        }

        HookManager::doAction('sirsoft-board.board_type.before_delete', $boardType);

        $result = $this->boardTypeRepository->delete($id);

        HookManager::doAction('sirsoft-board.board_type.after_delete', $boardType);

        Log::info('게시판 유형 삭제', ['id' => $boardType->id, 'slug' => $boardType->slug]);

        return $result;
    }
}
