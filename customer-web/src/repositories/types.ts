/**
 * Structured models consumed by the public pages and shared UI.
 *
 * Pages never hold business data inline: they read from a repository (see ./index.ts).
 * Module 02 ships Mock* implementations; later modules add Api* implementations that
 * return the same models, so the UI does not change when the backend arrives.
 */

export type IconName =
  | 'route' | 'search' | 'bag' | 'clock' | 'star' | 'shield' | 'heart' | 'users' | 'pin' | 'smile'
  | 'target' | 'eye' | 'gem' | 'bulb' | 'rocket' | 'flag' | 'car' | 'fork' | 'card' | 'user'
  | 'store' | 'phone' | 'chat' | 'mail' | 'android' | 'headset'

export type Tone = 'orange' | 'red' | 'purple' | 'green' | 'blue'

export type NavItem = { label: string; to: string; end?: boolean }

export type SiteNavigation = {
  primary: NavItem[]
  /** Secondary action shown as an outlined button (e.g. Login). */
  secondaryAction: NavItem
  /** Primary CTA shown as the gradient button. */
  primaryAction: NavItem
  accountLink: NavItem
  footer: {
    quickLinks: NavItem[]
    legal: NavItem[]
    /** Social profiles are not approved yet; entries carry `pending` until URLs exist. */
    social: Array<{ name: string; href?: string; pending: boolean }>
    tagline: string
  }
}

export type StoreBadge = { store: 'google-play' | 'app-store'; to?: string; status: 'placeholder' | 'deferred' | 'live'; label: string; sub: string }

export type FeatureCard = { icon: IconName; tone: Tone; title: string; text: string }
export type Step = { title: string; text: string }

export type HomeContent = {
  eyebrow: string
  title: string
  accent: string
  lead: string
  primaryCta: NavItem
  secondaryCta: NavItem
  stores: StoreBadge[]
  features: FeatureCard[]
  howItWorks: { eyebrow: string; title: string; steps: Step[] }
  /** Restaurant ids highlighted in the hero visual (resolved via RestaurantRepository). */
  featuredRestaurantIds: string[]
}

export type HowItWorksContent = {
  hero: { eyebrow: string; title: string; accent: string; lead: string; cta: NavItem }
  steps: Step[]
  benefits: Array<{ icon: IconName; title: string; text: string }>
}

export type AboutContent = {
  hero: { eyebrow: string; title: string; accent: string; leadHtml: string; script: string; pills: Array<{ icon: IconName; tone: Tone; title: string; sub: string }> }
  /** Figures come from the approved mockup and are NOT verified business data (tracked as pending content). */
  stats: { illustrative: true; items: Array<{ icon: IconName; tone: Tone; value: string; label: string }> }
  mission: string
  vision: string
  values: Array<{ icon: IconName; tone: Tone; title: string; text: string }>
  story: { eyebrow: string; title: string; intro: string; timeline: Array<{ icon: IconName; title: string; text: string }> }
}

export type HelpTopic = { icon: IconName; title: string; text: string; to: string; keywords: string }
export type HelpContact = { icon: IconName; kind: 'chat' | 'email' | 'phone'; title: string; detail: string; note?: string; href?: string; /** true = needs a backend that does not exist yet */ backendRequired: boolean }
export type HelpContent = { title: string; sub: string; searchPlaceholder: string; topics: HelpTopic[]; contacts: HelpContact[] }

export type ForRestaurantsContent = {
  hero: { title: string; accent: string; lead: string; primaryCta: NavItem; secondaryCta: NavItem }
  benefits: Array<{ icon: IconName; title: string; text: string }>
  steps: Step[]
  closing: { title: string; text: string; cta: NavItem }
}

export type GetAppContent = { title: string; accent: string; lead: string; stores: StoreBadge[]; highlights: Array<{ icon: IconName; title: string; text: string }> }

export type NotFoundContent = { code: string; title: string; text: string; actions: NavItem[] }

export type Restaurant = {
  id: string
  name: string
  cuisines: string[]
  rating: number
  reviews: number
  distance: string
  time: string
  detour: string
  tags: string[]
  image: string
  fallback: string
}

export interface ContentRepository {
  getSiteNavigation(): SiteNavigation
  getHomeContent(): HomeContent
  getHowItWorksContent(): HowItWorksContent
  getAboutContent(): AboutContent
  getHelpContent(): HelpContent
  getForRestaurantsContent(): ForRestaurantsContent
  getGetAppContent(): GetAppContent
  getNotFoundContent(): NotFoundContent
}

export interface RestaurantRepository {
  list(): Restaurant[]
  byId(id: string): Restaurant | undefined
}
