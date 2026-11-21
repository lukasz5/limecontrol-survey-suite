# LimeControl Survey Suite

Wtyczki LimeSurvey do prowadzenia CRM i badania satysfakcji klientów oraz jakości kupionych produktów. Zestaw zawiera CRM, CRM Desk, Feedback, siedem szablonów PL/EN i konektory WooCommerce, PrestaShop, OpenCart, Shopify i Shoper.

[Paczki instalacyjne ZIP i sumy SHA-256](limesurvey-plugins/suite/dist) · [Instrukcja instalacji i testów](limesurvey-plugins/suite/README.md)

Aby zbudować wszystkie paczki ze źródeł, pobierz repozytorium wraz z konektorami.

```sh
git clone --recurse-submodules https://github.com/lukasz5/limecontrol-survey-suite.git
cd limecontrol-survey-suite
npm run build:plugins
npm run test:plugins
npm run test:commerce
```
