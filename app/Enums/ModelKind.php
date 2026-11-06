<?php

namespace App\Enums;

/**
 * What a `model_versions` row describes. The values are stored (and written by the Python service), so they never change.
 */
enum ModelKind: string
{
    case Lsa = 'lsa';
    case Embedding = 'embedding';
    case ItemCf = 'item_cf';
    case Als = 'als';
    case Ltr = 'ltr';
    case DeweyClassifier = 'dewey_classifier';
    case HybridWeights = 'hybrid_weights';
    case InteractionWeights = 'interaction_weights';
}
