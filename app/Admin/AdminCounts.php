<?php

namespace App\Admin;

use App\Enums\DuplicateStatus;
use App\Enums\ReviewStatus;
use App\Models\DuplicateCandidate;
use App\Models\ExternalSource;
use App\Models\IngestionRun;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The figures the admin shell and the Overview share: what is waiting for review, and the size of the catalog and its people. A handful of small
 * queries kept for a minute, so the sidebar's badges cost nothing on most page views.
 */
final class AdminCounts
{
    public const CACHE_KEY = 'admin.dashboard-counts';

    /**
     * @return array{media: int, users: int, admins?: int, duplicates: int, classifications: int, sources: int, oldestDuplicate?: ?string, oldestClassification?: ?string, lastRun: ?array{status: string, at: string}}
     */
    public static function get(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $lastRun = IngestionRun::query()->latest('started_at')->first(['status', 'started_at']);
            $people = User::query()->toBase()->selectRaw('count(*) as total, count(*) filter (where is_admin) as admins')->first();
            $pairs = DuplicateCandidate::query()->where('status', DuplicateStatus::Pending);
            $classes = MediaDeweyClass::query()->where('review_status', ReviewStatus::Pending);

            return [
                'media' => Media::query()->count(),
                'users' => (int) $people->total,
                'admins' => (int) $people->admins,
                'duplicates' => $pairs->count(),
                'classifications' => $classes->count(),
                'oldestDuplicate' => $pairs->min('created_at'),
                'oldestClassification' => $classes->min('created_at'),
                'sources' => ExternalSource::providers()->where('enabled', true)->count(),
                'lastRun' => $lastRun ? ['status' => $lastRun->status->value, 'at' => $lastRun->started_at->diffForHumans()] : null,
            ];
        });
    }
}
