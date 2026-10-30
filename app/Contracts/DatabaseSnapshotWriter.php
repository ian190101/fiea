<?php
declare(strict_types=1);
namespace App\Contracts;

interface DatabaseSnapshotWriter
{
    /** Devuelve la ruta temporal privada; quien llama es responsable de eliminarla. */
    public function write(): string;
}
