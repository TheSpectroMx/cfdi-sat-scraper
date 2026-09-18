<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\MassiveDownload;

use DateTimeImmutable;
use JsonSerializable;
use PhpCfdi\CfdiSatScraper\Exceptions\InvalidArgumentException;

/**
 * The MetadataPackage class stores the values of a metadata package (ZIP file)
 * requested to the SAT portal massive download service.
 *
 * The uuid is the folio (request identifier) returned by the portal,
 * the package generation is asynchronous and can take up to 48 hours.
 */
final class MetadataPackage implements JsonSerializable
{
    private readonly string $uuid;

    /**
     * $uuid will be converted to lower case.
     *
     * @throws InvalidArgumentException when UUID is empty
     */
    public function __construct(
        string $uuid,
        private readonly DateTimeImmutable $startDate,
        private readonly DateTimeImmutable $endDate,
    ) {
        if ('' === $uuid) {
            throw InvalidArgumentException::emptyInput('UUID');
        }
        $this->uuid = strtolower($uuid);
    }

    /** Folio (request identifier) */
    public function uuid(): string
    {
        return $this->uuid;
    }

    public function startDate(): DateTimeImmutable
    {
        return $this->startDate;
    }

    public function endDate(): DateTimeImmutable
    {
        return $this->endDate;
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'startDate' => $this->startDate->format('Y-m-d\TH:i:s'),
            'endDate' => $this->endDate->format('Y-m-d\TH:i:s'),
        ];
    }
}
