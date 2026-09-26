<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/** @var array $params */
$fields = is_array($params['FIELDS'] ?? null) ? $params['FIELDS'] : [];
?>
<div class="roskassa-pay">
    <p>
        <?= Loc::getMessage('SMOSERVICE_ROSKASSA_TPL_INVOICE', ['#NUMBER#' => htmlspecialcharsbx((string)$params['PAYMENT_NUMBER'])]) ?>
    </p>
    <p>
        <?= Loc::getMessage('SMOSERVICE_ROSKASSA_TPL_SUM') ?>
        <strong><?= SaleFormatCurrency($params['SUM'], $params['CURRENCY']) ?></strong>
    </p>
    <form action="<?= htmlspecialcharsbx((string)$params['ACTION']) ?>" method="post" accept-charset="UTF-8">
        <?php foreach ($fields as $name => $value): ?>
            <input type="hidden" name="<?= htmlspecialcharsbx((string)$name) ?>" value="<?= htmlspecialcharsbx((string)$value) ?>">
        <?php endforeach; ?>
        <input type="submit" class="btn btn-primary" value="<?= htmlspecialcharsbx(Loc::getMessage('SMOSERVICE_ROSKASSA_TPL_PAY')) ?>">
    </form>
</div>
