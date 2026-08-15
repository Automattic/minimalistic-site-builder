<?php
declare(strict_types=1);

namespace Automattic\SiteBuild;

/**
 * Pure contract for resolving internal links inside authored navigation markup.
 *
 * Implementations perform no Project or filesystem I/O. Callers supply the
 * delivered page paths and anchors, then persist returned markup, repair rows,
 * and warning rows.
 *
 * Resolution is deterministic:
 * - Existing valid site-page paths and non-site destinations stay unchanged;
 *   trailing slashes do not affect page-path validity.
 * - One title/slug phrase match selects that page. The front page resolves to
 *   its root; another page retains only a fragment that exists on that page.
 * - Multiple plausible title/slug matches preserve the authored destination
 *   and return a warning naming every candidate rather than guessing.
 * - An unmatched shared-nav link that shares a resolved front-page link's
 *   authored destination inherits the root (for example, a brand link).
 * - Without a label match, a fragment resolves only when it has one owning
 *   page. Shared chrome roots it to that page; page-owned content may keep a
 *   bare fragment only when the current page owns it.
 * - An unresolved internal link is unwrapped. Its child bytes and every
 *   sibling byte remain unchanged.
 *
 * Every changed link returns one five-field context row. Successful rewrites
 * are repairs. Unwrapped links are warnings with delivered="removed".
 */
interface NavLinkResolver
{
    /**
     * @param list<array{label:string,path:string,anchors:list<string>}> $pages
     *        Normalized site pages in stable site-spec order. Paths are the
     *        verbatim public paths. Anchors omit the leading "#".
     * @param string      $file            Project-relative delivered file path.
     * @param string|null $currentPagePath Owning page path for page content;
     *                                     null means shared chrome.
     * @return array{
     *     markup:string,
     *     repairs:list<array{
     *         file:string,
     *         block:string,
     *         authored:string,
     *         delivered:string,
     *         disposition:string
     *     }>,
     *     warnings:list<array{
     *         file:string,
     *         block:string,
     *         authored:string,
     *         delivered:string,
     *         disposition:string
     *     }>
     * }
     */
    public function resolve(
        string $markup,
        array $pages,
        string $file,
        ?string $currentPagePath,
    ): array;
}
