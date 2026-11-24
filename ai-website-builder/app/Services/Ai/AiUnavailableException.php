<?php

namespace App\Services\Ai;

use RuntimeException;

/** The AI provider could not be reached or refused the call. Work already saved is kept. */
class AiUnavailableException extends RuntimeException {}
