<?php

// Copyright (c) 2026 Paymos Labs
// SPDX-License-Identifier: GPL-2.0-or-later
// Licensed under the GNU General Public License version 2 or later. See LICENSE.

declare(strict_types=1);

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class PlgHikashoppaymentPaymosInstallerScript
{
    private const NATIVE_TABLES = [
        'hikashop_order',
        'hikashop_history',
        'hikashop_product',
        'hikashop_order_product',
        'hikashop_discount',
        'hikashop_user',
    ];

    public function preflight($type, $parent): bool
    {
        if (!in_array($type, ['install', 'update', 'discover_install'], true)) {
            return true;
        }

        if (PHP_VERSION_ID < 80300 || !extension_loaded('curl') || !extension_loaded('openssl')) {
            return $this->reject('Paymos for HikaShop requires PHP 8.3 or later with cURL and OpenSSL.');
        }
        if (!preg_match('/\A(?:5\.4|6\.2)\.\d+\z/', JVERSION)) {
            return $this->reject('Paymos for HikaShop requires Joomla 5.4 or 6.2.');
        }

        try {
            $manifest = Installer::parseXMLInstallFile(JPATH_ADMINISTRATOR . '/components/com_hikashop/hikashop.xml');
            $version = $manifest['version'] ?? '';
            if (!ComponentHelper::isEnabled('com_hikashop') || !preg_match('/\A6\.8\.\d+\z/', $version)
                || version_compare($version, '6.8.1', '<')) {
                return $this->reject('Install and enable HikaShop 6.8.1 or a later release in the 6.8 series.');
            }

            $db = Factory::getContainer()->get(DatabaseInterface::class);
            if ($db->getServerType() !== 'mysql') {
                return $this->reject('Paymos for HikaShop requires MySQL or MariaDB with InnoDB.');
            }

            $tables = array_map(static fn (string $table): string => $db->getPrefix() . $table, self::NATIVE_TABLES);
            $quoted = array_map(static fn (string $table): string => $db->quote($table), $tables);
            $sql = 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(', ', $quoted) . ')';
            $engines = $db->setQuery($sql)->loadAssocList('TABLE_NAME', 'ENGINE');
            foreach ($tables as $table) {
                if (strcasecmp((string) ($engines[$table] ?? ''), 'InnoDB') !== 0) {
                    return $this->reject('Paymos for HikaShop requires InnoDB for HikaShop orders, order history, products, order items, discounts and users.');
                }
            }
        } catch (Throwable) {
            return $this->reject('Paymos for HikaShop could not verify the HikaShop installation and database tables.');
        }

        return true;
    }

    private function reject(string $message): bool
    {
        Factory::getApplication()->enqueueMessage($message, 'error');
        return false;
    }
}
