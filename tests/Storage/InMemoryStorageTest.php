<?php

declare(strict_types=1);

namespace Shawware\Yakb\Tests\Storage;

use Shawware\Yakb\Storage\InMemoryStorage;
use Shawware\Yakb\Storage\StorageInterface;

final class InMemoryStorageTest extends StorageContractTestCase
{
    protected function createStorage(): StorageInterface
    {
        return new InMemoryStorage();
    }
}
