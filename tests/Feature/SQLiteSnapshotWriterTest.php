<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Services\Backup\SQLiteSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

final class SQLiteSnapshotWriterTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_restaura_bytes_tipos_secuencias_indices_vistas_y_triggers():void
    {
        DB::statement('CREATE TABLE sqlitex_payload(id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT, payload BLOB, number REAL)');
        DB::statement('CREATE TABLE sqlitex_log(id INTEGER)');
        $source=DB::connection()->getPdo();
        $statement=$source->prepare('INSERT INTO sqlitex_payload(body,payload,number) VALUES(?,?,?)');
        $body="Comillas ' y \"; español ñ\ntexto\0completo";
        $blob="\0\xff\x01\x7f";
        $statement->bindValue(1,$body,PDO::PARAM_STR);
        $statement->bindValue(2,$blob,PDO::PARAM_LOB);
        $statement->bindValue(3,'1.2345678901234567',PDO::PARAM_STR);
        $statement->execute();
        $source->exec('INSERT INTO sqlitex_payload(body) VALUES(NULL); DELETE FROM sqlitex_payload WHERE id=2');
        $source->exec('CREATE INDEX payload_body_idx ON sqlitex_payload(body)');
        $source->exec('CREATE VIEW sqlitex_view AS SELECT id,body FROM sqlitex_payload');
        $source->exec('CREATE TRIGGER payload_log AFTER INSERT ON sqlitex_payload BEGIN INSERT INTO sqlitex_log(id) VALUES(new.id); END');
        $path=app(SQLiteSnapshotWriter::class)->write();
        try {
            $restored=new PDO('sqlite::memory:');
            $restored->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $restored->exec(file_get_contents($path));
            $select='SELECT id,hex(body),typeof(body),hex(payload),typeof(payload),number FROM sqlitex_payload';
            self::assertSame($source->query($select)->fetchAll(PDO::FETCH_NUM),$restored->query($select)->fetchAll(PDO::FETCH_NUM));
            self::assertSame($body,$restored->query('SELECT body FROM sqlitex_view')->fetchColumn());
            $restored->exec("INSERT INTO sqlitex_payload(body) VALUES('nuevo')");
            self::assertSame(3,(int)$restored->lastInsertId());
            self::assertSame(3,(int)$restored->query('SELECT id FROM sqlitex_log')->fetchColumn());
            self::assertSame(1,(int)$restored->query("SELECT COUNT(*) FROM sqlite_master WHERE name='payload_body_idx'")->fetchColumn());
            self::assertSame([],$restored->query('PRAGMA foreign_key_check')->fetchAll());
        } finally { if (is_file($path)) unlink($path); }
    }

    public function test_un_valor_no_representable_no_deja_un_snapshot_parcial():void
    {
        DB::statement('CREATE TABLE sqlitex_nonfinite(number REAL)');
        DB::statement('INSERT INTO sqlitex_nonfinite VALUES(9e999)');
        $pattern=storage_path('app/private/backups/tmp/*.sql');
        $before=glob($pattern)?:[];
        try { app(SQLiteSnapshotWriter::class)->write(); self::fail('Un respaldo incompleto no puede publicarse.'); }
        catch (\RuntimeException $e) { self::assertSame('Se encontró un número no finito.',$e->getMessage()); }
        self::assertSame($before,glob($pattern)?:[]);
    }
}
