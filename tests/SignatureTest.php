<?php

namespace Smoservice\Roskassa\Tests;

use PHPUnit\Framework\TestCase;
use Smoservice\Roskassa\Signature;

final class SignatureTest extends TestCase
{
    private const SECRET = 'GB%^&*YJni677';

    public function testPaymentFormMatchesDocumentedAlgorithm(): void
    {
        $sign = Signature::paymentForm([
            'shop_id' => 'D0F98E7D7742609DC508D86BB7500914',
            'amount' => '100',
            'currency' => 'RUB',
            'order_id' => '123',
        ], self::SECRET);

        self::assertSame('ab399192242d30c151efda7f8c47b304', $sign);
    }

    public function testPaymentFormIsAcceptedByGateway(): void
    {
        $fields = ['shop_id' => 'SHOP', 'amount' => '250.50', 'currency' => 'RUB', 'order_id' => '42', 'test' => '1'];
        $fields['sign'] = Signature::paymentForm($fields, self::SECRET);
        $fields['email'] = 'buyer@example.com'; // лишние поля в подпись не входят

        self::assertTrue(GatewayReference::formSignValid($fields, self::SECRET));
    }

    public function testTestFlagChangesSignature(): void
    {
        $base = ['shop_id' => 'S', 'amount' => '1.00', 'currency' => 'RUB', 'order_id' => '1'];

        self::assertNotSame(
            Signature::paymentForm($base, self::SECRET),
            Signature::paymentForm($base + ['test' => '1'], self::SECRET)
        );
    }

    public function testPaymentFormRejectsMissingField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signature::paymentForm(['shop_id' => 'S', 'amount' => '1', 'currency' => 'RUB'], self::SECRET);
    }

    public function testPaymentFormRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signature::paymentForm(['shop_id' => 'S', 'amount' => '1', 'currency' => 'RUB', 'order_id' => '1'], '');
    }

    public function testGenuineNotificationVerifies(): void
    {
        self::assertTrue(Signature::verifyNotification(GatewayReference::notification([], self::SECRET), self::SECRET));
    }

    public function testUppercaseSignatureVerifies(): void
    {
        $data = GatewayReference::notification([], self::SECRET);
        $data['sign'] = strtoupper($data['sign']);

        self::assertTrue(Signature::verifyNotification($data, self::SECRET));
    }

    public function testTamperedNotificationFails(): void
    {
        $data = GatewayReference::notification([], self::SECRET);
        $data['amount'] = '10000';

        self::assertFalse(Signature::verifyNotification($data, self::SECRET));
    }

    public function testWrongSecretFails(): void
    {
        self::assertFalse(Signature::verifyNotification(GatewayReference::notification([], self::SECRET), 'other'));
    }

    public function testEmptySecretFailsClosed(): void
    {
        $data = GatewayReference::notification([], '');

        self::assertFalse(Signature::verifyNotification($data, ''));
    }

    /**
     * @dataProvider malformedSigns
     * @param mixed $sign
     */
    public function testMalformedSignatureFails($sign): void
    {
        $data = GatewayReference::notification([], self::SECRET);
        $data['sign'] = $sign;

        self::assertFalse(Signature::verifyNotification($data, self::SECRET));
    }

    public function malformedSigns(): array
    {
        return [
            'empty' => [''],
            'magic hash' => ['0e462097431906509019562988736854'],
            'too short' => ['abc'],
            'array' => [['x']],
            'non-hex' => [str_repeat('z', 32)],
        ];
    }

    public function testApiSignatureIsHmacOfExactBody(): void
    {
        $body = '{"shop_id":"S","nonce":1}';

        self::assertSame(hash_hmac('sha256', $body, 'key'), Signature::api($body, 'key'));
    }
}
