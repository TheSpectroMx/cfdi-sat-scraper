<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\MassiveDownload;

use JsonSerializable;
use PhpCfdi\CfdiSatScraper\Exceptions\InvalidArgumentException;

/**
 * The AvailablePackage class stores the values of a metadata package that is
 * ready to be downloaded from the SAT portal massive download service.
 */
final class AvailablePackage implements JsonSerializable
{
    private readonly string $uuid;

    /**
     * The given uuid is stored as is (the portal generates it in upper case and
     * the download postback must send it back exactly as received); lookups on
     * the collection are case-insensitive.
     *
     * @throws InvalidArgumentException when UUID is empty
     */
    public function __construct(
        string $uuid,
        private readonly string $downloadUrl,
    ) {
        if ('' === $uuid) {
            throw InvalidArgumentException::emptyInput('UUID');
        }
        $this->uuid = $uuid;
    }

    /** Folio (request identifier), exactly as the portal generated it */
    public function uuid(): string
    {
        return $this->uuid;
    }

    /** Internal url (blobUri) used by the portal to resolve the package download */
    public function downloadUrl(): string
    {
        return $this->downloadUrl;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'downloadUrl' => $this->downloadUrl,
        ];
    }
}
