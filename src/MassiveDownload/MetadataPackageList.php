<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\MassiveDownload;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use PhpCfdi\CfdiSatScraper\Exceptions\LogicException;

/**
 * @implements IteratorAggregate<MetadataPackage>
 */
final class MetadataPackageList implements Countable, IteratorAggregate, JsonSerializable
{
    /** @var array<string, MetadataPackage> */
    private array $list = [];

    /** @param MetadataPackage[]|mixed[] $list */
    public function __construct(array $list)
    {
        foreach ($list as $package) {
            if (! $package instanceof MetadataPackage) {
                continue;
            }
            $this->list[strtolower($package->uuid())] = $package;
        }
    }

    public function merge(self $list): self
    {
        $new = new self([]);
        $new->list = array_merge($this->list, $list->list);
        return $new;
    }

    public function has(string $uuid): bool
    {
        return isset($this->list[strtolower($uuid)]);
    }

    /**
     * Retrieve a MetadataPackage by UUID, if the package does not exists returns NULL
     */
    public function find(string $uuid): ?MetadataPackage
    {
        return $this->list[strtolower($uuid)] ?? null;
    }

    /**
     * Obtain a MetadataPackage by UUID, the package object must exist in the collection
     *
     * @throws LogicException when UUID is not found
     */
    public function get(string $uuid): MetadataPackage
    {
        $package = $this->find($uuid);
        if (null === $package) {
            throw LogicException::generic("UUID $uuid not found");
        }
        return $package;
    }

    /** @return ArrayIterator<string, MetadataPackage> */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->list);
    }

    public function count(): int
    {
        return count($this->list);
    }

    /** @return array<string, MetadataPackage> */
    public function jsonSerialize(): array
    {
        return $this->list;
    }
}
