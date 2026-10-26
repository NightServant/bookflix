<?php

namespace Database\Factories;

use App\Enums\ClassificationMethod;
use App\Enums\ClassificationType;
use App\Enums\ReviewStatus;
use App\Models\DeweyClass;
use App\Models\Media;
use App\Models\MediaDeweyClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaDeweyClass>
 */
class MediaDeweyClassFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'dewey_class_id' => DeweyClass::factory(),
            'classification_type' => ClassificationType::Secondary,
            'confidence' => 0.9,
            'source' => 'rules',
            'classification_method' => ClassificationMethod::Automatic,
            'review_status' => ReviewStatus::Pending,
        ];
    }
}
