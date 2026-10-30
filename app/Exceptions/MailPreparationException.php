<?php

declare(strict_types=1);

namespace App\Exceptions;

// No se ha iniciado HTTP: permite corregir la preparación sin suponer una entrega ambigua.
final class MailPreparationException extends \RuntimeException {}
