<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Tests\Unit\Inputs;

use DateTimeImmutable;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Inputs\InputsByFiltersReceivedFullEndDate;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\Tests\TestCase;

final class InputsByFiltersReceivedFullEndDateTest extends TestCase
{
    public function testWholeMonthUsesZeroOnDayFields(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-31 23:59:59'),
            DownloadType::recibidos(),
        );

        $filters = (new InputsByFiltersReceivedFullEndDate($query))->getDateFilters();

        $this->assertSame('0', $filters['ctl00$MainContent$CldFecha$DdlDia']);
        $this->assertSame('0', $filters['ctl00$MainContent$CldFecha$DdlDiaFin']);
    }

    public function testWholeMonthSendsCompleteEndDate(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-31 23:59:59'),
            DownloadType::recibidos(),
        );

        $filters = (new InputsByFiltersReceivedFullEndDate($query))->getDateFilters();

        $this->assertSame('2026', $filters['ctl00$MainContent$CldFecha$DdlAnioFin']);
        $this->assertSame('1', $filters['ctl00$MainContent$CldFecha$DdlMesFin']);
        $this->assertSame('23', $filters['ctl00$MainContent$CldFecha$DdlHoraFin']);
        $this->assertSame('59', $filters['ctl00$MainContent$CldFecha$DdlMinutoFin']);
        $this->assertSame('59', $filters['ctl00$MainContent$CldFecha$DdlSegundoFin']);
    }

    public function testPartialRangeUsesExactDays(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-05 00:00:00'),
            new DateTimeImmutable('2026-01-20 23:59:59'),
            DownloadType::recibidos(),
        );

        $filters = (new InputsByFiltersReceivedFullEndDate($query))->getDateFilters();

        $this->assertSame('05', $filters['ctl00$MainContent$CldFecha$DdlDia']);
        $this->assertSame('20', $filters['ctl00$MainContent$CldFecha$DdlDiaFin']);
        $this->assertSame('2026', $filters['ctl00$MainContent$CldFecha$DdlAnioFin']);
        $this->assertSame('1', $filters['ctl00$MainContent$CldFecha$DdlMesFin']);
    }

    public function testWholeMonthOnFebruaryUsesZeroOnDayFields(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-02-01 00:00:00'),
            new DateTimeImmutable('2026-02-28 23:59:59'),
            DownloadType::recibidos(),
        );

        $filters = (new InputsByFiltersReceivedFullEndDate($query))->getDateFilters();

        $this->assertSame('0', $filters['ctl00$MainContent$CldFecha$DdlDia']);
        $this->assertSame('0', $filters['ctl00$MainContent$CldFecha$DdlDiaFin']);
    }

    public function testMonthWithoutLastDayIsNotWholeMonth(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-30 23:59:59'),
            DownloadType::recibidos(),
        );

        $filters = (new InputsByFiltersReceivedFullEndDate($query))->getDateFilters();

        $this->assertSame('01', $filters['ctl00$MainContent$CldFecha$DdlDia']);
        $this->assertSame('30', $filters['ctl00$MainContent$CldFecha$DdlDiaFin']);
    }

    public function testMonthWithPartialEndTimeIsNotWholeMonth(): void
    {
        $query = new QueryByFilters(
            new DateTimeImmutable('2026-01-01 00:00:00'),
            new DateTimeImmutable('2026-01-31 12:00:00'),
            DownloadType::recibidos(),
        );

        $filters = (new InputsByFiltersReceivedFullEndDate($query))->getDateFilters();

        $this->assertSame('01', $filters['ctl00$MainContent$CldFecha$DdlDia']);
        $this->assertSame('31', $filters['ctl00$MainContent$CldFecha$DdlDiaFin']);
    }
}
