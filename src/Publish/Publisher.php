<?php

declare(strict_types=1);

namespace FreifunkKarte\Publish;

/**
 * A way to get the result files to the web server. Never throws for a delivery that fails: the
 * failure is the result.
 */
interface Publisher
{
    public function name(): string;

    public function publish(ResultFiles $files): PublishResult;
}
