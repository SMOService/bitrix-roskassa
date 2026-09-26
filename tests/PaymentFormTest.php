<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\PaymentForm;

final class PaymentFormTest extends TestCase
{
    private const SECRET = 'k3y';

    private function input(array $overrides = []): array
    {
        return array_merge([
            'base_url' => 'https://roskassa.net',
            'shop_id' => 'SHOP',
            'secret' => self::SECRET,
            'order_id' => '718300',
            'amount' => '150',
            'currency' => 'rub',
            'notify_url' => 'https://shop.example/bitrix/tools/smoservice_roskassa_notify.php?ps=7',
        ], $overrides);
    }

    public function testMinimalFormIsSignedForGateway(): void
    {
        $form = PaymentForm::build($this->input());

        self::assertSame('https://roskassa.net/pay/', $form['action']);
        self::assertSame('150.00', $form['fields']['amount']);
        self::assertSame('RUB', $form['fields']['currency']);
        self::assertArrayNotHasKey('test', $form['fields']);
        self::assertSame(PaymentForm::SOURCE, $form['fields']['source']);
        self::assertTrue(GatewayReference::formSignValid($form['fields'], self::SECRET));
    }

    public function testTestModeIsSigned(): void
    {
        $form = PaymentForm::build($this->input(['test' => true]));

        self::assertSame('1', $form['fields']['test']);
        self::assertTrue(GatewayReference::formSignValid($form['fields'], self::SECRET));
    }

    public function testDirectMethodNeedsEmail(): void
    {
        $withoutEmail = PaymentForm::build($this->input(['payment_system' => '11']));
        self::assertSame('https://roskassa.net/pay/', $withoutEmail['action']);

        $direct = PaymentForm::build($this->input(['payment_system' => '11', 'email' => 'a@b.ru']));
        self::assertSame('https://roskassa.net/pay/form/', $direct['action']);
        self::assertSame('a@b.ru', $direct['fields']['fields[email]']);
    }

    public function testMirrorDomainIsUsedEverywhere(): void
    {
        $form = PaymentForm::build($this->input(['base_url' => 'https://pay.mirror.example/']));

        self::assertSame('https://pay.mirror.example/pay/', $form['action']);
    }

    public function testReceiptItemsWhenTotalsMatch(): void
    {
        $form = PaymentForm::build($this->input([
            'items' => [
                ['name' => 'Лайки', 'count' => 2, 'price' => 50],
                ['name' => 'Подписчики', 'count' => 1, 'price' => '50.00'],
            ],
            'fallback_item_name' => 'Оплата заказа № 1',
        ]));

        self::assertSame('Лайки', $form['fields']['receipt[items][0][name]']);
        self::assertSame('2', $form['fields']['receipt[items][0][count]']);
        self::assertSame('50.00', $form['fields']['receipt[items][0][price]']);
        self::assertSame('Подписчики', $form['fields']['receipt[items][1][name]']);
    }

    public function testSingleReceiptLineWhenTotalsDiffer(): void
    {
        $form = PaymentForm::build($this->input([
            'items' => [['name' => 'Лайки', 'count' => 1, 'price' => 500]],
            'fallback_item_name' => 'Оплата заказа № 1',
        ]));

        self::assertSame('Оплата заказа № 1', $form['fields']['receipt[items][0][name]']);
        self::assertSame('150.00', $form['fields']['receipt[items][0][price]']);
        self::assertArrayNotHasKey('receipt[items][1][name]', $form['fields']);
    }

    public function testFractionalQuantityFallsBackToSingleLine(): void
    {
        $form = PaymentForm::build($this->input([
            'items' => [['name' => 'Весовой товар', 'count' => 1.5, 'price' => 100]],
            'fallback_item_name' => 'Оплата заказа № 1',
        ]));

        self::assertSame('Оплата заказа № 1', $form['fields']['receipt[items][0][name]']);
        self::assertSame('1', $form['fields']['receipt[items][0][count]']);
    }

    public function testKopecksPassGatewayStringComparison(): void
    {
        // шлюз сравнивает (string)(0.1 * 3) с "0.30" — строки-числа, PHP сравнивает их как числа
        $form = PaymentForm::build($this->input([
            'amount' => '0.30',
            'items' => [['name' => 'Лайк', 'count' => 3, 'price' => '0.10']],
            'fallback_item_name' => 'Оплата заказа № 1',
        ]));

        self::assertSame('Лайк', $form['fields']['receipt[items][0][name]']);
        self::assertSame('3', $form['fields']['receipt[items][0][count]']);
    }

    public function testItemNamesAreStrippedOfTags(): void
    {
        $form = PaymentForm::build($this->input([
            'items' => [['name' => '<b>Лайки</b>', 'count' => 1, 'price' => 150]],
        ]));

        self::assertSame('Лайки', $form['fields']['receipt[items][0][name]']);
    }

    public function testUnknownLanguageDropped(): void
    {
        self::assertArrayNotHasKey('lang', PaymentForm::build($this->input(['lang' => 'de']))['fields']);
        self::assertSame('en', PaymentForm::build($this->input(['lang' => 'en']))['fields']['lang']);
    }

    public function testAllFieldsAreStrings(): void
    {
        foreach (PaymentForm::build($this->input(['test' => true]))['fields'] as $value) {
            self::assertIsString($value);
        }
    }
}
