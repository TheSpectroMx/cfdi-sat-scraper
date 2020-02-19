<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Integration;

use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Filters\Options\StatesVoucherOption;
use PhpCfdi\CfdiSatScraper\Query;

class RetrieveByCfdiStateTest extends IntegrationTestCase
{
    /**
     * @param DownloadType $downloadType
     * @dataProvider providerEmitidosRecibidos
     */
    public function testRetrieveByCfdiStateCancelados(DownloadType $downloadType): void
    {
        $state = StatesVoucherOption::cancelados();
        $typeText = $this->getDownloadTypeText($downloadType);
        $repository = $this->getRepository()->filterByType($downloadType);
        $repository = $repository->filterByState($state);
        if (0 === $repository->count()) {
            $this->markTestSkipped(
                sprintf('The repository does not have CFDI %s with state Cancelado', $typeText)
            );
        }

        $scraper = $this->getSatScraper();
        $query = (new Query($repository->getSinceDate(), $repository->getUntilDate()))
            ->setDownloadType($downloadType)
            ->setStateVoucher($state);
        $list = $scraper->downloadByDateTime($query);

        $this->assertRepositoryEqualsMetadataList($repository, $list);
    }

    /**
     * @param DownloadType $downloadType
     * @dataProvider providerEmitidosRecibidos
     */
    public function testRetrieveByCfdiStateVigentes(DownloadType $downloadType): void
    {
        $state = StatesVoucherOption::vigentes();
        $typeText = $this->getDownloadTypeText($downloadType);
        $repository = $this->getRepository()->filterByType($downloadType);
        $repository = $repository->filterByState($state);
        if (0 === $repository->count()) {
            $this->markTestSkipped(
                sprintf('The repository does not have CFDI %s with state Vigente', $typeText)
            );
        }

        $scraper = $this->getSatScraper();
        $query = (new Query($repository->getSinceDate(), $repository->getUntilDate()))
            ->setDownloadType($downloadType)
            ->setStateVoucher($state);
        $list = $scraper->downloadByDateTime($query);

        $this->assertRepositoryEqualsMetadataList($repository, $list);
    }
}
