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
use PHPUnit\Framework\Attributes\DataProvider;
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
use function is_int;
use function sprintf;
use function in_array;
use function array_map;
use function is_string;
use function is_numeric;
use function array_replace;
use function array_key_last;
use function iterator_to_array;
use function array_intersect_key;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

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
        $this->schema = new QueueDatabaseSchema($this->schemaFor(), self::TABLE, self::FAILED_TABLE);
        $this->clock = new FrozenClock($this->at('12:00:00.250'));
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
    public function it_holds_a_delayed_message_back_to_the_millisecond(): void
    {
        $this->queue->enqueue(new QueuedMessage('delayed', 'payload'), Duration::milliseconds(1500));

        $this->clock->advance(1499);
        self::assertNull($this->queue->reserve());

        $this->clock->advance(1);
        self::assertSame('delayed', $this->reserve()->message->type);
    }

    #[Test]
    public function it_rounds_a_delay_up_to_the_next_whole_millisecond(): void
    {
        $this->clock->set($this->at('12:00:00.000400'));
        $this->queue->enqueue(new QueuedMessage('delayed', 'payload'), Duration::milliseconds(500));
        $this->queue->enqueue(new QueuedMessage('immediate', 'payload'));

        self::assertSame('immediate', $this->reserve()->message->type);

        $this->clock->set($this->at('12:00:00.500999'));
        self::assertNull($this->queue->reserve());

        $this->clock->set($this->at('12:00:00.501000'));
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

        $this->reserve()->release(Duration::milliseconds(250));

        $this->clock->advance(249);
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
    public function it_purges_the_failed_messages_that_failed_before_a_moment(): void
    {
        $this->failedMessage('first');
        $this->clock->advance(60_000);
        $this->failedMessage('second');
        $this->clock->advance(60_000);
        $this->failedMessage('third');

        self::assertSame(1, $this->queue->purgeFailed($this->at('12:01:00.250')));
        self::assertSame(['second', 'third'], $this->failedTypes());
        self::assertSame(0, $this->queue->purgeFailed($this->at('12:01:00.250')));
    }

    #[Test]
    public function it_keeps_a_failed_message_that_failed_in_the_same_second_as_the_purge_moment(): void
    {
        $this->failedMessage('message');

        self::assertSame(0, $this->queue->purgeFailed($this->at('12:00:00.999')));
        self::assertSame(1, $this->queue->purgeFailed($this->at('12:00:01')));
    }

    #[Test]
    public function it_compares_the_purge_moment_in_utc(): void
    {
        $this->failedMessage('message');

        $before = $this->at('12:00:00')->setTimezone(new DateTimeZone('Pacific/Kiritimati'));
        self::assertSame(0, $this->queue->purgeFailed($before));

        $after = $this->at('12:00:01')->setTimezone(new DateTimeZone('America/Los_Angeles'));
        self::assertSame(1, $this->queue->purgeFailed($after));
    }

    #[Test]
    public function it_purges_only_the_failed_messages_of_its_own_queue(): void
    {
        $other = $this->queue('other');
        $this->failedMessage('default');
        $other->enqueue(new QueuedMessage('other', 'payload'));
        $this->reserve($other)->fail();

        self::assertSame(1, $this->queue->purgeFailed($this->at('13:00:00')));
        self::assertSame([], $this->failedTypes());
        self::assertCount(1, $this->failed($other));
    }

    #[Test]
    public function it_truncates_every_failed_message_of_its_own_queue(): void
    {
        $other = $this->queue('other');
        $this->failedMessage('first');
        $this->failedMessage('second');
        $other->enqueue(new QueuedMessage('other', 'payload'));
        $this->reserve($other)->fail();
        $this->queue->enqueue(new QueuedMessage('waiting', 'payload'));

        self::assertSame(2, $this->queue->truncateFailed());
        self::assertSame([], $this->failedTypes());
        self::assertSame(0, $this->queue->truncateFailed());
        self::assertCount(1, $this->failed($other));
        self::assertSame('waiting', $this->reserve()->message->type);
    }

    #[Test]
    public function it_wraps_a_database_failure_while_purging_failed_messages(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::FAILED_TABLE, function (): void {
            $this->queue->purgeFailed($this->at('12:00:00'));
        });
    }

    #[Test]
    public function it_wraps_a_database_failure_while_truncating_failed_messages(): void
    {
        $this->schema->drop();

        $this->expectOperationFailure(self::FAILED_TABLE, function (): void {
            $this->queue->truncateFailed();
        });
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
    public function it_reports_its_tables_missing_while_one_of_them_is_missing(): void
    {
        $schema = new QueueDatabaseSchema($this->schemaFor(), self::TABLE, 'conformance_missing_failed_messages');

        self::assertFalse($schema->exists());
    }

    #[Test]
    public function it_creates_a_queue_with_the_default_options(): void
    {
        $schema = new QueueDatabaseSchema($this->schemaFor());
        $schema->drop();
        $schema->create();

        try {
            $queue = new DatabaseQueueDriver($this->database, $this->clock)->create(new QueueConfiguration('database'));
            self::assertInstanceOf(DatabaseQueue::class, $queue);

            $queue->enqueue(new QueuedMessage('message', 'payload'));
            self::assertSame(1, $queue->reserve()?->attempt);

            self::assertSame(
                ['queue' => 'default', 'attempts' => 1],
                $this->only($this->database->table('queue_messages')->first(), 'queue', 'attempts'),
            );

            $this->clock->advance(59_999);
            self::assertNull($queue->reserve());

            $this->clock->advance(1);
            $redelivery = $queue->reserve();
            self::assertNotNull($redelivery);
            self::assertSame(2, $redelivery->attempt);

            $redelivery->fail();
            $failed = iterator_to_array($queue->failed(), preserve_keys: false);

            self::assertCount(1, $failed);
            self::assertNotNull($this->database->table('failed_messages')->first());
        } finally {
            $schema->drop();
        }
    }

    #[Test]
    public function it_runs_on_the_connection_its_configuration_names(): void
    {
        $named = new DatabaseQueueDriver($this->database, $this->clock)->create(new QueueConfiguration('database', [
            'connection' => 'conformance',
            'table' => self::TABLE,
            'failed_table' => self::FAILED_TABLE,
        ]));

        $named->enqueue(new QueuedMessage('message', 'payload'));

        self::assertSame('message', $this->reserve()->message->type);
    }

    #[Test]
    public function it_stores_times_in_utc_whatever_the_time_zone_of_its_clock(): void
    {
        $this->clock->set($this->at('12:00:00.250')->setTimezone(new DateTimeZone('Pacific/Kiritimati')));

        $this->queue->enqueue(new QueuedMessage('message', 'payload'), Duration::seconds(1));
        $this->clock->set($this->at('12:00:01.249')->setTimezone(new DateTimeZone('America/Los_Angeles')));
        self::assertNull($this->queue->reserve());

        $this->clock->set($this->at('12:00:01.250'));
        self::assertNotNull($this->queue->reserve());

        $row = $this->database->table(self::TABLE)->first();

        self::assertStringStartsWith('2026-10-05 12:00:31.25', $this->stored($row, 'available_at'));
        self::assertStringStartsWith('2026-10-05 12:00:00', $this->stored($row, 'created_at'));
    }

    #[Test]
    public function it_records_when_a_message_was_queued_and_when_it_failed(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $this->clock->advance(90_000);

        $this->reserve()->fail();

        self::assertStringStartsWith('2026-10-05 12:01:30', $this->stored(
            $this->database->table(self::FAILED_TABLE)->first(),
            'failed_at',
        ));
    }

    #[Test]
    public function it_discards_a_message_enqueued_in_a_transaction_that_rolls_back(): void
    {
        try {
            $this->database->transaction(function (): void {
                $this->queue->enqueue(new QueuedMessage('message', 'payload'));

                throw new RuntimeException('Roll back.');
            });
        } catch (RuntimeException) {
            self::assertNull($this->queue->reserve());
        }

        $this->database->transaction(function (): void {
            $this->queue->enqueue(new QueuedMessage('committed', 'payload'));
        });

        self::assertSame('committed', $this->reserve()->message->type);
    }

    #[Test]
    public function it_reserves_a_released_message_after_the_messages_available_before_it(): void
    {
        $this->queue->enqueue(new QueuedMessage('first', 'payload'));
        $this->queue->enqueue(new QueuedMessage('second', 'payload'));
        $this->clock->advance(1);

        $this->reserve()->release();

        self::assertSame('second', $this->reserve()->message->type);
        self::assertSame('first', $this->reserve()->message->type);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedAttempts(): iterable
    {
        yield 'boolean' => [true];
        yield 'float' => [1.0];
        yield 'decimal string' => ['1.0'];
        yield 'scientific notation' => ['1e3'];
        yield 'leading whitespace' => [' 1'];
        yield 'trailing whitespace' => ['1 '];
        yield 'leading zero' => ['01'];
        yield 'plus sign' => ['+1'];
        yield 'negative zero' => ['-0'];
        yield 'arbitrary string' => ['potato'];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'negative integer' => [-1];
        yield 'negative integer string' => ['-1'];
        yield 'beyond the integer range' => ['9223372036854775808'];
    }

    #[Test]
    #[DataProvider('malformedAttempts')]
    public function it_refuses_a_message_whose_stored_attempts_are_malformed(mixed $attempts): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $this->storedAs(['attempts' => $attempts]);

        $this->expectMalformed(self::TABLE, 'attempts', $this->queue->reserve(...));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformedMessageColumns(): iterable
    {
        yield 'zero id' => ['id', 0];
        yield 'negative id' => ['id', -3];
        yield 'zero id string' => ['id', '0'];
        yield 'float id' => ['id', 1.5];
        yield 'numeric type' => ['type', 7];
        yield 'null type' => ['type', null];
        yield 'payload outside the alphabet' => ['payload', 'not base64!'];
        yield 'payload with whitespace' => ['payload', 'cGF5 bG9hZA=='];
        yield 'payload without padding' => ['payload', 'cGF5bG9hZA'];
        yield 'null payload' => ['payload', null];
    }

    #[Test]
    #[DataProvider('malformedMessageColumns')]
    public function it_refuses_a_message_with_a_malformed_stored_value(string $column, mixed $value): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $this->storedAs([$column => $value]);

        $this->expectMalformed(self::TABLE, $column, $this->queue->reserve(...));
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function malformedFailedMessageColumns(): iterable
    {
        yield 'zero id' => ['id', 0, 'id'];
        yield 'zero failed attempt' => ['failed_attempt', 0, 'failed_attempt'];
        yield 'decimal failed attempt' => ['failed_attempt', '1.0', 'failed_attempt'];
        yield 'numeric type' => ['type', 5, 'type'];
        yield 'payload without padding' => ['payload', 'cGF5bG9hZA', 'payload'];
        yield 'failure without a message' => ['failure_message', null, 'failure_message'];
        yield 'numeric failure type' => ['failure_type', 12, 'failure_type'];
        yield 'decimal failure code' => ['failure_code_integer', '4.2', 'failure_code_integer'];
        yield 'failure without a code' => ['failure_code_integer', null, 'failure_code_integer'];
        yield 'failure with two codes' => ['failure_code_string', 'HY000', 'failure_code_string'];
    }

    #[Test]
    #[DataProvider('malformedFailedMessageColumns')]
    public function it_refuses_a_failed_message_with_a_malformed_stored_value(
        string $column,
        mixed $value,
        string $malformed,
    ): void {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $this->reserve()->fail(new RuntimeException('Failed.', 3));
        $this->storedAs([$column => $value]);

        $this->expectMalformed(self::FAILED_TABLE, $malformed, function (): void {
            $this->queue->failed();
        });
    }

    #[Test]
    public function it_refuses_to_retry_a_failed_message_whose_stored_payload_is_malformed(): void
    {
        $id = $this->failedMessage('message');
        $this->storedAs(['payload' => 'cGF5 bG9hZA==']);

        $this->expectMalformed(self::FAILED_TABLE, 'payload', function () use ($id): void {
            $this->queue->retry($id);
        });

        $this->connection()->rewriteRows(static fn(array $row): array => $row);
        self::assertSame('payload', $this->queue->findFailed($id)?->message->payload);
        self::assertNull($this->queue->reserve());
    }

    #[Test]
    public function it_reads_integers_a_driver_returns_as_canonical_strings(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));
        $this->reserve()->fail(new RuntimeException('Failed.', PHP_INT_MIN));
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->connection()->rewriteRows(static function (array $row): array {
            foreach (['id', 'attempts', 'failed_attempt', 'failure_code_integer'] as $column) {
                if (!is_int($row[$column] ?? null)) {
                    continue;
                }

                $row[$column] = (string) $row[$column];
            }

            return $row;
        });

        $delivery = $this->reserve();
        $failed = $this->failed();

        self::assertSame(1, $delivery->attempt);
        self::assertSame(1, $failed[0]->attempt);
        self::assertSame(PHP_INT_MIN, $failed[0]->failure?->code);

        $delivery->acknowledge();
        $this->queue->forget($failed[0]->id);
    }

    #[Test]
    public function it_keeps_a_negative_failure_code(): void
    {
        $this->queue->enqueue(new QueuedMessage('message', 'payload'));

        $this->reserve()->fail(new RuntimeException('Failed.', -42));

        self::assertSame(-42, $this->failed()[0]->failure?->code);
    }

    #[Test]
    public function it_refuses_to_reserve_a_message_whose_attempt_counter_cannot_be_incremented(): void
    {
        $this->insertMessage(attempts: 2_147_483_647);

        try {
            $this->queue->reserve();
            self::fail('Reserving a message with an exhausted attempt counter succeeded.');
        } catch (QueueOperationException $exception) {
            self::assertSame(2_147_483_647, $exception->context['attempts']);
        }

        self::assertSame(2_147_483_647, (int) $this->database->table(self::TABLE)->max('attempts'));
    }

    #[Test]
    public function it_reserves_a_message_up_to_the_last_attempt_its_counter_can_hold(): void
    {
        $this->insertMessage(attempts: 2_147_483_646);

        self::assertSame(2_147_483_647, $this->reserve()->attempt);
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

    /**
     * @param array<string, mixed> $values
     */
    private function storedAs(array $values): void
    {
        $this->connection()->rewriteRows(static fn(array $row): array => array_replace($row, array_intersect_key(
            $values,
            $row,
        )));
    }

    private function insertMessage(int $attempts): void
    {
        $this->database
            ->table(self::TABLE)
            ->insert([
                'queue' => 'default',
                'type' => 'message',
                'payload' => 'cGF5bG9hZA==',
                'attempts' => $attempts,
                'available_at' => '2026-10-05 12:00:00.000',
                'created_at' => '2026-10-05 12:00:00',
            ]);
    }

    /**
     * @param callable(): mixed $operation
     */
    private function expectMalformed(string $table, string $column, callable $operation): void
    {
        try {
            $operation();
            self::fail(sprintf('The operation accepted a malformed "%s".', $column));
        } catch (QueueOperationException $exception) {
            self::assertSame(
                ['queue' => 'default', 'table' => $table, 'connection' => 'conformance', 'column' => $column],
                $exception->context,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function failedTypes(): array
    {
        return array_map(static fn(FailedMessage $failed): string => $failed->message->type, $this->failed());
    }

    private function schemaFor(): Schema
    {
        return new Schema($this->database, new SchemaGrammarResolver([
            $this->driverName()->value => $this->schemaGrammar(),
        ]));
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function stored(?array $row, string $column): string
    {
        self::assertNotNull($row);

        // @mago-expect analysis:mixed-assignment
        $value = $row[$column] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>
     */
    private function only(?array $row, string ...$columns): array
    {
        self::assertNotNull($row);

        $values = [];

        foreach ($columns as $column) {
            // @mago-expect analysis:mixed-assignment
            $value = $row[$column] ?? null;
            $values[$column] = is_string($value) && is_numeric($value) ? (int) $value : $value;
        }

        return $values;
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-05 ' . $time, new DateTimeZone('UTC'));
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
