<?php

// SPDX-License-Identifier: GPL-2.0-or-later
// Copyright (c) 2026 Paymos

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use PaymosHikaShop\Payments;

defined('_JEXEC') or die;

$url = $this->paymosPaymentUrl ?? null;
if (!Payments::trustedCheckoutUrl($url)) {
    return;
}

Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
window.hikashop.ready(function () {
    const pay = document.getElementById('hikashop-paymos-checkout-link');
    if (pay) {
        window.location.assign(pay.href);
    }
});
JS
);

?>
<div class="hikashop_paymos_end">
    <a id="hikashop-paymos-checkout-link" class="btn btn-primary"
       href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?= htmlspecialchars(Text::_('PAY_NOW'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </a>
</div>
