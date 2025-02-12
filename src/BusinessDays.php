<?php

namespace Experteam\ApiLaravelBase;


use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class BusinessDays
{

    /**
     * Get business days.
     *
     * @param int $days
     * @param string|null $processingDate
     * @return array
     */
    public function getDays($days, $processingDate = null)
    {
        $processingDate = $processingDate ? Carbon::parse($processingDate) : Carbon::now();

        if ($processingDate->isMonday() || $processingDate->isSunday()) {
            return null;
        }

        $count = 0;

        while ($count < $days) {
            $processingDate->subDay();
            if ($processingDate->isWeekday()) {
                $count++;
            }
        }

        $endDate = $startDate = $processingDate->format('Y-m-d');

        if ($processingDate->isFriday()) {
            $endDate = $processingDate->copy()->addDays(2)->format('Y-m-d');
        } elseif ($processingDate->isSaturday()) {
            $endDate = $processingDate->copy()->addDay()->format('Y-m-d');
        } elseif ($processingDate->isSunday()) {
            $endDate = $processingDate->copy()->addDay()->format('Y-m-d');
        }

        return [
            'start' => $startDate,
            'end' => $endDate,
        ];

    }

}
