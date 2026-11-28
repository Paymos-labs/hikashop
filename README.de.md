# Payment - Paymos für HikaShop

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Akzeptieren Sie Stablecoin-Zahlungen über den gehosteten Paymos-Checkout in HikaShop. Die Erweiterung ist kostenlos und erfordert ein Paymos-Konto sowie ein Projekt. Es können Paymos-Servicegebühren und Blockchain-Netzwerkgebühren anfallen. [Paymos-Preise](https://paymos.io/de/pricing)

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video-Rundgang (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

- Joomla 5.4 oder 6.2 mit HikaShop 6.8.1 oder einem neueren Patch der 6.8-Reihe.
- PHP 8.3 oder neuer mit cURL und OpenSSL.
- MySQL oder MariaDB. Die nativen HikaShop-Tabellen für Bestellungen, Verlauf, Produkte, Bestellpositionen, Rabatte und Benutzer müssen InnoDB verwenden.
- Ein öffentlich erreichbarer HTTPS-Shop sowie die Standard-HikaShop-Bestellstatus `created`, `confirmed` und `refunded`. Stellen Sie **Order created status** auf `created` und belassen Sie `confirmed` und `refunded` verfügbar.

## Installieren und verbinden

1. Laden Sie das versionierte Installations-ZIP herunter und laden Sie es in Joomla unter **System → Install → Extensions** hoch. [Offizielle Releases](https://github.com/Paymos-labs/hikashop/releases)
2. Aktivieren Sie unter **System → Manage → Plugins** das Plugin **Payment - Paymos for HikaShop**. Öffnen Sie in HikaShop **System → Payment methods**, legen Sie eine Paymos-Zahlungsmethode an und speichern Sie sie.
3. Wählen Sie **Connect Paymos**. Öffnen Sie die Freigabeseite, gleichen Sie den in HikaShop angezeigten Code ab und wählen Sie ein Paymos-Projekt aus. Kehren Sie nach der Freigabe zu HikaShop zurück.
4. Wählen Sie **Sandbox**, speichern Sie den Modus und testen Sie eine Bestellung. Wenn alles bereit ist, wählen Sie **Live** und speichern Sie, bevor Sie die Zahlungsmethode für Kunden aktivieren.

Das ZIP-Paket enthält das Paymos PHP SDK. Joomla installiert das Plugin, seine Datenbanktabellen und seine Update-Site. Die Verbindung verknüpft die gespeicherte Zahlungsmethode und die Shop-Adresse mit dem genehmigten Projekt. Verwenden Sie die kanonische HTTPS-Adresse und verbinden Sie neu, falls sie sich ändert.

Sie können die **Success URL** Ihres Paymos-Projekts auf die native HikaShop-Checkout-Rückkehrseite unter Ihrer Joomla-Basis-URL setzen. Für eine Website unter `https://store.example/shop/` verwenden Sie `https://store.example/shop/index.php?option=com_hikashop&ctrl=checkout&task=after_end`. Prüfen Sie diese projektweite Einstellung, falls mehrere Shops dasselbe Projekt teilen.

## Zahlung und Auftragsabwicklung

Der Checkout übermittelt das Projekt, eine Bestellreferenz sowie Betrag und Währung der gespeicherten Bestellung an Paymos. Enthält die Bestellung 1 bis 50 gültige Produktzeilen in `#__hikashop_order_product` (mit optionalen Klartext-Beschreibungen aus `#__hikashop_product`), sendet der Checkout zusätzlich die normalisierten `products[]`-Positionen zusammen mit der HikaShop-Bestellsumme (`order_full_price`). Fehlende oder leere Beschreibungen werden pro Artikel weggelassen. Produktbilder werden weder abgefragt noch übermittelt. Enthält die Bestellung 0 oder mehr als 50 Produktzeilen oder eine ungültige Zeile (z. B. gebrochene Menge, leerer Titel oder negativer/ungültiger Stückpreis), fällt der Checkout auf eine `amount-only`-Rechnung zurück. Der normalisierte Snapshot wird vor der ersten Anfrage in `#__hikashop_paymos_attempt.products_json` gespeichert, sodass Wiederholungen die gespeicherten Produktdaten unverändert erneut senden. Ältere Snapshots mit `image_url` behalten ihr gespeichertes JSON unverändert; lediglich dieser veraltete Schlüssel wird aus der ausgehenden Kopie entfernt. Kunden schließen die Zahlung bei Paymos ab und können zur HikaShop-Bestellseite zurückkehren. Die Zahlungsbestätigung erfolgt über eine signierte Benachrichtigung und ein übereinstimmendes Paymos-API-Ergebnis. Die Rückkehrseite zeigt den aktuellen HikaShop-Bestellstatus an.

HikaShop bestätigt eine vollständig bezahlte, übereinstimmende Bestellung genau einmal. Auch eine Zahlung mit verifizierter Überzahlung kann die Bestellung bestätigen. Teilzahlungen warten auf den Restbetrag oder bleiben unbestätigt; verspätete oder abweichende Zahlungen müssen geprüft werden. Lagerbestandsaktualisierungen und Produktfreigaben richten sich nach dem konfigurierten HikaShop-Workflow für bestätigte Bestellungen.

Falls ein Status-Update fehlt, öffnen Sie die Bestellung in HikaShop und wählen Sie im Paymos-Panel **Check payment**. Dadurch wird die gespeicherte Rechnung abgerufen und abgeglichen.

## Rückerstattungen

Öffnen Sie eine bezahlte Bestellung in HikaShop als Administrator mit Berechtigung zum Bearbeiten von Bestellungen. Sofern das Projekt Rückerstattungen unterstützt, zeigt das Paymos-Panel die verfügbaren Aktionen **Refund full payment** und **Refund overpayment** an. Paymos berechnet den Betrag und verwendet die verifizierte Absenderadresse, die bei der Zahlung erfasst wurde.

- Eine vollständige Rückerstattung setzt die Bestellung nach Bestätigung durch Paymos auf `refunded`. HikaShop wendet das konfigurierte Bestell- und Lagerverhalten an.
- Eine abgeschlossene Überzahlungs-Rückerstattung behält den aktuellen Bestellstatus bei und fügt einen Verlaufseintrag hinzu.
- Bei einem ausstehenden oder unklaren Ergebnis nutzen Sie **Check refund** für den bestehenden Vorgang. Eine signierte Benachrichtigung oder ein Abgleich aktualisiert den Vorgang, sobald Paymos seinen Status meldet.
- Eine außerhalb dieses Plugins angestoßene Rückerstattung, die keinem gespeicherten Vorgang zugeordnet werden kann, muss in Paymos und HikaShop geprüft werden.

## Zugangsdaten und Entfernung

Zugangsdaten werden mithilfe des Joomla-Installationsgeheimnisses verschlüsselt gespeichert. Klärung offener Zahlungen und Rückerstattungen sollte vor dem Entfernen der Integration erfolgen. **Disconnect** löscht die gespeicherten Zugangsdaten dieser Zahlungsmethode und stoppt Zahlungsaktualisierungen bis zu einer erneuten Verbindung. Beim Deinstallieren des Plugins werden seine eigenen Tabellen und Zugangsdaten entfernt; native HikaShop-Bestellungen und Verlaufsdaten bleiben erhalten.

## Lizenz und Support

Diese Erweiterung steht unter der Lizenz GPL-2.0-or-later. Das enthaltene Paymos PHP SDK steht unter der MIT-Lizenz. [Erweiterungslizenz](LICENSE), [Hinweise zu Drittanbietersoftware](NOTICE).

Integrationsanleitungen und Hilfe finden Sie in der [Paymos-Dokumentation](https://paymos.io/de/docs/cms-hikashop) oder über den [Paymos-Support](mailto:support@paymos.io).
