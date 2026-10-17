<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DiagnosticBundle;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SQLite3;
use Throwable;
use ZipArchive;

/** Lit uniquement la copie SQLite contenue dans un rapport de diagnostic. */
final class DiagnosticSqliteReader
{
    private const MAX_DATABASE_BYTES = 50 * 1024 * 1024;

    /**
     * @template T
     *
     * @param  callable(SQLite3): T  $callback
     * @return T
     */
    public function read(DiagnosticBundle $bundle, callable $callback): mixed
    {
        $archivePath = Storage::disk('local')->path($bundle->file_path);
        if (! is_file($archivePath)) {
            throw new RuntimeException("L'archive est introuvable sur le serveur.");
        }

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Archive illisible ou corrompue.');
        }

        $temporaryPath = null;
        $database = null;

        try {
            // Le nom vient de la métadonnée du rapport, jamais d'un chemin fourni au GET.
            $name = $bundle->db_name;
            if (! is_string($name) || $name === '' || basename($name) !== $name) {
                throw new RuntimeException('Ce rapport ne contient pas de base SQLite.');
            }

            $index = $zip->locateName($name);
            $stat = $index === false ? false : $zip->statIndex($index);
            if ($stat === false) {
                throw new RuntimeException('Ce rapport ne contient pas de base SQLite.');
            }
            if ((int) $stat['size'] > self::MAX_DATABASE_BYTES) {
                throw new RuntimeException('La base SQLite dépasse la taille autorisée.');
            }

            $input = $zip->getStream($name);
            $temporaryPath = tempnam(sys_get_temp_dir(), 'coudi-sqlite-');
            if ($input === false || $temporaryPath === false) {
                throw new RuntimeException('Impossible de lire la base SQLite.');
            }

            $output = fopen($temporaryPath, 'wb');
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('Impossible de préparer la base SQLite.');
            }
            try {
                $copied = stream_copy_to_stream($input, $output, self::MAX_DATABASE_BYTES + 1);
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($copied === false || $copied > self::MAX_DATABASE_BYTES) {
                throw new RuntimeException('La base SQLite dépasse la taille autorisée.');
            }

            $header = file_get_contents($temporaryPath, false, null, 0, 16);
            if ($header !== "SQLite format 3\0") {
                throw new RuntimeException("Le fichier reçu n'est pas une base SQLite valide.");
            }

            $database = new SQLite3($temporaryPath, SQLITE3_OPEN_READONLY);
            $database->enableExceptions(true);
            $database->exec('PRAGMA query_only = ON');

            return $callback($database);
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException) {
                throw $error;
            }
            throw new RuntimeException('Base SQLite illisible ou corrompue.', previous: $error);
        } finally {
            $database?->close();
            $zip->close();
            if (is_string($temporaryPath) && is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
