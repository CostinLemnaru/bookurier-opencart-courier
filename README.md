# Modul Bookurier Courier pentru OpenCart

- integrare Bookurier pentru livrare standard,
- integrare SameDay Locker pentru livrare la locker,
- generare AWB automata sau manuala din admin.

Compatibilitate actuala:

- OpenCart `3.0.5.x`
- OpenCart `4.1.x`

## Download last version

- OpenCart 4:
  [bookurier.ocmod.zip](https://github.com/CostinLemnaru/bookurier-opencart-courier/releases/download/v0.1.0/bookurier.ocmod.zip)
- OpenCart 3:
  [bookurier-opencart-courier-oc3-v0.1.0.ocmod.zip](https://github.com/CostinLemnaru/bookurier-opencart-courier/releases/download/v0.1.0/bookurier-opencart-courier-oc3-v0.1.0.ocmod.zip)

## Functionalitate

- adauga 2 metode de livrare:
  - `Bookurier`
  - `Sameday Locker`
- genereaza AWB automat pe statusurile selectate,
- permite generare manuala AWB din pagina comenzii,
- pentru `Sameday Locker`, clientul selecteaza lockerul in checkout,
- afiseaza in admin un tab `Courier AWB` cu:
  - numarul de AWB,
  - statusul curent,
  - download PDF,
  - refresh tracking.

## 1. Instalare

Pachetele de release sunt:

- OpenCart 4 installer: `bookurier.ocmod.zip`
- OpenCart 3 installer: `bookurier-opencart-courier-oc3-vX.Y.Z.ocmod.zip`

### OpenCart 4

1. mergi in `Marketplace > Installer`
2. upload la pachetul `bookurier.ocmod.zip`
3. dupa instalare, mergi in `Extensions > Extensions > Modules`
4. instaleaza `Bookurier Courier`
5. intra pe `Edit` si salveaza configurarea modulului
6. mergi in `Extensions > Extensions > Shipping`
7. instaleaza `Bookurier`
8. instaleaza `Sameday Locker` daca vrei fluxul de locker

### OpenCart 3

1. mergi in `Extensions > Installer`
2. upload la pachetul `bookurier-opencart-courier-oc3-vX.Y.Z.ocmod.zip`
3. mergi in `Extensions > Modifications` si apasa `Refresh` doar daca folosesti si alte OCMOD-uri in magazin
4. mergi in `Extensions > Extensions > Modules`
5. instaleaza `Bookurier Courier`
6. intra pe `Edit` si salveaza configurarea modulului
7. mergi in `Extensions > Extensions > Shipping`
8. instaleaza `Bookurier`
9. instaleaza `Sameday Locker` daca vrei fluxul de locker

## 2. Configurare rapida

Mergi la:

- `Extensions > Extensions > Modules > Bookurier Courier > Edit`

Completeaza:

- `Bookurier API Username`
- `Bookurier API Password`
- `Bookurier API Key (Tracking)`
- `Bookurier Default Pickup Point`
- `Bookurier Default Service`

Setari AWB:

- `Auto Generate AWB` = ON/OFF
- `Auto AWB Allowed Statuses` = statusurile care declanseaza AWB automat

Setari SameDay Locker:

- `Enable SameDay` = Yes
- `SameDay Environment` = `Production` sau `Demo`
- `SameDay API Username`
- `SameDay API Password`
- `Sync Pickup Points`
- selecteaza `SameDay Pickup Point`
- selecteaza `SameDay Package Type`
- apasa `Sync Lockers`

Dupa asta, configureaza si metodele de shipping:

- `Extensions > Extensions > Shipping > Bookurier > Edit`
- `Extensions > Extensions > Shipping > Sameday Locker > Edit`

In mod normal setezi:

- `Status = Enabled`
- `Geo Zone = All Zones` sau zona dorita
- `Cost` si `Sort Order` dupa regulile magazinului

## 3. Cum functioneaza AWB

- comanda cu metoda `Bookurier` -> AWB se genereaza prin Bookurier
- comanda cu metoda `Sameday Locker` -> AWB se genereaza prin SameDay, cu lockerul selectat in checkout
- daca `Auto Generate AWB = OFF`, folosesti butonul `Generate AWB` din pagina comenzii
- daca `Auto Generate AWB = ON`, AWB-ul se genereaza la trecerea comenzii intr-un status permis

## 4. Unde vezi AWB-ul

In pagina comenzii din admin, in tab-ul `Courier AWB`, vezi:

- courierul folosit,
- lockerul selectat pentru SameDay, daca exista,
- codul de AWB,
- statusul providerului,
- butoanele `Generate AWB`, `Refresh Tracking`, `Download AWB PDF`.

## Probleme frecvente

- Nu apar metodele de shipping in checkout:
  - verifica sa fie instalate si active in `Extensions > Extensions > Shipping`
- Nu apar lockere in checkout:
  - verifica `Enable SameDay = Yes`
  - ruleaza `Sync Pickup Points`
  - ruleaza `Sync Lockers`
- Nu se genereaza AWB Bookurier:
  - verifica `API Username`, `API Password`, `Default Pickup Point`, `Default Service`
  - verifica sa existe un cod postal valid pentru adresa comenzii
- Nu se genereaza AWB automat:
  - verifica `Auto Generate AWB`
  - verifica `Auto AWB Allowed Statuses`
- Statusul Bookurier ramane generic:
  - completeaza `Bookurier API Key (Tracking)`

## Requirements / Prerequisites

- OpenCart `4.1.x`
- extensia PHP `cURL`
- acces HTTPS outbound catre API-urile Bookurier si SameDay
- credentiale Bookurier valide
- pentru tracking Bookurier: `API Key`
- pentru SameDay Locker: credentiale SameDay valide si sync rulat cu succes
