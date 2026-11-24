<?php

namespace App\Ai\Assistant;

/**
 * How one assistant message is answered (P11-R25): the task (what to do, and which tools may be used) and the depth (Quick: the fast model, two
 * tool turns; Thorough: the smart model, four). It is stored on the answer as "{task}:{depth}". Anything unknown, from a client, is the default.
 */
final readonly class AssistantMode
{
    public const TASKS = ['find', 'ask', 'plan'];

    public const DEPTHS = ['quick', 'thorough'];

    /** The tools each task may use; `find` may use them all (get_recommendations still needs the library rules). */
    private const TOOLS = [
        'find' => null,
        'ask' => ['get_story', 'search_catalog'],
        'plan' => ['search_catalog', 'explore_subject', 'get_recommendations'],
    ];

    /** The static block appended after the base system prompt, so the base stays byte-stable and first. */
    private const INSTRUCTIONS = [
        'find' => 'Task: find stories. Search the catalog and suggest a few stories that fit what the reader wants, each with one sentence on why.',
        'ask' => 'Task: answer a question about one story. Use search_catalog to find the story the reader means and get_story to read it. Answer only from what the tools returned; if the catalog does not say, say so. Do not suggest other stories unless the reader asks.',
        'plan' => 'Task: plan the reader\'s reading. Use search_catalog and explore_subject to choose a few stories and put them in a sensible order, for example an easy start and then the next steps, with one line on why each is there. Name only stories the tools returned.',
    ];

    public function __construct(public string $task = 'find', public string $depth = 'quick') {}

    public static function parse(?string $key): self
    {
        [$task, $depth] = array_pad(explode(':', (string) $key, 2), 2, null);

        return new self(
            in_array($task, self::TASKS, true) ? $task : 'find',
            in_array($depth, self::DEPTHS, true) ? $depth : 'quick',
        );
    }

    public function key(): string
    {
        return $this->task.':'.$this->depth;
    }

    /**
     * @return 'fast'|'smart'
     */
    public function model(): string
    {
        return $this->depth === 'thorough' ? 'smart' : 'fast';
    }

    public function maxToolTurns(): int
    {
        return $this->depth === 'thorough' ? 4 : 2;
    }

    public function instruction(): string
    {
        return self::INSTRUCTIONS[$this->task];
    }

    public function allows(string $tool): bool
    {
        $tools = self::TOOLS[$this->task];

        return $tools === null || in_array($tool, $tools, true);
    }
}
