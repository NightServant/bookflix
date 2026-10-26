<?php

namespace App\Models;

use Database\Factories\DeweyClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int|null $parent_id
 * @property int $level Depth in the tree; a main class is level 1.
 * @property string $path Codes from the root down, e.g. 800/810/813.
 * @property int $stories_count Only on classes from DeweyCounts::rootsWithCounts(): the stories in the subtree.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['code', 'name', 'description', 'parent_id', 'level', 'path'])]
class DeweyClass extends Model
{
    /** @use HasFactory<DeweyClassFactory> */
    use HasFactory;

    /**
     * The class itself and every class below it.
     *
     * @param  Builder<DeweyClass>  $query
     */
    public function scopeSubtree(Builder $query, DeweyClass $class): void
    {
        $query->where(fn (Builder $inner) => $inner->where('id', $class->id)->orWhere('path', 'like', "{$class->path}/%"));
    }
}
