<?php

use App\Enums\ServiceRequestStatus;

it('lets only an open request become awarded or cancelled', function (ServiceRequestStatus $from, ServiceRequestStatus $to) {
    $allowed = [['open', 'awarded'], ['open', 'cancelled']];

    expect($from->canBecome($to))->toBe(in_array([$from->value, $to->value], $allowed, true));
})->with(ServiceRequestStatus::cases())->with(ServiceRequestStatus::cases());
