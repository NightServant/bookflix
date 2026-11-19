<?php

namespace App\Actions\Library;

use App\Models\Collection;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a staff pick (P10-R3): an editorial collection an admin makes, its name, description, whether readers see it, and its stories in
 * order. Only stories every reader may see (Media::visibleTo(null): not mature, not merged away) are kept, so a staff pick never shows a reader
 * something behind their gate. A new pick belongs to the admin who makes it; any admin may edit any pick. Admin only.
 */
class SaveStaffPick
{
    public function __construct(private readonly CreateCollection $create) {}

    /**
     * @param  list<int>  $mediaIds  the stories, in the order they show
     *
     * @throws ValidationException
     */
    public function handle(User $admin, ?Collection $pick, string $name, ?string $description, bool $shown, array $mediaIds): Collection
    {
        Gate::forUser($admin)->authorize('access-admin');

        $name = trim($name);
        $description = trim((string) $description) ?: null;

        Validator::make(
            ['name' => $name, 'description' => $description],
            ['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500']],
        )->validate();

        if ($pick !== null && ! $pick->loadMissing('user')->user?->is_admin) {
            throw ValidationException::withMessages(['pick' => __('Only staff picks can be edited here.')]);
        }

        $ids = array_values(array_unique(array_map('intval', $mediaIds)));
        $limit = (int) config('bookflix.collections.max_items');

        if (count($ids) > $limit) {
            throw ValidationException::withMessages(['stories' => trans_choice('A collection holds at most :count story.|A collection holds at most :count stories.', $limit)]);
        }

        $allowed = array_flip(Media::query()->unmerged()->visibleTo(null)->whereIn('id', $ids)->pluck('id')->all());
        $ids = array_values(array_filter($ids, fn (int $id): bool => isset($allowed[$id])));

        return DB::transaction(function () use ($admin, $pick, $name, $description, $shown, $ids): Collection {
            $pick ??= $this->create->handle($admin, $name, $description);

            $pick->fill(['name' => $name, 'description' => $description])->forceFill(['is_editorial' => $shown])->save();

            $pick->items()->delete();
            $pick->items()->createMany(array_map(fn (int $id, int $position): array => ['media_id' => $id, 'position' => $position], $ids, array_keys($ids)));
            $pick->touch();

            return $pick;
        });
    }
}
