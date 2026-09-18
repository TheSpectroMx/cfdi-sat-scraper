<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\MassiveDownload\Internal;

use Generator;
use PhpCfdi\CfdiSatScraper\Inputs\InputsByFiltersIssued;
use PhpCfdi\CfdiSatScraper\Inputs\InputsByFiltersReceivedFullEndDate;
use PhpCfdi\CfdiSatScraper\Inputs\InputsInterface;
use PhpCfdi\CfdiSatScraper\Internal\HtmlForm;
use PhpCfdi\CfdiSatScraper\Internal\ParserFormatSAT;
use PhpCfdi\CfdiSatScraper\MassiveDownload\AvailablePackage;
use PhpCfdi\CfdiSatScraper\MassiveDownload\AvailablePackageList;
use PhpCfdi\CfdiSatScraper\MassiveDownload\Exceptions\MassiveDownloadException;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MassiveDownloadGateway;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MetadataPackage;
use PhpCfdi\CfdiSatScraper\MassiveDownload\MetadataPackageList;
use PhpCfdi\CfdiSatScraper\QueryByFilters;

/**
 * This class contains the logic to request and download metadata packages (ZIP files)
 * using the massive download flow of the SAT portal.
 *
 * The flow was reverse-engineered from a real HAR capture of the portal:
 *
 * 1) Execute the regular search (same mechanism as QueryResolver) so the server renders
 *    the hidden field "hfParametrosMetadata" on the results page.
 * 2) Call the PageMethod "<ConsultaEmisor|ConsultaReceptor>.aspx/DescargaMetadatos" with
 *    those parameters encoded in Base64; the portal answers with a folio (UUID).
 * 3) Up to 48 hours later, the folio appears listed on ConsultaDescargaMasiva.aspx.
 * 4) Perform a regular postback (non ajax) to ConsultaDescargaMasiva.aspx sending the folio
 *    and its encrypted "blobUri"; the http response is the ZIP file itself.
 *
 * Be aware that this flow depends on portal pages that are not a stable SAT API.
 *
 * @internal
 */
class MassiveMetadataRequester
{
    /** @var string The page to list and download the requested massive download packages */
    public const URL_CONSULTA_DESCARGA_MASIVA = 'https://portalcfdi.facturaelectronica.sat.gob.mx/ConsultaDescargaMasiva.aspx';

    public function __construct(private readonly MassiveDownloadGateway $gateway)
    {
    }

    public function getGateway(): MassiveDownloadGateway
    {
        return $this->gateway;
    }

    public function createInputsFromQuery(QueryByFilters $query): InputsInterface
    {
        if ($query->getDownloadType()->isEmitidos()) {
            return new InputsByFiltersIssued($query);
        }
        return new InputsByFiltersReceivedFullEndDate($query);
    }

    /**
     * Request the generation of the metadata package for the given query.
     *
     * When the download type is "recibidos" and the period spans more than one month,
     * the query is split by month (one package per month) since the portal does not
     * accept multi-month ranges on that page.
     *
     * @throws MassiveDownloadException when the search has no results or the portal rejects the request
     */
    public function requestPackages(QueryByFilters $query): MetadataPackageList
    {
        $packages = new MetadataPackageList([]);
        foreach ($this->splitQueryByFiltersByMonthsIfNeeded($query) as $current) {
            $packages = $packages->merge(new MetadataPackageList([$this->requestPackage($current)]));
        }
        return $packages;
    }

    /**
     * Request the generation of the metadata package for a query with a single month period.
     *
     * @throws MassiveDownloadException when the search has no results or the portal rejects the request
     */
    public function requestPackage(QueryByFilters $query): MetadataPackage
    {
        $inputs = $this->createInputsFromQuery($query);

        $html = $this->resolveSearchHtml($inputs);
        $parameters = $this->extractMetadataParameters($html);
        if ('' === $parameters) {
            throw MassiveDownloadException::searchHasNoResults();
        }

        $uuid = $this->requestFolio($inputs->getUrl(), $parameters);

        return new MetadataPackage($uuid, $query->getStartDate(), $query->getEndDate());
    }

    /**
     * List the packages that are ready to be downloaded (visible on the portal, last 3 days)
     */
    public function listAvailablePackages(): AvailablePackageList
    {
        return $this->extractAvailablePackages($this->gateway->getPortalPage(self::URL_CONSULTA_DESCARGA_MASIVA));
    }

    /**
     * Download the ZIP file of a package already available (see listAvailablePackages)
     *
     * @throws MassiveDownloadException when the package is not available, the portal
     *         returns an html page instead of the zip, or the file cannot be written
     */
    public function downloadPackage(string $uuid, string $destinationPath): void
    {
        $html = $this->gateway->getPortalPage(self::URL_CONSULTA_DESCARGA_MASIVA);
        $available = $this->extractAvailablePackages($html);

        if (! $available->has($uuid)) {
            throw MassiveDownloadException::packageIsNotAvailable($uuid);
        }

        $package = $available->get($uuid);
        $fields = $this->extractHiddenFields($html);
        $fields['__EVENTTARGET'] = 'ctl00$MainContent$setLinkButtonDescarga';
        $fields['__EVENTARGUMENT'] = '';
        $fields['ctl00$MainContent$hfFolioDescargaActual'] = $package->uuid();
        $fields['ctl00$MainContent$hfUrlDescargaActual'] = $package->downloadUrl();

        $contents = $this->gateway->postForm(self::URL_CONSULTA_DESCARGA_MASIVA, $fields);

        if (! $this->isZipContents($contents)) {
            throw MassiveDownloadException::invalidPackageContents($uuid, $contents);
        }

        if (false === @file_put_contents($destinationPath, $contents)) {
            throw MassiveDownloadException::unableToWritePackage($destinationPath);
        }
    }

    /**
     * Check that the contents look like a ZIP file (they start with the PK signature).
     * The portal returns an html page when the postback is not valid, so this check
     * prevents storing an html document with a zip extension.
     */
    private function isZipContents(string $contents): bool
    {
        return str_starts_with($contents, "PK\x03\x04")
            || str_starts_with($contents, "PK\x05\x06")  // empty zip
            || str_starts_with($contents, "PK\x07\x08"); // spanned zip
    }

    /**
     * Execute the search the same way QueryResolver does, but return the final html contents
     * instead of a MetadataList, since this flow needs the hidden field "hfParametrosMetadata".
     *
     * @see \PhpCfdi\CfdiSatScraper\Internal\QueryResolver::resolve()
     */
    public function resolveSearchHtml(InputsInterface $inputs): string
    {
        $url = $inputs->getUrl();
        $ajaxFilters = $inputs->getAjaxInputs();

        // access to download type page, it returns the hole set of inputs
        $completePage = $this->gateway->getPortalPage($url);
        $completePage = str_replace('charset=utf-16', 'charset=utf-8', $completePage); // quick and dirty hack
        $baseInputs = (new HtmlForm($completePage, 'form', ['/^ctl00\$MainContent\$Btn.+/', '/^seleccionador$/']))
            ->getFormValues();

        // select query type (uuid or filters), it returns only a subset of inputs
        $post = array_merge($baseInputs, $ajaxFilters);
        $html = $this->gateway->postAjaxSearch($url, $post);
        $lastViewStates = (new ParserFormatSAT())->getFormValues($html);

        // execute search
        $post = array_merge($baseInputs, $ajaxFilters, $lastViewStates, $inputs->getQueryAsInputs());
        return $this->gateway->postAjaxSearch($url, $post);
    }

    /**
     * Extract the value of the hidden field "hfParametrosMetadata" from the search results page.
     * Returns an empty string when the field is not present (the search has no results).
     */
    public function extractMetadataParameters(string $html): string
    {
        if (! preg_match('/id="hfParametrosMetadata"[^>]*value="([^"]*)"/', $html, $matches)) {
            return '';
        }
        return html_entity_decode($matches[1]);
    }

    /**
     * Extract the folio (UUID) and download url of the packages listed on the given
     * ConsultaDescargaMasiva page contents.
     */
    public function extractAvailablePackages(string $html): AvailablePackageList
    {
        preg_match_all(
            "/AccionRetencion\('([0-9A-Fa-f-]{36})','([^']+)','Recuperacion'\)/",
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        $packages = [];
        foreach ($matches as $match) {
            $packages[] = new AvailablePackage($match[1], html_entity_decode($match[2]));
        }

        return new AvailablePackageList($packages);
    }

    /**
     * Generates a clone of the query split by month only when the download type is
     * "recibidos" and the period spans more than one month; otherwise the query itself.
     *
     * @return Generator<QueryByFilters>
     */
    public function splitQueryByFiltersByMonthsIfNeeded(QueryByFilters $query): Generator
    {
        $startDate = $query->getStartDate();
        $endDate = $query->getEndDate();

        if ($query->getDownloadType()->isEmitidos() || $startDate->format('Y-m') === $endDate->format('Y-m')) {
            yield $query;
            return;
        }

        $date = $startDate;
        while ($date <= $endDate) {
            /** @var \DateTimeImmutable $monthEnd modify returns DateTimeImmutable when called on DateTimeImmutable */
            $monthEnd = $date->modify('last day of this month')->setTime(23, 59, 59);
            $partial = clone $query;
            $partial->setPeriod($date, min($monthEnd, $endDate));
            yield $partial;
            $date = $monthEnd->modify('midnight +1 day');
        }
    }

    /**
     * Call the PageMethod "<url>/DescargaMetadatos" to request the package generation
     * and extract the folio (UUID) from the portal response.
     *
     * @throws MassiveDownloadException
     */
    private function requestFolio(string $url, string $parameters): string
    {
        $endpoint = $url . '/DescargaMetadatos';
        $encoded = rawurlencode(base64_encode($parameters));

        // the portal expects literal single quotes (the format used by its own javascript)
        $response = $this->gateway->postJson($endpoint, sprintf("{'Parametros':'%s'}", $encoded));

        $data = json_decode($response, true);
        $message = is_array($data) ? strval($data['d'] ?? '') : '';

        if (str_starts_with($message, 'Error:')) {
            throw MassiveDownloadException::requestRejected($message);
        }

        if (! preg_match(
            '/folio de descarga:\s*([0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})/',
            $message,
            $matches,
        )) {
            throw MassiveDownloadException::unableToObtainFolio($message);
        }

        return $matches[1];
    }

    /**
     * Extract all the hidden inputs of the page as name => value.
     *
     * The portal renders the attributes of the inputs in different orders and may
     * include extra attributes (id, etc.) between them, so a strict attribute-order
     * regular expression would miss required fields like __VIEWSTATE or __CSRFTOKEN.
     *
     * @return array<string, string>
     */
    private function extractHiddenFields(string $html): array
    {
        preg_match_all('/<input\b[^>]*>/i', $html, $inputs);

        $fields = [];
        foreach ($inputs[0] as $input) {
            if (! preg_match('/\btype\s*=\s*["\']?hidden["\']?/i', $input)) {
                continue;
            }
            $name = $this->extractAttribute($input, 'name');
            if ('' === $name) {
                continue;
            }
            $fields[$name] = $this->extractAttribute($input, 'value');
        }

        return $fields;
    }

    /**
     * Extract the html-decoded value of an attribute from an element, whatever
     * its position and whether it is single-quoted, double-quoted or unquoted.
     */
    private function extractAttribute(string $element, string $attribute): string
    {
        $pattern = sprintf('/\b%s\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', preg_quote($attribute, '/'));
        if (! preg_match($pattern, $element, $matches)) {
            return '';
        }
        // the value is in the first participating capturing group (double, single or unquoted)
        foreach ([1, 2, 3] as $group) {
            if (isset($matches[$group]) && '' !== $matches[$group]) {
                return html_entity_decode($matches[$group]);
            }
        }
        return '';
    }
}
