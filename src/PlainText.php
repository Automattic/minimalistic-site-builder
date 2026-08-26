<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Markup to plain text. One decoder so passes that read the same markup agree
 * on what its text is. Callers that need trimming trim their own result.
 */
final class PlainText
{
    public static function fromMarkup(string $markup): string
    {
        return html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
