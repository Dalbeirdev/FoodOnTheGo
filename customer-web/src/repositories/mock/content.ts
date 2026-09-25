import type { AboutContent, ContentRepository, ForRestaurantsContent, GetAppContent, HelpContent, HomeContent, HowItWorksContent, NotFoundContent, SiteNavigation } from '../types'

/**
 * Approved public copy for the Customer Web (Module 01 designs), served through the
 * repository abstraction so pages stay free of hardcoded business data.
 */
const NAVIGATION: SiteNavigation = {
  primary: [
    { label: 'Home', to: '/', end: true },
    { label: 'How It Works', to: '/how-it-works' },
    { label: 'Restaurants', to: '/restaurants' },
    { label: 'Plan a Journey', to: '/plan-journey' },
    { label: 'About Us', to: '/about-us' },
    { label: 'For Restaurants', to: '/for-restaurants' },
    { label: 'Help', to: '/help' },
  ],
  secondaryAction: { label: 'Login', to: '/login' },
  primaryAction: { label: 'Get the App', to: '/get-app' },
  accountLink: { label: 'My Account', to: '/my-profile' },
  footer: {
    quickLinks: [
      { label: 'Home', to: '/' },
      { label: 'How It Works', to: '/how-it-works' },
      { label: 'Restaurants', to: '/restaurants' },
      { label: 'Plan a Journey', to: '/plan-journey' },
      { label: 'About Us', to: '/about-us' },
      { label: 'For Restaurants', to: '/for-restaurants' },
      { label: 'Help & Support', to: '/help' },
    ],
    legal: [
      { label: 'Terms of Service', to: '/terms' },
      { label: 'Privacy Policy', to: '/privacy' },
      { label: 'Refund Policy', to: '/refund-policy' },
      { label: 'Cookie Policy', to: '/cookie-policy' },
    ],
    // Social profile URLs have not been supplied/approved — rendered as pending, never as live links.
    social: [
      { name: 'Facebook', pending: true },
      { name: 'Instagram', pending: true },
      { name: 'X', pending: true },
      { name: 'YouTube', pending: true },
    ],
    tagline: 'Discover and order from the best restaurants on your travel route. Good food, always on the way.',
  },
}

const HOME: HomeContent = {
  eyebrow: 'Good food makes every journey better',
  title: 'Delicious Food',
  accent: 'On Your Route',
  lead: 'Plan your journey, discover great restaurants along your route, pre-order your favourite food and pick it up at the perfect time.',
  primaryCta: { label: 'Plan a Journey', to: '/plan-journey' },
  secondaryCta: { label: 'Explore Restaurants', to: '/restaurants' },
  stores: [
    { store: 'google-play', to: '/get-app', status: 'placeholder', label: 'Google Play', sub: 'GET IT ON' },
    // iOS is deferred: shown as a non-link "coming later" state, never as an active download.
    { store: 'app-store', status: 'deferred', label: 'App Store', sub: 'Coming later' },
  ],
  features: [
    { icon: 'route', tone: 'red', title: 'Plan Your Journey', text: 'Set your start and destination to see restaurants on your route.' },
    { icon: 'search', tone: 'purple', title: 'Discover Restaurants', text: 'Find great food options along your way.' },
    { icon: 'bag', tone: 'green', title: 'Pre-Order & Pick Up', text: 'Order in advance and pick up at the perfect time.' },
    { icon: 'clock', tone: 'orange', title: 'Save Time & Enjoy', text: 'No waiting, no detours. Just great food on your route.' },
  ],
  howItWorks: {
    eyebrow: 'How it works',
    title: 'Simple Steps to Great Food',
    steps: [
      { title: 'Plan Your Journey', text: 'Enter where you are starting from and where you are heading.' },
      { title: 'Explore Restaurants', text: 'See restaurants along your route with ratings and detour time.' },
      { title: 'Pre-Order Your Food', text: 'Pick your dishes, choose a pickup time and pay securely.' },
      { title: 'Pick Up & Enjoy', text: 'Show your pickup code, collect your order and carry on.' },
    ],
  },
  featuredRestaurantIds: ['burger-hub', 'pizza-point'],
}

const HOW_IT_WORKS: HowItWorksContent = {
  hero: {
    eyebrow: 'How it works',
    title: 'Great Food',
    accent: 'On Your Journey',
    lead: "Plan your route, discover amazing restaurants along the way, pre-order your favourite food, and pick it up at the perfect time. It's that simple!",
    cta: { label: 'Start Your Journey', to: '/plan-journey' },
  },
  steps: [
    { title: 'Plan Your Journey', text: 'Set your start and destination to see restaurants on your route.' },
    { title: 'Discover Restaurants Along Your Route', text: 'Find great restaurants along your route with real detour time and distance.' },
    { title: 'Pre-Order Your Food', text: 'Browse the menu, customize your order and pre-order for your preferred pickup time.' },
    { title: 'Pick Up & Continue Your Journey', text: 'Pick up your order at the selected time without waiting. Enjoy great food on your journey!' },
  ],
  benefits: [
    { icon: 'clock', title: 'Save Time', text: 'No long waits, no unnecessary detours.' },
    { icon: 'star', title: 'Great Food', text: 'Choose from top-rated restaurants.' },
    { icon: 'shield', title: 'On Your Route', text: 'Perfectly located for your journey.' },
    { icon: 'heart', title: 'Better Journeys', text: 'Good food makes every trip better.' },
  ],
}

const ABOUT: AboutContent = {
  hero: {
    eyebrow: 'About us',
    title: 'Good Food',
    accent: 'For Every Journey',
    leadHtml: 'At FoodOnTheGo, we believe great food should fit <b>seamlessly into your journey</b>. We help you discover amazing <b>restaurants along your route, pre-order</b> your favourite meals, and pick them up at the perfect time — so you can enjoy fresh, delicious food without detours.',
    script: 'Delicious Food Travels With You',
    pills: [
      { icon: 'fork', tone: 'orange', title: 'Discover Restaurants', sub: 'Along Your Route' },
      { icon: 'clock', tone: 'green', title: 'Pre-Order', sub: '& Save Time' },
      { icon: 'car', tone: 'blue', title: 'No Detours', sub: 'Just Great Food' },
    ],
  },
  stats: {
    illustrative: true,
    items: [
      { icon: 'users', tone: 'orange', value: '500+', label: 'Partner Restaurants' },
      { icon: 'pin', tone: 'purple', value: '50+', label: 'Cities & Growing' },
      { icon: 'smile', tone: 'green', value: '100,000+', label: 'Happy Customers' },
      { icon: 'star', tone: 'red', value: '4.8', label: 'Average Rating' },
    ],
  },
  mission: 'To make every journey better by giving people easy access to great food, exactly when and where they need it.',
  vision: 'To become the most trusted food pre-order platform for travellers, connecting people with amazing local restaurants across every route.',
  values: [
    { icon: 'users', tone: 'orange', title: 'Customer First', text: 'Your time and satisfaction matter.' },
    { icon: 'shield', tone: 'red', title: 'Quality & Trust', text: 'We partner with the best restaurants.' },
    { icon: 'bulb', tone: 'blue', title: 'Innovation', text: 'We build simple, smart solutions.' },
    { icon: 'heart', tone: 'green', title: 'Better Journeys', text: 'Good food makes every trip better.' },
  ],
  story: {
    eyebrow: 'Our story',
    title: 'A Journey Inspired\nby Real Travellers',
    intro: 'FoodOnTheGo was created to solve a simple problem – finding good food while on the move. We saw how many people travel every day and still struggle to get quality meals without long delays or unnecessary detours.',
    timeline: [
      { icon: 'car', title: 'The Idea', text: "Born from a real traveller's need for better food options on the road." },
      { icon: 'users', title: 'Building Together', text: 'We partnered with amazing restaurants to bring great food to your route.' },
      { icon: 'rocket', title: 'Growing Community', text: 'More travellers, more restaurants, more journeys.' },
      { icon: 'flag', title: 'A Better Tomorrow', text: 'Continuing to make every journey tastier, easier and more enjoyable.' },
    ],
  },
}

const HELP: HelpContent = {
  title: 'Help & Support',
  sub: 'How can we help you?',
  searchPlaceholder: 'Search for help articles...',
  topics: [
    { icon: 'bag', title: 'Orders & Pickup', text: 'Track orders, cancellations', to: '/my-orders', keywords: 'order pickup cancel track refund late ready' },
    { icon: 'card', title: 'Payments & Refunds', text: 'Payment methods, refunds', to: '/payment-methods', keywords: 'payment card upi wallet refund charge failed' },
    { icon: 'user', title: 'Account & Profile', text: 'Manage your account', to: '/my-profile', keywords: 'account profile password email phone delete login' },
    { icon: 'store', title: 'Restaurant Questions', text: 'Discover restaurants', to: '/restaurants', keywords: 'restaurant menu hours partner list' },
    { icon: 'route', title: 'Plan a Journey', text: 'Route and travel help', to: '/plan-journey', keywords: 'route journey trip detour distance map' },
    { icon: 'phone', title: 'App Issues', text: 'Technical support', to: '#contact', keywords: 'app crash bug update android install technical' },
  ],
  contacts: [
    { icon: 'chat', kind: 'chat', title: 'Live Chat', detail: 'Chat with our support team', note: 'Available once the support system is connected', backendRequired: true },
    { icon: 'mail', kind: 'email', title: 'Email Support', detail: 'support@foodonthego.com', href: 'mailto:support@foodonthego.com', backendRequired: false },
    { icon: 'phone', kind: 'phone', title: 'Call Us', detail: '+91 98765 43210', note: 'Available 8 AM – 10 PM (IST)', href: 'tel:+919876543210', backendRequired: false },
  ],
}

const FOR_RESTAURANTS: ForRestaurantsContent = {
  hero: {
    title: 'Grow Your Restaurant',
    accent: 'With FoodOnTheGo',
    lead: 'Reach more customers on their travel routes. Increase visibility, get more pickup orders and grow your business.',
    primaryCta: { label: 'List Your Restaurant', to: '/for-restaurants/register' },
    secondaryCta: { label: 'Learn More', to: '#how' },
  },
  benefits: [
    { icon: 'users', title: 'More Customers', text: 'Get discovered by travellers on your route' },
    { icon: 'pin', title: 'Easy Setup', text: 'Quick and simple onboarding' },
    { icon: 'bag', title: 'No Delivery Hassle', text: 'Pickup orders only' },
    { icon: 'car', title: 'Increase Revenue', text: 'Grow your business with zero delivery cost' },
  ],
  steps: [
    { title: 'List your restaurant', text: 'Tell us about your restaurant, location and opening hours.' },
    { title: 'Add your menu', text: 'Upload dishes, prices and photos from the partner dashboard.' },
    { title: 'Receive pre-orders', text: 'Travellers order ahead; you prepare for their pickup time.' },
    { title: 'Get paid', text: 'Payouts settle automatically for every completed pickup.' },
  ],
  closing: { title: 'Ready to List Your Restaurant?', text: 'Join FoodOnTheGo today and start receiving orders.', cta: { label: 'Get Started', to: '/for-restaurants/register' } },
}

const GET_APP: GetAppContent = {
  title: 'FoodOnTheGo',
  accent: 'In Your Pocket',
  lead: 'Plan a journey, find restaurants along your route and pre-order for pickup — all from your phone.',
  stores: [
    { store: 'google-play', status: 'placeholder', label: 'Google Play', sub: 'Coming soon' },
    { store: 'app-store', status: 'deferred', label: 'App Store', sub: 'Coming later' },
  ],
  highlights: [
    { icon: 'route', title: 'Plan on the go', text: 'Set your route and see restaurants near it in seconds.' },
    { icon: 'bag', title: 'Order ahead', text: 'Pre-order and pay securely before you arrive.' },
    { icon: 'clock', title: 'Skip the wait', text: 'Your order is ready when you get there.' },
  ],
}

const NOT_FOUND: NotFoundContent = {
  code: '404',
  title: 'Page not found',
  text: "The page you're looking for took a different route. Let's get you back on track.",
  actions: [
    { label: 'Return Home', to: '/' },
    { label: 'Plan a Journey', to: '/plan-journey' },
    { label: 'Explore Restaurants', to: '/restaurants' },
  ],
}

export class MockContentRepository implements ContentRepository {
  getSiteNavigation() { return NAVIGATION }
  getHomeContent() { return HOME }
  getHowItWorksContent() { return HOW_IT_WORKS }
  getAboutContent() { return ABOUT }
  getHelpContent() { return HELP }
  getForRestaurantsContent() { return FOR_RESTAURANTS }
  getGetAppContent() { return GET_APP }
  getNotFoundContent() { return NOT_FOUND }
}
