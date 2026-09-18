<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit\MassiveDownload;

use DateTimeImmutable;
use PhpCfdi\CfdiSatScraper\MassiveDownload\Internal\MassiveMetadataRequester;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MassiveDownloadGateway;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MassiveMetadataScraper;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MetadataPackage;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MetadataPackageList;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\Sessions\SessionManager;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class MassiveMetadataScraperTest extends TestCase
{
    /** @var SessionManager&MockObject */
    private MockObject $sessionManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionManager = $this->createMock(SessionManager::class);
    }

    public function testConstructorUsesDefaultGatewayWhenNotProvided(): void
    {
        $scraper = new MassiveMetadataScraper($this->sessionManager);

        $this->assertSame($this->sessionManager, $scraper->getSessionManager());
        $this->assertInstanceOf(MassiveDownloadGateway::class, $scraper->getSatHttpGateway());
    }

    public function testConstructorUsesProvidedGateway(): void
    {
        $gateway = new MassiveDownloadGateway();
        $scraper = new MassiveMetadataScraper($this->sessionManager, $gateway);

        $this->assertSame($gateway, $scraper->getSatHttpGateway());
    }

    public function testConfirmSessionIsAlivePerformLoginWhenNotLogged(): void
    {
        $this->sessionManager->method('hasLogin')->willReturn(false);
        $this->sessionManager->expects($this->once())->method('login');
        $this->sessionManager->expects($this->once())->method('accessPortalMainPage');

        $scraper = new MassiveMetadataScraper($this->sessionManager);
        $this->assertSame($scraper, $scraper->confirmSessionIsAlive());
    }

    public function testConfirmSessionIsAliveDoesNotPerformLoginWhenLogged(): void
    {
        $this->sessionManager->method('hasLogin')->willReturn(true);
        $this->sessionManager->expects($this->never())->method('login');
        $this->sessionManager->expects($this->once())->method('accessPortalMainPage');

        $scraper = new MassiveMetadataScraper($this->sessionManager);
        $scraper->confirmSessionIsAlive();
    }

    public function testGatewayCanBeSharedWithSatScraper(): void
    {
        $gateway = new MassiveDownloadGateway();
        $satScraper = new \PhpCfdi\CfdiSatScraper\SatScraper($this->sessionManager, $gateway);
        $massiveScraper = new MassiveMetadataScraper($this->sessionManager, $gateway);

        $this->assertSame($satScraper->getSatHttpGateway(), $massiveScraper->getSatHttpGateway());
    }

    public function testRequestByPeriodNormalizesToWholeDays(): void
    {
        $this->sessionManager->method('hasLogin')->willReturn(true);

        $captured = null;
        $requester = new class () extends MassiveMetadataRequester {
            /** @var QueryByFilters|null */
            public ?QueryByFilters $captured = null;

            public function __construct()
            {
                // parent constructor is not called, gateway is not needed for this test
            }

            public function requestPackages(QueryByFilters $query): MetadataPackageList
            {
                $this->captured = $query;
                return new MetadataPackageList([
                    new MetadataPackage('7C8D02B2-91A6-4AE0-801D-F05D5027939C', $query->getStartDate(), $query->getEndDate()),
                ]);
            }
        };

        $scraper = new class ($this->sessionManager, $requester) extends MassiveMetadataScraper {
            public function __construct(SessionManager $sessionManager, private readonly MassiveMetadataRequester $requester)
            {
                parent::__construct($sessionManager);
            }

            protected function createRequester(): MassiveMetadataRequester
            {
                return $this->requester;
            }
        };

        $query = new QueryByFilters(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-31'));
        $packages = $scraper->requestByPeriod($query);

        // the query was normalized to whole days before requesting the packages
        $this->assertNotNull($requester->captured);
        $this->assertSame('2026-08-01 00:00:00', $requester->captured->getStartDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 23:59:59', $requester->captured->getEndDate()->format('Y-m-d H:i:s'));
        // the original query was not modified
        $this->assertSame('2026-08-31 00:00:00', $query->getEndDate()->format('Y-m-d H:i:s'));
        // the package exposes the normalized period
        $package = $packages->get('7C8D02B2-91A6-4AE0-801D-F05D5027939C');
        $this->assertSame('2026-08-31 23:59:59', $package->endDate()->format('Y-m-d H:i:s'));
    }

    public function testRequestByDateTimeKeepsTheExactDates(): void
    {
        $this->sessionManager->method('hasLogin')->willReturn(true);

        $requester = new class () extends MassiveMetadataRequester {
            /** @var QueryByFilters|null */
            public ?QueryByFilters $captured = null;

            public function __construct()
            {
                // parent constructor is not called, gateway is not needed for this test
            }

            public function requestPackages(QueryByFilters $query): MetadataPackageList
            {
                $this->captured = $query;
                return new MetadataPackageList([]);
            }
        };

        $scraper = new class ($this->sessionManager, $requester) extends MassiveMetadataScraper {
            public function __construct(SessionManager $sessionManager, private readonly MassiveMetadataRequester $requester)
            {
                parent::__construct($sessionManager);
            }

            protected function createRequester(): MassiveMetadataRequester
            {
                return $this->requester;
            }
        };

        $query = new QueryByFilters(
            new DateTimeImmutable('2026-08-01 06:30:00'),
            new DateTimeImmutable('2026-08-31 18:45:00'),
        );
        $scraper->requestByDateTime($query);

        $this->assertNotNull($requester->captured);
        $this->assertSame('2026-08-01 06:30:00', $requester->captured->getStartDate()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-31 18:45:00', $requester->captured->getEndDate()->format('Y-m-d H:i:s'));
    }
}
