# Analytics and privacy

```
   Transactional personal data                      Analytics
   (name, phone, e-mail, addresses,     ──────►     pseudonyms and aggregates
    the journey's origin, destination               customer  = keyed hash of the internal id
    and route)                                      journey   = keyed hash of its id + bands + corridors
   stays in the SOURCE DOMAIN only                  restaurant, market, day = plain dimensions

   Route analytics                      ──────►     corridor / city near an end / band
                                                    NOT a traveller's journey — none is stored
```

## What analytics never holds

No analytics table has a phone number, an e-mail address, a name, a postal address, an IP address, a user agent, a
device identifier, a coordinate, a geometry or a route. Tests check the schema (no such column, no geometry type)
and the stored rows.

The ingestion endpoint accepts only the events and properties of the registry. Anything else — a phone number put
into a property, an amount, a search text — makes that event refused, not stored with the field dropped.

## Pseudonyms

A signed-in customer is stored as a **keyed hash** of the internal id (SHA-256 with a key that is configuration;
the first 32 hex characters). It answers "the same customer as that one?" and nothing else; without the key it
cannot be computed from an id, and no API returns one. Changing the key starts new pseudonyms. A journey is stored
the same way.

A guest is a **session**: a random identifier the application makes up, not derived from hardware, replaced after
30 minutes idle or 12 hours. There is no device identifier and no cross-device stitching; after sign-in the same
session simply carries the customer's pseudonym, so the funnel of that session is attributed to the account —
nothing joins two devices.

## Small groups

Fine-grained rows — a corridor, a city, a band, a restaurant's new / returning split — are shown only from the
minimum cohort up (default 5). Below it they are folded away. A rate is shown only from the minimum denominator up
(default 5); below it the answer is "insufficient data", with its numerator and denominator.

## What a person may see

| Audience | Sees | Never sees |
|---|---|---|
| Administrator | Aggregates of the markets their permission covers; finance and support figures only with those permissions | A customer list, a traveller's route, another market's figures |
| Restaurant member | Aggregates of their own locations | Another restaurant, a customer's identity, any customer list |
| Customer | Nothing — there is no customer-facing analytics | |

Exports carry order numbers and case numbers — no customer name, phone, e-mail, address or route, and no text a
customer wrote. Asking for and downloading an export are audited.

## Consent and trackers

The events are first-party operational telemetry of the service's own funnel. There is **no advertising tracker and
no third-party analytics SDK** — no Meta Pixel, no Google Ads or Analytics, no ad identifier.

The architecture has a consent state: each application keeps a local opt-out switch (nothing is queued or sent when
it is off), and the endpoint stores nothing for a batch sent with `consent: false`. A consent screen and the policy
behind it are **not built** — they belong to the privacy and compliance module (Module 39).

No sensitive trait is inferred. The only customer segments are by ordering behaviour: first-time, returning,
repeat.

## Retention and deletion

| Data | Kept | Then |
|---|---|---|
| Raw behavioural events | 180 days (configuration) | deleted by `analytics:prune` (daily) |
| Session facts | 400 days | deleted |
| Daily aggregates | not limited | — they hold counts, no person |
| Export files | 24 hours | file deleted, the row marked EXPIRED |
| Aggregation and check records | 30 days | deleted |

`analytics:prune --forget-customer=<public id>` detaches one customer's behavioural trace: their events and sessions
lose the pseudonym and become anonymous. Orders, payments, refunds and the ledger are other domains' records, kept
under their own rules, and are not touched.

Every period above is a development default, PENDING BUSINESS APPROVAL; the legal basis, the consent policy and the
final retention schedule are Module 39's.
