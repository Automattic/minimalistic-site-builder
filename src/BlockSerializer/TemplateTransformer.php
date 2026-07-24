<?php
declare(strict_types=1);

namespace Automattic\SiteBuild\BlockSerializer;

interface TemplateTransformer
{
    public function transform(string $html): TransformResult;
}
