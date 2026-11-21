# CRM i opinie klientów dla LimeSurvey

Moduły dla własnej instalacji LimeSurvey Community Edition. CRM obsługuje jedną organizację z zespołami. Sklep może zbierać opinie bez CRM. Połączenie obu modułów przekazuje opiekunowi sprawy z ankiet imiennych.

## Pakiety instalacyjne

Z głównego katalogu repozytorium uruchom:

```sh
npm run build:plugins
```

Ten sam build można uruchomić bez npm poleceniem `python3 limesurvey-plugins/suite/build.py`. Do budowania potrzebne są Python 3.9+, Node.js 20+ oraz PHP z rozszerzeniem ZIP. Polecenie zapisuje w `limesurvey-plugins/suite/dist/` dziewięć archiwów oraz `SHA256SUMS`. Paczki wtyczek wgraj przez instalator właściwej platformy. Konektory Shopify i Shoper rozpakuj na własnym serwerze. Archiwum ankiet rozpakuj przed importem plików LSS.

| Pakiet | Miejsce instalacji | Zastosowanie |
| --- | --- | --- |
| LimeControlCrm | Menedżer wtyczek LimeSurvey | Firmy, kontakty, szanse, sprawy, zadania, historia i raport kierownika. [Instrukcja CRM](../limecontrol-crm/README.md) |
| LimeControlFeedback | Menedżer wtyczek LimeSurvey | Kampanie, adresowane zaproszenia, raporty produktu i obsługi oraz dobrowolne prośby o kontakt. [Konfiguracja kampanii i harmonogramu](../limecontrol-feedback/README.md) |
| LimeControlCrmDesk | Menedżer wtyczek LimeSurvey, po aktywacji CRM | Wersje ofert i PDF, przypisywanie korespondencji do spraw, subskrypcja terminów w kalendarzu. [Konfiguracja poczty i kalendarza](../limecontrol-crm-desk/README.md) |
| limecontrol-feedback-woocommerce | WordPress → Wtyczki | Pozycje i warianty zamówienia, zwroty oraz sprawdzenie zamówienia przed wysyłką. [Instrukcja konektorów](../../commerce-connectors/README.md) |
| limecontrol-feedback-prestashop | PrestaShop → Menedżer modułów | Zamówienia, warianty i zwroty z uwzględnieniem identyfikatora sklepu. [Instrukcja konektorów](../../commerce-connectors/README.md) |
| limecontrol-feedback-shopify | Własny serwer PHP z HTTPS i cronem | Odczyt zamówień przez Shopify Admin GraphQL, warianty, realizacja, doręczenia i zwroty. [Instalacja Shopify](../../commerce-connectors/shopify/README.md) |
| limecontrol-feedback-shoper | Własny serwer PHP z HTTPS i cronem | Odczyt zamówień przez Shoper REST API, z potwierdzeniem rozliczenia pozycji przez sklep. [Instalacja Shoper](../../commerce-connectors/shoper/README.md) |
| limecontrol_feedback.ocmod.zip | OpenCart 4 → Extensions → Installer | Statusy zamówień, warianty i zwroty według konfiguracji konkretnego sklepu. [Instalacja OpenCart](../../commerce-connectors/opencart/README.md) |
| LimeControlSurveys | LimeSurvey → Importuj ankietę | Siedem definicji PL/EN. Każda tworzy osobną ankietę. |

## Uruchomienie CRM

1. Zainstaluj i aktywuj **LimeControlCrm**. Administrator otwiera pozycję **CRM** w panelu i włącza grupę użytkowników LimeSurvey. Właściciel grupy prowadzi zespół, a jego członkowie pracują na przydzielonych rekordach.
2. Zaimportuj i aktywuj `crm-inquiry.lss`. W ustawieniach CRM połącz numer ankiety z zespołem. Wskaż kody `CONTACT`, `PERSON`, `EMAIL`, `COMPANY` i `SUBJECT`. Po przesłaniu zapytania z prośbą o kontakt pojawia się szansa sprzedaży.
3. Przypisz opiekuna, firmę i termin następnego działania. Zapisz rozmowę, dodaj kwotę oraz wynik sprzedaży. Po zakupie obsłuż sprawę i zapisz rozwiązanie.
4. Opcjonalnie włącz **LimeControlCrmDesk**. Na karcie szansy utwórz ofertę, na karcie sprawy przypisz korespondencję, a z zadania utwórz subskrypcję własnych terminów.

## Uruchomienie badań sklepu

1. Zainstaluj **LimeControlFeedback** i wybrany konektor sklepu. Skonfiguruj osobny sekret, adresy HTTPS i status realizacji dla danego sklepu. Uruchom pracownika kolejki według instrukcji wtyczki. Każdy sklep i każda kampania mają osobny numer ankiety.
2. Dobierz LSS do celu. `purchase-experience` mierzy obsługę i skłonność do polecenia, `delivery` dostawę, `product-quality` jakość kupionych wariantów po czasie na ich użycie, a `returns` przyczynę i doświadczenie ze zwrotem. Utwórz tabelę uczestników dla każdej ankiety wysyłanej z zaproszeniem.
3. Ustaw progi i opóźnienia. W raporcie korzystaj z liczebności odpowiedzi obok oceny produktu. Raport porównawczy zestawia oceny zatrzymanych wariantów z przyczynami zwrotów w tym samym sklepie. Powód obejmujący kilka różnych zwracanych produktów trafia do osobnej grupy. W ankiecie produktowej przewidziano maksymalnie pięć pozycji na zaproszenie. Odpowiedź „jeszcze nie używam” nie wchodzi do oceny jakości.
4. Dla badania anonimowego wybierz `anonymous-service.lss`. Jeżeli chcesz przyjmować prośby o pomoc, zaimportuj osobno `service-contact.lss` jako publiczny formularz imienny i ustaw w CRM typ **sprawa obsługi**. Na stronie zakończenia badania anonimowego umieść stały adres tego formularza, taki sam dla wszystkich uczestników. Użyj go bez parametrów odpowiedzi, tokenu i zamówienia.
5. Gdy CRM ma przejmować sprawy z ankiety imiennej, zmapuj jej numer i identyfikator sklepu w ustawieniach zespołu. Osobne badanie z formularza anonimowego nie uczestniczy w takim mapowaniu.

## Odbiór lokalny i pilotaż

`npm run test:plugins` wykonuje podstawowe testy bez sklepu i konta dostawcy. Obejmują one m.in. `php limesurvey-plugins/limecontrol-crm/tests/crm.php`, `php limesurvey-plugins/limecontrol-feedback/tests/acceptance.php` i `php limesurvey-plugins/limecontrol-crm-desk/tests/documents.php`. W katalogach modułów są też scenariusze dla rzeczywistego LimeSurvey, WooCommerce i PrestaShop. `npm run test:commerce` uruchamia lokalne testy konektorów Shopify, Shoper i OpenCart oraz ich protokołu z LimeSurvey. `native-install.cjs` przechodzi przez menedżer wtyczek i sprawdza instalację lub aktualizację ZIP w jednorazowym środowisku na loopback.

Pilotaż operacyjny wymaga zespołu prowadzącego sprzedaż i sklepu, który wyznaczy własne statusy dostawy i zwrotów. Po pierwszych zamówieniach sprawdź z nimi, czy opiekun znajduje następny kontakt, czy raport wskazuje konkretny wadliwy wariant i czy po prośbie klienta jedna osoba doprowadza sprawę do rozwiązania. W Shoper i Shopify pozostaje próba na autoryzowanych sklepach z ich faktycznymi uprawnieniami i sposobem rozliczania zwrotów. [Zakres połączeń i testów](../../commerce-connectors/README.md)

### Sprawdzone konfiguracje wersji 0.1.0

Poniższe testy instalacji LimeSurvey z 20 listopada 2026 używały fikcyjnych danych i PHP 8.3.28. W każdej konfiguracji wgrano w przeglądarce trzy osobne ZIP-y (CRM, Feedback i CRM Desk). Sprawdzono aktywację w bazie i zgodność plików z archiwum. Osobna instalacja przeszła aktualizację paczek z istniejącymi rekordami i import wszystkich siedmiu szablonów PL/EN.

| LimeSurvey CE | PostgreSQL 14.20 | MySQL 8.4.11 |
| --- | --- | --- |
| 6.15.12 | Instalacja i aktualizacja, formularz CRM i pełny przebieg ankiety sklepowej | Instalacja i aktualizacja, rekord CRM, oferta PDF, skrzynka i kalendarz |
| 7.11.0 | Instalacja i aktualizacja, formularz CRM i pełny przebieg ankiety sklepowej | Instalacja i aktualizacja, rekord CRM, oferta PDF, skrzynka, kalendarz, odbiór zdarzeń obu sklepów i raport porównawczy |

Próby sklepowe objęły WordPress 6.8.1 z WooCommerce 8.0.2 oraz PrestaShop 8.1.7. Każdy sklep przekazał podpisaną migawkę zamówienia po częściowym zwrocie. Powtórzenie zgłoszenia zachowało jedno zdarzenie i dwa zadania kampanii. W testach publicznego formularza na obu wersjach CE zaproszenia odbierał lokalny SMTP. Kalendarz, przypisanie wiadomości do sprawy i pobranie PDF sprawdzono w przeglądarce. Osobny test wykorzystał lokalną skrzynkę IMAP z TLS.

OpenCart 4.1.0.4 przyjął nowy ZIP przez natywny instalator. Na lokalnym sklepie sprawdzono zakup gościnny dwóch sztuk wariantu, otwarcie zwrotu, rozliczenie jednej sztuki i anulowanie. Harmonogram sklepu dostarczył przez HTTPS wszystkie cztery wersje do testowego odbiornika i zapisał ich potwierdzenia w bazie. Ponowny przebieg przy tym samym stanie zachował po jednej dostawie każdej wersji. Sprawdzono też odczyt przed zaproszeniem i izolację sklepów.

Konektory Shopify i Shoper uruchomiono z rozpakowanych ZIP-ów. Testy korzystały z lokalnych serwerów odtwarzających odpowiedzi API. Objęły stronicowanie, zmianę stanu przed wysyłką, podpisy i rozliczenie zwrotów. W osobnym teście rzeczywisty kod odbiornika LimeControl Feedback przyjął rewizje do SQLite i wstrzymał oczekujące zaproszenie po anulowaniu. Próby z aplikacjami na kontach sprzedawców pozostają otwarte.

Sumy SHA-256 wcześniejszych sześciu paczek zachowano po rozszerzeniu zestawu. Aktualny wykaz zawiera wszystkie dziewięć plików. [Sumy kontrolne](dist/SHA256SUMS)
