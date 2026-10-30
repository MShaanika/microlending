<?php

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Model;
use PHPUnit\Framework\TestCase;

/**
 * A slow call between two queries (AI statement analysis, a bureau or bank API) can outlast MySQL's wait_timeout, and the next query fails
 * with "MySQL server has gone away". The model layer reconnects and retries once -- never inside a transaction -- and the shared connection
 * pings itself after a quiet spell. The connection is really killed from a second connection here.
 */
class DatabaseReconnectTest extends TestCase
{
    private const TABLE = 'phpunit_gone_away';

    protected function setUp(): void
    {
        Database::connection()->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(40) NOT NULL)');
    }

    protected function tearDown(): void
    {
        $pdo = Database::reconnect();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    /** Kills the server side of the shared connection, exactly as MySQL does when it has sat idle too long. */
    private function killSharedConnection(): void
    {
        $threadId = (int) Database::connection()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $config = require ROOT_PATH . '/config/database.php';
        $other = new \PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname=" . (getenv('MLS_DB_NAME') ?: $config['database']) . ";charset={$config['charset']}",
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $other->exec('KILL ' . $threadId);
        usleep(200000);
    }

    private function model(): Model
    {
        return new class () extends Model {
            public function value(): mixed
            {
                return $this->scalar('SELECT 42');
            }

            public function add(string $label): int
            {
                return $this->insert('phpunit_gone_away', ['label' => $label]);
            }

            public function rename(int $id, string $label): bool
            {
                return $this->update('phpunit_gone_away', ['label' => $label], 'id', $id);
            }

            public function labelOf(int $id): mixed
            {
                return $this->scalar('SELECT label FROM phpunit_gone_away WHERE id = ?', [$id]);
            }
        };
    }

    public function testAReadSurvivesADroppedConnection(): void
    {
        $model = $this->model();
        $this->assertSame(42, (int) $model->value());
        $this->killSharedConnection();
        $this->assertSame(42, (int) $model->value(), 'reconnected and retried once');
    }

    public function testAnInsertAndAnUpdateSurviveADroppedConnection(): void
    {
        $model = $this->model();
        $this->killSharedConnection();
        $id = $model->add('first');
        $this->assertGreaterThan(0, $id);
        $this->killSharedConnection();
        $this->assertTrue($model->rename($id, 'second'));
        $this->assertSame('second', $model->labelOf($id), 'and the data really was written');
    }

    public function testATransactionIsNeverSilentlyRetried(): void
    {
        $model = $this->model();
        $model->add('seed');
        $this->expectException(\PDOException::class);
        try {
            $model->transaction(function () use ($model) {
                $model->add('inside the transaction');
                $this->killSharedConnection();
                $model->add('after the connection died'); // must fail loudly, not retry on a new connection outside the transaction
            });
        } finally {
            $this->assertSame(0, (int) Database::connection()->query("SELECT COUNT(*) FROM " . self::TABLE . " WHERE label = 'after the connection died'")->fetchColumn());
        }
    }

    public function testATransactionStartsOnAFreshConnectionWhenTheOldOneDiedWhileIdle(): void
    {
        $model = $this->model();
        $this->assertSame(42, (int) $model->value());
        $this->killSharedConnection();
        $id = $model->transaction(fn () => $model->add('in a new transaction'));
        $this->assertGreaterThan(0, $id);
        $this->assertSame('in a new transaction', $model->labelOf($id), 'committed');
    }

    public function testTheSharedConnectionPingsItselfAfterAQuietSpell(): void
    {
        Database::connection();
        $this->killSharedConnection();

        // pretend it has been idle for a minute
        $lastUse = new \ReflectionProperty(Database::class, 'lastUse');
        $lastUse->setAccessible(true);
        $lastUse->setValue(null, time() - 60);

        $this->assertSame(1, (int) Database::connection()->query('SELECT 1')->fetchColumn(), 'a working connection comes back, not the dead one');
    }

    public function testOnlyTheGoneAwayKindOfFailureIsRecognised(): void
    {
        $gone = new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $gone->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        $this->assertTrue(Database::isGoneAway($gone));

        $lost = new \PDOException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query');
        $this->assertTrue(Database::isGoneAway($lost));

        $syntax = new \PDOException('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax');
        $syntax->errorInfo = ['42000', 1064, 'You have an error in your SQL syntax'];
        $this->assertFalse(Database::isGoneAway($syntax), 'a real SQL error is never retried');
        $this->assertFalse(Database::isGoneAway(new \RuntimeException('something else')));
    }
}
