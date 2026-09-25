# FoodOnTheGo Logo Asset Package

Master source: `foodonthego-logo-master.svg`

## Approved direction
- Icon concept: location pin + road + cloche/chef + motion lines
- Food: dark navy `#101827`
- OnTheGo: orange/red gradient `#FF6A00` to `#FF2538`
- Secondary gray: `#667085`
- Background: white `#FFFFFF`
- Tagline: `ORDER • PICKUP • ON YOUR ROUTE`

## Files
- `foodonthego-logo-master.svg` — primary horizontal logo with tagline
- `foodonthego-logo-no-tagline.svg` — header/navigation version
- `foodonthego-icon.svg` — transparent icon-only mark
- `foodonthego-icon-square.svg` — Android/app-icon source
- `foodonthego-logo-dark-bg.svg` — dark background version
- `foodonthego-logo-monochrome-dark.svg` — one-color dark version
- `foodonthego-logo-monochrome-white.svg` — one-color white version on navy
- PNG exports are included for review and implementation.

## Illustrator
SVG is the recommended master. Adobe Illustrator can open these SVG files directly and save a native `.ai` copy when needed. This package intentionally does not fake an `.ai` file by renaming another format.

## Production notes
- Use the no-tagline version for website/app headers.
- Use the icon-only version for favicons and small placements.
- Use the square version as the Android launcher source, then generate adaptive launcher assets.
- Do not rasterize the master logo for implementation where SVG is supported.
