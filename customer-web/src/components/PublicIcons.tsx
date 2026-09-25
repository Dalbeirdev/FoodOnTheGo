import type { ReactElement } from 'react'
import type { IconName } from '../repositories'
import { BagIcon, ClockIcon, PinIcon, RouteIcon, SearchIcon, StoreIcon } from './Icons'

type P = { size?: number }
const stroke = (size: number) => ({ width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true })

const REGISTRY: Record<IconName, (p: P) => ReactElement> = {
  route: ({ size = 22 }) => <RouteIcon size={size} />,
  search: ({ size = 22 }) => <SearchIcon size={size} />,
  bag: ({ size = 22 }) => <BagIcon size={size} />,
  clock: ({ size = 22 }) => <ClockIcon size={size} />,
  pin: ({ size = 22 }) => <PinIcon size={size} />,
  store: ({ size = 22 }) => <StoreIcon size={size} />,
  star: ({ size = 22 }) => (<svg {...stroke(size)}><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z" /></svg>),
  shield: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z" /><path d="m9 12 2 2 4-4" /></svg>),
  heart: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M12 21s-7.5-4.6-9.5-9.3C1 8 3.5 4.5 7 4.5c2 0 3.5 1 5 2.8 1.5-1.8 3-2.8 5-2.8 3.5 0 6 3.5 4.5 7.2C19.5 16.4 12 21 12 21Z" /></svg>),
  users: ({ size = 22 }) => (<svg {...stroke(size)}><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" /><circle cx="17" cy="9" r="2.5" /><path d="M16 15.5a5 5 0 0 1 5.5 4.5" /></svg>),
  smile: ({ size = 22 }) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><path d="M8.5 14.5a4.5 4.5 0 0 0 7 0M9 10h.01M15 10h.01" /></svg>),
  target: ({ size = 22 }) => (<svg {...stroke(size)}><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="4" /><path d="M12 3v3M21 12h-3" /></svg>),
  eye: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" /><circle cx="12" cy="12" r="3" /></svg>),
  gem: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M6 3h12l4 6-10 12L2 9l4-6ZM2 9h20M9 3l3 6 3-6M12 9l-3 12M12 9l3 12" /></svg>),
  bulb: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3Z" /></svg>),
  rocket: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M5 15c-1.5 1.5-2 6-2 6s4.5-.5 6-2M14 4c3-1.5 6-1 7 0s1.5 4-1 7l-6 6-5-5 5-8Z" /><circle cx="15" cy="9" r="1.5" /></svg>),
  flag: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M5 21V4M5 4h11l-2 4 2 4H5" /></svg>),
  car: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11M4 11h16v6H4zM6 17v2M18 17v2" /><circle cx="7.5" cy="14" r="1" /><circle cx="16.5" cy="14" r="1" /></svg>),
  fork: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M7 2v8a3 3 0 0 0 6 0V2M10 2v20M17 2c-2 1-3 4-3 7v3h3v10" /></svg>),
  card: ({ size = 22 }) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="M3 10h18M7 15h4" /></svg>),
  user: ({ size = 22 }) => (<svg {...stroke(size)}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>),
  phone: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z" /></svg>),
  chat: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M4 5h16v11H9l-5 4V5Z" /><path d="M8 9h8M8 12h5" /></svg>),
  mail: ({ size = 22 }) => (<svg {...stroke(size)}><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></svg>),
  android: ({ size = 22 }) => (<svg {...stroke(size)}><rect x="6" y="2" width="12" height="20" rx="2.5" /><path d="M10 18h4" /></svg>),
  headset: ({ size = 22 }) => (<svg {...stroke(size)}><path d="M4 13v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1.5" /><rect x="17" y="13" width="4" height="6" rx="1.5" /><path d="M19 19a3 3 0 0 1-3 2h-2" /></svg>),
}

/** Renders an icon by repository name so content models stay free of React components. */
export default function PublicIcon({ name, size }: { name: IconName; size?: number }) {
  const Icon = REGISTRY[name]
  return <Icon size={size} />
}
