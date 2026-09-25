/**
 * LEGAL CONTENT = DRAFT / PENDING FINAL BUSINESS & LEGAL APPROVAL
 *
 * These pages exist so the site structure, navigation and layout are complete.
 * The section text below is structural placeholder copy only. It must be replaced
 * with owner-approved legal wording before any production release.
 */
export type LegalSection = { heading: string; body: string[] }
export type LegalDoc = { slug: string; title: string; intro: string; lastUpdated: string; sections: LegalSection[] }

const PENDING = 'Final wording pending business and legal approval.'

export const LEGAL_DOCS: Record<string, LegalDoc> = {
  terms: {
    slug: 'terms',
    title: 'Terms & Conditions',
    intro: 'These terms will govern the use of the FoodOnTheGo website and mobile app, including planning journeys, placing pre-orders and collecting them from partner restaurants.',
    lastUpdated: 'Draft — not yet in effect',
    sections: [
      { heading: '1. Acceptance of terms', body: ['Describes when and how a customer agrees to these terms by creating an account or placing an order.', PENDING] },
      { heading: '2. Accounts and eligibility', body: ['Account creation, accurate information, keeping credentials secure, and minimum age requirements.', PENDING] },
      { heading: '3. Orders and pickup', body: ['How pre-orders work, pickup time windows, arriving to collect an order, and what happens if an order is not collected.', PENDING] },
      { heading: '4. Pricing and payment', body: ['Prices are set by partner restaurants; taxes, fees, accepted payment methods and payment processing by our provider.', PENDING] },
      { heading: '5. Cancellations and refunds', body: ['Summary that points to the Refund & Cancellation Policy.', PENDING] },
      { heading: '6. Partner restaurants', body: ['Restaurants are independent businesses responsible for food preparation, quality and safety.', PENDING] },
      { heading: '7. Route and location information', body: ['Route, distance and detour estimates are indicative and depend on third-party map and routing providers.', PENDING] },
      { heading: '8. Acceptable use', body: ['Prohibited behaviour, misuse of promotions, and consequences.', PENDING] },
      { heading: '9. Limitation of liability', body: [PENDING] },
      { heading: '10. Changes to these terms', body: [PENDING] },
      { heading: '11. Contact', body: ['Questions about these terms can be sent to support@foodonthego.com.'] },
    ],
  },
  privacy: {
    slug: 'privacy',
    title: 'Privacy Policy',
    intro: 'This policy will explain what personal data FoodOnTheGo collects, why, how it is protected, and the choices available to you.',
    lastUpdated: 'Draft — not yet in effect',
    sections: [
      { heading: '1. Information we collect', body: ['Account details, contact information, order history, saved addresses, payment method references (not full card numbers), and device information.', PENDING] },
      { heading: '2. Location data', body: ['Journey start/destination and, with permission, current location, used only to find restaurants along your route.', PENDING] },
      { heading: '3. How we use information', body: ['To process orders, notify you about pickup, personalise recommendations, prevent fraud and improve the service.', PENDING] },
      { heading: '4. Sharing with partner restaurants and providers', body: ['Order details shared with the restaurant you order from; payment, maps/routing, notification and hosting providers.', PENDING] },
      { heading: '5. Data retention', body: [PENDING] },
      { heading: '6. Your rights and choices', body: ['Access, correction, deletion, notification preferences and account deletion requests.', PENDING] },
      { heading: '7. Security', body: [PENDING] },
      { heading: '8. Children', body: [PENDING] },
      { heading: '9. Changes to this policy', body: [PENDING] },
      { heading: '10. Contact', body: ['Privacy questions can be sent to support@foodonthego.com.'] },
    ],
  },
  'refund-policy': {
    slug: 'refund-policy',
    title: 'Refund & Cancellation Policy',
    intro: 'This policy will set out when a pre-order can be cancelled and how refunds are handled for orders placed through FoodOnTheGo.',
    lastUpdated: 'Draft — not yet in effect',
    sections: [
      { heading: '1. Cancelling before preparation starts', body: ['Cancellation window and how to cancel from Order Tracking or My Orders.', PENDING] },
      { heading: '2. Cancelling after preparation starts', body: [PENDING] },
      { heading: '3. Restaurant-initiated cancellations', body: ['Full refund when a restaurant cannot fulfil an order.', PENDING] },
      { heading: '4. Uncollected orders', body: [PENDING] },
      { heading: '5. Refund method and timing', body: ['Refunds return to the original payment method; typical processing times by payment type.', PENDING] },
      { heading: '6. Order issues and disputes', body: ['How to report a wrong or unsatisfactory order through Help & Support.', PENDING] },
      { heading: '7. Contact', body: ['Refund questions can be sent to support@foodonthego.com.'] },
    ],
  },
  'cookie-policy': {
    slug: 'cookie-policy',
    title: 'Cookie Policy',
    intro: 'This policy will describe the cookies and similar technologies used on the FoodOnTheGo website and how you can control them.',
    lastUpdated: 'Draft — not yet in effect',
    sections: [
      { heading: '1. What cookies are', body: [PENDING] },
      { heading: '2. Cookies we use', body: ['Strictly necessary (sign-in, cart), preferences (remembered route, filters), analytics and performance.', PENDING] },
      { heading: '3. Third-party cookies', body: ['Maps, payment and analytics providers.', PENDING] },
      { heading: '4. Managing cookies', body: ['Browser settings and in-app preferences.', PENDING] },
      { heading: '5. Changes to this policy', body: [PENDING] },
      { heading: '6. Contact', body: ['Questions can be sent to support@foodonthego.com.'] },
    ],
  },
}
