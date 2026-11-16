# WordPress.org and README assets

Sources for the images in `.wordpress-org/`. Not shipped in the plugin.

**Real captures** (`captures/`) come from the public demo, the same blueprint as the README's Playground link, on WordPress 7.1 with Twenty Twenty-Five at 1280×800, 2× scale (phone: 390×844, 3×):

```sh
./node_modules/.bin/wp-playground-cli server --blueprint=.wordpress-org/blueprints/blueprint.json --port=9402
node .wordpress-org-src/capture.mjs              # writes .wordpress-org-src/captures/*.png
```

Screenshots 2–9 are copies of `single`, `list`, `editor`, `setup`, `settings`, `rsvp`, `tickets`, and `waitlist` (run from this folder):

```sh
i=2; for n in single list editor setup settings rsvp tickets waitlist; do cp captures/$n.png ../.wordpress-org/screenshot-$i.png; i=$((i+1)); done
```

**Brand.** Feriae were the Roman festival days, so the look borrows from them: Pompeian red `#A63A2B` (deep `#7E2A1F`), parchment `#F7F1E6`, ochre `#E0A33A`, laurel `#2F6B4F`, and warm ink `#1F1A17` (muted `#5E5149`). The wordmark is set in Fraunces; everything else is Inter. The mark (`mark.svg`) is a calendar day tile with a rising sun.

The drawn images keep text at WCAG AA contrast (4.5:1): parchment or white on the red and laurel, ink on the ochre, ink or muted text on parchment.

**Drawn images** are HTML pages rendered with Playwright (run from this folder):

```sh
node render.mjs screenshot-1.html ../.wordpress-org/screenshot-1.png 1280 800 2   # uses captures/month.png and phone.png
node render.mjs banner.html ../.wordpress-org/banner-1544x500.png 772 250 2
node render.mjs banner.html ../.wordpress-org/banner-772x250.png 772 250 1
node render.mjs icon.html ../.wordpress-org/icon-256x256.png 128 128 2
node render.mjs icon.html ../.wordpress-org/icon-128x128.png 128 128 1
cp mark.svg ../.wordpress-org/icon.svg
```

Set `PLAYWRIGHT_CHROMIUM_PATH` to reuse an installed Chromium.
