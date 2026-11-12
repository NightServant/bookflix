<?php

namespace App\Classification\Dewey;

use App\Classification\ClassificationCandidate;
use App\Ml\MlClient;
use App\Ml\MlServiceUnavailable;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The ML service's Dewey classifier, as suggestions next to the rules. Its confidence is capped below the public threshold
 * (`classifier_max_confidence`), so a guess of its own never shows publicly until a reviewer accepts it. Codes the seeded hierarchy lacks are
 * dropped, and so is a literature class wherever the rules would not guess one (DeweyMapper::allowsLiterature, the #28 rule).
 *
 * It waits for the service, so it only asks from the console (a queued job or a command): in a web request it answers nothing and the rules
 * classify alone. Without a configured service or an active classifier version (none is trained before reviewers have accepted enough
 * primaries) it answers nothing and asks nothing; any failure is one warning and nothing.
 */
class DeweyClassifier
{
    public function __construct(private MlClient $client, private DeweyMapper $mapper) {}

    /**
     * @return Collection<int, ClassificationCandidate>
     */
    public function predict(Media $media): Collection
    {
        if (! app()->runningInConsole() || (string) config('services.ml.token') === '' || ModelVersion::activeFor('dewey-classifier') === null) {
            return new Collection;
        }

        try {
            $candidates = collect($this->client->classifyDewey($media));
        } catch (MlServiceUnavailable $exception) {
            Log::warning('Dewey classifier unavailable; classifying by rules only.', ['media_id' => $media->id, 'error' => $exception->getMessage()]);

            return new Collection;
        }

        $cap = (float) config('bookflix.classification.classifier_max_confidence');
        $known = DeweyClass::whereIn('code', $candidates->pluck('code')->all())->pluck('code')->all();
        $literature = $this->mapper->allowsLiterature($media);

        return $candidates
            ->filter(fn (ClassificationCandidate $candidate): bool => in_array($candidate->code, $known, true) && ($literature || ! str_starts_with($candidate->code, '8')))
            ->map(fn (ClassificationCandidate $candidate): ClassificationCandidate => new ClassificationCandidate($candidate->code, min($cap, $candidate->confidence), $candidate->method, $candidate->source, $candidate->modelVersionId))
            ->values();
    }
}
