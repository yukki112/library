<?php
require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $pdo = null;

    public static function conn(): PDO {
        if (self::$pdo === null) {
            // DSN including the database.  We will attempt to connect and, if
            // the specified database does not yet exist, bootstrap it using
            // the migrations/schema.sql file.  This allows the system to run
            // out‑of‑the‑box without requiring manual database creation in
            // environments like XAMPP or fresh installations.
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            try {
                // First try connecting directly using the defined database.
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // MySQL error code 1049 corresponds to "Unknown database".  If
                // this happens, attempt to create the database and run the
                // schema migration automatically.  Any other error should be
                // rethrown to avoid masking unexpected configuration issues.
                if ((int)$e->getCode() === 1049) {
                    // Connect without specifying a database to perform
                    // administrative tasks such as creating the database and
                    // executing the schema.  Use the same connection options.
                    $dsnNoDb = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
                    $pdoTmp = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
                    // Create the database if it doesn't exist.  We specify
                    // utf8mb4_unicode_ci to match the collation used in the
                    // provided migration scripts.  Surround the name with
                    // backticks to avoid issues with reserved words.
                    $pdoTmp->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET ' . DB_CHARSET . ' COLLATE utf8mb4_unicode_ci');
                    // Now execute the schema SQL file to create all tables and
                    // seed default data.  The schema includes the INSERT
                    // statements for admin, librarian and assistant users, so
                    // you can log in immediately after a fresh install.  Use
                    // semicolon splitting to handle multiple statements.
                    $schemaFile = __DIR__ . '/../migrations/schema.sql';
                    if (file_exists($schemaFile)) {
                        $sql = file_get_contents($schemaFile);
                        // Switch to the newly created database before running
                        // the table creation commands.  This ensures
                        // subsequent CREATE TABLE statements target the
                        // correct schema.
                        $pdoTmp->exec('USE `' . DB_NAME . '`');
                        // Split the SQL into individual statements.  Trim
                        // whitespace and ignore empty fragments.
                        $statements = array_filter(array_map('trim', explode(';', $sql)));
                        foreach ($statements as $statement) {
                            if ($statement !== '') {
                                $pdoTmp->exec($statement);
                            }
                        }
                    }
                    // Attempt to connect again now that the database exists.
                    self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                } else {
                    // Rethrow any other exception as we cannot recover.
                    throw $e;
                }
            }
        }
        return self::$pdo;
    }
}
?>
