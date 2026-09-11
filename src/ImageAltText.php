<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

use Automattic\SiteBuild\BlockSerializer\Html\HtmlFragment;

/** Remove image request metadata after the collector saves the complete request. */
final class ImageAltText
{
    /** @return array{markup:string,repairs:list<array{index:int,authored:string,delivered:string}>} */
    public static function clean(string $markup): array
    {
        $repairs = [];
        $edits = [];
        if (!str_contains($markup, 'AI_IMAGE:')) {
            return ['markup' => $markup, 'repairs' => []];
        }
        foreach (HtmlFragment::parse($markup)->querySelectorAll('img[alt]') as $index => $image) {
            $alt = trim($image->attribute('alt') ?? '');
            if (!str_starts_with($alt, 'AI_IMAGE:')) {
                continue;
            }
            $attribute = MarkupScan::tagAttribute($image->rawHtml(), 'alt');
            if ($attribute === null) {
                continue;
            }
            $subject = trim(explode('|', substr($alt, strlen('AI_IMAGE:')), 2)[0]);
            $edits[] = [
                'offset' => $image->startOffset() + $attribute[1],
                'length' => strlen($attribute[0]),
                'value' => htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            ];
            $repairs[] = ['index' => $index, 'authored' => $alt, 'delivered' => $subject];
        }
        foreach (array_reverse($edits) as $edit) {
            $markup = substr_replace($markup, $edit['value'], $edit['offset'], $edit['length']);
        }
        return ['markup' => $markup, 'repairs' => $repairs];
    }
}
