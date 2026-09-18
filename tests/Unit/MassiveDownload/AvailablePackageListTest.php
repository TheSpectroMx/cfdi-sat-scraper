<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit\MassiveDownload;

use PhpCfdi\CfdiSatScraper\Exceptions\InvalidArgumentException;
use PhpCfdi\CfdiSatScraper\MassiveDownload\AvailablePackage;
use PhpCfdi\CfdiSatScraper\MassiveDownload\AvailablePackageList;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;

final class AvailablePackageListTest extends TestCase
{
    public function testPackagePreservesTheUuidAsGiven(): void
    {
        $package = new AvailablePackage('7C8D02B2-91A6-4AE0-801D-F05D5027939C', 'blobUri');
        $this->assertSame('7C8D02B2-91A6-4AE0-801D-F05D5027939C', $package->uuid());
        $this->assertSame('blobUri', $package->downloadUrl());
    }

    public function testPackageWithEmptyUuidThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AvailablePackage('', 'blobUri');
    }

    public function testListAccessors(): void
    {
        $package = new AvailablePackage('AAA-BBB', 'blobUri');
        $list = new AvailablePackageList([$package, 'not-a-package']);

        $this->assertCount(1, $list);
        $this->assertTrue($list->has('aaa-bbb'));
        $this->assertFalse($list->has('ccc-ddd'));
        $this->assertSame($package, $list->find('AAA-BBB'));
        $this->assertNull($list->find('ccc-ddd'));
        $this->assertSame($package, $list->get('aaa-bbb'));
    }

    public function testListMerge(): void
    {
        $first = new AvailablePackageList([new AvailablePackage('aaa', 'uno')]);
        $second = new AvailablePackageList([new AvailablePackage('bbb', 'dos')]);

        $merged = $first->merge($second);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertCount(2, $merged);
    }

    public function testListIsIterableAndJsonSerializable(): void
    {
        $list = new AvailablePackageList([new AvailablePackage('aaa', 'uno'), new AvailablePackage('bbb', 'dos')]);

        $this->assertSame(['aaa', 'bbb'], array_keys(iterator_to_array($list)));
        $this->assertSame(['aaa', 'bbb'], array_keys($list->jsonSerialize()));
        $this->assertSame(
            ['uuid' => 'aaa', 'downloadUrl' => 'uno'],
            $list->get('aaa')->jsonSerialize(),
        );
    }
}
