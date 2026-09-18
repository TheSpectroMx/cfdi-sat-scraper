<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\MassiveDownload;

use PhpCfdi\CfdiSatScraper\MassiveDownload\Internal\MassiveMetadataRequester;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\Sessions\SessionManager;

/**
 * MassiveMetadataScraper performs the massive download (metadata packages) flow
 * of the SAT portal: request the generation of a ZIP file with the metadata of
 * all the documents of a period, list the generated packages and download them.
 *
 * This flow is asynchronous: once a package is requested, the portal can take
 * up to 48 hours to make it available for download.
 *
 * Be aware that this flow depends on portal pages that are not a stable SAT API.
 *
 * Usage:
 *
 * ```php
 * $gateway = new MassiveDownloadGateway();
 * $satScraper = new SatScraper($sessionManager, $gateway); // shares the same session
 * $massiveScraper = new MassiveMetadataScraper($sessionManager, $gateway);
 *
 * // request the package generation, returns the folio (UUID) of each request
 * $packages = $massiveScraper->requestByPeriod($query);
 *
 * // up to 48 hours later, list the packages ready to be downloaded
 * $available = $massiveScraper->listAvailablePackages();
 *
 * // download the ZIP file of a package
 * $massiveScraper->downloadPackage($uuid, '/storage/metadata.zip');
 * ```
 */
class MassiveMetadataScraper
{
    private readonly MassiveDownloadGateway $satHttpGateway;

    public function __construct(
        private readonly SessionManager $sessionManager,
        ?MassiveDownloadGateway $satHttpGateway = null,
    ) {
        $this->satHttpGateway = $satHttpGateway ?? $this->createDefaultSatHttpGateway();
    }

    /**
     * Method factory to create a MassiveDownloadGateway
     *
     * @internal
     */
    protected function createDefaultSatHttpGateway(): MassiveDownloadGateway
    {
        return new MassiveDownloadGateway();
    }

    /**
     * Method factory to create a MassiveMetadataRequester
     *
     * @internal
     */
    protected function createRequester(): MassiveMetadataRequester
    {
        return new MassiveMetadataRequester($this->satHttpGateway);
    }

    public function confirmSessionIsAlive(): self
    {
        $sessionManager = $this->getSessionManager();
        $sessionManager->setHttpGateway($this->getSatHttpGateway());

        if (! $sessionManager->hasLogin()) {
            $sessionManager->login();
        }
        $sessionManager->accessPortalMainPage();

        return $this;
    }

    /**
     * Request the generation of the metadata package for the given query.
     *
     * The period is normalized to whole days: the start date is set to 00:00:00
     * and the end date is set to 23:59:59. Use requestByDateTime to request
     * the package with the exact dates and times of the query.
     *
     * When the download type is "recibidos" and the period spans more than one month,
     * one package per month is requested since the portal does not accept multi-month
     * ranges on that page.
     *
     * @return MetadataPackageList the list of requested packages with its folio (UUID)
     * @throws Exceptions\MassiveDownloadException when the search has no results or the portal rejects the request
     */
    public function requestByPeriod(QueryByFilters $query): MetadataPackageList
    {
        /** @var \DateTimeImmutable $startDate set this type definition as setTime can return FALSE */
        $startDate = $query->getStartDate()->setTime(0, 0, 0);
        /** @var \DateTimeImmutable $endDate set this type definition as setTime can return FALSE */
        $endDate = $query->getEndDate()->setTime(23, 59, 59);

        $query = clone $query;
        $query->setPeriod($startDate, $endDate);

        return $this->requestByDateTime($query);
    }

    /**
     * Request the generation of the metadata package for the given query,
     * using the exact dates and times of the query.
     *
     * When the download type is "recibidos" and the period spans more than one month,
     * one package per month is requested since the portal does not accept multi-month
     * ranges on that page.
     *
     * @return MetadataPackageList the list of requested packages with its folio (UUID)
     * @throws Exceptions\MassiveDownloadException when the search has no results or the portal rejects the request
     */
    public function requestByDateTime(QueryByFilters $query): MetadataPackageList
    {
        $this->confirmSessionIsAlive();
        return $this->createRequester()->requestPackages($query);
    }

    /**
     * List the packages that are ready to be downloaded (visible on the portal, last 3 days)
     */
    public function listAvailablePackages(): AvailablePackageList
    {
        $this->confirmSessionIsAlive();
        return $this->createRequester()->listAvailablePackages();
    }

    /**
     * Download the ZIP file of a package already available (see listAvailablePackages)
     *
     * @throws Exceptions\MassiveDownloadException when the package is not available or the file cannot be written
     */
    public function downloadPackage(string $uuid, string $destinationPath): void
    {
        $this->confirmSessionIsAlive();
        $this->createRequester()->downloadPackage($uuid, $destinationPath);
    }

    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }

    public function getSatHttpGateway(): MassiveDownloadGateway
    {
        return $this->satHttpGateway;
    }
}
