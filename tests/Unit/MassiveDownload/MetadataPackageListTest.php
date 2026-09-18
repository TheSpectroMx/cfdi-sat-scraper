<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit\MassiveDownload;

use DateTimeImmutable;
use PhpCfdi\CfdiSatScraper\Exceptions\InvalidArgumentException;
use PhpCfdi\CfdiSatScraper\Exceptions\LogicException;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MetadataPackage;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MetadataPackageList;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;

final class MetadataPackageListTest extends TestCase
{
    private function createPackage(string $uuid): MetadataPackage
    {
        return new MetadataPackage(
            $uuid,
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-31 23:59:59'),
        );
    }

    public function testPackageIsConstructedInLowerCase(): void
    {
        $package = $this->createPackage('7C8D02B2-91A6-4AE0-801D-F05D5027939C');
        $this->assertSame('7c8d02b2-91a6-4ae0-801d-f05d5027939c', $package->uuid());
        $this->assertSame('2026-01-01', $package->startDate()->format('Y-m-d'));
        $this->assertSame('2026-01-31', $package->endDate()->format('Y-m-d'));
    }

    public function testPackageWithEmptyUuidThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->createPackage('');
    }

    public function testListIgnoresNonPackageElements(): void
    {
        $list = new MetadataPackageList([$this->createPackage('aaa'), 'not-a-package', null]);
        $this->assertCount(1, $list);
    }

    public function testListAccessors(): void
    {
        $package = $this->createPackage('AAA-BBB');
        $list = new MetadataPackageList([$package]);

        $this->assertTrue($list->has('aaa-bbb'));
        $this->assertFalse($list->has('ccc-ddd'));
        $this->assertSame($package, $list->find('AAA-BBB'));
        $this->assertNull($list->find('ccc-ddd'));
        $this->assertSame($package, $list->get('aaa-bbb'));
    }

    public function testListGetWithUnknownUuidThrowsException(): void
    {
        $list = new MetadataPackageList([]);
        $this->expectException(LogicException::class);
        $list->get('unknown-uuid');
    }

    public function testListMerge(): void
    {
        $first = new MetadataPackageList([$this->createPackage('aaa')]);
        $second = new MetadataPackageList([$this->createPackage('bbb')]);

        $merged = $first->merge($second);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertCount(2, $merged);
        $this->assertTrue($merged->has('aaa'));
        $this->assertTrue($merged->has('bbb'));
    }

    public function testListIsIterableAndJsonSerializable(): void
    {
        $list = new MetadataPackageList([$this->createPackage('aaa'), $this->createPackage('bbb')]);

        $this->assertSame(['aaa', 'bbb'], array_keys(iterator_to_array($list)));
        $this->assertSame(['aaa', 'bbb'], array_keys($list->jsonSerialize()));
        $this->assertArrayHasKey('uuid', $list->get('aaa')->jsonSerialize());
    }
}
