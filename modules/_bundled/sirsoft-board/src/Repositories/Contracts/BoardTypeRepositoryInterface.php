<?php

namespace Modules\Sirsoft\Board\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Models\BoardType;

interface BoardTypeRepositoryInterface
{
    /**
     * @return Collection<int, BoardType>
     */
    public function getAll(): Collection;

    /**
     * @param int $id
     * @return BoardType|null
     */
    public function findById(int $id): ?BoardType;

    /**
     * @param string $slug
     * @return BoardType|null
     */
    public function findBySlug(string $slug): ?BoardType;

    /**
     * @param array $data
     * @return BoardType
     */
    public function create(array $data): BoardType;

    /**
     * @param int $id
     * @param array $data
     * @return BoardType
     */
    public function update(int $id, array $data): BoardType;

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;
}
