<?php

namespace App\Exceptions\Wallet;

use DomainException;

final class IdempotencyConflict extends DomainException {}
