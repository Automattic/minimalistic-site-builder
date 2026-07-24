<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer\Save;

/** Closed save behavior used by every admitted registered block. */
enum SaveStrategy: string
{
    case DYNAMIC_NULL = 'dynamic-null';
    case INNER_BLOCKS = 'inner-blocks';
    case STATIC_RENDERER = 'static-renderer';
    case CONDITIONAL = 'conditional';
    case RAW_CONTENT = 'raw-content';
    case MISSING_BLOCK = 'missing-block';
}
