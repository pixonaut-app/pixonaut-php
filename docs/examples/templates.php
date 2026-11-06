<?php

declare(strict_types=1);

// Lists the store's templates, then reads the first one.

use Pixonaut\Sdk\Generated\Model\TemplatesListResponseOption1;
use Pixonaut\Sdk\Pixonaut;

/** @var Pixonaut $pixonaut */
$pixonaut = require __DIR__ . '/client.php';

// A key's list is paged: `items`, `page`, `pageSize` and `total`.
$list = $pixonaut->templates()->list(status: 'live', page: 1, pageSize: 50);
if (!$list instanceof TemplatesListResponseOption1) {
    throw new UnexpectedValueException('A key reads its templates a page at a time.');
}
if ($list->items === []) {
    echo "No live templates yet.\n";

    return;
}
foreach ($list->items as $item) {
    echo "{$item->id}: {$item->name}\n";
}

// Each id is `baseId@version`, and names that version for good.
$template = $pixonaut->templates()->get($list->items[0]->id);
echo "Read {$template->template->name}, version {$template->template->version}.\n";
