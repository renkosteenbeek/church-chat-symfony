Ondersteun deze flows:

## Flow 1:
Stap 1: nieuwe content
Vanuit de Content microservice wordt content gegenereerd en naar OpenAI gepushed. Zodra dit gereed is moet er een event worden gestuurd die de chat microservice oppikt.

Stap 2: content verspreiden
De nieuwe content moet worden ingepland aan alle leden die lid van van de betreffende kerk (tabel 'content status'). Er wordt een quied record aangemaakt.

Stap 3: Versturen
ER is een proces die queed content uitleest en stuk voor stuk verwerkt. Eventueel kan dit parallel via een setting. Die doet het volgende:
1. Uitlezen content
2. Bepalen: Is iemand lid van meerdere kerken? Indien meerdere -> zet status op wait.
3. maak een nieuwe converstation aan bij Open AI, met hierin de versturen content als eerste message. Sla het converstation nummer op in de database.
4. Verstuur de content via Signal, naar de Signal API.

Dit is het einde van dit proces.

## Flow 2
Stap 1:
Een member reageert via Signal. Dit bericht wordt gepolled door de chat service.

Stap 2:
We sturen de ontvangen content door aan de Response API van OpenAI, met het converstation Id.

Stap 3:
Open AI geeft een response. Dit kan zijn:
- Tekst -> dan sturen we dit terug via Signal API
- Tool call -> we voeren de betreffende tool uit (zie api's)

Relevante services:
- /Users/renko/docker/church-media/church-content-symfony
- /Users/renko/docker/church-media/church-signal-service
- /Users/renko/docker/church-media/church-infrastructure
