<?php

namespace App\Ai\Assistant;

use RuntimeException;

/**
 * A tool call whose arguments failed validation; the message is safe to show the model.
 */
class InvalidToolArguments extends RuntimeException {}
