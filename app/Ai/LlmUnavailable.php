<?php

namespace App\Ai;

use RuntimeException;

/**
 * The model cannot answer: off, killed, not configured, over budget, down, or an answer that is unusable. Its message never holds the API key or the request.
 */
class LlmUnavailable extends RuntimeException {}
