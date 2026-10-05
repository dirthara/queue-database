<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Integration;

use PDO;
use DateTimeZone;
use RuntimeException;
use DateTimeImmutable;
use Dirthara\Schema\Schema;
use Dirthara\Database\Database;
use PHPUnit\Framework\TestCase;
use Dirthara\Queue\Contract\Delivery;
use PHPUnit\Framework\Attributes\Test;
use Dirthara\Queue\ValueObject\Failure;
use Dirthara\Queue\ValueObject\Duration;
use Dirthara\QueueDatabase\DatabaseQueue;
use Dirthara\Schema\Grammar\SchemaGrammar;
use Dirthara\Queue\Config\QueueConfiguration;
use Dirthara\Queue\ValueObject\FailedMessage;
use Dirthara\Queue\ValueObject\QueuedMessage;
use Dirthara\Database\Connection\Driver\Driver;
use Dirthara\Database\Exception\QueryException;
use Dirthara\Database\Query\Grammar\QueryGrammar;
use Dirthara\Schema\Grammar\SchemaGrammarResolver;
use Dirthara\Database\Connection\ConnectionFactory;
use Dirthara\Database\Connection\ConnectionManager;
use Dirthara\Database\Connection\Driver\DriverName;
use Dirthara\QueueDatabase\Driver\DatabaseQueueDriver;
use Dirthara\QueueDatabase\Schema\QueueDatabaseSchema;
use Dirthara\QueueDatabase\Tests\Fixtures\FrozenClock;
use Dirthara\Database\Query\Grammar\QueryGrammarResolver;
use Dirthara\Queue\Exception\FailedMessageNotFoundException;
use Dirthara\Queue\Exception\DeliveryAlreadySettledException;
use Dirthara\QueueDatabase\Exception\QueueOperationException;
use Dirthara\Database\Connection\ValueObjects\ConnectionConfig;
use Dirthara\QueueDatabase\Tests\Fixtures\InterceptConnections;
use Dirthara\QueueDatabase\Tests\Fixtures\StringCodedException;
use Dirthara\QueueDatabase\Tests\Fixtures\InterceptingConnection;

use function getenv;
use function sprintf;
use function in_array;
use function array_map;
use function array_key_last;
use function iterator_to_array;

use const PHP_INT_MAX;

/**
 * @require-extends TestCase
 */
trait DatabaseQueueConformance
{
    private const string TABLE = 'conformance_queue_messages';

    private const string FAILED_TABLE = 'conformance_failed_messages';

    private const int RESERVATION_TIMEOUT_SECONDS = 30;

    private Database $database;

    private QueueDatabaseSchema $schema;

    private InterceptConnections $interceptor;

    private FrozenClock $clock;

    private DatabaseQueue $queue;

    abstract protected function driverName(): DriverName;

    abstract protected function driver(): Driver;

    abstract protected function schemaGrammar(): SchemaGrammar;

    abstract protected function queryGrammar(): QueryGrammar;

    abstract protected function config(): ConnectionConfig;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->driverIsAvailable()) {
            self::markTestSkipped(sprintf('The %s PDO driver is not installed.', $this->driverName()->value));
        }

        $this->interceptor = new InterceptConnections();
        $this->database = new Database(
            new ConnectionManager(
                new ConnectionFactory([$this->driver()], [$this->interceptor]),
                [$this->config()],
                default: 'conformance',
            ),
            new QueryGrammarResolver([$this->driverName()->value => $this->queryGrammar()]),
        );
        $this->schema = new QueueDatabaseSchema(new Schema($this->database, new SchemaGrammarResolver([
            $this->driverName()->value => $this->schemaGrammar(),
        ])), self::TABLE, self::FAILED_TABLE);
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-05 12:00:00', new DateTimeZone('UTC')));
        $this->queue = $this->queue('default');

        $this->schema->drop();
        $this->schema->create();
    }

    protected function tearDown(): void
    {
        if ($this->driverIsAvailable()) {
            $this->schema->drop();
        }

        parent::tearDown();
    }

    protected function driverIsAvailable(): bool
    {
        return in_array($this->driverName()->value, PDO::getAvailableDrivers(), strict: true);
    }

    protected function env(string $name, string $fallback): string
    {
        $value = getenv($name);

        return $value === false ? $fallback : $value;
    }

    #[Test]
    public function it_creates_and_drops_its_tables(): void
    {
        self::assertTrue($this->schema->exists());

        $this->schema->create();
        $this->schema->drop();

        self::assertFalse($this->schema->exists());
    }

    #[Test]
    public function it_reserves_nothing_from_an_empty_queue(): void
    {
        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_delivers_a_message_exactly_as_it_was_enqueued(): void
    {
        $payload = "O:8:\"stdClass\":1:{s:6:\"\x00*\x00key\";s:5:\"\xff\xfe\x00\x01v\";}";

        $this->queue->enqueue(new QueuedMessage('App\\Message\\SendInvoice', $payload));

        $delivery = $this->reserve();

        self::assertSame('App\\Message\\SendInvoice', $delivery->message->type);
        self::assertSame($payload, $delivery->message->payload);
        self::assertSame(1, $delivery->attempt);
    }

    #[Test]
    public function it_delivers_messages_in_the_order_they_became_available(): void
    {
        $this->queue->enqueue(new QueuedMessage('later', 'payload'), Duration::seconds(1));
        $this->queue->enqueue(new QueuedMessage('first', 'payload'));
        $this->queue->enqueue(new QueuedMessage('second', 'payload'));
        $this->clock->advance(1000);

        self::assertSame('first', $this->reserve()->message->type);
        self::assertSame('second', $this->reserve()->message->type);
        self::assertSame('later', $this->reserve()->message->type);
        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_holds_a_delayed_message_back_until_its_delay_has_passed(): void
    {
        $this->queue->enqueue(new QueuedMessage('delayed', 'payload'), Duration::seconds(2));

        $this->clock->advance(1999);
        self::assertNull($this->queue->reserve());

        $this->clock->advance(1);
        self::assertSame('delayed', $this->reserve()->message->type);
    }

    #[Test]
    public function it_rounds_a_delay_up_to_the_next_whole_second(): void
    {
        $this->clock->advance(250);
        $this->queue->enqueue(new QueuedMessage('delayed', 'payload'), Duration::milliseconds(500));
        $this->queue->enqueue(new QueuedMessage('immediate', 'payload'));

        self::assertSame('immediate', $this->reserve()->message->type);

        $this->clock->advance(749);
        self::assertNull($this->queue->reserve());

        $this->clock->advance(1);
        self::assertSame('delayed', $this->reserve()->message->type);
    }

    #[Test]
    public function it_never_delivers_a_message_delayed_beyond_the_largest_time_it_can_store(): void
    {
        if ($this->driverName() === DriverName::MySql) {
            self::markTestSkipped('A MySQL TIMESTAMP column cannot hold a time after 2038-01-19 03:14:07 UTC.');
        }

        $this->queue->enqueue(new QueuedMessage('never', 'payload'), Duration::milliseconds(PHP_INT_MAX));

        $this->clock->advance(3_153_600_000_000);

        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_keeps_queues_that_share_a_table_apart(): void
    {
        $other = $this->queue('other');

        $this->queue->enqueue(new QueuedMessage('default', 'payload'));
        $other->enqueue(new QueuedMessage('other', 'payload'));

        self::assertSame('other', $this->reserve($other)->message->type);
        self::assertNull($other->reserve());
        self::assertSame('default', $this->reserve()->message->type);
    }

    #[Test]
    public function it_hides_a_reserved_message_until_its_reservation_expires(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $this->reserve();

        $this->clock->advance((self::RESERVATION_TIMEOUT_SECONDS * 1000) - 1);
        self::assertNull($this->queue->reserve());

        $this->clock->advance(1);
        $redelivery = $this->reserve();

        self::assertSame('message', $redelivery->message->type);
        self::assertSame(2, $redelivery->attempt);
    }

    #[Test]
    public function it_gives_each_message_to_one_worker_when_two_reserve_at_once(): void
    {
        $this->queue->enqueue(new QueuedMessage('first', 'payload'));
        $this->queue->enqueue(new QueuedMessage('second', 'payload'));

        $competing = null;

        $this->connection()->beforeNext('UPDATE', function () use (&$competing): void {
            $competing = $this->queue->reserve();
        });

        $delivery = $this->reserve();

        self::assertInstanceOf(Delivery::class, $competing);
        self::assertSame('first', $competing->message->type);
        self::assertSame('second', $delivery->message->type);
        self::assertSame(1, $delivery->attempt);
    }

    #[Test]
    public function it_removes_an_acknowledged_message(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->reserve()->acknowledge();
        $this->clock->advance(self::RESERVATION_TIMEOUT_SECONDS * 1000);

        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_acknowledges_a_message_whose_expired_reservation_nobody_took_over(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $delivery = $this->reserve();

        $this->clock->advance(self::RESERVATION_TIMEOUT_SECONDS * 1000);
        $delivery->acknowledge();

        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_makes_a_released_message_available_again_as_the_next_attempt(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->reserve()->release();
        $redelivery = $this->reserve();

        self::assertSame('message', $redelivery->message->type);
        self::assertSame(2, $redelivery->attempt);
    }

    #[Test]
    public function it_holds_a_message_released_with_a_delay_back_until_the_delay_ends(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->reserve()->release(Duration::seconds(1));

        $this->clock->advance(999);
        self::assertNull($this->queue->reserve());

        $this->clock->advance(1);
        self::assertSame(2, $this->reserve()->attempt);
    }

    #[Test]
    public function it_releases_a_message_with_a_delay_that_leaves_its_availability_unchanged(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->reserve()->release(Duration::seconds(self::RESERVATION_TIMEOUT_SECONDS));

        $this->clock->advance((self::RESERVATION_TIMEOUT_SECONDS * 1000) - 1);
        self::assertNull($this->queue->reserve());

        $this->clock->advance(1);
        self::assertSame(2, $this->reserve()->attempt);
    }

    #[Test]
    public function it_moves_a_failed_message_to_the_failed_messages_with_its_failure(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', "pay\x00load"));
        $this->reserve()->release();

        $this->reserve()->fail(new RuntimeException('The invoice could not be sent.', 42));

        $failed = $this->failed();

        self::assertCount(1, $failed);
        self::assertSame('message', $failed[0]->message->type);
        self::assertSame("pay\x00load", $failed[0]->message->payload);
        self::assertSame(2, $failed[0]->attempt);
        self::assertEquals(
            new Failure(RuntimeException::class, 'The invoice could not be sent.', 42),
            $failed[0]->failure,
        );
        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_keeps_a_string_failure_code(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->reserve()->fail(new StringCodedException('Constraint violated.', '23000'));

        self::assertSame('23000', $this->failed()[0]->failure?->code);
    }

    #[Test]
    public function it_records_a_failure_without_a_cause(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->reserve()->fail();

        self::assertNull($this->failed()[0]->failure);
    }

    #[Test]
    public function it_settles_a_delivery_only_once(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $delivery = $this->reserve();

        $delivery->acknowledge();

        $this->expectException(DeliveryAlreadySettledException::class);

        $delivery->fail();
    }

    #[Test]
    public function it_refuses_to_acknowledge_a_message_another_worker_reserved_again(): void
    {
        $delivery = $this->reservedTwice();

        try {
            $delivery->acknowledge();
            self::fail('Acknowledging a lost reservation succeeded.');
        } catch (QueueOperationException $exception) {
            self::assertSame(1, $exception->context['attempt']);
        }

        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_refuses_to_release_a_message_another_worker_reserved_again(): void
    {
        $delivery = $this->reservedTwice();

        $this->expectExceptionObject(QueueOperationException::reservationLost(
            'default',
            self::TABLE,
            'conformance',
            $this->messageId(),
            attempt: 1,
        ));

        $delivery->release();
    }

    #[Test]
    public function it_refuses_to_fail_a_message_another_worker_reserved_again(): void
    {
        $delivery = $this->reservedTwice();

        try {
            $delivery->fail(new RuntimeException('Too late.'));
            self::fail('Failing a lost reservation succeeded.');
        } catch (QueueOperationException $exception) {
            self::assertSame(1, $exception->context['attempt']);
        }

        self::assertSame([], $this->failed());
    }

    #[Test]
    public function it_lists_the_failed_messages_of_its_own_queue_in_the_order_they_failed(): void
    {
        $other = $this->queue('other');

        $this->queue->enqueue(new QueuedMessage('first', 'payload'));
        $other->enqueue(new QueuedMessage('other', 'payload'));
        $this->queue->enqueue(new QueuedMessage('second', 'payload'));

        $this->reserve()->fail();
        $this->reserve($other)->fail();
        $this->reserve()->fail();

        self::assertSame(
            ['first', 'second'],
            array_map(static fn(FailedMessage $failed): string => $failed->message->type, $this->failed()),
        );
        self::assertSame(
            ['other'],
            array_map(static fn(FailedMessage $failed): string => $failed->message->type, $this->failed($other)),
        );
    }

    #[Test]
    public function it_finds_a_failed_message_by_its_id(): void
    {
        $id = $this->failedMessage('message');

        self::assertEquals(
            new FailedMessage($id, new QueuedMessage('message', 'payload'), attempt: 1),
            $this->queue->findFailed($id),
        );
    }

    #[Test]
    public function it_finds_nothing_for_an_id_no_failed_message_of_its_queue_has(): void
    {
        $id = $this->failedMessage('message');

        self::assertNull($this->queue->findFailed('999999'));
        self::assertNull($this->queue('other')->findFailed($id));
    }

    #[Test]
    public function it_finds_nothing_for_an_id_that_is_not_a_failed_message_key(): void
    {
        $this->failedMessage('message');

        foreach (['', 'abc', '0', '-1', '01', ' 1', '1.0', '99999999999999999999'] as $id) {
            self::assertNull($this->queue->findFailed($id), sprintf('Found a failed message for id "%s".', $id));
        }
    }

    #[Test]
    public function it_retries_a_failed_message_as_a_new_message(): void
    {
        $id = $this->failedMessage('message', "pay\x00load");

        $this->queue->retry($id);

        $delivery = $this->reserve();

        self::assertSame('message', $delivery->message->type);
        self::assertSame("pay\x00load", $delivery->message->payload);
        self::assertSame(1, $delivery->attempt);
        self::assertSame([], $this->failed());
    }

    #[Test]
    public function it_refuses_to_retry_a_failed_message_it_cannot_find(): void
    {
        $id = $this->failedMessage('message');

        foreach (['999999', 'abc'] as $missing) {
            try {
                $this->queue->retry($missing);
                self::fail(sprintf('Retrying the missing failed message "%s" succeeded.', $missing));
            } catch (FailedMessageNotFoundException $exception) {
                self::assertSame($missing, $exception->context['id']);
            }
        }

        $this->expectException(FailedMessageNotFoundException::class);

        $this->queue('other')->retry($id);
    }

    #[Test]
    public function it_refuses_to_retry_a_failed_message_another_process_removed_meanwhile(): void
    {
        $id = $this->failedMessage('message');

        $this->connection()->beforeNext('DELETE', function () use ($id): void {
            $this->queue->forget($id);
        });

        try {
            $this->queue->retry($id);
            self::fail('Retrying a removed failed message succeeded.');
        } catch (FailedMessageNotFoundException) {
            self::assertNull($this->queue->reserve());
        }
    }

    #[Test]
    public function it_forgets_a_failed_message(): void
    {
        $id = $this->failedMessage('message');
        $kept = $this->failedMessage('kept');

        $this->queue->forget($id);

        self::assertNull($this->queue->findFailed($id));
        self::assertNotNull($this->queue->findFailed($kept));
    }

    #[Test]
    public function it_refuses_to_forget_a_failed_message_it_cannot_find(): void
    {
        $id = $this->failedMessage('message');

        foreach (['999999', 'abc'] as $missing) {
            try {
                $this->queue->forget($missing);
                self::fail(sprintf('Forgetting the missing failed message "%s" succeeded.', $missing));
            } catch (FailedMessageNotFoundException $exception) {
                self::assertSame($missing, $exception->context['id']);
            }
        }

        $this->expectException(FailedMessageNotFoundException::class);

        $this->queue('other')->forget($id);
    }

    #[Test]
    public function it_refuses_a_message_whose_stored_payload_is_malformed(): void
    {
        $this->database
            ->table(self::TABLE)
            ->insert([
                'queue' => 'default',
                'type' => 'message',
                'payload' => 'not base64!',
                'attempts' => 0,
                'available_at' => '2026-10-05 12:00:00',
                'created_at' => '2026-10-05 12:00:00',
            ]);

        try {
            $this->queue->reserve();
            self::fail('Reserving a malformed message succeeded.');
        } catch (QueueOperationException $exception) {
            self::assertSame('payload', $exception->context['column']);
            self::assertSame(self::TABLE, $exception->context['table']);
        }

        self::assertSame(0, (int) $this->database->table(self::TABLE)->max('attempts'));
    }

    #[Test]
    public function it_wraps_a_database_failure_while_enqueueing(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::TABLE, function (): void {
            $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        });
    }

    #[Test]
    public function it_wraps_a_database_failure_while_reserving(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::TABLE, function (): void {
            $this->queue->reserve();
        });
    }

    #[Test]
    public function it_wraps_a_database_failure_while_acknowledging(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $delivery = $this->reserve();
        $this->schema->drop();

        $this->expectOperationFailure(self::TABLE, $delivery->acknowledge(...));
    }

    #[Test]
    public function it_wraps_a_database_failure_while_releasing(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $delivery = $this->reserve();
        $this->schema->drop();

        $this->expectOperationFailure(self::TABLE, $delivery->release(...));
    }

    #[Test]
    public function it_wraps_a_database_failure_while_failing(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $delivery = $this->reserve();
        $this->schema->drop();

        $this->expectOperationFailure(self::FAILED_TABLE, $delivery->fail(...));
    }

    #[Test]
    public function it_wraps_a_database_failure_while_listing_failed_messages(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::FAILED_TABLE, function (): void {
            $this->queue->failed();
        });
    }

    #[Test]
    public function it_wraps_a_database_failure_while_finding_a_failed_message(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::FAILED_TABLE, function (): void {
            $this->queue->findFailed('1');
        });
    }

    #[Test]
    public function it_wraps_a_database_failure_while_retrying_a_failed_message(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::FAILED_TABLE, function (): void {
            $this->queue->retry('1');
        });
    }

    #[Test]
    public function it_wraps_a_database_failure_while_forgetting_a_failed_message(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::FAILED_TABLE, function (): void {
            $this->queue->forget('1');
        });
    }

    private function queue(string $name): DatabaseQueue
    {
        $queue = new DatabaseQueueDriver($this->database, $this->clock)->create(new QueueConfiguration('database', [
            'queue' => $name,
            'table' => self::TABLE,
            'failed_table' => self::FAILED_TABLE,
            'reservation_timeout' => self::RESERVATION_TIMEOUT_SECONDS,
        ]));

        self::assertInstanceOf(DatabaseQueue::class, $queue);

        return $queue;
    }

    private function connection(): InterceptingConnection
    {
        $connection = $this->interceptor->connection;

        self::assertNotNull($connection);

        return $connection;
    }

    private function reserve(?DatabaseQueue $queue = null): Delivery
    {
        $delivery = ($queue ?? $this->queue)->reserve();

        self::assertNotNull($delivery);

        return $delivery;
    }

    /**
     * @return list<FailedMessage>
     */
    private function failed(?DatabaseQueue $queue = null): array
    {
        return iterator_to_array(($queue ?? $this->queue)->failed(), preserve_keys: false);
    }

    private function failedMessage(string $type, string $payload = 'payload'): string
    {
        $this->queue->enqueue(new QueuedMessage($type, $payload));
        $this->reserve()->fail();

        $failed = $this->failed();
        $last = array_key_last($failed);

        self::assertNotNull($last);

        return $failed[$last]->id;
    }

    private function messageId(): int
    {
        return (int) $this->database->table(self::TABLE)->max('id');
    }

    private function reservedTwice(): Delivery
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $delivery = $this->reserve();

        $this->clock->advance(self::RESERVATION_TIMEOUT_SECONDS * 1000);
        self::assertSame(2, $this->reserve()->attempt);

        return $delivery;
    }

    /**
     * @param callable(): mixed $operation
     */
    private function expectOperationFailure(string $table, callable $operation): void
    {
        try {
            $operation();
            self::fail('The operation succeeded without its tables.');
        } catch (QueueOperationException $exception) {
            self::assertInstanceOf(QueryException::class, $exception->getPrevious());
            self::assertSame(['queue' => 'default', 'table' => $table, 'connection' => 'conformance'], [
                'queue' => $exception->context['queue'],
                'table' => $exception->context['table'],
                'connection' => $exception->context['connection'],
            ]);
        }
    }
}
