<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\MassiveDownload;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use PhpCfdi\CfdiSatScraper\Exceptions\SatHttpGatewayClientException;
use PhpCfdi\CfdiSatScraper\Exceptions\SatHttpGatewayResponseException;
use PhpCfdi\CfdiSatScraper\Internal\Headers;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;

/**
 * SatHttpGateway extension with the transport operations required by the
 * massive download (metadata packages) flow of the SAT portal:
 *
 * - POST with a raw JSON body, as expected by the ASP.NET PageMethods
 * - POST with form data (non ajax) where the response can be binary content (ZIP package)
 *
 * Since it extends SatHttpGateway, the same instance can be shared with SatScraper,
 * reusing the same http client and cookie jar (session).
 */
class MassiveDownloadGateway extends SatHttpGateway
{
    private readonly ClientInterface $client;

    private readonly CookieJarInterface $cookieJar;

    public function __construct(?ClientInterface $client = null, ?CookieJarInterface $cookieJar = null)
    {
        // if the cookieJar was set on the client but not in the configuration
        if (null === $cookieJar && null !== $client) {
            /** @var mixed $configuredCookieJar */
            $configuredCookieJar = $client->getConfig(RequestOptions::COOKIES);
            if ($configuredCookieJar instanceof CookieJarInterface) {
                $cookieJar = $configuredCookieJar;
            }
        }

        $cookieJar ??= new CookieJar();
        $client ??= new Client([RequestOptions::COOKIES => $cookieJar]);

        parent::__construct($client, $cookieJar);

        $this->client = $client;
        $this->cookieJar = $cookieJar;
    }

    /**
     * Perform a POST request sending a raw JSON body (used by ASP.NET PageMethods)
     *
     * @throws SatHttpGatewayClientException
     * @throws SatHttpGatewayResponseException
     */
    public function postJson(string $url, string $jsonBody, string $reason = 'post json data'): string
    {
        $options = [
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            RequestOptions::BODY => $jsonBody,
        ];
        return $this->request('POST', $url, $options, $reason);
    }

    /**
     * Perform a POST request sending form data (non ajax).
     * The response contents are returned as is, since they can be binary data (ZIP package).
     *
     * The "Accept-Encoding" header is removed so the portal does not compress the response:
     * the ZIP file must be stored with the exact bytes sent by the server, otherwise the
     * package would be corrupted when the http layer decodes the content transfer encoding.
     *
     * @param array<string, string> $formData
     * @throws SatHttpGatewayClientException
     * @throws SatHttpGatewayResponseException
     */
    public function postForm(string $url, array $formData, string $reason = 'post form data'): string
    {
        $headers = array_merge(Headers::post($this->urlHost($url), $url), ['Accept-Encoding' => 'identity']);
        $options = [
            RequestOptions::HEADERS => $headers,
            RequestOptions::FORM_PARAMS => $formData,
        ];
        return $this->request('POST', $url, $options, $reason);
    }

    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    public function getCookieJar(): CookieJarInterface
    {
        return $this->cookieJar;
    }

    /**
     * Helper to perform a request, replicates the behavior of SatHttpGateway::request
     * since that method is private and cannot be reused from here.
     *
     * @param array<string, mixed> $options
     * @throws SatHttpGatewayClientException
     * @throws SatHttpGatewayResponseException
     */
    private function request(string $method, string $uri, array $options, string $reason): string
    {
        $options = [
            RequestOptions::COOKIES => $this->cookieJar,
            RequestOptions::ALLOW_REDIRECTS => ['trackredirects' => true],
        ] + $options;

        try {
            $response = $this->client->request($method, $uri, $options);
        } catch (GuzzleException $exception) {
            /** @var array<string, mixed> $requestHeaders */
            $requestHeaders = $options[RequestOptions::HEADERS] ?? [];
            /** @var array<string, mixed> $requestData */
            $requestData = $options[RequestOptions::FORM_PARAMS] ?? [];
            throw SatHttpGatewayClientException::clientException(
                $reason,
                $method,
                $uri,
                $requestHeaders,
                $requestData,
                $exception,
            );
        }

        $contents = strval($response->getBody());
        if ('' === $contents) {
            /** @var array<string, mixed> $requestHeaders */
            $requestHeaders = $options[RequestOptions::HEADERS] ?? [];
            /** @var array<string, mixed> $requestData */
            $requestData = $options[RequestOptions::FORM_PARAMS] ?? [];
            throw SatHttpGatewayResponseException::unexpectedEmptyResponse(
                $reason,
                $response,
                $method,
                $uri,
                $requestHeaders,
                $requestData,
            );
        }

        return $contents;
    }

    private function urlHost(string $url): string
    {
        return strval(parse_url($url, PHP_URL_HOST));
    }
}
