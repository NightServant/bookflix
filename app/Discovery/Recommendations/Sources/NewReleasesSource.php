<?php

namespace App\Discovery\Recommendations\Sources;

use App\Discovery\Recommendations\Candidate;
use App\Enums\ReasonType;
use App\Models\Media;
use App\Models\MediaType;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Collection;

/**
 * Titles released in the last 90 days, newest scoring highest. A reader who chose media types or languages sees only those; one who chose
 * none sees every release.
 */
class NewReleasesSource extends Source
{
    private const DAYS = 90;

    public function key(): string
    {
        return 'new_releases';
    }

    protected function gather(User $user, int $limit): Collection
    {
        $types = array_values(MediaType::query()
            ->whereIn('id', UserPreference::query()->where('user_id', $user->id)->where('preferable_type', 'media_type')->select('preferable_id'))
            ->pluck('slug')->map(fn ($slug): string => (string) $slug)->all());
        $languages = $user->profile->preferred_languages ?? [];

        $today = today();

        return $this->eligible($user)
            ->whereBetween('media.published_on', [$today->copy()->subDays(self::DAYS), $today])
            ->when($types !== [], fn ($query) => $query->ofTypes($types))
            ->when($languages !== [], fn ($query) => $query->whereIn('media.language', $languages))
            ->orderByDesc('media.published_on')->orderByDesc('media.id')
            ->limit($limit)
            ->get(['media.id', 'media.published_on'])
            ->map(fn (Media $media): Candidate => new Candidate(
                $media->id,
                $this->key(),
                max(0.0, 1 - $media->published_on->diffInDays($today, true) / self::DAYS),
                ReasonType::NewRelease,
            ))->values();
    }
}
