# TrafoPilot – Lexware Phase 1

Inventar vom 2026-10-01: [offizielle API-Referenz](https://developers.lexware.io/docs/).
Basis `https://api.lexware.io/v1`. Ausschließlich GET; keine Webhook-Registrierung.

| Ressource | GET-Pfad | Ermittlung |
| --- | --- | --- |
| Organisation | `/profile` | einzeln |
| Kontakte | `/contacts`, `/contacts/{id}` | Pagination |
| Produkte und Leistungen | `/articles`, `/articles/{id}` | Pagination |
| Länder | `/countries` | Liste |
| Angebote | `/quotations/{id}` | Voucherlist |
| Aufträge | `/order-confirmations/{id}` | Voucherlist |
| Lieferungen | `/delivery-notes/{id}` | Voucherlist |
| Rechnungen | `/invoices/{id}` | Voucherlist |
| Abschläge | `/down-payment-invoices/{id}` | Voucherlist |
| Gutschriften | `/credit-notes/{id}` | Voucherlist |
| Mahnungen | `/dunnings/{id}` | bekannte Referenzen; keine dokumentierte Gesamtliste |
| Belegübersicht | `/voucherlist` | Pagination; `voucherType=any&voucherStatus=any` |
| Buchhaltungsbelege | `/vouchers/{id}`, `/vouchers?voucherNumber=…` | Voucherlist / Nummer |
| Zahlungen | `/payments/{voucherId}` | zahlungsfähige Belege |
| Dateien | `/files/{id}` | Belegreferenzen |
| Verkaufsdateien | `/{Verkaufsressource}/{id}/file` | PDF und XML getrennt |
| Zahlungsbedingungen | `/payment-conditions` | Liste |
| Kategorien | `/posting-categories` | Liste |
| Drucklayouts | `/print-layouts` | Liste |
| Wiederholungen | `/recurring-templates`, `/recurring-templates/{id}` | Pagination |
| Bestehende Webhook-Konfiguration | `/event-subscriptions`, `/event-subscriptions/{id}` | nur lesen |
| Uploadstatus | `/files/{id}/status` | bekannte Dateireferenzen |

Veraltete `/document`-GETs lösen Rendering aus und sind ausgeschlossen. Alle POST/PUT/PATCH/DELETE sind ausgeschlossen. Keine UI-Scraping-Ergänzung.

## Implementierungsplan und Abbildungsregeln

Zuerst Profil prüfen (UUID und Firmenname), dann persistenten Lauf anlegen. Rohantworten werden unverändert gespeichert; Prüfsummen entstehen aus sortierten JSON-Objektschlüsseln bei erhaltener Listenreihenfolge. Entität, Organisation, Typ und Remote-ID bilden die eindeutige Identität. Separate Tabellen speichern Lauf/Checkpoint, Mapping, Konflikte, Dateien und Beziehungen. Jede lokale Mutation erfolgt mit Rechten, Transaktion und Core-Audit.

Kontakte vor Dokumenten, Produkte vor Positionen, Aufträge vor Lieferscheinen. UUID-Mapping zuerst; eindeutige USt-ID nur als Zuordnungsvorschlag mit manueller Bestätigung bei bestehenden Objekten. Keine Namenszusammenführung. Native Projektionen verwenden Dolibarr-Klassen mit unterdrückten Triggern. Vor Aktualisierung werden lokale Snapshots verglichen; Abweichungen blockieren Überschreibungen.

Zahlungen bleiben Spiegelinformationen. Versandprojektionen dürfen weder validiert werden noch Lagerbewegungen auslösen. Fehlende Auftragsbeziehungen bleiben sichtbar im Spiegel. Originalnummern und Originaldateien werden gesondert erhalten. Nicht eindeutig abbildbare Steuer-/Statuskonstellationen bleiben prüfpflichtig im Spiegel.

Ein Batch hält einen Organisations-Lock und persistiert seinen Checkpoint. Wiederholungen verwenden dieselbe Identität. Periodische vollständige Scans ergänzen inkrementelle Voucherlist-Filter mit zeitlicher Überlappung. Fehlende Datensätze werden niemals gelöscht. Fehler werden einzeln gespeichert und können erneut verarbeitet werden. Dateiidentität umfasst Remote-Datei-ID und Repräsentation; Inhalt wird gehasht.

Risiken: Mahnungen sind nicht vollständig enumerierbar; API-Lesezugriff ist keine Garantie für vollständige UI-Abdeckung. Historische Statusabbildung, Steuern, Nummernkollisionen und lokale Ergänzungen verlangen Konfliktprüfung. Neue API-Typen werden roh gespeichert und ausdrücklich als nicht unterstützt angezeigt. Phase 2 benötigt separat freigegebene Schreibrechte, Feldhoheit, Zahlungs-/Bankmapping und Webhook-Einrichtung.
