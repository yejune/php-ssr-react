<?php

declare(strict_types=1);

namespace PhpSsrReact\Attributes;

use Attribute;

/**
 * Define React component page for Props class
 *
 * @example #[Page('modules/board/Detail.tsx')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Page
{
    public function __construct(
        public string $component
    ) {}
}
