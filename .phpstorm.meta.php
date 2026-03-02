<?php
/**
 * PhpStorm meta file — provides IDE type hints for WordPress globals
 * so IDE warnings about "Call to unknown function" for WP functions are suppressed.
 *
 * This file is NEVER loaded at runtime. It is read only by JetBrains IDEs.
 * For VS Code / Intelephense, the wordpress-stubs Composer package
 * (php-stubs/wordpress-stubs) provides the same declarations.
 *
 * @see https://www.jetbrains.com/help/phpstorm/ide-advanced-metadata.html
 */

namespace PHPSTORM_META {
    // Tell the IDE that get_option() returns a mixed value.
    override(\get_option(0, 1), type(1));
    override(\get_post_meta(0, 1, 0), type(0));
}
