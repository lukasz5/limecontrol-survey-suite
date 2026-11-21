# Oferty, skrzynka zespołu i kalendarz

Zainstaluj **LimeControlCrm** i włącz co najmniej jedną grupę użytkowników. Następnie zainstaluj i aktywuj ZIP **LimeControlCrmDesk**. Rozszerzenie korzysta z dostępu do rekordu i grupy sprawdzanego przez CRM przy każdym otwarciu lub zapisie.

- Otwórz szansę sprzedaży i wybierz **Oferty handlowe**. Dodaj odbiorcę, pozycje, ilości i ceny. Zapis tworzy wersję oferty z własnymi danymi i walutą. Kolejna wersja wykorzystuje poprzednie pozycje. Przy każdej wersji można pobrać PDF i zapisać decyzję klienta. Kwoty oznaczają wartości wpisane przez handlowca w jednej walucie. Moduł nie wylicza podatku.
- Otwórz zadanie przypisane do operatora i wybierz **Mój kalendarz**. Prywatny adres ICS służy do subskrypcji jego terminów w danym zespole. Nowy adres unieważnia poprzedni. Odebranie członkostwa lub wyłączenie konta blokuje kolejne odczyty. Aktualizacje są jednokierunkowe z CRM do kalendarza. Używaj HTTPS i traktuj adres jak hasło.
- Otwórz sprawę i wybierz **Korespondencja**. Kierownik widzi także nowe wiadomości wspólnej skrzynki i przypisuje je do sprawy. Pracownik widzi korespondencję powiązaną z dostępną mu sprawą. Moduł pobiera przychodzącą pocztę w trybie odczytu. Integracja pokazuje tekst wiadomości i nie importuje załączników.

## Źródło korespondencji

W środowisku procesu CLI ustaw jedną z konfiguracji dla grupy. Numer oznacza identyfikator grupy LimeSurvey. Dla skrzynki IMAP ustaw `LIMECONTROL_CRM_IMAP` np. na:

```json
{"3":{"host":"imap.example.invalid","port":993,"username":"support@example.invalid","passwordEnv":"CRM_SUPPORT_IMAP_PASSWORD","folder":"INBOX"}}
```

Hasło lub hasło aplikacyjne umieść w zmiennej środowiskowej wskazanej przez `passwordEnv`. Połączenie używa IMAP przez TLS i folderu otwieranego za pomocą EXAMINE. Alternatywnie skieruj pocztę do dedykowanego katalogu Maildir i ustaw `LIMECONTROL_CRM_MAILDIRS` na `{"3":"/srv/mail/crm-support"}`. Używaj jednego źródła dla danej skrzynki.

Z katalogu instalacji LimeSurvey uruchamiaj cyklicznie:

```sh
php application/commands/console.php plugin index --target=LimeControlCrmDesk --function=mail
```

Jedno uruchomienie importuje do 100 wiadomości z każdego źródła. Wiadomości większe niż 4 MiB są pomijane. Dane CRM, wersje ofert, skrzynka oraz prywatne subskrypcje są częścią bazy tej instalacji. Kopia zapasowa bazy przed aktualizacją obejmuje wszystkie moduły.

## Odtworzenie próby skrzynki

W jednorazowej instalacji CE z aktywnym CRM, CRM Desk i grupą testową uruchom `LC_DESK_SYNTHETIC=1 python3 tests/imap.py /ścieżka/do/testowego/LimeSurvey NUMER_GRUPY`. Wymagane są Python, OpenSSL i PHP CLI zgodne z instalacją. Zmienna `LC_TEST_PHP` może wskazywać konkretny interpreter. Próba tworzy lokalną skrzynkę IMAP z tymczasowym certyfikatem TLS i sprawdza, czy powtórne pobranie zachowuje jedną wiadomość.
