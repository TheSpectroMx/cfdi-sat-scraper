<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit\MassiveDownload\Internal;

use DateTimeImmutable;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\MassiveDownload\Exceptions\MassiveDownloadException;
use PhpCfdi\CfdiSatScraper\MassiveDownload\Internal\MassiveMetadataRequester;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MassiveDownloadGateway;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;
use PhpCfdi\CfdiSatScraper\URLS;
use PHPUnit\Framework\MockObject\MockObject;

final class MassiveMetadataRequesterTest extends TestCase
{
    private const FOLIO = '7C8D02B2-91A6-4AE0-801D-F05D5027939C';

    /** @var MassiveDownloadGateway&MockObject */
    private MockObject $gateway;

    private MassiveMetadataRequester $requester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = $this->createMock(MassiveDownloadGateway::class);
        $this->requester = new MassiveMetadataRequester($this->gateway);
    }

    public function testGatewayIsTheSameAsConstructor(): void
    {
        $this->assertSame($this->gateway, $this->requester->getGateway());
    }

    public function testExtractMetadataParametersReturnsEmptyWhenFieldIsNotPresent(): void
    {
        $html = $this->fileContentPath('massive-download-search-without-metadata-parameters.html');
        $this->assertSame('', $this->requester->extractMetadataParameters($html));
    }

    public function testExtractMetadataParametersReturnsValueWhenFieldIsPresent(): void
    {
        $html = $this->fileContentPath('massive-download-search-with-metadata-parameters.html');
        $this->assertSame('PARAMETROS-METADATA-CODIFICADOS', $this->requester->extractMetadataParameters($html));
    }

    public function testExtractAvailablePackages(): void
    {
        $html = $this->fileContentPath('massive-download-pending-packages.html');
        $pending = $this->requester->extractAvailablePackages($html);

        $this->assertCount(2, $pending);
        $this->assertTrue($pending->has(self::FOLIO));
        $this->assertTrue($pending->has('1a2b3c4d-5e6f-4890-abcd-ef1234567890'));
        $this->assertSame('YmxvYlVyaVVubz09', $pending->get(self::FOLIO)->downloadUrl());
    }

    public function testRequestPackage(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('sample-response-receiver-form-page.html'),
        );
        $this->gateway->method('postAjaxSearch')->willReturn(
            $this->fileContentPath('sample-response-receiver-using-filters-initial.html'),
            $this->fileContentPath('massive-download-search-with-metadata-parameters.html'),
        );
        $this->gateway->expects($this->once())
            ->method('postJson')
            ->with(URLS::PORTAL_CFDI_CONSULTA_RECEPTOR . '/DescargaMetadatos')
            ->willReturn(json_encode(['d' => 'Solicitud registrada con el folio de descarga: ' . self::FOLIO]) ?: '');

        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-31 23:59:59'),
            DownloadType::recibidos(),
        );

        $package = $this->requester->requestPackage($query);

        $this->assertSame(strtolower(self::FOLIO), $package->uuid());
        $this->assertSame('2026-01-01', $package->startDate()->format('Y-m-d'));
        $this->assertSame('2026-01-31', $package->endDate()->format('Y-m-d'));
    }

    public function testRequestPackageWithoutResultsThrowsException(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('sample-response-receiver-form-page.html'),
        );
        $this->gateway->method('postAjaxSearch')->willReturn(
            $this->fileContentPath('sample-response-receiver-using-filters-initial.html'),
            $this->fileContentPath('massive-download-search-without-metadata-parameters.html'),
        );

        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-31 23:59:59'),
            DownloadType::recibidos(),
        );

        $this->expectException(MassiveDownloadException::class);
        $this->requester->requestPackage($query);
    }

    public function testRequestPackageWithPortalErrorThrowsException(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('sample-response-receiver-form-page.html'),
        );
        $this->gateway->method('postAjaxSearch')->willReturn(
            $this->fileContentPath('sample-response-receiver-using-filters-initial.html'),
            $this->fileContentPath('massive-download-search-with-metadata-parameters.html'),
        );
        $this->gateway->method('postJson')
            ->willReturn(json_encode(['d' => 'Error: La solicitud no pudo ser procesada']) ?: '');

        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-31 23:59:59'),
            DownloadType::recibidos(),
        );

        $this->expectException(MassiveDownloadException::class);
        $this->requester->requestPackage($query);
    }

    public function testRequestPackagesSplitsReceivedMultiMonthQueryByMonth(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('sample-response-receiver-form-page.html'),
        );
        // each month performs 2 ajax calls: select download type + execute search
        $this->gateway->method('postAjaxSearch')->willReturn(
            $this->fileContentPath('sample-response-receiver-using-filters-initial.html'),
            $this->fileContentPath('massive-download-search-with-metadata-parameters.html'),
            $this->fileContentPath('sample-response-receiver-using-filters-initial.html'),
            $this->fileContentPath('massive-download-search-with-metadata-parameters.html'),
            $this->fileContentPath('sample-response-receiver-using-filters-initial.html'),
            $this->fileContentPath('massive-download-search-with-metadata-parameters.html'),
        );
        $this->gateway->method('postJson')->willReturn(
            json_encode(['d' => 'folio de descarga: 7C8D02B2-91A6-4AE0-801D-F05D5027939C']) ?: '',
            json_encode(['d' => 'folio de descarga: 1A2B3C4D-5E6F-4890-ABCD-EF1234567890']) ?: '',
            json_encode(['d' => 'folio de descarga: 2B3C4D5E-6F70-4901-BCDE-F12345678901']) ?: '',
        );

        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-15 00:00:00'),
            new DateTimeImmutable('2026-03-10 23:59:59'),
            DownloadType::recibidos(),
        );

        $packages = $this->requester->requestPackages($query);

        $this->assertCount(3, $packages);
        $this->assertTrue($packages->has('7c8d02b2-91a6-4ae0-801d-f05d5027939c'));
        $this->assertTrue($packages->has('1a2b3c4d-5e6f-4890-abcd-ef1234567890'));
        $this->assertTrue($packages->has('2b3c4d5e-6f70-4901-bcde-f12345678901'));
    }

    public function testSplitQueryByFiltersByMonthsIfNeededWithReceivedMultiMonth(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-15 00:00:00'),
            new DateTimeImmutable('2026-03-10 23:59:59'),
            DownloadType::recibidos(),
        );

        $split = iterator_to_array($this->requester->splitQueryByFiltersByMonthsIfNeeded($query), false);

        $this->assertCount(3, $split);
        $this->assertSame('2026-01-15 00:00:00', $split[0]->getStartDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-31 23:59:59', $split[0]->getEndDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-01 00:00:00', $split[1]->getStartDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-28 23:59:59', $split[1]->getEndDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01 00:00:00', $split[2]->getStartDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-10 23:59:59', $split[2]->getEndDate()->format('Y-m-d H:i:s'));
    }

    public function testSplitQueryByFiltersByMonthsIfNeededWithReceivedSingleMonth(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-15 00:00:00'),
            new DateTimeImmutable('2026-01-31 23:59:59'),
            DownloadType::recibidos(),
        );

        $split = iterator_to_array($this->requester->splitQueryByFiltersByMonthsIfNeeded($query), false);

        $this->assertCount(1, $split);
        $this->assertSame($query, $split[0]);
    }

    public function testSplitQueryByFiltersByMonthsIfNeededWithIssuedMultiMonth(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-15 00:00:00'),
            new DateTimeImmutable('2026-03-10 23:59:59'),
            DownloadType::emitidos(),
        );

        $split = iterator_to_array($this->requester->splitQueryByFiltersByMonthsIfNeeded($query), false);

        $this->assertCount(1, $split);
        $this->assertSame($query, $split[0]);
    }

    public function testListAvailablePackages(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('massive-download-pending-packages.html'),
        );

        $pending = $this->requester->listAvailablePackages();

        $this->assertCount(2, $pending);
        $this->assertTrue($pending->has(self::FOLIO));
    }

    public function testDownloadPackage(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('massive-download-pending-packages.html'),
        );

        $zipContents = "PK\x03\x04" . 'fake-zip-contents';
        $this->gateway->expects($this->once())
            ->method('postForm')
            ->with(
                MassiveMetadataRequester::URL_CONSULTA_DESCARGA_MASIVA,
                $this->callback(fn (array $fields): bool => ($fields['ctl00$MainContent$hfFolioDescargaActual'] ?? '') === self::FOLIO
                    && ($fields['ctl00$MainContent$hfUrlDescargaActual'] ?? '') === 'YmxvYlVyaVVubz09'
                    && ($fields['__EVENTTARGET'] ?? '') === 'ctl00$MainContent$setLinkButtonDescarga'
                    // the hidden fields of the page must be preserved on the postback
                    && ($fields['__VIEWSTATE'] ?? '') === 'VIEWSTATE-VALUE'
                    && ($fields['__EVENTVALIDATION'] ?? '') === 'EVENTVALIDATION-VALUE'),
            )
            ->willReturn($zipContents);

        $destinationPath = tempnam(sys_get_temp_dir(), 'massive-download-test-');
        $this->assertNotFalse($destinationPath);
        try {
            $this->requester->downloadPackage(self::FOLIO, $destinationPath);
            $this->assertSame($zipContents, file_get_contents($destinationPath));
        } finally {
            @unlink($destinationPath);
        }
    }

    public function testDownloadPackageWithHtmlResponseThrowsException(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('massive-download-pending-packages.html'),
        );
        // the portal returns an html page instead of the zip when the postback is not valid
        $this->gateway->method('postForm')->willReturn('<!DOCTYPE html><html><body>Error</body></html>');

        $destinationPath = tempnam(sys_get_temp_dir(), 'massive-download-test-');
        $this->assertNotFalse($destinationPath);
        try {
            $this->expectException(MassiveDownloadException::class);
            $this->requester->downloadPackage(self::FOLIO, $destinationPath);
        } finally {
            @unlink($destinationPath);
        }
    }

    public function testDownloadPackageNotAvailableThrowsException(): void
    {
        $this->gateway->method('getPortalPage')->willReturn(
            $this->fileContentPath('massive-download-pending-packages.html'),
        );

        $this->expectException(MassiveDownloadException::class);
        $this->requester->downloadPackage('ffffffff-ffff-ffff-ffff-ffffffffffff', sys_get_temp_dir() . '/not-used.zip');
    }
}
