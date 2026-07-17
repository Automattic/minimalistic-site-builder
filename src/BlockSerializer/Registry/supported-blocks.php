<?php
declare(strict_types=1);

use Automattic\SiteBuild\BlockSerializer\Save\SaveStrategy;

/*
 * The bounded runtime domain. Every registered entry has an explicit save
 * strategy; unregistered names use the separately tested missing-block path.
 */
return [
    'core/paragraph' => SaveStrategy::STATIC_RENDERER,
    'core/group' => SaveStrategy::STATIC_RENDERER,
    'core/column' => SaveStrategy::STATIC_RENDERER,
    'core/columns' => SaveStrategy::STATIC_RENDERER,
    'core/heading' => SaveStrategy::STATIC_RENDERER,
    'core/image' => SaveStrategy::STATIC_RENDERER,
    'core/separator' => SaveStrategy::STATIC_RENDERER,
    'core/buttons' => SaveStrategy::STATIC_RENDERER,
    'core/button' => SaveStrategy::STATIC_RENDERER,
    'core/spacer' => SaveStrategy::STATIC_RENDERER,
    'core/list' => SaveStrategy::STATIC_RENDERER,
    'core/list-item' => SaveStrategy::STATIC_RENDERER,
    'core/cover' => SaveStrategy::STATIC_RENDERER,
    'core/media-text' => SaveStrategy::STATIC_RENDERER,
    'core/quote' => SaveStrategy::STATIC_RENDERER,
    'core/pullquote' => SaveStrategy::STATIC_RENDERER,
    'core/gallery' => SaveStrategy::STATIC_RENDERER,
    'core/table' => SaveStrategy::STATIC_RENDERER,
    'core/embed' => SaveStrategy::STATIC_RENDERER,
    'core/html' => SaveStrategy::RAW_CONTENT,
    'core/navigation' => SaveStrategy::CONDITIONAL,
    'core/navigation-link' => SaveStrategy::INNER_BLOCKS,
    'core/social-links' => SaveStrategy::STATIC_RENDERER,
    'core/social-link' => SaveStrategy::DYNAMIC_NULL,

    // save() === null in the pinned registered runtime.
    'core/page-list' => SaveStrategy::DYNAMIC_NULL,
    'core/post-content' => SaveStrategy::DYNAMIC_NULL,
    'core/site-logo' => SaveStrategy::DYNAMIC_NULL,
    'core/site-tagline' => SaveStrategy::DYNAMIC_NULL,
    'core/site-title' => SaveStrategy::DYNAMIC_NULL,
    'core/template-part' => SaveStrategy::DYNAMIC_NULL,
];
