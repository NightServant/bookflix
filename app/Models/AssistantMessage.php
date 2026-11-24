<?php

namespace App\Models;

use Database\Factories\AssistantMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of a saved chat: plain text, and for an answer the ids of the verified catalog stories it came with.
 *
 * @property int $id
 * @property string $assistant_conversation_id
 * @property string $role
 * @property string $text
 * @property list<int> $story_ids
 * @property string|null $mode
 * @property bool $used_library
 * @property bool $fixed
 * @property list<string> $attachment_names
 * @property Carbon|null $created_at
 */
#[Fillable(['assistant_conversation_id', 'role', 'text', 'story_ids', 'mode', 'used_library', 'fixed', 'attachment_names'])]
class AssistantMessage extends Model
{
    /** @use HasFactory<AssistantMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'story_ids' => 'array',
            'attachment_names' => 'array',
            'used_library' => 'boolean',
            'fixed' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }
}
