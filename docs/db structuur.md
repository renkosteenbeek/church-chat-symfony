Database:

1. Members
Hierin wordt de member status bijgehouden.
velden:
- id
- openAI conversation Id
- Voornaam
- doelgroep (enum: volwassen, verdieping, jongeren)
- leeftijd
- intake is afgerond (ja/nee)
- notificaties nieuwe dienst (ja / nee /  default ja.)
- notifiacties reflectievragen (ja / nee /  default ja.)
- telefoonnummer
- platform (enum met 1 optie: signal)
- active sinds
- active sermon (linkt naar 0 of 1 actieve preek)
- churd ids (een member kan met 0, 1 of meerdere kerken verbonden zijn)
- active sinds
- laatste activiteit

2: Content status
Hierin wordt bijgehouden wie welke content heeft ontvangen en de chat history. Dit wordt alleen gebruikt als log.

velden:
- contentid (relatie tot content id in de content microservice)
- member
- status (enum)
  - scheduled -> het ingelpand voor een later moment
  - waiting -> wacht op iets
  - quied for sending  -> het kan nu ieder moment worden verstuurd
  - sent -> is verstuurd
  - error -> fout
- schedule date (relevant indien scheduled)
- sent date

3: chat history
een database waarin we de chat history bijhouden.


als we info moeten queried over kerken of content, dan roepen we direct de content api aan.
