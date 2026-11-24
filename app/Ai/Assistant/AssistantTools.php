<?php

namespace App\Ai\Assistant;

use App\Ai\LlmToolCall;
use App\Classification\Dewey\DeweyHierarchy;
use App\Discovery\Recommendations\ShelfBuilder;
use App\Discovery\Search\HybridSearch;
use App\Discovery\Search\QueryVocabulary;
use App\Discovery\Search\SearchFilters;
use App\Models\AssistantConversation;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The read-only tools the assistant may call, and their execution. Every argument is validated here, strictly: an unknown key, a wrong type or an
 * out-of-range value is a tool error the model reads, never something the app acts on. A tool only reads the catalogue as the signed-in reader sees it
 * (mature gate included); none writes, mails, fetches a URL or reads another reader's data. Results are JSON inside a `<data>` block with a few catalogue fields.
 */
class AssistantTools
{
    public const MAX_QUERY = HybridSearch::MAX_QUERY_LENGTH;

    public const MAX_LIMIT = 10;

    public const DEFAULT_LIMIT = 5;

    /** The most values one filter list takes. */
    private const MAX_FILTER_VALUES = 10;

    /** The shelves get_recommendations can read: ShelfBuilder's candidate shelves, in the order the first one that exists is the default. */
    public const SHELVES = ['recommended', 'because-you-read', 'similar-to', 'trending', 'new-releases', 'popular-in-genres', 'discover-new'];

    /** The longest description excerpt, in characters. */
    private const EXCERPT = 300;

    /** The filters search_catalog takes, and the vocabulary kind each draws from. */
    private const FILTER_KINDS = ['mediaTypes' => 'type', 'genres' => 'genre', 'themes' => 'theme'];

    /** The shape the app's Dewey code route accepts. */
    private const DEWEY_CODE = '/^[0-9]{3}(\.[0-9]{1,6})?$/';

    public function __construct(
        private HybridSearch $search,
        private QueryVocabulary $vocabulary,
        private ShelfBuilder $shelves,
        private DeweyHierarchy $dewey,
    ) {}

    /**
     * The OpenAI function definitions for this conversation; get_recommendations only when the reader allows the library and personalization is on.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(User $user, AssistantConversation $conversation): array
    {
        $vocabulary = $this->vocabulary->slugs($user);
        $list = fn (string $kind): array => ['type' => 'array', 'maxItems' => self::MAX_FILTER_VALUES, 'items' => ['type' => 'string', 'enum' => $vocabulary[$kind]]];

        $tools = [
            $this->definition('search_catalog', 'Search the Bookflix catalog for stories. Optional filters narrow by media type, genre and theme.', [
                'query' => ['type' => 'string', 'maxLength' => self::MAX_QUERY, 'description' => 'What the reader is looking for, in plain words.'],
                'filters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'mediaTypes' => $list('type'),
                    'genres' => $list('genre'),
                    'themes' => $list('theme'),
                ]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT],
            ], ['query']),
            $this->definition('get_story', 'Look at one story in the catalog by its id.', [
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['id']),
            $this->definition('explore_subject', 'List stories shelved under a Dewey subject code, for example 813 or 741.5.', [
                'code' => ['type' => 'string', 'pattern' => trim(self::DEWEY_CODE, '/')],
            ], ['code']),
        ];

        if ($this->libraryAllowed($user, $conversation)) {
            $tools[] = $this->definition('get_recommendations', "Read this reader's own recommendation shelf. Only offered because the reader switched on \"Use my library\".", [
                'shelf' => ['type' => 'string', 'enum' => self::SHELVES],
            ], []);
        }

        return $tools;
    }

    public function execute(User $user, AssistantConversation $conversation, LlmToolCall $call): ToolResult
    {
        if ($call->arguments === null) {
            return ToolResult::error('The arguments must be a JSON object.');
        }

        try {
            return match ($call->name) {
                'search_catalog' => $this->searchCatalog($user, $call->arguments),
                'get_story' => $this->getStory($user, $call->arguments),
                'explore_subject' => $this->exploreSubject($user, $call->arguments),
                'get_recommendations' => $this->libraryAllowed($user, $conversation)
                    ? $this->getRecommendations($user, $call->arguments)
                    : ToolResult::error('Unknown tool.'),
                default => ToolResult::error('Unknown tool.'),
            };
        } catch (InvalidToolArguments $e) {
            return ToolResult::error($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return ToolResult::error('The tool failed. Try again or answer without it.');
        }
    }

    private function libraryAllowed(User $user, AssistantConversation $conversation): bool
    {
        return $conversation->use_library && $user->personalizationEnabled();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function searchCatalog(User $user, array $arguments): ToolResult
    {
        $this->only($arguments, ['query', 'filters', 'limit']);

        $query = $arguments['query'] ?? null;

        if (! is_string($query) || trim($query) === '' || mb_strlen($query) > self::MAX_QUERY) {
            throw new InvalidToolArguments('query must be a non-empty string of at most '.self::MAX_QUERY.' characters.');
        }

        $limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;

        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidToolArguments('limit must be an integer from 1 to '.self::MAX_LIMIT.'.');
        }

        $filters = $this->filters($user, $arguments['filters'] ?? []);
        $results = $this->search->search(trim($query), $filters, $user, $limit, page: 1);

        return $this->result($results->items(), $user, isSearch: true);
    }

    /**
     * @return SearchFilters only the vocabulary values the reader may be offered
     */
    private function filters(User $user, mixed $input): SearchFilters
    {
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new InvalidToolArguments('filters must be an object.');
        }

        $this->only($input, array_keys(self::FILTER_KINDS), 'filters.');
        $known = $this->vocabulary->slugs($user);
        $clean = [];

        foreach ($input as $key => $values) {
            if (! is_array($values) || ! array_is_list($values) || count($values) > self::MAX_FILTER_VALUES) {
                throw new InvalidToolArguments("filters.{$key} must be a list of at most ".self::MAX_FILTER_VALUES.' values.');
            }

            foreach ($values as $value) {
                if (! is_string($value) || ! in_array($value, $known[self::FILTER_KINDS[$key]], true)) {
                    throw new InvalidToolArguments("filters.{$key} has a value that is not in the catalog vocabulary.");
                }
            }

            $clean[$key] = $values;
        }

        return new SearchFilters(...$clean);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function getStory(User $user, array $arguments): ToolResult
    {
        $this->only($arguments, ['id']);

        $id = $arguments['id'] ?? null;

        if (! is_int($id) || $id < 1) {
            throw new InvalidToolArguments('id must be a positive integer.');
        }

        $story = Media::query()->unmerged()->visibleTo($user)->find($id);

        return $story === null ? ToolResult::error('No such story.') : $this->result([$story], $user);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function exploreSubject(User $user, array $arguments): ToolResult
    {
        $this->only($arguments, ['code']);

        $code = $arguments['code'] ?? null;

        if (! is_string($code) || preg_match(self::DEWEY_CODE, $code) !== 1) {
            throw new InvalidToolArguments('code must be a Dewey code such as 813 or 741.5.');
        }

        $class = $this->dewey->findByCode($code);

        if ($class === null) {
            return ToolResult::error('No such subject.');
        }

        $stories = Media::query()->unmerged()->visibleTo($user)->inDeweySubtree($class)
            ->orderByDesc('media.ratings_count')->orderBy('media.title')->orderBy('media.id')
            ->limit(self::MAX_LIMIT)->get();

        return $this->result($stories->all(), $user, subject: ['code' => $class->code, 'name' => $this->clean((string) $class->name, 100)]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function getRecommendations(User $user, array $arguments): ToolResult
    {
        $this->only($arguments, ['shelf']);

        $shelf = $arguments['shelf'] ?? null;

        if ($shelf !== null && (! is_string($shelf) || ! in_array($shelf, self::SHELVES, true))) {
            throw new InvalidToolArguments('shelf must be one of: '.implode(', ', self::SHELVES).'.');
        }

        // Only the signed-in reader's own shelves; the default is the first one this reader has.
        $built = $this->shelves->forHome($user, log: false)->keyBy('key');
        $found = $shelf === null ? $built->first(fn ($candidate): bool => in_array($candidate->key, self::SHELVES, true)) : $built->get($shelf);
        $stories = $found === null ? [] : $found->items->take(self::MAX_LIMIT)->filter(fn ($item): bool => $item instanceof Media)->values()->all();

        return $this->result($stories, $user, library: true);
    }

    /**
     * @param  iterable<int, Media>  $stories
     * @param  array{code: string, name: string}|null  $subject
     */
    private function result(iterable $stories, User $user, bool $isSearch = false, bool $library = false, ?array $subject = null): ToolResult
    {
        $media = (new EloquentCollection(collect($stories)->values()->all()))->load(['mediaType', 'creators', 'genres']);
        $data = ['stories' => $media->map(fn (Media $story): array => $this->format($story))->values()->all()];

        if ($subject !== null) {
            $data['subject'] = $subject;
        }

        return new ToolResult(
            '<data>'.json_encode($data, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).'</data>',
            new Collection($media->all()),
            isSearch: $isSearch,
            usedLibrary: $library,
        );
    }

    /**
     * @return array<string, mixed> id, title, creators, type, year, genres and description
     */
    private function format(Media $story): array
    {
        return [
            'id' => $story->id,
            'title' => $this->clean($story->title, 200),
            'creators' => $story->creators->sortBy('pivot.position')->take(3)->map(fn ($creator): string => $this->clean((string) $creator->name, 100))->values()->all(),
            'type' => $story->mediaType?->slug,
            'year' => $story->publication_year,
            'genres' => $story->genres->take(5)->pluck('slug')->values()->all(),
            'description' => $this->clean((string) $story->description, self::EXCERPT),
        ];
    }

    /**
     * Plain text: HTML and entities stripped, control characters and runs of whitespace collapsed, cut to `$max` characters.
     */
    private function clean(string $text, int $max): string
    {
        $text = strip_tags(html_entity_decode(strip_tags(mb_scrub($text)), ENT_QUOTES | ENT_HTML5));
        $text = preg_replace(['/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', '/\s+/u'], ' ', $text) ?? '';

        return mb_substr(trim($text), 0, $max);
    }

    /**
     * @param  array<mixed>  $arguments
     * @param  list<string>  $allowed
     */
    private function only(array $arguments, array $allowed, string $prefix = ''): void
    {
        $unknown = array_diff(array_map('strval', array_keys($arguments)), $allowed);

        if ($unknown !== []) {
            throw new InvalidToolArguments('Unknown argument: '.$prefix.mb_substr((string) reset($unknown), 0, 40).'.');
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function definition(string $name, string $description, array $properties, array $required): array
    {
        return ['type' => 'function', 'function' => [
            'name' => $name,
            'description' => $description,
            'parameters' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required],
        ]];
    }
}
