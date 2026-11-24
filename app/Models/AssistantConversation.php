<?php

namespace App\Models;

use App\Ai\Assistant\AssistantReply;
use Database\Factories\AssistantConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One saved assistant chat, private to its owner (P11-R23): every read and write goes through `$user->assistantConversations()`, so another
 * reader's id is simply not found. The model only ever sees the last ten of its messages (`history`).
 *
 * @property string $id
 * @property int $user_id
 * @property string $title
 * @property bool $use_library
 * @property Carbon|null $archived_at
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'title', 'use_library', 'archived_at', 'last_message_at'])]
class AssistantConversation extends Model
{
    /** @use HasFactory<AssistantConversationFactory> */
    use HasFactory, HasUlids;

    /** The most past messages sent back to the model. */
    public const HISTORY_LIMIT = 10;

    /** The longest title a chat is given from its first message. */
    public const TITLE_FROM_MESSAGE = 60;

    /** The longest title a reader can rename it to. */
    public const TITLE_MAX = 120;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'use_library' => 'boolean',
            'archived_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * The title a new chat gets: its first message on one line, cut to 60 characters (no model call).
     */
    public static function titleFor(string $message): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        return mb_substr($line === '' ? 'New chat' : $line, 0, self::TITLE_FROM_MESSAGE);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AssistantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * What the model sees of this chat: the last ten messages that are not fixed app lines, oldest first. Messages from turns that used the
     * library are left out while the library is not allowed, so switching it off cuts the library titles out of what the provider receives (P11-R19).
     *
     * @return list<array{role: 'user'|'assistant', text: string}>
     */
    public function history(bool $libraryAllowed): array
    {
        $messages = AssistantMessage::query()
            ->where('assistant_conversation_id', $this->id)
            ->where('fixed', false)
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['role', 'text', 'used_library'])
            ->reverse()
            ->filter(fn (AssistantMessage $message): bool => $libraryAllowed || ! $message->used_library)
            ->map(fn (AssistantMessage $message): array => ['role' => $message->role === 'assistant' ? 'assistant' : 'user', 'text' => $message->text])
            ->all();

        return array_values($messages);
    }

    /**
     * Save one exchange: the reader's message and the reply. A fixed line (see AssistantReply::$fixed) is kept for the page but flagged, with its
     * question, so it is never sent back to the model.
     */
    public function record(string $message, AssistantReply $reply): void
    {
        DB::transaction(function () use ($message, $reply): void {
            $shared = ['used_library' => $reply->usedLibrary, 'fixed' => $reply->fixed];

            $this->messages()->create(['role' => 'user', 'text' => $message, ...$shared]);
            $this->messages()->create(['role' => 'assistant', 'text' => $reply->text, 'story_ids' => $reply->stories->pluck('id')->all(), ...$shared]);
            $this->forceFill(['last_message_at' => now()])->save();
        });
    }

    /**
     * The OpenAI `user` field: a keyed hash of this chat's id, never an email, name or user id.
     */
    public function hashedUser(): string
    {
        return hash_hmac('sha256', $this->id, (string) config('app.key'));
    }
}
