<?php

namespace App\Http\Controllers;

use App\Discovery\Recommendations\InteractionRecorder;
use App\Discovery\Recommendations\RecommendationLogger;
use App\Enums\InteractionEvent;
use App\Enums\RecommendationEventType;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What Home reports back about the tiles it showed, sent by the page in the background (a beacon or fetch, never a link parameter: Phase 4 R11).
 * Every route answers 204 whatever the id: one that is not the reader's, or that belongs to a reader who opted out, is ignored without saying so.
 */
class RecommendationFeedbackController extends Controller
{
    /** The most tiles one impressions request may name. */
    private const MAX_IMPRESSIONS = 100;

    public function click(Request $request, RecommendationLogger $logger, InteractionRecorder $interactions): Response
    {
        $data = $request->validate(['recommendation_id' => ['required', 'integer', 'min:1']]);
        $user = $request->user();

        $recommendation = $logger->click($user, (int) $data['recommendation_id']);

        if ($recommendation !== null) {
            $interactions->record($user, InteractionEvent::RecommendationClick, $recommendation->media, [
                'surface' => 'home',
                'shelf' => $recommendation->shelf,
                'recommendation_id' => $recommendation->id,
            ]);
        }

        return response()->noContent();
    }

    public function impressions(Request $request, RecommendationLogger $logger): Response
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.self::MAX_IMPRESSIONS], 'ids.*' => ['integer', 'min:1']]);

        $logger->impressions($request->user(), array_values(array_map('intval', $data['ids'])));

        return response()->noContent();
    }

    public function dismiss(Request $request, RecommendationLogger $logger, int $recommendation): Response
    {
        $logger->event($request->user(), $recommendation, RecommendationEventType::Dismiss);

        return response()->noContent();
    }

    public function undo(Request $request, RecommendationLogger $logger, int $recommendation): Response
    {
        $logger->undoDismiss($request->user(), $recommendation);

        return response()->noContent();
    }
}
