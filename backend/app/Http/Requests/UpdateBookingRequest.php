<?php

namespace App\Http\Requests;

/** Correcting an active booking takes the same fields, with the same rules, as making one. */
class UpdateBookingRequest extends StoreBookingRequest {}
