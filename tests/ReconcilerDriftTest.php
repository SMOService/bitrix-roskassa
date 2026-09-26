<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\ApiClient;
use Smoservice\Roskassa\Reconciler;

/**
 * Полный обход сверки: место хранится датой, смещение находится поиском по дате.
 */
final class ReconcilerDriftTest extends TestCase
{
    /** @var list<int> время создания оплат шлюза по возрастанию (новые — в конце); id = 'g'.ts */
    private $asc = [];
    /** @var int */
    private $clock = 1700000000;
    /** @var int */
    private $calls = 0;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../smoservice.roskassa/lib/Reconciler.php';
    }

    private function addPaid(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->clock += 7;
            $this->asc[] = $this->clock;
        }
    }

    /** запись на позиции $pos списка шлюза (0 — самая новая) */
    private function positionTs(int $pos): ?int
    {
        $i = count($this->asc) - 1 - $pos;

        return $i >= 0 ? $this->asc[$i] : null;
    }

    /** @return list<int> */
    private function slice(int $offset, int $len): array
    {
        $out = [];
        for ($p = $offset; $p < $offset + $len; $p++) {
            $ts = $this->positionTs($p);
            if ($ts === null) {
                break;
            }
            $out[] = $ts;
        }

        return $out;
    }

    private function client(): ApiClient
    {
        return new ApiClient('https://roskassa.net', 'SHOP', 'k', function (string $url, string $body) {
            $this->calls++;
            $req = json_decode($body, true);
            $data = array_map(static function ($ts) {
                return ['id' => 'g' . $ts, 'order_id' => 'x-g' . $ts, 'status' => 1, 'date_created' => date('Y-m-d H:i:s', $ts)];
            }, $this->slice((int)$req['page'], 100));

            return json_encode(['type' => 'success', 'desc' => '', 'data' => $data]);
        });
    }

    public function testOffsetOfDateFindsPosition(): void
    {
        $this->addPaid(10000);
        $ts = $this->positionTs(4321);

        $offset = Reconciler::offsetOfDate($this->client(), $ts);

        self::assertLessThanOrEqual(4321, $offset);
        self::assertGreaterThan(4321 - 300, $offset);
        self::assertLessThan(40, $this->calls, 'search must be logarithmic');
    }

    public function testOffsetOfDateBeyondEnd(): void
    {
        $this->addPaid(250);

        self::assertLessThanOrEqual(250, Reconciler::offsetOfDate($this->client(), 1));
    }

    /**
     * Модель запусков: $perRun новых оплат между запусками, полный обход читает 500 записей
     * от места, найденного по сохранённой дате. Старая оплата на позиции 5000 должна быть прочитана.
     *
     * @dataProvider flows
     */
    public function testOldPaymentIsEventuallyReached(int $perRun): void
    {
        $this->addPaid(20000);
        $target = $this->positionTs(5000);
        $client = $this->client();
        $cursorTs = 0;

        // обход продвигается не меньше чем на 300 записей за запуск при любом потоке сверху:
        // до цели не больше (глубина + поток первого запуска) / 300 запусков (две страницы запаса на стыке)
        $maxRuns = intdiv(5000 + $perRun, 300) + 2;
        for ($run = 0; $run < $maxRuns; $run++) {
            $this->addPaid($perRun);
            $offset = $cursorTs > 0 ? Reconciler::groupStart($client, $cursorTs) : 0;
            $read = $this->slice($offset, 500);
            if (in_array($target, $read, true)) {
                self::assertLessThan($maxRuns, $run);

                return;
            }
            [$cursorTs] = Reconciler::parseCursor(Reconciler::nextCursor($cursorTs, 0, $read, false));
        }

        self::fail('old payment never reached with ' . $perRun . ' new payments per run');
    }

    /**
     * Регрессия: 1600 заказов одной секунды на позициях 3200–4799 — обход должен пройти группу
     * и дойти до хвоста (позиция 5000), двигаясь внутри группы по сохранённому смещению.
     */
    public function testSameSecondGroupIsCrossed(): void
    {
        $this->addPaid(10000);
        // одинаковые даты для позиций 3200..3599 (asc-индексы соответственно)
        $n = count($this->asc);
        $groupTs = $this->asc[$n - 1 - 4799];
        for ($p = 3200; $p <= 4799; $p++) {
            $this->asc[$n - 1 - $p] = $groupTs;
        }
        $target = $this->positionTs(5000);
        $client = $this->client();
        $cursor = '';

        for ($run = 0; $run < 40; $run++) {
            [$ts, $skip] = Reconciler::parseCursor($cursor);
            $offset = $ts > 0 ? Reconciler::groupStart($client, $ts) + $skip : 0;
            $read = $this->slice($offset, 500);
            if (in_array($target, $read, true)) {
                self::assertLessThan(40, $run);

                return;
            }
            $cursor = Reconciler::nextCursor($ts, $skip, $read, false);
        }

        self::fail('same-second group was never crossed');
    }

    public function testNextCursor(): void
    {
        self::assertSame('', Reconciler::nextCursor(5, 0, [9, 8], true));
        self::assertSame('', Reconciler::nextCursor(5, 0, [], false));
        self::assertSame('5:700', Reconciler::nextCursor(5, 200, array_fill(0, 500, 5), false));
        self::assertSame('3:2', Reconciler::nextCursor(5, 0, [5, 4, 3, 3], false));
    }

    public function testParseCursor(): void
    {
        self::assertSame([0, 0], Reconciler::parseCursor(''));
        self::assertSame([1700000000, 0], Reconciler::parseCursor('1700000000'));
        self::assertSame([1700000000, 120], Reconciler::parseCursor('1700000000:120'));
        self::assertSame([0, 0], Reconciler::parseCursor('junk'));
    }

    public function flows(): array
    {
        return ['quiet' => [3], 'busy' => [400], 'beyond quick pass' => [3000], '13000' => [13000]];
    }
}
