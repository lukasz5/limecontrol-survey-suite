# LimeControl Feedback

Samodzielna wtyczka LimeSurvey Community Edition dla sklepów. Obsługuje osobne ankiety po zakupie, potwierdzonym doręczeniu, okresie użytkowania produktu i po zwrocie z rozliczonymi pozycjami. Każdy sklep może uruchomić dowolny podzbiór tych kampanii. Magazyn spraw działa również bez LimeControl CRM.

## Instalacja

1. W ustawieniach wtyczek LimeSurvey CE zainstaluj ZIP zawierający katalog `LimeControlFeedback/` i aktywuj go. Aktywacja tworzy tabele z prefiksem bieżącej instalacji. Wtyczka korzysta z PHP 8.1+, PDO, cURL, lokalnej bazy MySQL/PostgreSQL i sposobu dostarczania poczty skonfigurowanego w LimeSurvey.
2. Zaimportuj odpowiednie szablony LSS z pakietu kampanii. Dla każdej pary sklep–kampania użyj osobnej ankiety, aktywuj ją i utwórz tabelę uczestników. Ustaw poprawny adres nadawcy ankiety. Tryb imienny lub anonimowy ustawia się przed aktywacją. Własne szablony mogą używać kodów pytań opisanych niżej.
3. Ustaw losowy, odrębny sekret współdzielony dla każdego sklepu o długości co najmniej 32 bajtów. Udostępnij go administratorowi adaptera oraz procesom PHP-FPM/Apache i CLI LimeSurvey przez zmienną środowiskową. W panelu wtyczki podaj nazwę zmiennej, publiczny adres np. `https://ankiety.example/index.php` oraz wskazany przez adapter adres HTTPS do weryfikacji zamówienia. PHP ma ufać certyfikatowi sklepu.
4. Skopiuj do adaptera url akcji LimeSurvey `plugins/unsecure` z `plugin=LimeControlFeedback&function=ingest`. Przykład dla adresów ścieżkowych: `https://ankiety.example/index.php/plugins/unsecure?plugin=LimeControlFeedback&function=ingest`. Dla adresów parametrycznych: `https://ankiety.example/index.php?r=plugins/unsecure&plugin=LimeControlFeedback&function=ingest`.
5. Uruchamiaj z katalogu instalacji polecenie `php application/commands/console.php plugin --target=LimeControlFeedback --function=run` np. co 5 minut. Standardowe `plugin cron` również obsługuje kolejkę, więc wystarczy jeden z tych harmonogramów.

Przykładowe ustawienie **Stores and campaigns**. Identyfikatory ankiet zastąp własnymi. Klucz sklepu ma odpowiadać `X-LC-Store` adaptera.

```json
{
  "demo-shop": {
    "secretEnv": "LCF_DEMO_SHOP_SECRET",
    "preflight": "https://sklep.example/wp-json/limecontrol-feedback/v1/order-snapshot",
    "campaigns": {
      "experience": {"survey": 100001, "delayHours": 24, "frequencyHours": 24},
      "delivery": {"survey": 100002, "delayHours": 12, "frequencyHours": 24},
      "product": {"survey": 100003, "delayHours": 168, "frequencyHours": 24, "lowScore": 2},
      "returns": {"survey": 100004, "delayHours": 48, "frequencyHours": 24}
    }
  }
}
```

`delayHours` liczy pełne godziny od zdarzenia kampanii. Kampania dostawy czeka na jawne `delivered_at`. Dla produktu punktem odniesienia jest potwierdzone doręczenie, a przy jego braku pierwsze zakwalifikowanie zamówienia. W kampanii zwrotu źródłem są rozliczone zwrócone pozycje. `frequencyHours` określa minimalny odstęp od poprzedniego zaproszenia tego sklepu dla adresu, również z innej kampanii. Krótszy odstęp pomija nowe zaproszenie. Wartość 0 wyłącza odstęp. Próg niskiej oceny `lowScore` domyślnie wynosi 2 na 5. Link rezygnacji wymaga potwierdzenia przyciskiem i zatrzymuje przyszłe zaproszenia ze wszystkich kampanii sklepu.

## Pomiar i obsługa

| Kod pytania | Dane w raporcie |
| --- | --- |
| CSAT, DELIVERY | Dwie niezależne skale 1–5. CSAT to odsetek ocen 4 lub 5 wśród odpowiedzi na dane pytanie. |
| NPS | Skala 0–10. Wynik to procent promotorów (9–10) pomniejszony o procent krytyków (0–6). |
| PRODUCT1…PRODUCT5, DEFECT1…DEFECT5 | Ocena 1–5 dla każdej z maksymalnie pięciu pozycji. Kolejność zawartości pochodzi z ostatniej podpisanej weryfikacji sklepu. Etykiety widoczne w ankiecie, kody produktów i opcjonalny kod wady zasilają agregaty. NA oznacza produkt jeszcze nieużywany. |
| CATEGORY, RETURNREASON | Kody zamkniętych pytań jednokrotnego wyboru, do raportów grupowanych. |
| CONTACT, RETURN | Natywne pytania Tak/Nie. CONTACT=Y w ankiecie imiennej tworzy jedną sprawę dla ukończonej odpowiedzi. RETURN=Y zmienia jej rodzaj na prośbę w sprawie zwrotu. Pytania o puste pozycje powinny być opcjonalne. |

Raport w menu narzędzi ankiety wymaga prawa odczytu odpowiedzi. Podaje liczebności, średnie, odsetki wysokich ocen, NPS i liczby wystąpień danej wady dla SKU. Wspólny wynik jakości agreguje ocenione pozycje. Zwrócona pozycja wypada z ankiety produktu przed wysyłką. Ankieta zwrotów może zapytać o nią osobno. Zmiany koszyka po zaproszeniu nie przestawiają etykiet przy wcześniej ocenionych pozycjach.

Anonimowy raport czyta wyniki bezpośrednio z LimeSurvey. Odczytuje dozwolone pola oceny i kody zamkniętych kategorii, ujawnia grupy od pięciu ocen. W tym trybie odpowiedź nie zapisuje sprawy, relacji do kupującego ani powiązania z pozycjami zamówienia. Do tematyki można użyć pytania CATEGORY z szerokimi, ustalonymi kategoriami.

W menu **Product ratings and returns** operator porównuje oceny produktów pozostawionych przez kupujących z przyczynami zwrotów. Obie aktywne ankiety imienne muszą należeć do tego samego sklepu, a operator mieć odczyt odpowiedzi w obu. Raport pokazuje sumę sklepu, liczbę ocen i średnią dla produktu i wariantu oraz liczby wskazanych przyczyn. Gdy ankieta dotyczy kilku zwróconych pozycji i respondent nie wskazał jednej, przyczyna pozostaje w grupie bez przypisanej pozycji.

W menu **Feedback cases** operator z prawami odczytu uczestników, odczytu i zmiany odpowiedzi otwiera sprawę oraz oznacza ją jako obsługiwaną lub zamkniętą. Jeżeli aktywny LimeControl CRM ma mapowanie sklepu i ankiety, worker ponawia przekazanie tej samej sprawy po stabilnym identyfikatorze. Brak pozytywnego CONTACT nie tworzy imiennej sprawy. Przy kilku niskich ocenach jedna sprawa zawiera wszystkie powiązane pozycje.

## Przetwarzanie i utrzymanie

Wspólny kontrakt adapterów przyjmuje dokument JSON v1 z polami event_id, order_id, revision, status, occurred_at, email i items. Opcjonalnie przyjmuje customer_ref, delivered_at, returned_items i return_closed_at. Pozycja niesie line_id, sku lub product_id, label, quantity i opcjonalne variant_id/category. Podpis to mały szesnastkowy HMAC-SHA256 z połączenia `X-LC-Timestamp`, kropki i dokładnych bajtów ciała. Proces akceptuje różnicę czasu do 300 sekund. Ta sama reguła podpisuje żądanie i odpowiedź przed wysyłką zaproszenia. Przy braku aktualnej weryfikacji worker odkłada próbę.

Każda para sklep/zamówienie ma monotoniczną rewizję, a każda para zamówienie/kampania jedno zaproszenie. Do SMTP trafia wyłącznie adres z ostatniej podpisanej weryfikacji. Stan `sending` oznacza, że pracownik zarezerwował wysyłkę. Po przerwaniu połączenia sprawdź log operatora poczty dla adresu i czasu w bezpiecznym panelu. Automatyczne ponowienie takiej wysyłki mogłoby dostarczyć drugi list. Oczekujące zdarzenia sprzed zdobycia rezerwacji podlegają ponowieniu.

Ustawienie retencji domyślnie przechowuje dane zamówienia, fakty imienne i sprawy przez 90 dni. Cron usuwa zapisaną treść i zachowuje znaczniki kolejki chroniące przed ponowną wysyłką starych zdarzeń oraz pseudonimowe identyfikatory blokad wyliczane przez HMAC. Dane uczestników i odpowiedzi należą do natywnej ankiety i jej polityki retencji. Wyłączenie wtyczki zatrzymuje kampanie. Przy przywracaniu należy odtworzyć bazę razem z ustawieniami i sekretem kontaktów.

## Testy

`php tests/acceptance.php` uruchamia testy na SQLite w pamięci. Dla jednorazowych baz testowych ustaw LCF_TEST_DSN, LCF_TEST_USER i LCF_TEST_PASSWORD. Test uruchamia unikalny prefiks tabel. Zestaw obejmuje m.in. ponowienia, kolejność zdarzeń, równoczesną zmianę adresu lub koszyka, częściowy zwrot, potwierdzone doręczenie, jedną rezerwację wysyłki, limit kontaktu, rezygnację, brak mieszania sklepów, pojedynczą sprawę z pozytywną zgodą oraz małe grupy anonimowe.

`tests/native.py` służy wyłącznie do lokalnej, jednorazowej instalacji CE z aktywnymi Feedback i CRM. Importuje własne ankiety, uruchamia lokalny sklepowy endpoint testowy TLS i serwer SMTP, przechodzi trzy formularze w przeglądarce i sprawdza stan w bazie. Wymaga `LCF_NATIVE_QA=disposable`, danych QA w LCF_QA_USER i LCF_QA_PASSWORD, zmiennej LCF_SUITE_SECRET także w procesie HTTP LimeSurvey, Node/Playwright i włączonego RemoteControl. Wywołaj z --tree, --base (loopback), --templates oraz --php. PLAYWRIGHT_MODULE i CHROME_PATH mogą wskazywać lokalne instalacje narzędzi.

Przykład odtworzenia testu ręcznie lub jako krok CI na jednorazowym runnerze. Administrator, dane połączenia CLI do bazy i ścieżki poniżej należą do przygotowanej instalacji testowej. Każdy przebieg tworzy trzy nowe ankiety i grupę, uruchamia najpierw samodzielną obsługę bez aktywnego zespołu CRM, potem sprawdza przekazanie sprawy po włączeniu zespołu. Sekret jest odczytywany przez proces HTTP i PHP CLI. Jeśli inne konfiguracje używają tej samej nazwy zmiennej, przygotuj oddzielną testową instalację.

```sh
export LCF_NATIVE_QA=disposable
export LCF_QA_USER="$TEST_ADMIN"
export LCF_QA_PASSWORD="$TEST_PASSWORD"
export LCF_SUITE_SECRET="$TEST_STORE_SECRET"
python3 tests/native.py --tree "$LS_CE_DIR" --base "http://127.0.0.1:$LS_TEST_PORT" \
  --templates "$EXTRACTED_LSS_DIR" --php "$PHP_CLI"
```

Wymagane są Python 3.10+, OpenSSL CLI, PHP CLI z rozszerzeniami LimeSurvey, cURL i PDO, Node.js i Playwright z lokalnym Chromium. Serwer HTTP LimeSurvey oraz CLI muszą wskazywać tę samą bazę QA. Wtyczki i szablony pobiera się z lokalnych ZIP-ów suite. Runner potrzebuje dostępu do portów pętli zwrotnej i uruchamiania Chromium. Wysyłki trafiają wyłącznie do tymczasowego SMTP testu.
