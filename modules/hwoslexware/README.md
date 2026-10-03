# TrafoPilot – Lexware Office Spiegel

Externes Dolibarr-24-Modul `hwoslexware`, Worktree-Version 0.2.0, Modul-ID 700200. Abhängigkeit: `modHwosCore`. Der Dolibarr-Core bleibt unverändert.

## Sicherheit und Betrieb

Ausschließlich `LEXWARE_TEST_API_KEY_READ_ONLY`; kein Vollzugriffs-Fallback. Der Client bietet ausschließlich erlaubte GET-Pfade auf `https://api.lexware.io`. Redirects und `/document`-Rendering sind gesperrt. UUID und Name der Testorganisation werden vor jedem Batch geprüft. Ein organisationsweiter Datenbank-Lock und mindestens 600 ms Abstand begrenzen parallele Abrufe. Wiederholbare Fehler werden maximal fünfmal mit Backoff versucht; Response-Fehlertexte und Header werden nicht protokolliert.

Die lokale Secret-Datei `/home/vadmin/.config/trafopilot/lexware-dev.env` wird ausschließlich vom DEV-Launcher gelesen, mit Eigentümer-/Rechteprüfung. Nur die benannte Read-only-Zeile wird ausgewertet; die Datei wird nicht als Shellcode geladen. Der Wert wird per stdin übergeben. Web und Cron lesen alternativ die rohe Secret-Datei `/run/secrets/lexware_test_api_key_read_only`. Ein anderer Pfad kann über `LEXWARE_TEST_API_KEY_READ_ONLY_FILE` konfiguriert werden. Gleichzeitig gesetzte Wert-/Pfadvariablen werden abgewiesen. Die Datei muss regulär, lesbar, höchstens 4096 Bytes groß und ohne Rechte für andere Benutzer oder Gruppenschreibrecht sein; Symlinks werden abgewiesen. Das Modul zeigt ausschließlich die Verfügbarkeit an. Der feste Docker-Secret-Pfad funktioniert auch ohne geerbte Cron-Umgebung.

## Daten und Oberfläche

Geschützte Tabellen speichern Original-JSON, Prüfsummen, Versionen, Zeitpunkte, Archiv-/Fehlend-Markierungen, Läufe, Aufgaben, Zuordnungen, Konflikte, Dokumentbeziehungen und Originaldateien. Alle UI-Zugriffe prüfen das Leserecht; schreibende lokale Aktionen zusätzlich ihr eigenes Recht. Webaktionen verwenden POST und Dolibarr-CSRF-Prüfung.

Die Modulübersicht bietet Verbindungstest, Vorschau, Voll-/Änderungsabruf, Batch-Fortsetzung, Historie und Fehlerwiederholung. Ressourcen können einzeln neu geladen werden. Die Lexware-Tabs zeigen IDs, Originalnummern, Versionen, Status, Abgleich, Dateien, Dokumentketten und Zahlungsinformationen. Unsichere Kontakte werden zur manuellen Zuordnung angezeigt. Eine bestätigte Zuordnung überschreibt keine vorhandenen Kontaktfelder; spätere Remote-Änderungen eines solchen verknüpften Kontakts bleiben Konflikte.

Native Kontakte, Produkte/Dienstleistungen, Angebote, Aufträge, Rechnungen, Gutschriften und eindeutig auftragsbezogene Lieferungen werden über native Dolibarr-Klassen angelegt. UUID-Extrafields werden beim Duplizieren geleert. Materielle Änderungen an den projizierten Kontakt-, Artikel- und Dokumentfeldern einschließlich Positionen, Versandzustand und Dokumentbeziehungen öffnen einen sichtbaren Konflikt, bevor ein bereits projiziertes Dokument geändert wird. Eine ausdrückliche Lexware-Konfliktlösung kann Positionen und Versandbeziehungen ändern; der vorherige native Zustand wird zuvor unveränderlich gespeichert. Lokale Änderungen einschließlich Extrafields und Ansprechpartnern blockieren automatische Überschreibungen. Erweiterte Steuerszenarien ohne geprüftes Mapping bleiben im Spiegel. Belege ohne Kontaktreferenz erzeugen keine erfundenen Kunden.

Zahlungen bleiben ausschließlich Spiegelinformationen; keine Bank-, Kassen-, Buchhaltungs- oder Lagerbewegungen. Historische Statuswerte werden ohne operative Validierung gesetzt. Dolibarr 24 hat einen Fehler in `Expedition::create(...,1)`; eine externe Import-Unterklasse unterdrückt den Triggerdispatcher und umgeht diesen Fehler ohne Core-Patch.

## DEV-Prüfung

Profilantworten werden unverändert gespeichert. Einzelne Referenzobjekte werden aus dem Original-JSON ausgeschnitten; leere Objekte und große numerische Werte bleiben erhalten. Contract- und DEV-Tests prüfen diese Fälle ausdrücklich.

Ein externer Modultrigger entfernt übernommene Lexware-UUIDs und passende `ref_ext`-Kennungen aus neuen lokalen Angeboten, Aufträgen und Rechnungen. Native Erstellrechte gelten weiterhin. Die Importpipeline unterdrückt Trigger und behält die Originalidentität. DEV-Tests prüfen native Duplikate aller drei Dokumenttypen sowie Auftrag aus Angebot und Rechnung aus Auftrag; Spiegel, Mapping und Original bleiben unverändert.

Nur gegen `dolibarr-dev-dolibarr-1` mit geprüften Compose-Labels:

```bash
scripts/test-dev.sh
python3 scripts/lexware-dev.py --mode smoke
python3 scripts/lexware-dev.py --mode dry
python3 scripts/lexware-dev.py --mode full
python3 scripts/lexware-dev.py --mode incremental
python3 scripts/lexware-ui-dev.py
# Optional: Chromium-Bedienprüfung mit Screenshots (Playwright erforderlich)
NODE_PATH=/tmp/hwos-lexware-browser/node_modules python3 scripts/lexware-ui-dev.py --visual
```

Der Quellcode muss in DEV unter `/var/www/html/custom/hwoslexware` verfügbar sein. Der CLI-Launcher installiert oder deployt keinen Code. Seine Modulaktivierung wird nach dem Lauf auf den vorherigen Zustand zurückgesetzt. Unterbrochene Läufe werden mit `--run ID` fortgesetzt. Vollimporte erfordern einen erfolgreichen persistenten Dry Run. Der Launcher prüft Geschäftsdatentabellen beim Dry Run sowie Lager-, Zahlungs-, Bank- und Buchhaltungstabellen bei Imports per Datenbankprüfsumme.

Integrationstest-Fixtures verwenden zufällige, geschützte Testentitäten im Bereich 1100000000–1900000000 und künstliche Daten. Migrationstests prüfen sämtliche Tabellenspalten inklusive Typ, Nullbarkeit, Default und Extra sowie alle Indizes. Aktivierung wird zweimal ausgeführt; Deaktivierung bewahrt Spiegel und Audit. Tests stellen den vorherigen Aktivierungszustand wieder her. Der Cronjob ist bei Registrierung deaktiviert; ein freigegebener Worker-/Cron-Benutzer muss Dolibarr-Administrator sein und das Abrufrecht besitzen; auch manuelle Synchronisation erfordert einen Dolibarr-Administrator. Er verarbeitet begrenzte Batches, wählt wöchentlich einen vollständigen Kontrollscan und sonst einen Änderungsabruf.

TEST und Produktion dürfen ausschließlich ein versioniertes, unveränderliches Release-Artefakt erhalten. Kein solches Deployment gehört zu diesem Auftrag.

Der HTTP-Prüfer verwendet einen temporären Benutzer mit ausschließlich den Modulrechten und löscht ihn anschließend. Das DEV-Elternverzeichnis `/var/www/html/custom` ist seit der dauerhaften Einrichtung `root:www-data 750` und für den Webserver traversierbar. Der Prüfer stellt die ursprünglichen Verzeichnisrechte und den Aktivierungszustand im `finally`-Block wieder her. Er schreibt keine Probe-Datei in das read-only gemountete Modul. Die aktuelle History-Browserprüfung verwendet lokale Fixtures ohne Live-Anfrage und bedient die Spiegelnavigation und die Ressourcendetails; Ein fehlender Ressourcendetail-Link lässt die Browserprüfung scheitern. Screenshots landen standardmäßig in `/tmp/hwos-lexware-visual`: Symlink-Verzeichnisse sowie bestehende Verzeichnisse mit fremdem Eigentümer oder anderem Modus als 0700 werden abgewiesen. PNG-Dateien werden exklusiv mit 0600 angelegt; vorhandene Dateien werden nicht überschrieben. Bestehende Benutzerpasswörter bleiben unverändert.

## API-Grenzen und spätere Phasen

Siehe [Endpoint-Matrix](../../docs/lexware-endpoints.md). Mahnungen sind nicht vollständig enumerierbar. Entwürfe besitzen häufig keine PDF-Datei; 404/406/409 bei Dateirepräsentationen werden sichtbar übersprungen. Nicht lesbare UI-Bereiche werden nicht gescrapt. Native Zahlungsprojektion, Schreibzugriff, Webhook-Registrierung, erweiterte Steuermappings und feinere Feldkonfliktauflösung benötigen separate Planung und Freigabe.

## Dauerhafte DEV-Einrichtung

`scripts/configure-lexware-dev.py` ist ausschließlich für den vorhandenen DEV-Host vorgesehen und verlangt root sowie geprüfte Compose-Labels. Es ergänzt `/opt/dolibarr/dev/compose.yaml` um read-only Modulmounts und Docker-Secrets für Web und Cron. Der benannte Read-only-Schlüssel wird aus der geschützten Launcher-Datei gelesen und ohne Ausgabe als `/opt/dolibarr/dev/secrets/lexware_test_api_key_read_only` (`root:www-data`, 0440) gespeichert. Nach erfolgreicher Kandidatenvalidierung wird die ursprüngliche Compose-Datei vor der ersten Installation als `compose.before-lexware.yaml` (`root:root`, 0600) gesichert. Datei und Verzeichniseintrag werden vor den Installationsänderungen per fsync gesichert; ein bestehender Backup-Pfad wird niemals überschrieben und muss eine reguläre root-eigene Datei mit Modus 0600 sein. Symlinks und unsichere Backups werden abgewiesen. Bei Validierungsfehlern bleiben alle installierten Dateien unverändert und es entsteht kein Backup. Andere Schlüssel werden nicht übernommen.

```bash
sudo python3 scripts/configure-lexware-dev.py
sudo docker compose -f /opt/dolibarr/dev/compose.yaml up -d --no-deps --force-recreate dolibarr cron
```

Nach Secret-Rotation ist explizit `--force-recreate` für **dolibarr und cron** erforderlich, weil atomar ersetzte Dateien einen neuen Inode haben. Modulaktivierung erfolgt separat in Dolibarr. In DEV wurden Core und Lexware am 2026-10-02 aktiviert; der Lexware-Cronjob bleibt deaktiviert. Die Secret-Injektion ist für beide Kontexte geprüft, ein automatisch ausgeführter Lexware-Cronlauf ist damit noch nicht nachgewiesen.

Für die optionale Browserprüfung wurde Playwright 1.63.0 unter `/tmp/hwos-lexware-browser` installiert. Reproduktion: `npm install --prefix /tmp/hwos-lexware-browser playwright@1.63.0`, anschließend dessen `playwright install chromium` und bei fehlenden Systembibliotheken `playwright install-deps chromium`. Zugangsdaten werden dem Browser ausschließlich per stdin übergeben; der temporäre Testbenutzer wird anschließend entfernt.

Die Einrichtung benötigt Python-PyYAML. Sie prüft eine temporäre Compose-Datei im DEV-Projektverzeichnis vor jeder Installation. Jede der beiden Services erhält genau einen vorgesehenen Mount und ein Secret; doppelte oder abweichende Einträge werden abgewiesen. Compose und Secret werden einzeln atomar ersetzt; schlägt der Compose-Austausch fehl, wird die Wiederherstellung des vorigen Secrets versucht. Scheitert auch sie, bleibt die einzige Rollback-Kopie root-privat mit 0600 erhalten; `INCOMPLETE_RECOVERY` nennt ausschließlich den Pfad und verlangt manuelle Wiederherstellung. Während der Installation dürfen keine Container parallel neu erstellt werden. Kein Schutz gegen Stromausfall zwischen beiden Dateiaustauschen wird behauptet.

Das Dateilimit beträgt 4096 Bytes nach UTF-8-Serialisierung einschließlich des abschließenden LF (maximal 4095 ASCII-Schlüsselbytes). Der Client prüft dieselbe Grenze für die tatsächlich gelesenen Dateibytes. Konfigurierbare Pfade müssen absolut sein, ohne Symlinks, mit vertrauenswürdigen Eigentümern und nicht schreibbaren Elternverzeichnissen; root-eigene Sticky-Vorfahren oberhalb eines privaten direkten Elternverzeichnisses sind zulässig. Die geöffnete Datei wird über fstat und Identitätsvergleich vor/nach dem Öffnen geprüft. `/run/secrets` bleibt unterstützt.

Der Konfigurationsinstaller liest die Quelldatei über einen begrenzten No-follow-Deskriptor (maximal 8192 Bytes für die ENV-Datei), prüft geöffnete Identität, Eigentümer und Rechte und traversiert nur gepinnte, vertrauenswürdige Elternverzeichnisse. Konkurrierende Compose-Secrets am effektiven Ziel `/run/secrets/lexware_test_api_key_read_only` werden auch bei abweichendem Quellnamen vor der Serialisierung abgewiesen. PHP-Testkopien liegen in einem pro Aufruf neu erzeugten privaten Containerverzeichnis. Fehlgeschlagene HTTP-Fixture-Erstellung räumt Benutzer und Modulaktivierung selbst auf; die Entfernung des Helpers wird auch nach fehlgeschlagener Rechtewiederherstellung versucht.

### Sichere Quelle und schreibfreier Preflight

`python3 scripts/configure-lexware-dev.py --check-only` prüft ausschließlich die
Quelle über dieselben gepinnten No-follow-Deskriptoren. Kein Docker-Aufruf, Backup,
Installation oder Rechteänderung. Erfolg bestätigt nur die Quelle, keine laufenden
Container oder Compose-Konfiguration.

Jeder Vorfahr ab `/` muss root oder `vadmin` gehören, ohne Gruppen-/Fremdschreibrecht
und ohne Symlink. Private Quellverzeichnisse sollen 0700 haben; die reguläre ENV-Datei
muss `vadmin` gehören und soll 0600 haben. Installiertes Ziel: `root:www-data 0440`.
Am 2026-10-02 verweigerte der Preflight die reale Quelle zunächst, weil
`/home/vadmin/.config` als `vadmin:vadmin 0775` gruppenschreibbar war. Nach Prüfung
von Eigentümer, Symlinkfreiheit und Auswirkungen wurden `/home/vadmin/.config` und
`/home/vadmin/.config/trafopilot` auf 0700 sowie die ENV-Datei auf 0600 gesetzt; die
privaten Quellen gehören weiterhin `vadmin`. Der anschließende schreibfreie Preflight
bestand mit `SOURCE_PREFLIGHT_OK`. Bei Wiederherstellung oder Umzug der Quelle gelten
dieselben Eigentümer- und Rechteanforderungen, bevor Installation oder Rotation erfolgt.

HTTP-Fixtures verwenden ein zufälliges 0700-Containerverzeichnis pro Aufruf.
Eine hostlokale benutzereigene 0600-flock-Datei unter
`/tmp/hwos-lexware-ui-<uid>.lock` serialisiert HTTP-Prüfer dieses Benutzers vom Lesen
der Ausgangszustände bis zum Ende sämtlicher Aufräumversuche. Der Lock-Inode bleibt
für wartende Aufrufe bestehen. Andere Werkzeuge oder Benutzer sind nicht serialisiert.
Vor Modul-/Benutzer-/CSRF-Änderungen wird ein privates 0600-Journal ohne Passwort
angelegt. Die Laufanlage trägt eine bereits journalisierte Fixture-Identität, damit
auch ein Abbruch vor Speicherung der Lauf-ID bereinigt werden kann. Bereinigung
versucht CSRF, Historie, Benutzer, beide Module und Helper unabhängig und meldet
alle Phasenfehler. Exakte vorherige CSRF-/Modulkonstanten werden wiederhergestellt;
Modulfehler unterdrücken diese Wiederherstellung nicht. Der Python-Prüfer versucht
zusätzlich Verzeichnismodus, Helperentfernung und Lockfreigabe unabhängig. Bei
unvollständiger Fixture-Bereinigung bleiben das private Journal und sein gemeldeter
Pfad für erneute Wiederherstellung erhalten.


## Historie, Entfernung und Konfliktentscheidungen (0.2.0)

`payload_version` bewahrt jede unterschiedliche rohe JSON-Antwort bytegenau auf. Identische Bytes werden über SHA-256 dedupliziert; zusätzlich bleibt die normalisierte Quellprüfsumme erhalten. Sehr große JSON-Ganzzahlen und gleichlautende JSON-Strings haben unterschiedliche Quellprüfsummen. `file_version` bewahrt jede unterschiedliche Datei pro Ressource, Remote-Datei-ID und Repräsentation unveränderlich auf. Bekannte Datei-IDs werden erneut GET-abgerufen, damit auch geänderte Bytes unter derselben ID erkannt werden. Die bestehende Dateitabelle ist der aktuelle Lookup-Cache; historische Downloads verwenden die unveränderliche Versions-ID. Das Limit bleibt 32 MiB. Es gibt keinen Pruning-Pfad.

Ein vollständiger erfolgreicher Vollabgleich verwendet die Mitgliedschaft der vollständigen Kontakt-, Artikel- und Beleglisten. Verschwundene Kontakte, Artikel/Dienstleistungen, autoritativ aufgelistete Dokumenttypen und Beziehungen bleiben gespeichert und erhalten Entfernt-/Inaktiv-Historie mit Lauf und Zeitpunkt. Mahnungen besitzen keine vollständige öffentliche Auflistung und werden niemals durch Abwesenheit entfernt. Positive Detail-/Beziehungsbeobachtungen reaktivieren sofort; Abwesenheit erfordert typbezogene Vollabgleichautorität. Neuere Läufe sperren ältere Abwesenheitsentscheidungen. Wiederauftauchen wird deterministisch protokolliert. Inkrementelle, fehlgeschlagene, noch laufende und alte Checkpoints ohne vollständigen Mitgliedschaftsnachweis markieren keine Entfernungen. Einzelne 404-Antworten markieren ebenfalls keine Entfernung. Ungültige Pagination verhindert einen erfolgreichen Abschluss. Native Zuordnungen bleiben erhalten.

Nur Dolibarr-Administratoren starten oder verarbeiten Synchronisationsläufe; ein allein vergebenes Modulrecht `sync` oder `admin` genügt nicht. Lesen, manuelle Zuordnung, Konfliktlösung und Einzelwiederholung bleiben über ihre eigenen Rechte zugänglich, auch für berechtigte Nichtadministratoren. Administratoren dürfen diese Aktionen ebenfalls ausführen. Cron bleibt standardmäßig deaktiviert und wird durch diese Änderung nicht aktiviert.

Die Konfliktentscheidung erfolgt pro offenem Fall und exakter Quellprüfsumme, ausschließlich per POST mit nativer CSRF- und Rechteprüfung. Bei verknüpften nativen Belegen, Auftragspositionen mit Lieferursprung, Lieferungs-Chargen sowie Rechnungs-Rabatt-/Zeitverknüpfungen blockiert **Lexware** vor jedem Schreibzugriff. Für zulässige Änderungen speichert **Lexware** zuerst den vorherigen nativen Dolibarr-Zustand unveränderlich und wendet danach den ausgewählten aktuellen Spiegel innerhalb derselben Transaktion an. Fehlgeschlagene Änderungen rollen native Daten, Mapping, Entscheidung und Audit zurück. **Dolibarr** verändert den nativen Datensatz nicht und akzeptiert ausschließlich diese Quellversion; identische Wiederholungen bleiben unterdrückt, eine neue Quellprüfsumme öffnet wieder einen Konflikt. Ein späterer lokaler Edit nach einer Lexware-Entscheidung kann als neuer Fall auch gegen dieselbe Quelle entschieden werden. Es gibt keine globale Vorrangoption.

Artikelentscheidungen verwenden native Typ-/Preisoperationen und prüfen gespeicherte
Netto-/Bruttopreise, Steuer-/Preisbasistyp, Produkt-/Dienstleistungstyp und Preishistorie
vor Abschluss des Konflikts. Zugeordnete Angebots-, Auftrags- und Rechnungspositionen
behalten ihre IDs; die native Produktzuordnung wird geprüft geschrieben und aus der
Datenbank zurückgelesen. Eine ausdrücklich bestätigte Lieferungsauflösung kann deren
Positionen neu aufbauen, bewahrt aber das Lieferungsobjekt und den unveränderlichen
vorherigen Snapshot. Kontaktpersonen-Extrafields gehören zu Sperren, lokalen Konflikten
und unveränderlichen vorherigen Snapshots. Nicht erhaltbare abhängige Daten blockieren
die Entscheidung.

Ressourcendetails zeigen Payload- und Dateiversionen, Statushistorie, Konfliktentscheidungen, vorherige native Snapshots und das geschützte Auditprotokoll. Listen zeigen Entfernt-/Archiviert-Markierungen; Ressourcendetails und native Tabs zeigen Workflow und Projektion getrennt; Leseberechtigte sehen Konfliktwarnungen ohne Lösungsrechte. Payload- und Dateiversionen sind geschützt herunterladbar. Dynamische Inhalte werden HTML-escaped. Es wurden keine Benachrichtigungen oder Lager-, Bank-, Zahlungs- oder Buchhaltungsaktionen ergänzt.

Die vier neuen Modultabellen sind additive Migrationen. Wiederholbare Backfills übernehmen die aktuell vorhandenen Payloads und Dateien des veröffentlichten Schemas, ohne bestehende Zuordnungen oder Dateien zu ändern. Bereits vor dieser Änderung überschriebene Versionen können daraus nicht rekonstruiert werden.

Die [Implementierungsabnahme vom 03.10.2026](../../docs/lexware-history-acceptance-2026-10-03.md) beschreibt synthetische Datenbanktests und authentifizierte lokale UI-Prüfungen. Sie ist **kein neuer Live-Lexware-Nachweis** und keine TEST-/Produktionsfreigabe. Die private UI-Evidenz umfasst Desktop-Ansichten und eine befüllte mobile Ressource/Historie; die Konfliktseitenaufnahme verwendet bewusst einen Benutzer ohne Lösungsrecht. Admin-/Zuordnungsformulare werden separat serverseitig und per HTTP geprüft. Für die browserbasierte UI-Prüfung ist Playwright erforderlich; der Prüfer führt keine Lexware-Verbindungsaktion mehr aus.
