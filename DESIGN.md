---
name: Prime Printing Co.
description: Square-edged navy and sky print boutique for Kuwait, bilingual, built like a printer's proof sheet.
colors:
  navy: "#10254A"
  navy-deep: "#08132A"
  sky: "#7CA5C4"
  paper: "#FFFFFF"
  mist: "#F4F7F9"
  stock-green: "#1D7A4F"
  stock-green-wash: "#E8F4EE"
  caution-amber: "#B5730C"
  caution-amber-wash: "#FBF2E3"
  alert-red: "#B23A38"
typography:
  display:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "clamp(42px, 7vw, 86px)"
    fontWeight: 200
    lineHeight: 1.02
    letterSpacing: "normal"
  headline:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "clamp(28px, 3.6vw, 42px)"
    fontWeight: 300
    lineHeight: 1.12
  title:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "17px"
    fontWeight: 300
    lineHeight: 1.25
    letterSpacing: "0.1em"
  body:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "15px"
    fontWeight: 300
    lineHeight: 1.65
  label:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "12px"
    fontWeight: 400
    letterSpacing: "0.2em"
  arabic:
    fontFamily: "GE Dinar Two, IBM Plex Sans Arabic, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.65
    letterSpacing: "0"
rounded:
  none: "0"
  pill: "50%"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "42px"
  section: "96px"
components:
  button-primary:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.paper}"
    rounded: "{rounded.none}"
    padding: "15px 32px"
  button-primary-hover:
    backgroundColor: "{colors.sky}"
    textColor: "{colors.navy-deep}"
  button-outline:
    backgroundColor: "{colors.paper}"
    textColor: "{colors.navy}"
    rounded: "{rounded.none}"
    padding: "12px 20px"
  button-outline-hover:
    backgroundColor: "{colors.navy}"
    textColor: "{colors.paper}"
  input-field:
    backgroundColor: "{colors.mist}"
    textColor: "{colors.navy-deep}"
    rounded: "{rounded.none}"
    padding: "13px 14px"
  status-ok:
    backgroundColor: "{colors.stock-green-wash}"
    textColor: "{colors.stock-green}"
  status-caution:
    backgroundColor: "{colors.caution-amber-wash}"
    textColor: "{colors.caution-amber}"
---

# Design System: Prime Printing Co.

## Overview

**Creative North Star: "The Print Proof Sheet"**

Prime's interface behaves like a proof pulled from the press: square edges, hairline rules, uppercase tracked labels and one small sky-blue square standing in for the dot of the "i" in the logo. It is precise before it is pretty. Sizes, units and prices read as exact, and the product photography supplies the colour and warmth. Navy anchors the page, white and mist give it air, and sky appears sparingly as the signal that something is live.

The voice is precise, confident and warm and local. Precise: everything aligns to a grid and a 3-decimal KWD price is never rounded off. Confident: thin Montserrat set large, wide letter-spaced capitals, and deep navy panels. Warm and local: the system is bilingual from the root, and Arabic gets its own scale and no tracking rather than a mirrored copy of the Latin layout.

**Key Characteristics:**
- Zero border radius everywhere except circular avatars and category medallions in the app.
- One signature motif: the 9px sky square (`.prime-mark`), used as bullet, section marker and button glyph.
- Light weights (200 to 400) set large; weight is not the hierarchy device, size and case are.
- Hairline borders (1px at 12% navy) instead of fills to separate content.
- Real product photography fills cards edge to edge.
- Same system on website and iOS app shell.

## Colors

A navy and sky-blue palette on white and mist, with three status colours reserved for stock and order states.

### Primary
- **Press Navy** (#10254A): the brand's dominant ink and the default call-to-action fill; also the hero, menu, footer and page-head surfaces.
- **Deep Press Navy** (#08132A): body ink, hero overlays and the strongest text on sky.

### Secondary
- **Proof Sky** (#7CA5C4): the single accent. Used for the signature square, eyebrow labels, focus outlines, hover fills and the active underline. Never a large background.

### Neutral
- **Paper White** (#FFFFFF): page and card surface, and text on navy.
- **Cool Mist** (#F4F7F9): section bands, input fields and the homepage wall's background.
- **Hairline Navy** (12% navy): the standard 1px divider; **Strong Hairline** (22% navy) for emphasis.
- **Soft Ink** (55% deep navy): secondary text, with 45%, 50% and 60% steps in the tokens.
- **Sky Wash** (12% sky): tinted hover and selected backgrounds.

### Status
- **Stock Green** (#1D7A4F) on its wash (#E8F4EE): in stock, delivered.
- **Caution Amber** (#B5730C) on its wash (#FBF2E3): awaiting quote, no design yet.
- **Alert Red** (#B23A38): errors; extrapolated, no reference page yet uses it.

### Named Rules
**The One Voice Rule.** Sky is the only accent. If a screen needs a second accent colour, the screen is wrong; use navy, white or the product photograph.
**The Quiet Status Rule.** Status colours appear only as small pills on their wash, never as button or section fills.

## Typography

**Display Font:** Montserrat (with generic sans-serif fallback)
**Body Font:** Montserrat
**Arabic Font:** GE Dinar Two (licensed, self-hosted when supplied) falling back to IBM Plex Sans Arabic

**Character:** A single geometric family in thin and light weights does everything. Hierarchy comes from scale, uppercase and tracking, not from bold. Arabic is set a step larger with all tracking removed because letter-spacing breaks connected script.

### Hierarchy
- **Display** (200, clamp(42px, 7vw, 86px), 1.02): hero statements only.
- **Headline** (300, clamp(28px, 3.6vw, 42px), 1.12): section titles; page titles use clamp(34px, 5vw, 56px).
- **Title** (300, 17px, 1.25, uppercase, 0.1em tracking): product names on tiles and cards.
- **Body** (300, 15px, 1.65): descriptions and forms, 16px in Arabic.
- **Label** (400, 12px, 0.2em tracking, uppercase): eyebrows, field labels, buttons (buttons and nav at 16px, 0.14 to 0.16em).

### Named Rules
**The No Tracking In Arabic Rule.** Every letter-spacing token collapses to 0 under `:lang(ar)`, and text sizes step up about 15%.
**The Light Weight Rule.** Weights above 500 do not appear; emphasis comes from size, case or colour.

## Layout

Content sits in a 1440px wrapper with 28px side padding (16px on phones). Spacing follows a 4, 8, 12, 16, 20, 24, 28, 34, 42, 56, 74, 96px scale; sections breathe at 96px on desktop. Grids are four columns on desktop, two on tablet and phone; the homepage wall uses dense packing with one tall and two wide tiles, collapsing to all-square tiles on phones. The header is 110px on desktop and 76px on phones, sticky and white, or transparent over the hero until scrolled. In app mode the website header gives way to a topbar and bottom tab bar, and the home becomes a banner, a category strip and product grids.

## Elevation & Depth

Flat by default: surfaces are separated by hairlines and tonal bands (white against mist), not shadows. Depth appears only as a response to interaction.

### Shadow Vocabulary
- **Lift** (`box-shadow: 0 14px 30px rgba(16, 37, 74, .18)`): card hover on devices with a pointer, with a 3px upward shift and a navy border.
- **Press** (`box-shadow: 0 4px 10px rgba(16, 37, 74, .2)` and `scale(.985)`): active state on cards and nav items.
- **Tile hover** (`box-shadow: 0 18px 40px rgba(16, 37, 74, .28)`): homepage wall tiles.

### Named Rules
**The Flat-By-Default Rule.** No resting shadows. A shadow means the user is touching or hovering the object.
**The Pointer-Only Hover Rule.** Hover effects are wrapped in `@media (hover: hover)` so they never stick after a tap.

## Shapes

Square. Cards, buttons, inputs, tiles and badges all use zero radius and 1px hairline borders. The only curves are circular category medallions in the iOS app and the status pill. The signature geometry is the 9px sky square. Photos are cropped to their frame with `object-fit: cover`, never distorted.

## Components

### Buttons
- **Shape:** square (0 radius), 1px border, 15px 32px padding, uppercase Montserrat 300 at 16px with 0.16em tracking.
- **Primary (navy):** navy fill, white text; hover turns sky with deep-navy text and lifts 2px.
- **Default (white):** white fill used on navy surfaces; same hover.
- **Ghost:** transparent with a 42% white border on navy.
- **Outline:** hairline border, navy text; hover fills navy and inverts text. Used for secondary actions (shuffle, load more).

### Cards / Containers
- **Corner style:** none. **Background:** white on mist bands. **Border:** 1px hairline.
- **Hover:** border turns navy, card lifts 3px with the Lift shadow, title takes the accent colour.
- **Category card:** 1:0.58 landscape frame; a category photo fills it, with the 3D glossy SVG icon as fallback.
- **Product card:** square image, badge in sky-wash, title, KWD price to 3 decimals, outline add button.

### Inputs / Fields
- **Style:** mist fill, 1px hairline border, 13px 14px padding, 16px text, uppercase 12px tracked label above.
- **Focus:** 2px sky outline pulled in 1px; keyboard focus uses the same 2px sky outline with a 2px offset.

### Navigation
- **Website:** uppercase 16px links at 0.14em tracking with 12px 16px hit areas; hover gives a sky wash and a growing sky underline, press fills sky. The language switch is an outlined button, the cart a text link.
- **App:** a bottom tab bar (Home, Shop, Cart, Account) and a topbar with logo and search; category circles are 64px medallions with the category photo.

### Homepage Wall (signature component)
A dense mosaic of product photographs with caption overlays. Tiles reveal on scroll, drift slowly, and swap products live by sliding out and in from random sides; the Shuffle button reorders on demand. It honours `prefers-reduced-motion`.

## Do's and Don'ts

### Do:
- **Do** keep every corner square and every divider a 1px hairline.
- **Do** use Proof Sky only as the single accent, and keep the sky square as the recurring motif.
- **Do** show prices in KWD with exactly three decimals.
- **Do** set Arabic at a larger scale with zero tracking, and check every change in RTL and in the iOS app.
- **Do** let product photography carry colour and warmth.
- **Do** wrap hover-only effects in `@media (hover: hover)` and give touch an active state.

### Don't:
- **Don't** introduce rounded corners, gradient buttons or soft glassy SaaS styling.
- **Don't** use bold weights as hierarchy; use size, case and tracking.
- **Don't** add letter-spacing to Arabic text.
- **Don't** add a second accent colour or a status colour as a surface fill.
- **Don't** add resting drop shadows.
- **Don't** hide essential information behind hover.
