<?php

declare(strict_types=1);

namespace PhpSsrReact\Attributes;

use Attribute;

/**
 * Define page title for Props class
 *
 * @example #[Title('게시판 상세')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Title
{
    public function __construct(
        public string $title
    ) {}
}
