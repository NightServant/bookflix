<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;

/**
 * Reader-written Markdown as HTML that is safe to print unescaped (P10-R8). Raw HTML is stripped and unsafe link schemes are refused by the parser's
 * own settings; on top of that the document is narrowed: images become their alt text (nothing loads from another site), headings become paragraphs,
 * and a link survives only with an http, https or mailto address, marked nofollow ugc.
 */
class Markdown
{
    private const SCHEMES = ['http', 'https', 'mailto'];

    public static function safe(string $markdown): HtmlString
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addEventListener(DocumentParsedEvent::class, static function (DocumentParsedEvent $event): void {
            self::narrow($event->getDocument());
        });

        return new HtmlString(trim((new MarkdownConverter($environment))->convert($markdown)->getContent()));
    }

    private static function narrow(Node $document): void
    {
        // Collected first: the tree is edited while it is walked.
        $nodes = [];

        foreach ($document->iterator() as $node) {
            $nodes[] = $node;
        }

        foreach ($nodes as $node) {
            if ($node instanceof Image) {
                $node->replaceWith(new Text(self::plainText($node)));
            } elseif ($node instanceof Heading) {
                $paragraph = new Paragraph;

                foreach ([...$node->children()] as $child) {
                    $paragraph->appendChild($child);
                }

                $node->replaceWith($paragraph);
            } elseif ($node instanceof Link) {
                if (self::allowed($node->getUrl())) {
                    $node->data->set('attributes/rel', 'nofollow ugc noopener noreferrer');

                    continue;
                }

                foreach ([...$node->children()] as $child) {
                    $node->insertBefore($child);
                }

                $node->detach();
            }
        }
    }

    private static function allowed(string $url): bool
    {
        return preg_match('/^(?:'.implode('|', self::SCHEMES).'):/i', $url) === 1;
    }

    private static function plainText(Node $node): string
    {
        $text = '';

        foreach ([...$node->children()] as $child) {
            $text .= $child instanceof StringContainerInterface ? $child->getLiteral() : self::plainText($child);
        }

        return $text;
    }
}
