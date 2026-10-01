# Hindi translation — notes for the reviewer

Status: **machine-assisted translation, not yet reviewed by a native speaker** (tracker CF-317).
Date: 2026-10-01. Scope: Restaurant Dashboard, Platform Admin, staff sign-in / password / MFA / account-security
screens, and the staff e-mails. Not translated: the customer website, the Android app, data shown in the
dashboards.

## Where the texts are

| What | File | Texts |
|---|---|---|
| Restaurant Dashboard | `customer-web/src/dashboard/strings.hi.ts` | 626 |
| Platform Admin | `customer-web/src/admin/strings.hi.ts` | 1,257 |
| Sign-in, password, MFA, account security | `customer-web/src/auth/staff/strings.hi.ts` | about 110 |
| Customer-side texts the dashboards also show (review tags, payment and pickup-code states) | `customer-web/src/i18n/shared.hi.ts` | 34 |
| E-mails (invitation, password reset, MFA notices) | `backend/lang/hi/auth.php` | 18 |
| Messages in API answers (refusals, confirmations) | `backend/lang/hi.json` (English text → Hindi text) | 94 |
| Standard validation messages and field names | `backend/lang/hi/validation.php` | about 170 |

Each file has the same keys as its English counterpart (`strings.ts`, `lang/en/auth.php`); a test fails if a key
is missing or a `{placeholder}` differs. To change a wording, edit the Hindi value only.

## Style that was followed

Simple, modern Hindi as used in Indian partner apps; widely understood transliterations for product and technical
terms; polite imperative for buttons ("सेव करें"); "आप"; Latin digits; nuqta spellings (फ़, ज़). Kept in Latin:
`FoodOnTheGo`, codes and identifiers (ACTIVE, INR, UPI, MFA, OTP, QR, GeoJSON …), e-mail addresses, units.

Glossary (main terms): Order ऑर्डर · Restaurant रेस्टोरेंट · Pickup पिकअप · Menu मेन्यू · Item आइटम · Category श्रेणी ·
Customer ग्राहक · Staff स्टाफ़ · Owner मालिक · Dashboard डैशबोर्ड · Settings सेटिंग्स · Notification सूचना · Review समीक्षा ·
Analytics एनालिटिक्स · Payment भुगतान · Refund रिफ़ंड · Settlement सेटलमेंट · Fee शुल्क · Tax टैक्स · Promotion प्रमोशन ·
Support सहायता · Market मार्केट · City शहर · Service area सेवा क्षेत्र · Route corridor रूट कॉरिडोर · Location लोकेशन ·
Status स्थिति · Active सक्रिय · Suspended निलंबित · Disabled अक्षम · Pending लंबित · Role भूमिका · Permission अनुमति ·
Administrator एडमिनिस्ट्रेटर · Account खाता · Audit log ऑडिट लॉग · Security सुरक्षा · Save सेव करें · Cancel रद्द करें ·
Delete हटाएं · Edit संपादित करें · Search खोजें · Reason कारण · Required ज़रूरी.

## Choices the translation had to make — please confirm or correct

- **"Delivery" of notifications** is written as "भेजे जाने की स्थिति" / "सूचना भेजने के चैनल", never "डिलीवरी",
  because the product has no food delivery.
- **"Review" as a verb** (review a document) is "जांच करें"; "समीक्षा" is used only for customer reviews.
- **"Cuisine"** → "व्यंजन" / "व्यंजन शैलियां".
- **"Sold out"** → "स्टॉक में नहीं" (same as "Out of stock").
- **"Viewer" role** → "दर्शक" (alternative: "व्यूअर").
- **"Subtotal"** → "उप-योग" (alternative: "सबटोटल").
- **Platform health badges**: Operational / Degraded / Outage → "चालू" / "धीमा" / "ठप".
- **Environment names**: Local / Staging / Production → "लोकल" / "स्टेजिंग" / "प्रोडक्शन".
- **Navigation groups**: Operations / Finance / Growth → "ऑपरेशन्स" / "फ़ाइनेंस" / "ग्रोथ".
- **"Planned"** (market, city, payment method) → "नियोजित" (alternative: "प्लान्ड"); **"Paused"** → "रोका गया".
- **"Scheduled"** → "शेड्यूल्ड".
- **"Captured"** (payment) → "कैप्चर हुआ"; **"Reconciliation"** → "मिलान"; **"Exceptions"** → "अपवाद".
- **"Gross / Net payout"** → "सकल" / "शुद्ध पेआउट" (a little formal).
- **"Audience"** (announcements) → "प्राप्तकर्ता"; **"Case"** (support) → "मामला"; **"Assignee"** → "असाइनी".
- **"Actor" / "Target"** (audit log) → "कर्ता" / "लक्ष्य".
- **"Enrolled / Not enrolled"** (MFA) → "चालू है" / "चालू नहीं है".
- **"Denied"** (audit result) → "अस्वीकार" (to differ from "Rejected" अस्वीकृत).
- **"Postal code"** → "पिन कोड" (India usage).
- **"State / UT"** → "राज्य / केंद्र शासित प्रदेश" (long for a tab or a column header).
- **"Region" and "Area"** column headers are both "क्षेत्र".
- **"Lead time"** → "लीड टाइम"; **"Cut-off"** → "ऑर्डर लेने की आख़िरी सीमा".
- **"ASAP"** → "जल्द से जल्द (ASAP)".
- **"From" / "To"** for opening hours → "से" / "तक"; these are postpositions in Hindi, so they may read oddly
  where the screen puts them before the time.
- **Dietary tags** (Vegetarian / Vegan / Gluten-Free) in a hint text are translated ("शाकाहारी, वीगन, ग्लूटेन-फ़्री").
- **Plural markers** such as "location(s)" are dropped; Hindi does not need them.
- **Feminine forms** are used where the noun is feminine (समीक्षा → "छिपी हुई", अनुमति → "दी गई").
- **"Account security"** is "खाते की सुरक्षा" on the screens and in the e-mails.
- **Internal development notes** on some screens (mentions of mock data, the backend, module numbers) are
  translated with technical nouns transliterated; they are meant for the team, not for restaurants.

## Backend messages (added 2026-10-01)

- Field names in validation messages are translated (ई-मेल, पासवर्ड, नाम, फ़ोन नंबर, कारण, भूमिका, स्थिति, भाषा …);
  a field without a Hindi name appears with its technical name.
- Verb gender in the standard validation messages is fixed per message ("होना चाहिए" / "होनी चाहिए"), so it does
  not always agree with the field name that is inserted.
- "must be missing" → "नहीं भेजा जाना चाहिए"; "prohibited" → "भरने की अनुमति नहीं है"; "array" → "सूची (ऐरे)";
  "string" → "टेक्स्ट"; "true or false" → "सही या गलत में से एक"; lowercase / uppercase keep the English word in
  brackets.
- Technical messages meant for developers of a client (Idempotency-Key, GeoJSON geometry, filter / sort syntax)
  are translated with the technical words in Latin.
- Status values inside a message stay as codes: "स्थिति ACTIVE से INVITED नहीं बदली जा सकती।"
- A reason reported by the database for an invalid geometry (e.g. "Self-intersection") stays English inside the
  Hindi sentence.
