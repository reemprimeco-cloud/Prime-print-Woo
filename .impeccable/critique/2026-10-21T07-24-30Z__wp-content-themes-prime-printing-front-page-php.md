---
target: homepage
total_score: 20
max_score: 32
na_heuristics: 7,10
p0_count: 1
p1_count: 2
target_identity: "file:/home/user/Prime-print-Woo/wp-content/themes/prime-printing/front-page.php"
target_fingerprint: "sha256:e38526c975c33d5fe105a7b5c414e74c6eff17de09554a85dc58443fbfd06280"
target_path: /home/user/Prime-print-Woo/wp-content/themes/prime-printing/front-page.php
timestamp: 2026-10-21T07-24-30Z
slug: wp-content-themes-prime-printing-front-page-php
---
Method: dual-agent (source-based; live site unreachable; PHP not parsed by detector)

Score 20/32 (Acceptable). n/a: 7, 10.
Heuristics: 1=3, 2=3, 3=1, 4=3, 5=3, 6=2, 8=2, 9=3.

Verdict: system authored for Prime; composition is generic motion-theme; positioning claims absent above the fold.

Priority issues
- [P0] No pause/stop for auto-moving content (WCAG 2.2.2): hero columns, ticker, clients marquee, tile drift, 2.2s swaps. Fix: visible Pause motion toggle. Commands: harden, animate.
- [P1] Tile price hidden behind hover above 560px. Fix: always show price; gate hover with @media (hover: hover). Command: adapt.
- [P1] Homepage omits categories, designer, quote path, delivery/48h; "Price my sticker" CTA anchor is dead. Commands: shape, layout.
- [P2] Caption/label contrast fails on bright photos (about 2.1-2.4:1; sky on white 2.6:1; ink-faint/softer fail). Command: colorize.
- [P2] RTL marquee translateX seam, hero cue off-centre in RTL; about 18-21 infinite animations; swapped images lose srcset (800px into ~190px tiles); tap race on swapped links. Commands: adapt, optimize.

Detector: 0 on PHP (not parsed); 22 advisory on CSS/JS (21 font-size drift, 1 radius). 9px text in shop.css is a real legibility risk.
