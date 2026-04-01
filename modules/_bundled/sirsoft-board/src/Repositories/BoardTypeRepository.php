<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardTypeRepositoryInterface;

class BoardTypeRepository implements BoardTypeRepositoryInterface
{
    /**
     * @return Collection<int, BoardType>
     */
    public function getAll(): Collection
    {
        return BoardType::orderBy('id')->get();
    }

    /**
     * @param int $id
     * @return BoardType|null
     */
    public function findById(int $id): ?BoardType
    {
        return BoardType::find($id);
    }

    /**
     * @param string $slug
     * @return BoardType|null
     */
    public function findBySlug(string $slug): ?BoardType
    {
        return BoardType::where('slug', $slug)->first();
    }

    /**
     * @param array $data
     * @return BoardType
     */
    public function create(array $data): BoardType
    {
        return BoardType::create($data);
    }

    /**
     * @param int $id
     * @param array $data
     * @return BoardType
     */
    public function update(int $id, array $data): BoardType
    {
        $boardType = BoardType::findOrFail($id);
        $boardType->update($data);

        return $boardType->fresh();
    }

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $boardType = BoardType::findOrFail($id);

        return $boardType->delete();
    }
}
