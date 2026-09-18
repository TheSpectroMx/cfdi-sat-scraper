<?php

declare(strict_types=1);

namespace PhpCfdi\CfdiSatScraper\Inputs;

use PhpCfdi\CfdiSatScraper\QueryByFilters;

/**
 * Inputs for received CFDI that send the complete end date to the SAT portal.
 *
 * InputsByFiltersReceived only sends the end time (hour, minute and second), so the portal
 * assumes the end date is the same as the start date and the results are limited to one day.
 * This implementation also sends end year, month and day.
 *
 * Additionally, when the query period covers a whole month (first day at 00:00:00 to last day
 * at 23:59:59) it sends the day fields as "0" ("Todos"), as the portal expects; otherwise the
 * portal only returns the documents of the first day.
 *
 * @see InputsByFiltersReceived
 */
class InputsByFiltersReceivedFullEndDate extends InputsByFilters implements InputsInterface
{
    /** @return array<string, string> */
    public function getDateFilters(): array
    {
        /** @var QueryByFilters $query PhpStorm does not know correct type by template */
        $query = $this->getQuery();
        $startDate = $query->getStartDate();
        $endDate = $query->getEndDate();

        $isWholeMonth = $this->isWholeMonth($query);
        $startDay = $isWholeMonth ? '0' : $this->sidate($startDate, 'd', 2);
        $endDay = $isWholeMonth ? '0' : $this->sidate($endDate, 'd', 2);

        return [
            'ctl00$MainContent$CldFecha$DdlAnio' => $startDate->format('Y'),
            'ctl00$MainContent$CldFecha$DdlMes' => $this->sidate($startDate, 'm', 1),
            'ctl00$MainContent$CldFecha$DdlDia' => $startDay,
            'ctl00$MainContent$CldFecha$DdlHora' => $this->sidate($startDate, 'H', 1),
            'ctl00$MainContent$CldFecha$DdlMinuto' => $this->sidate($startDate, 'i', 1),
            'ctl00$MainContent$CldFecha$DdlSegundo' => $this->sidate($startDate, 's', 1),
            'ctl00$MainContent$CldFecha$DdlAnioFin' => $endDate->format('Y'),
            'ctl00$MainContent$CldFecha$DdlMesFin' => $this->sidate($endDate, 'm', 1),
            'ctl00$MainContent$CldFecha$DdlDiaFin' => $endDay,
            'ctl00$MainContent$CldFecha$DdlHoraFin' => $this->sidate($endDate, 'H', 1),
            'ctl00$MainContent$CldFecha$DdlMinutoFin' => $this->sidate($endDate, 'i', 1),
            'ctl00$MainContent$CldFecha$DdlSegundoFin' => $this->sidate($endDate, 's', 1),
        ];
    }

    /**
     * The query period covers a whole month when it starts on the first day at 00:00:00
     * and ends on the last day of the same month at 23:59:59.
     */
    private function isWholeMonth(QueryByFilters $query): bool
    {
        $startDate = $query->getStartDate();
        $endDate = $query->getEndDate();

        return $startDate->format('Y-m') === $endDate->format('Y-m')
            && '01' === $startDate->format('d')
            && $endDate->format('d') === $endDate->format('t')
            && '00:00:00' === $startDate->format('H:i:s')
            && '23:59:59' === $endDate->format('H:i:s');
    }
}
